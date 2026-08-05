import { defineConfig, devices } from '@playwright/test';
import {
  adminStatePath,
  delegateStatePath,
  userStatePath,
} from './e2e/support/storage';

/**
 * Playwright configuration for the whity-core web UI E2E suite.
 *
 * Prerequisites (NOT started by Playwright):
 *  - The backend stack (FrankenPHP + PostgreSQL) must be running at
 *    http://localhost:8000 (docker compose project `whity-demo`).
 *
 * The frontend dev server IS started by Playwright via the `webServer`
 * block below (port 3010, so it never collides with a developer's own
 * `next dev` on :3000). `reuseExistingServer` keeps an already-running
 * instance alive on local re-runs.
 *
 * The relative `/api/*` calls made by the app are proxied to the backend by
 * the catch-all route handler at `app/api/[...path]/route.ts` (hard-coded to
 * http://localhost:8000), so no extra env/rewrite config is needed for the
 * proxied calls to reach the backend from this dev instance.
 *
 * Projects:
 *  - `setup`     logs in once per role and saves browser storage state. Also
 *                provisions the delegate account + its delegations (WC-173).
 *  - `authflow`  the from-scratch login/logout/role-switch specs (no stored auth).
 *  - `admin`     admin-authenticated specs (depends on `setup`).
 *  - `user`      regular-user-authenticated specs (depends on `setup`).
 *  - `matrix-*`  the multi-role matrix (WC-173): every `e2e/matrix-*.spec.ts`
 *                file runs THREE times — once per role project (admin / user /
 *                delegate) — branching its expectations on the `role` fixture
 *                (see e2e/support/fixtures.ts for the spec contract).
 */

const PORT = Number(process.env.E2E_PORT ?? 3010);
const BASE_URL = process.env.E2E_BASE_URL ?? `http://localhost:${PORT}`;

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  // One retry everywhere: CI and local runs share the same flakiness policy
  // (a retry must be able to succeed, so specs are self-arranging).
  retries: 1,
  // One worker: the suite mutates a shared dev database, so serialised writes
  // keep runs deterministic and re-runnable.
  workers: 1,
  reporter: process.env.CI ? [['list'], ['html', { open: 'never' }]] : 'list',
  timeout: 45_000,
  expect: { timeout: 12_000 },
  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'off',
  },
  projects: [
    {
      name: 'setup',
      testMatch: /support[\\/]auth\.setup\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'authflow',
      testMatch: /auth(-bugs|-transitions)?\.spec\.ts/,
      use: { ...devices['Desktop Chrome'] },
    },
    {
      name: 'user',
      testMatch: /regular-user\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: userStatePath },
    },
    {
      name: 'admin',
      testMatch: /(navigation|roles|users|ous-tenants|ous-hub|stats|settings-2fa|profile|website-settings|branding|global-settings|sso|email-settings|document-designer|rtl-direction)\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: adminStatePath },
    },
    // WC-221: the plugin upload + RBAC-visibility journeys run admin-authenticated
    // (the upload/enable lifecycle needs admin); the read-only delegate journey
    // logs in within the spec against a plugins:read-only delegation it provisions.
    {
      name: 'plugins-upload',
      testMatch: /plugin-upload\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: adminStatePath },
    },
    // WC-228: the UiKitShowcase example plugin's UI-Kit Reference block screen
    // runs admin-authenticated (the migration grants uikit:view to admin, so
    // the admin sees the feature). It proves the SDK block contract -> host
    // validation -> web renderer -> example-plugin pipeline end-to-end.
    {
      name: 'plugins-uikit',
      testMatch: /ui-kit-showcase\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: adminStatePath },
    },
    // The multi-role matrix (WC-173): one spec file, three role projects.
    // All three share the same testMatch, so every e2e/matrix-*.spec.ts runs
    // once per role; specs read the role via the `role` fixture (which parses
    // the `matrix-` project-name suffix) and branch their expectations on it.
    {
      name: 'matrix-admin',
      testMatch: /matrix-.*\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: adminStatePath },
    },
    {
      name: 'matrix-user',
      testMatch: /matrix-.*\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: userStatePath },
    },
    {
      name: 'matrix-delegate',
      testMatch: /matrix-.*\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: delegateStatePath },
    },
  ],
  webServer: {
    command: `npm run dev -- -p ${PORT}`,
    url: `${BASE_URL}/login`,
    reuseExistingServer: true,
    timeout: 120_000,
    stdout: 'pipe',
    stderr: 'pipe',
  },
});
