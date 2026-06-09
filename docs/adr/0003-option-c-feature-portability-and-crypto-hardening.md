# ADR 0003 — Option C: Feature Portability & Commodity Hardening

- **Status:** Accepted
- **Date:** 2026-06-09
- **Tracking:** GitHub epic #156 (Tasker WC-157); child issues #157–#171; Tasker flow "Option C: Feature Portability & Commodity Hardening"
- **Deciders:** Project owner + maintainers

## Context

Whity-Core powers several of the project owner's applications (notably **KeyHub** and
**Elmak**) that share features. The driving question was strategic: keep investing in
the bespoke Whity-Core framework, or rebase the platform onto **Laravel** to get an
ecosystem "for free" — with the real goal being *transporting features between apps
without rewriting them each time*.

A grounded codebase audit (five areas → adversarial verification → synthesis →
completeness critic) established the forces:

1. **Portability already exists, partially.** The plugin system (`PluginInterface`,
   `PluginLoader`) already lets a feature ship as routes + permissions + hooks +
   migrations. But "transporting a feature" today means *copying a folder*: there is no
   versioning, no dependency contract, no distribution channel, the contract lives
   inside the AGPL core, plugin `getMigrations()` is never executed, and there is no
   frontend story — so the UI is re-built per app.
2. **Some hand-rolled commodity code is genuinely dangerous.** TOTP 2FA secrets are
   encrypted with raw AES-256-CBC and **no MAC** (`src/Auth/TotpService.php`) —
   unauthenticated and malleable. The plugin hot-reload runs `eval('?>' . $rewritten)`
   (`src/Core/PluginLoader.php:871`) on the normal load path, an arbitrary-code-execution
   primitive that is not gated to development. The JWT layer (`src/Auth/JwtParser.php`)
   is hand-rolled with a base64url padding bug and missing claim hygiene (it is *not*,
   however, vulnerable to alg-confusion).
3. **A documented tenant-isolation guarantee does not run.** `ScopesToTenant`
   (`src/Core/Database/ScopesToTenant.php`) is advertised — including in the project
   instruction set ("never bypass the `ScopesToTenant` trait") — but **zero production
   paths use it**; real isolation relies on developers hand-writing `tenant_id = ?` in
   every handler. For a framework that customers *extend*, a single forgotten predicate
   is a silent cross-tenant leak.
4. **Whity-Core is also a product.** It is licensed (AGPL-3.0 + Commons Clause), so a
   wholesale Laravel rebase would both discard substantial working, tested code (700+
   tests) and *weaken* the differentiation — a lean, sovereign, near-zero-dependency
   platform is the selling point, not "another Laravel starter."

The deciding constraint is therefore **not** "custom vs Laravel" in the abstract — both
ecosystems offer feature portability (Laravel via Composer packages + service-provider
auto-discovery). It is: *which parts are worth owning forever*, and *how to make the
existing plugin system a real cross-app distribution mechanism*.

## Decision

**We will keep Whity-Core as a custom framework (Option C) and NOT rebase onto Laravel.**
We will (1) stop hand-rolling security-critical commodity code where a vetted library is
demonstrably safer, while keeping the parts already done correctly, and (2) evolve the
plugin system into a versioned, distributable, full-stack cross-app feature-portability
SDK. The thesis is proven end-to-end by extracting **one simple shared CRUD feature**
into a distributable plugin consumed by **both** KeyHub and Elmak (extract-once /
consume-twice).

Per-area decisions (**keep** / **replace** / **build**):

