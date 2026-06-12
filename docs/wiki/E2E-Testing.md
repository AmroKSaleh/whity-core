# E2E Testing

The end-to-end suite lives in `web/e2e/` and drives the real admin UI with
[Playwright](https://playwright.dev) against the real backend stack — no API
mocks anywhere. Playwright starts its own Next.js dev server on port **3010**
(`webServer` block in `web/playwright.config.ts`); the app's `/api/*` proxy
forwards to the backend at `http://localhost:8000`, exactly as in development.

---

## Prerequisites

1. **The dev stack is running** at `http://localhost:8000`:

   ```bash
   docker compose up -d --wait
   ```

   With the default `APP_ENV=development`, the one-shot `db-init` service runs
   migrations + seed automatically, so a fresh database comes up with the
   deterministic accounts below. Re-running `up` on a populated database is a
   no-op (seed-if-empty).

2. **Seeded accounts** (compose defaults, override via `INITIAL_*_PASSWORD`):

   | Account | Password | Role |
   |---|---|---|
   | `admin@example.com` | `admin123` | `admin` |
   | `user@example.com` | `user123` | `user` (no admin permissions) |

3. **2FA disabled baseline**: `admin@example.com` must log in WITHOUT a 2FA
   challenge. The 2FA spec enrols and restores admin itself, and the auth setup
   self-heals residue from interrupted runs by clearing 2FA directly in the
   database (`e2e/support/totp.ts`), so this normally needs no manual action.

4. **Node dependencies**: `cd web && npm ci`. No extra packages are needed —
   even TOTP codes are computed by reusing the OTPHP library inside the
   backend container (`docker exec whity_frankenphp …`), so the suite expects
   the compose container names (`whity_frankenphp`, `whity_postgres`).

---

## Projects and roles

Authentication is handled at the **project** level: the `setup` project logs in
once per role through the real UI and saves the browser storage state under
`web/e2e/.auth/` (gitignored); the authenticated projects load that state, so
specs start already logged in without one slow UI login per test.

| Project | Auth state | Picks up |
|---|---|---|
| `setup` | — (performs the logins) | `e2e/support/auth.setup.ts` |
| `authflow` | none (from scratch) | `auth.spec.ts`, `auth-bugs.spec.ts`, `auth-transitions.spec.ts`, `demo.spec.ts` |
| `user` | `user@example.com` | `regular-user.spec.ts` |
| `admin` | `admin@example.com` | `navigation`, `roles`, `users`, `ous-tenants`, `ous-hub`, `stats`, `settings-2fa`, `profile` specs |
| `matrix-admin` | `admin@example.com` | every `matrix-*.spec.ts` |
| `matrix-user` | `user@example.com` | every `matrix-*.spec.ts` |
| `matrix-delegate` | `delegate@example.com` | every `matrix-*.spec.ts` |

### The third role: a delegation-granted user

`delegate@example.com` (password `delegate123`, overridable via
`E2E_DELEGATE_EMAIL` / `E2E_DELEGATE_PASSWORD`) is **not seeded**. The auth
setup provisions it idempotently through the admin API:

1. `ensureUser` — find the account by email or create it with role `user`,
   so its *role* grants nothing beyond the regular user;
2. `ensureDelegation` — ensure a **live** delegation from admin of the
   permissions in `DELEGATED_PERMISSIONS` (`e2e/support/constants.ts`):
   `relations:read`, `audit:read`, `hello:view`.

Everything the delegate can do beyond the plain user therefore comes from a
**delegation**, which is the access path the matrix exercises. All three
permissions are held by the seeded admin grantor on a fresh database (core
migrations grant `relations:read`/`audit:read`; the bundled HelloWorld plugin
migration grants `hello:view`), satisfying the delegation API's
subset-of-own-permissions invariant in every environment, including CI.

Observed contrast (live-verified):

| Endpoint | admin | user | delegate |
|---|---|---|---|
| `GET /api/frontend/features` | all features | `[]` | only `hello-greetings` |
| `GET /api/relations` | 200 | 403 | 200 |
| `GET /api/audit-logs` | 200 | 403 | 200 |
| `GET /api/delegations` | 200 | 403 | 403 |
| `GET /api/users` | 200 | 403 | 403 |

Note `/api/navigation` is **unfiltered** — every role sees every link (the
current intended behaviour; issue #191 tracks filtering). Access is enforced at
the data layer, so specs assert on page *content* (data vs the "Access denied"
card), not on the sidebar.

### The matrix pattern — contract for spec authors

A matrix spec is written **once** and runs **three times**, because the three
`matrix-*` projects share the testMatch `e2e/matrix-*.spec.ts`. The spec learns
its current role from the `role` fixture (`'admin' | 'user' | 'delegate'`,
derived from the project name's `matrix-` suffix) and branches its
**expectations** on it — same journey, role-dependent outcome. `roleSession` is
the role-agnostic counterpart of `adminPage`/`userPage`: it lands the
already-authenticated page on the dashboard and exposes the `AppShell` page
object.

```ts
// web/e2e/matrix-relations.spec.ts — runs under all three matrix-* projects
import { test, expect } from './support/fixtures';

test('relations page access', async ({ roleSession, role, page }) => {
  await roleSession.shell.clickNav('Family Relations');
  if (role === 'user') {
    await expect(page.getByRole('heading', { name: 'Access denied' })).toBeVisible();
  } else {
    // admin holds relations:read via its role, delegate via a delegation
    await expect(page.getByRole('heading', { name: 'Access denied' })).toHaveCount(0);
  }
});
```

`matrix-smoke.spec.ts` is the reference implementation; it pins the pattern
itself (each project authenticated as the right account).

---

## Running locally

```bash
cd web
npm run test:e2e                         # the full suite (all projects)
npm run test:e2e:ui                      # Playwright UI mode
npx playwright test --project=authflow   # one project (setup runs if depended on)
npx playwright test --project=matrix-delegate   # one role of the matrix
npx playwright test e2e/users.spec.ts    # one spec file
npx playwright show-report               # open the last HTML report
```

`reuseExistingServer` keeps an already-running dev server on :3010 alive
between runs; set `E2E_PORT` / `E2E_BASE_URL` to point elsewhere.

---

## Continuous integration

`.github/workflows/e2e.yml` runs the full suite on every push and pull request
to `main`, with a 30-minute job timeout and a per-ref concurrency group
(`e2e-${{ github.ref }}`) so a newer push cancels the in-flight run:

1. provisions PHP 8.4 + Composer on the runner and runs `composer install`
   at the repo root — the compose file bind-mounts the checkout, and the
   `db-init` migrate + seed (and the suite's in-container TOTP helper) need
   the gitignored `vendor/` to exist inside the containers;
2. `docker compose -f docker-compose.yml up -d --wait` — builds
   `whity-core:dev` and bootstraps migrate + seed via `db-init`;
3. polls `GET /api/health` until the API answers (120 s budget);
4. `npm ci` + `npx playwright install chromium --with-deps` in `web/`;
5. `npx playwright test` (the config starts the Next dev server itself);
6. on failure, uploads `playwright-report/` and `test-results/` (traces,
   screenshots) as the `playwright-artifacts` artifact.

CI runs from a fresh database every time, so it has no dev residue (no
Announcements plugin data, no leftover test entities). Specs must pass in both
worlds — assert only on what the seed plus the suite's own setup guarantee.

---

## Flakiness policy

- **`workers: 1`** — the suite mutates one shared database; serialised writes
  keep runs deterministic and re-runnable.
- **`retries: 1`** (locally and in CI) with `trace: 'on-first-retry'` and
  screenshots on failure — a retry must be able to succeed, so every spec has
  to be **self-arranging** (set up its own preconditions; never depend on a
  previous test's side effects).
- Web-first assertions (`expect(locator)…`) over manual waits; transient
  toasts are asserted immediately after the triggering action.

## Data hygiene rules

- **Never mutate the seeded accounts** (`admin@example.com`,
  `user@example.com`) or the seeded tenants/roles. Specs that need an entity
  create a **throwaway** one named with `uniqueSuffix()` and delete it
  best-effort afterwards.
- The delegate account is *provisioned, not seeded* — treat it like the seeded
  accounts: matrix specs may use its session, but must not change its
  password, role, or delegations.
- The 2FA spec is the only one allowed to enrol an account (admin), and it
  restores the baseline in teardown; the auth setup additionally hard-resets
  admin 2FA via the database before logging in, so interrupted runs can never
  wedge the suite.

## See also

- [Development Workflow](Development-Workflow.md) — where the E2E gate sits in
  the verify step
- [Installation](Installation.md) — bringing the dev stack up
- [Plugin Development](Plugin-Development.md) — the HelloWorld feature the
  plugin-screen specs assert on
