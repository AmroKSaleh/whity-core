# Workspace plugins

A workspace plugin is a `plugin.json` manifest that belongs to one workspace. It's published as data, without a deploy, and is served on the next request (WHIT-760). It is the runtime under Whity Builder (`docs/design/WHITY-BUILDER.md` section 2.3), and it uses the same format as a code plugin's manifest ([Plugin-Development.md](./Plugin-Development.md), Step 13).

| | Code plugin | Workspace plugin |
| --- | --- | --- |
| Lives in | `plugins/` on disk | `plugin_manifest_versions`, one workspace |
| Installed by | an operator, with a deploy | publishing a manifest |
| Loaded | once per worker, at boot | read per request |
| Behaviour | PHP routes, hooks and jobs | core's fixed entry points only |

## How it is served

`TenantManifestHost` reads the workspace's live, switched-on plugins once per request: one query for the version hash of each. Parsed manifests are cached per worker by hash, so a new publish is picked up by every worker on its next request, and nothing is reloaded. The read is cleared at the end of each request, like the RoleChecker's cache.

Core's fixed entry points ask the host, so nothing is registered into a worker's routing:

- `GET /api/frontend/features` adds the workspace's screens after core's and install-wide plugins' screens. A workspace screen that reuses a taken id is dropped. All screens go through the same permission filter and block validation.
- The navigation adds a menu item for each of those screens (`TenantPluginNavigation`), and the navigation handler applies each item's permission.
- Record routes and MCP operations come with the record store (WHIT-761).

## Publishing

`TenantManifestPublisher::publish($tenantId, $manifest, ...)` is the one write path. The manifest versions API (WHIT-764) adds previews, impact, tests and proposals in front of it. A publish:

1. validates the manifest as a whole, then applies the workspace-plugin policy below;
2. stores an immutable version and points the plugin at it;
3. adds its permission slugs to the global `permissions` catalogue so roles can grant them, and records them as this workspace's;
4. seeds its suggested roles into this workspace only;
5. writes `tenant_plugin.published` to the audit trail.

The system tenant can't publish a workspace plugin, because its roles are global.

## What a workspace plugin may not declare

`TenantManifestPolicy` refuses:

- `tables`, `dataTypes` and `limits`. A workspace plugin owns no tables (its records go in the shared record store), and limits are the operator's to set.
- Dependencies on other plugins.
- A name an install-wide plugin already uses.
- Permissions outside its own name. A plugin called CakeOrders may declare only `cakeorders:…` or `cakeorders_…:…`.
- A role whose parent isn't one of its own roles, so it can't inherit an administrator's permissions.
- Any API path in a screen outside its own record routes, `/api/x/<name>/`. Every string in the block tree is checked, so a block type added later can't open a gap.

## Privacy between workspaces

Permission slugs live in one global catalogue, so role grants can reference them. Two filters stop one workspace from learning another's plugin logic from those names:

- `GET /api/permissions` leaves out slugs that only other workspaces' plugins declare.
- The roles API refuses to grant such a slug.

Two workspaces may each have a plugin of the same name. Each one's permissions are filed separately in the worker's permission registry, and grants are per workspace role.

## Tables (migration 164)

- `plugin_manifest_versions`: immutable published versions, with hash, publisher, the person acted for, and the approver.
- `tenant_plugins`: the live version for each plugin, and whether it is switched on.
- `tenant_plugin_permissions`: the slugs each workspace's plugins declare.

All three are tenant-owned, and every query binds `tenant_id`.