| # | Area | Decision | One-line rationale |
|---|------|----------|--------------------|
| 1 | **Auth & crypto** | **REPLACE** the unauthenticated AES-256-CBC TOTP encryption with an authenticated-encryption library, and **REPLACE** the hand-rolled JWT with `firebase/php-jwt` v7; **KEEP** bcrypt, otphp TOTP verification, `hash_equals`, and `JwtSecretGuard`. | Homemade symmetric crypto and JWT are the most dangerous, least differentiating code; the correct primitives already in use stay. |
| 2 | **Data & SQL** | **KEEP** raw-PDO persistence (no query builder / DBAL); **RESOLVE** the dead `ScopesToTenant` trait (wire it in structurally, or delete it and correct the docs). | Persistence is sound and the lean dependency profile is a feature; the only real defect is a security guarantee that does not execute. |
| 3 | **Routing & HTTP** | **KEEP** the hand-rolled router/kernel; **fix** the 404-vs-405 and `{id:\d+}` bugs in-house; **DEFER** full PSR-7/PSR-15 adoption; **DROP** FastRoute. | The router is fine for this scale; PSR-7/15 only pays off if cross-host plugin interop becomes a hard requirement, and it adds worker hot-path allocation cost. |
| 4 | **Plugin SDK** | **BUILD** a standalone, semver'd `Whity\Sdk` contract package; execute plugin migrations; add SDK/version + dependency gating; stage Composer distribution. **REJECT** runtime `composer/composer` and premature published JSON-Schema manifests. | This is the keystone for cross-app sharing — turn folder-drop into versioned, dependency-aware, distributable packages. |
| 5 | **Frontend** | **BUILD** a layered portability story: enrich OpenAPI (the engine) → typed client + shared shadcn-registry components → plugin frontend-contribution descriptor with a schema-driven CRUD renderer. **DROP** Module Federation; share UI via the shadcn registry (copy-in) before minting npm packages. | A full-stack feature has UI; without a frontend contribution surface, every app re-implements the screen. |

