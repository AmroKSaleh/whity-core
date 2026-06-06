import { defineConfig, devices } from '@playwright/test';
import { adminStatePath, userStatePath } from './e2e/support/storage';

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
 *  - `setup`     logs in once per role and saves browser storage state.
 *  - `authflow`  the from-scratch login/logout specs (no stored auth).
 *  - `admin`     admin-authenticated specs (depends on `setup`).
 *  - `user`      regular-user-authenticated specs (depends on `setup`).
 */

const PORT = Number(process.env.E2E_PORT ?? 3010);
const BASE_URL = process.env.E2E_BASE_URL ?? `http://localhost:${PORT}`;

export default defineConfig({
  testDir: './e2e',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 1,
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
      testMatch: /(auth(-bugs)?|demo)\.spec\.ts/,
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
      testMatch: /(navigation|roles|users|ous-tenants|ous-hub|stats|settings-2fa|profile)\.spec\.ts/,
      dependencies: ['setup'],
      use: { ...devices['Desktop Chrome'], storageState: adminStatePath },
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
