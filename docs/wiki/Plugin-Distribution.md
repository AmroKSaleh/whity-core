# Plugin Distribution

How a real (non-example) plugin is packaged, installed into a Whity host, and
removed. Established by the WC-170 pilot
(`whity/plugin-announcements`) — use that repository as the template.

Core principle: **real plugins are never committed to whity-core**. `plugins/`
is a runtime mount point — `plugins/.gitignore` keeps anything except the
reference plugins (`HelloWorld/`, `ExamplePlugin.php`) out of git, so an
installed plugin never dirties the host checkout. Product plugins live in
their own repositories.

## Package anatomy

A distributable plugin is one Composer package in its own repository, with the
plugin code at the **repo root** (the loader maps `plugins/<DirName>` to the
`<DirName>\` namespace, so the deploy-copy lands as `plugins/Announcements/`
containing `AnnouncementsPlugin.php`, `Api/`, `Migrations/`):

```
whity-plugin-announcements/
  AnnouncementsPlugin.php       # PluginInterface + PluginRequirementsInterface + PluginFrontendInterface
  Api/…                         # handlers (tenant-scoped, prepared statements)
  Migrations/…                  # MigrationInterface impls incl. the permission-grant migration
  composer.json                 # name whity/plugin-*, require whity/plugin-sdk ^1.2 — NEVER whity-core
  tests/, phpunit.xml, phpstan.neon, stubs/   # dev-only; export-ignored
  README.md
```

- **Depends on the SDK only.** `composer.json` requires `whity/plugin-sdk`
  (path repository for local dev, e.g. `"url": "../whity-core/sdk"`). The two
  runtime host seams — `\Whity\app(Database::class)` for the PDO and
  `TenantContext` for the tenant id — are *not* package dependencies: they are
  provided by the host at runtime and stubbed for the package's own PHPStan
  (`stubs/whity-host.stub.php`). The package's test suite runs against the SDK
  alone; that is the proof of independence.
- **Versioning:** semver; declare `getSdkConstraint()` (e.g. `'^1.2'`). The
  host's version gate refuses incompatible plugins at load (quarantine), so a
  plugin never half-loads against the wrong contract.

## Installing into a host

1. **Deploy-copy the plugin into `plugins/`** (dev files excluded — the repo's
   `.gitattributes` export-ignores them):

   ```powershell
   robocopy ..\whity-plugin-announcements plugins\Announcements /E `
     /XD tests vendor stubs .git .phpunit.cache `
     /XF phpunit.xml phpstan.neon .gitattributes .gitignore composer.lock
   ```

   (or `git archive HEAD` from the plugin repo, which honors the repo's
   `.gitattributes` export-ignore list and produces the same file set). The
   host's `plugins/.gitignore` keeps the copy out of git.

2. **Run migrations** — the runner discovers plugin migrations automatically
   and records them as `plugin:<Name>:<Class>`:

   ```
   php public/index.php migrate run
   ```

   The plugin's grant migration attaches its permissions to the `admin` role,
   so the feature works without manual SQL.

3. **Regenerate the OpenAPI spec** — this is a *deploy step*: the served
   `public/openapi.json` must describe the deployment's actual route surface
   (core + installed plugins), because the schema-driven CRUD screens derive
   their columns and forms from it at runtime:

   ```
   php public/index.php generate:openapi
   ```

   Note: the spec file **committed to whity-core** is the core baseline (core
   routes + the reference plugins). The core test suite's snapshot guard
   regenerates over a copy of the reference plugins only, so an installed real
   plugin does not fail it — but the plugin-inflated `public/openapi.json` in
   your working tree is DEPLOYMENT state: **never commit it**. Restore it
   before committing core changes (`git checkout -- public/openapi.json`); the
   snapshot test will fail loudly on a dirty spec to remind you.

4. **Restart the workers** (`docker compose up -d --force-recreate frankenphp`
   or your deployment's equivalent). Two reasons: outside `APP_ENV=development`
   plugins are deliberately not hot-loaded (WC-160), and the RBAC checker's
   worker-level permission cache must pick up the migration's new grants.

That's the whole install: the plugin's routes are live (RBAC-enforced via its
route-level `requiredPermission`), its screen appears in the sidebar
(descriptor-derived, `/admin/x/<feature-id>`), and the list/create/edit/delete
UI renders with **zero per-app frontend code**.

## Uninstalling

1. Disable at runtime if needed: `POST /api/plugins/{name}/disable` (drops its
   routes/hooks/features immediately).
2. Roll back its migrations **before** removing the code (the runner needs the
   migration classes). **Caveat — rollback is global LIFO:** each
   `php public/index.php migrate rollback` reverts the single most recently
   applied migration across the WHOLE ledger; there is no per-plugin
   targeting. Check `php public/index.php migrate status` first: if the
   plugin's `plugin:<Name>:*` entries are the most recent, run rollback once
   per entry; if other migrations were applied after them, you CANNOT
   selectively roll the plugin back this way — either roll forward with a
   manual cleanup migration or accept the schema remnants. (The grant
   migration's `down()` is safe either way: it removes only its own
   marker-scoped permissions and never another role's grants.)
3. Delete `plugins/<Name>/`, regenerate the spec, restart workers.

## Sanity checklist after install

- `php public/index.php migrate status` shows the plugin's migrations Executed.
- `GET /api/plugins` lists the plugin `active` (version gate passed).
- `GET /api/frontend/features` (as a permitted user) includes its descriptor.
- Its screen renders at `/admin/x/<feature-id>` and a denied user gets the
  empty feature list + 403 on the data API.