Two items are **high-urgency** and are sequenced first (after this ADR): the
unauthenticated TOTP encryption (#158) and the ungated `eval()` hot-reload (#160).

### In-house hardening decisions (no new dependency)

These four hardening items (consumer #160) are decided to be **fixed in-house**, adding
no dependency:

- **Cookie `Secure` flag — FIX:** `CookieManager` will emit `; Secure` whenever
  `APP_ENV` is not `development` (today it is hard-omitted for *all* environments);
  `HttpOnly` + `SameSite=Lax` are **KEPT**.
- **CSRF — ADD (defense-in-depth):** add a CSRF defense for state-changing auth POSTs
  (`/api/login`, `/api/login/2fa`, `/api/auth/refresh`, `/api/auth/logout`) — a
  double-submit cookie token or a required custom header validated server-side — rather
  than relying on `SameSite=Lax` alone.
- **`eval()` hot-reload — GATE:** the `eval('?>' . $rewritten)` plugin hot-reload path
  is gated to development only (unreachable when `APP_ENV != development`).
- **Router — FIX:** correct the 404-vs-405 handling and `{id:\d+}` constraint matching
  in-house.

### Corrected library facts (recorded so implementers don't re-litigate)

- **`firebase/php-jwt` v7** — BSD-3-Clause, **zero runtime dependencies**, `php ^8.0`
  (8.4-ready). Preferred over `lcobucci/jwt`, which hard-requires `ext-sodium` +
  `ext-openssl`.
- **`defuse/php-encryption`** — MIT; pure-PHP AES-256-CTR + HMAC (Encrypt-then-MAC).
  **Caveat:** issue #525 reports v2.4.0 refusing to load under some web SAPIs — it
  **must be load-tested under the FrankenPHP worker SAPI and pinned** (fall back to
  v2.3.x or to halite).
- **`paragonie/halite`** — **MPL-2.0** (not ISC/MIT); libsodium XChaCha20-Poly1305 AEAD;
  needs `ext-sodium` / `sodium_compat`. Fallback option for #158.
- **`composer/semver`** — MIT; used for plugin constraint evaluation (no hand-rolled
  `version_compare`, no runtime `composer/composer`).
- **FastRoute (`nikic/fast-route`)** — stale / effectively permanent-beta; **dropped**.
- **`@module-federation/nextjs-mf`** — EOL / App-Router "Not Recommended" on Next 16;
  **dropped**.
- **Licensing:** all candidate inbound licenses (MIT / BSD-3 / Apache-2.0 / MPL-2.0) are
  compatible with an AGPL-3.0 + Commons-Clause product — Commons Clause is a seller-side
  restriction with no inbound obligation, and none are GPL-incompatible per the
  dependency policy.

### License-string follow-up

`composer.json` declares `"license": "AGPL-3.0"` only, but `LICENSE` carries **AGPL-3.0
+ Commons Clause**. This discrepancy must be corrected (an SPDX expression plus a
`LICENSE`-file pointer, or a documented note) — tracked as a follow-up under this
initiative.

## Alternatives Considered

- **Option A — stay fully custom, change nothing structural.** Rejected: leaves the
  dangerous crypto, the ungated `eval()`, and the folder-drop portability gap in place;
  does not advance the stated goal.
- **Option B — rebase onto Laravel.** Rejected: discards working, tested code; Octane
  would give the FrankenPHP worker model, but `spatie/laravel-permission` does **not**
  model the OU-chain role inheritance that is core differentiation, so we would fight
  the ecosystem to keep it; and as a product it weakens differentiation ("another
  Laravel starter"). The escape hatch — if maintenance of the whole stack ever outruns
  team capacity — is revisited, not taken now.
- **Option C — keep custom, de-risk commodity internals, build the portability SDK.**
  **Chosen.**
- **Do nothing.** The unauthenticated TOTP crypto and the ungated ACE primitive remain
  shipped; portability stays manual.

## Consequences

### Positive

- Feature portability becomes real: a shared feature is authored once and installed in
  KeyHub and Elmak as a versioned, dependency-gated, full-stack plugin.
- The two highest-severity security findings are eliminated; the SDK contract is
  decoupled from the AGPL core so it can be depended on safely.
- The lean, sovereign positioning is preserved — only narrowly-scoped, well-justified,
  license-reviewed dependencies are added.

### Negative / Trade-offs

- New (vetted) dependencies enter the tree (`firebase/php-jwt`, an AEAD library,
  `composer/semver`, plus dev-only `openapi-typescript`/`openapi-fetch`), each requiring
  the dependency-policy approval + security audit.
- Replacing the TOTP scheme requires a re-encryption migration of existing
  `users.two_factor_secret` values; the JWT swap touches three claim re-parse sites.
- The SDK extraction touches the plugin contract and every example plugin; CI must grow
  a real-Postgres + plugin-load job to make the "verified against a real DB" bar
  enforceable.
- The pilot (T14/T15) crosses into the separate KeyHub/Elmak repositories.

### Impact on existing conventions

- **Plugin interface** moves from `Whity\Core\PluginInterface` to a versioned
  `Whity\Sdk` contract; plugins implement the SDK type, not the core. Plugins gain a
  declared SDK constraint and inter-plugin dependencies (semver), and a frontend feature
  descriptor.
- **Tenant isolation:** the instruction-set guarantee about `ScopesToTenant` is made
  true (structural wire-in) or the wording is corrected to match reality — no advertised
  guarantee may remain that only runs under tests.
- **Migrations:** plugin-declared migrations are actually executed and recorded under a
  per-plugin namespace, each wrapped in a transaction (reinforces the tested-`down()`
  rule for plugin schema too).
- **OpenAPI:** the spec becomes the typed contract source of truth (populated
  `components.schemas`), feeding generated clients and schema-driven UI.
- **Testing:** CI gains a PostgreSQL service container and covers the SDK package +
  `plugins/` tree; data-layer work is verified against real SQLite/Postgres, not mocked
  PDO.
- **Dependency policy:** this ADR is the architect-approval + licensing-review record
  for the libraries above.

## References

- GitHub epic **#156** and child issues **#157–#171**; Tasker flow "Option C: Feature
  Portability & Commodity Hardening" (project `whity-core-mpxv43br`).
- [ADR 0001 — OU Management Hub](0001-ou-management-hub.md);
  [ADR 0002 — Family Relations Management](0002-family-relations-management.md)
  (a textbook candidate for the first portable plugin once this SDK lands).
- [CONTRIBUTING.md](../../CONTRIBUTING.md); the FrankenPHP worker-safety and
  tenant-isolation rules in the project instruction set.
- Audit areas: auth & crypto (`src/Auth/`), data & SQL (`src/Core/Database/`),
  routing & HTTP (`src/Core/`, `src/Http/`), plugin SDK (`src/Core/PluginLoader.php`,
  `PluginInterface.php`, `plugins/`), frontend (`web/`, `src/design/tokens/`).
