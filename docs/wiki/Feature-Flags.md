# Feature Flags

How Whity Core lets an operator turn platform-wide capabilities on or off,
end to end: the `SettingsRegistry` mechanism, the generic admin page, and how
a flag actually gates code.

Related: [PERMISSION_SYSTEM](PERMISSION_SYSTEM.md) · [MCP-Operator-Runbook](MCP-Operator-Runbook.md) ·
[SSO-Google-Setup](SSO-Google-Setup.md) · [Document-Designer](Document-Designer.md) ·
[Cron-Operations](Cron-Operations.md) · [ADR 0012](../adr/0012-document-render-microservice.md).

---

## What this is

A **feature flag**, in this codebase, is a curated boolean **Website Setting**
(`src/Core/Settings/SettingsRegistry.php`) that reads as a platform
**capability** an operator would recognise and casually switch on/off for the
whole instance — not a technical config detail of some other setting.

There is no separate storage for flags. They live in the same `app_settings` /
`tenant_settings` tables and the same `SettingsService::effective()`
resolution as every other setting; a flag is just a `BOOL_KEYS` entry that the
registry additionally curates into `FEATURE_FLAG_KEYS`:

```
FEATURE_FLAG_KEYS ⊆ BOOL_KEYS ⊆ (all registry keys)
```

`SettingsRegistry::isFeatureFlag(string $key): bool` answers "is this key
curated as a flag?", and every registry descriptor (`describe()` /
`describeText()`) carries `isFlag: true` for those keys and omits the field
entirely otherwise (mirroring how `options` is only present for enum keys).

### The generic admin page

`web/app/(protected)/admin/settings/feature-flags/page.tsx` is a **system-tenant**
admin tab that renders one toggle per registry entry where `isFlag === true` —
nothing is hardcoded on the client. It fetches `GET /api/v1/settings/global`,
filters the returned `registry` array with `featureFlagEntries()`
(`web/app/(protected)/admin/settings/settings-shared.tsx`), and PATCHes
`/api/v1/settings/global` on save, exactly like the rest of the global settings
surface.

**Adding a key to `FEATURE_FLAG_KEYS` server-side is the ONLY change needed
for a toggle to appear on this page.** No frontend PR is required.

### Why a flag alone doesn't do anything

`FEATURE_FLAG_KEYS` only controls whether a toggle **appears** on the admin
page. It does not, by itself, change any runtime behaviour — the setting has
to be read and enforced by the feature it names. A key that sits in
`FEATURE_FLAG_KEYS` but that no code path checks would be a **decorative
toggle**: worse than no flag, because it tells an operator they turned
something off when they didn't. Every flag documented below is verified to
actually gate its feature (see [Inventory](#inventory) and the tests listed
there).

### Scope boundary — see GitHub issue #326

This is deliberately the **lightweight, `SettingsRegistry`-based** path only:
one boolean setting, one global (or per-tenant) value, read fresh per request.
It explicitly does **not** include:

- a dedicated flags database table,
- a plugin-facing capability-declaration SDK contract,
- per-tenant flag overrides beyond the existing global/tenant settings layer,
- percentage rollouts / gradual release / A-B targeting.

That larger system is tracked separately as **GitHub issue #326,
"Platform-level feature-flags registry"** (`post-launch`). If you find
yourself wanting any of the above, that issue — not this mechanism — is where
it belongs.

---

## The canonical worked example: `documents.render_enabled`

The document/label designer's server-side render tier
(`POST /api/document-templates/{id}/render`) is the reference pattern every
flag in this codebase should mirror. Read `src/Api/DocumentRenderApiHandler.php`
alongside this section.

1. **Registry entry** (`SettingsRegistry.php`): a constant
   (`DOCUMENTS_RENDER_ENABLED = 'documents.render_enabled'`), a `'false'`
   default (opt-in — the render tier is a whole separate Chromium-bearing
   Docker service, not something every sovereign deploy wants running),
   membership in `BOOL_KEYS`, `GLOBAL_ONLY_KEYS` (an infra-level master switch,
   not a meaningful per-tenant preference), and `FEATURE_FLAG_KEYS`, plus a
   `validateBoolean()` arm in `validate()`.
2. **The gate runs FIRST, before any other work:**

   ```php
   public function render(Request $request, array $params): Response
   {
       $ctx = $this->context($request);
       if ($ctx instanceof Response) {
           return $ctx;
       }
       [$tenantId, $callerId] = $ctx;

       $effective = $this->settings->effective($tenantId);
       if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
           return Response::error('Server-side document rendering is disabled on this instance', 503);
       }
       // ... RBAC, tenant-scoping, batch limits, the actual render call ...
   }
   ```

   Note the order: the flag is checked **before** the template lookup, RBAC, or
   any other work — a disabled instance never touches the database for this
   route, let alone calls the external render service. The error is a clean,
   generic 503 (never a raw exception, never a downstream stack trace).
3. **Tests** (`tests/Api/DocumentRenderApiHandlerRealEngineTest.php`): a
   real-engine test proves the flag-off path returns 503 **and** that the
   downstream service was never invoked (via a fake client's call log,
   `testFeatureFlagOffReturns503AndNeverCallsRenderService`), and that the
   happy path works once the flag is set to `'true'`
   (`enableRendering()` helper).

Every other flag in this codebase — `mcp.enabled`, `auth.sso_enabled`,
`plugins.store_enabled`, etc. — follows the same shape: **check the flag
first, fail closed with a clean typed error, prove both paths with a test that
also asserts the disabled path never reaches the expensive/external part.**

---

## Recipe: adding a new flag

1. **Decide it's actually a flag, not a config detail.** Ask: would an
   operator describe this as "a capability I can switch on/off", or is it a
   parameter of an already-on feature (an SMTP host, a batch limit, an S3
   region)? Only the former belongs in `FEATURE_FLAG_KEYS`. See the "Deliberately
   excluded" note in `SettingsRegistry::FEATURE_FLAG_KEYS`'s docblock for
   worked counter-examples (`mail.events.*`, `storage.s3.path_style`).
2. **In `src/Core/Settings/SettingsRegistry.php`:**
   - Add a `public const YOUR_FLAG = 'your.flag_key';` (dotted, snake_case,
     matching existing naming).
   - Add it to `BOOL_KEYS`.
   - Add it to `GLOBAL_ONLY_KEYS` if the flag only makes sense instance-wide
     (most heavyweight/optional-subsystem flags do); omit it there if a
     per-tenant override is meaningful.
   - Add it to `FEATURE_FLAG_KEYS`.
   - Add its default to `DEFAULTS`. Think hard about the default's direction:
     - **Opt-in (`'false'`)** for a brand-new heavyweight/optional subsystem
       nobody has configured yet (`documents.render_enabled`'s shape).
     - **Opt-out (`'true'`)** for an additional kill-switch layered on top of
       an already-existing, already-configured mechanism, so you don't
       silently break deployments that configured that mechanism before your
       flag shipped (`auth.sso_enabled`'s shape, and this task's
       `plugins.store_enabled`).
   - Add a `self::YOUR_FLAG => self::validateBoolean($value, self::YOUR_FLAG),`
     arm in `validate()`.
3. **Gate the actual feature** — mirror `documents.render_enabled` exactly:
   read `SettingsService::effective()` (or `getGlobal()` for a global-only
   flag) and check it **first**, before any other work, returning a clean
   typed error (403/503, matching the feature's existing error conventions)
   when off. Never let a disabled flag reach the expensive/external/sensitive
   part of the code.
4. **Frontend label (optional but recommended):** add an entry to `FIELD_META`
   in `web/app/(protected)/admin/settings/settings-shared.tsx` — `{ label,
   help }` — so the flag reads well on the Feature Flags tab. Without one it
   still renders (via `humanizeKey()` fallback), just less polished.
5. **Tests:**
   - Extend `tests/Unit/Core/Settings/SettingsRegistryTest.php`:
     `testFeatureFlagKeysAreExactlyTheCuratedCapabilityToggles` (assert
     `isFeatureFlag()` true), `testDescriptorMarksFeatureFlagKeysWithIsFlagTrueAndOmitsItOtherwise`
     (assert `isFlag: true` in `describe()`), plus a dedicated default/type/
     validation test for the new key, and update
     `testKnownKeysAreExactlyTheDesignedFields()` / the `describe()` count.
   - Add a real-engine test on the handler that gates the feature, proving:
     the disabled path returns the clean error **and never reaches the
     expensive/external call** (assert on a fake/stub's call log, like
     `DocumentRenderApiHandlerRealEngineTest`), and the enabled path still
     works.
6. **Nothing else.** No migration, no new table, no OpenAPI schema change
   (unless the feature's own request/response shape changes), no frontend
   code beyond the optional `FIELD_META` entry.

---

## Inventory

Current `FEATURE_FLAG_KEYS`, in registry declaration order. "Global-only" means
the flag is operator-level and not tenant-overridable (`SettingsRegistry::isGlobalOnly()`).

| Flag key | Default | Global-only | Controls | Why an operator would turn it off |
| --- | --- | --- | --- | --- |
| `mcp.enabled` | `false` | no (per-tenant) | Whether the MCP JSON-RPC endpoint (`src/Mcp/JsonRpc/Dispatcher.php`) accepts tool/resource/prompt calls for a tenant | Most instances don't want to expose an AI-agent-facing API surface at all; opt-in per tenant |
| `auth.self_registration_enabled` | `false` | yes | Whether the public `POST /api/register` route is open at all | A sovereign, operator-provisioned instance keeps signup closed by default |
| `auth.registration_approval_required` | `true` | yes | When signup is open, whether a new membership lands `invited` (pending admin approval) instead of active | Lets an operator open signup while still gating activation |
| `auth.sso_enabled` | `true` | yes | Master kill-switch for federated sign-in (`SsoAuthHandler::start/callback`), instance-wide, on top of per-tenant IdP configuration | Instantly disable SSO (e.g. an IdP incident) without deleting configured providers |
| `plugins.store_enabled` | `true` | yes | Master kill-switch for the plugin marketplace — `POST /api/plugins/install-from-store` and `GET /api/plugins/store/catalog` (`InstallFromStoreApiHandler`) — on top of the `plugins.store_allowed_hosts` allowlist | A sovereign/compliance-sensitive deployment may want the whole external-network-calling integration off, without losing its configured trusted-host list |
| `documents.render_enabled` | `false` | yes | Whether `POST /api/document-templates/{id}/render` calls the separate `whity_render` headless-Chromium Docker service (`DocumentRenderApiHandler`) | A whole extra Chromium-bearing container most sovereign deploys never run |

`plugins.store_enabled` and `plugins.store_allowed_hosts` work together, not
redundantly: the allowlist is the **SSRF control** (which hosts, if any, may
ever be contacted — empty is already "off" by default) and is a free-form
string, so it cannot itself be a `FEATURE_FLAG_KEYS` entry (the tab only
renders booleans). `plugins.store_enabled` is the **one-click kill-switch** —
flip it off during an incident or compliance freeze without losing the
allowlist you already configured. Both are checked, allowlist after switch,
in `InstallFromStoreApiHandler::resolveStoreOrigin()` before any outbound
request.

---

## Audit: subsystems considered and not flagged

This section records the reasoning behind subsystems that were evaluated for
a feature flag during the WC-feature-flags-audit effort and deliberately
**not** given one, so the decision isn't re-litigated from scratch later.

- **Queue worker / cron / scheduler** (`docker-compose.yml`'s `queue-worker`,
  `cron`, `scheduler` services). These are already opt-in at the
  **infrastructure** level via Compose `profiles` — a different axis from a
  settings flag (does the app *try* to use the feature vs. does a worker
  *exist* to process it). A settings-level "queue enabled" flag was
  considered, specifically to stop `POST /api/jobs` from silently accepting
  work nobody will process. It was **not added**, because:
  - The dominant producer of queue jobs is **internal**
    (`NotificationDispatcher` enqueues `SendNotificationDeliveryJob` for every
    channel delivery) — this must always succeed for configured notifications
    to work at all, so a flag on the public submission path wouldn't touch the
    bulk of the risk.
  - The public submission surface (`JobsApiHandler` / `JobRegistry::isSubmittable()`)
    is already fail-closed at the job-**name** level: as of this writing the
    only core job registered as API-submittable is the diagnostic `EchoJob`.
    There is very little to gate.
  - The queue is durable/async by design — a pending job isn't "broken," it's
    waiting; there's no clean synchronous failure point to hook a flag into
    without changing that contract. The actual operational signal an operator
    wants ("is anything actually draining the queue?") is a job-age
    monitoring/alerting concern, not a runtime toggle.
  - A second on/off switch living in Settings, independent of whether the
    worker container is actually running, is exactly the kind of config-drift
    footgun a sovereign operator should not have to reason about.

  Conclusion: the existing Compose-profile opt-in is sufficient; no settings
  flag was added.

- **2FA enforcement** (`TwoFactorPolicyResolver`, `two_factor_policies`
  table). Already governed by tenant/OU/user-scoped policy **rows**, not a
  blunt instance-wide switch — no policies means no enforcement, which is
  already the safe default. A global boolean would add nothing a policy row
  doesn't already express more precisely.

- **Email sending** (`mail.transport`). Already an enum (`none` / `log` /
  `smtp`) whose `none` default **is** the off state — adding a redundant
  boolean alongside an already-working tri-state control would be decorative.

- **Storage driver** (`storage.driver`) and **billing/payment-wall
  enforcement** (`billing.enforcement_default`). Same shape as email: each is
  already an enum with a safe "off"/"local"/"warn" default. `FEATURE_FLAG_KEYS`
  is deliberately boolean-only (see its docblock); these don't fit the shape
  and don't need to.

- **Avatar/file upload, branding assets.** No heavyweight/optional dependency
  found — branding upload validation is core, always-on admin functionality,
  not something a sovereign operator would want to disable outright.

- **SSO/OIDC** (`auth.sso_enabled`) and **MCP** (`mcp.enabled`) were both
  already flagged before this audit. Verified, not re-added: `SsoAuthHandler::start()`,
  `::callback()`, and the token-exchange path all check `ssoEnabled()` first;
  `Whity\Mcp\JsonRpc\Dispatcher` throws `McpFeatureDisabledException` (→ HTTP
  403) via the `$tenantMcpEnabled` closure wired in `public/index.php`, which
  reads `mcp.enabled` fresh per tenant.

- **Subsystems with no implementation to gate.** n8n integration (issue #45),
  outbound webhook subscriptions (issues #182/#187), and realtime/SSE
  (Tasker task WC-210) are all **unbuilt** as of this writing — there is no
  code path to flag. Revisit this document once any of them ship.

---

## Related

- [ADR 0012 — Document render as a dedicated microservice](../adr/0012-document-render-microservice.md)
- [MCP Operator Runbook](MCP-Operator-Runbook.md)
- [Sign in with Google (SSO)](SSO-Google-Setup.md)
- [Cron Operations](Cron-Operations.md)
- GitHub issue #326 — Platform-level feature-flags registry (the bigger system this one deliberately does not build)
