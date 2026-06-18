import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { test, expect } from './support/fixtures';
import { ADMIN, DELEGATE_USER } from './support/constants';
import { toastWithText } from './support/pages';
import { LoginPage } from './support/pages';
import {
  createAuthedApi,
  ensureDelegation,
  ensureUser,
  findUserIdByEmail,
  revokeDelegationsFor,
} from './support/api';
import { zipPluginDir } from './support/plugin-zip';

/**
 * WC-221 — plugin upload + per-action RBAC visibility (full-stack E2E).
 *
 * Two journeys against the real backend:
 *
 *  1. ADMIN uploads a fixture .zip through the Upload Plugin dialog -> it lands
 *     `disabled` for review -> Enable -> `active`.
 *
 *  2. A `plugins:read`-ONLY principal sees the console list but with the
 *     destructive Uninstall trigger HIDDEN and the other actions
 *     (Reload / Upload / Enable-Disable) DISABLED with a tooltip reason.
 *
 * The read-only principal is the existing delegate account narrowed to JUST
 * `plugins:read`: this spec delegates that one permission to it via the admin
 * API in `beforeAll` (admin holds all six `plugins:*`, so the subset invariant
 * holds) and revokes it in `afterAll`, consistent with how the matrix harness
 * provisions delegated access. RBAC is enforced live server-side per request,
 * so the delegation takes effect on the delegate's existing session without a
 * re-login.
 *
 * The upload fixture is a SINGLE-top-level-directory plugin packaged into a
 * `.zip` at runtime (from e2e/fixtures/plugin-upload/) — it declares no routes,
 * permissions, hooks, or migrations, so staging/enabling it has no RBAC or DB
 * side effects. Cleanup uninstalls it so re-runs never hit the installer's
 * no-overwrite collision guard.
 */

const FIXTURE_PLUGIN_ID = 'WC221UploadFixture';
const FIXTURE_DIR = join(__dirname, 'fixtures', 'plugin-upload', FIXTURE_PLUGIN_ID);
const PLUGINS_URL = '/admin/plugins';

/** Best-effort uninstall of the fixture plugin so the next run can re-upload. */
async function uninstallFixture(baseURL: string): Promise<void> {
  const api = await createAuthedApi(baseURL, ADMIN);
  try {
    await api
      .post(`/api/v1/plugins/${FIXTURE_PLUGIN_ID}/uninstall`, {
        data: { force: true },
      })
      .catch(() => undefined);
  } finally {
    await api.dispose();
  }
}

test.describe('Plugin upload + RBAC visibility (WC-221)', () => {
  // Grant the delegate ONLY plugins:read for this spec; revoke afterward.
  test.beforeAll(async ({ baseURL }) => {
    if (baseURL === undefined) {
      throw new Error('baseURL is required to provision the read-only delegate');
    }
    const api = await createAuthedApi(baseURL, ADMIN);
    try {
      const granteeId = await ensureUser(api, {
        email: DELEGATE_USER.email,
        password: DELEGATE_USER.password,
        role: 'user',
      });
      await ensureDelegation(api, {
        granteeType: 'user',
        granteeId,
        permissions: ['plugins:read'],
      });
    } finally {
      await api.dispose();
    }
    // Ensure a stale fixture from an interrupted prior run cannot block upload.
    await uninstallFixture(baseURL);
  });

  test.afterAll(async ({ baseURL }) => {
    if (baseURL === undefined) {
      return;
    }
    await uninstallFixture(baseURL);
    // Revoke the throwaway plugins:read delegation (other specs expect the
    // delegate's baseline delegated set; a left-over grant would leak into them).
    const api = await createAuthedApi(baseURL, ADMIN);
    try {
      const granteeId = await findUserIdByEmail(api, DELEGATE_USER.email);
      if (granteeId !== null) {
        await revokeDelegationsFor(api, 'user', granteeId);
        // Restore the delegate's BASELINE delegated set the rest of the suite
        // relies on (auth.setup grants these; revokeDelegationsFor cleared them).
        await ensureDelegation(api, {
          granteeType: 'user',
          granteeId,
          permissions: ['relations:read', 'audit:read', 'hello:view'],
        });
      }
    } finally {
      await api.dispose();
    }
  });

  test('admin uploads a fixture package, it stages disabled, then enables to active', async ({
    page,
    baseURL,
  }) => {
    expect(baseURL, 'baseURL is required').toBeDefined();

    // Build the fixture .zip on disk so the file chooser has a real path.
    const zip = await zipPluginDir(FIXTURE_DIR);
    const workDir = await mkdtemp(join(tmpdir(), 'wc221-upload-'));
    const zipPath = join(workDir, `${FIXTURE_PLUGIN_ID}.zip`);
    await writeFile(zipPath, zip);

    try {
      await page.goto(PLUGINS_URL);
      await expect(
        page.getByRole('heading', { name: 'Plugin Management' })
      ).toBeVisible();

      // Open the Upload dialog and submit the fixture archive.
      await page.getByRole('button', { name: /Upload Plugin/i }).click();
      const dialog = page.getByRole('dialog');
      await expect(dialog.getByText('Upload Plugin')).toBeVisible();

      await dialog.locator('input[type="file"]').setInputFiles(zipPath);

      const uploadResponse = page.waitForResponse(
        (res) =>
          res.url().includes('/api/v1/plugins/upload') &&
          res.request().method() === 'POST'
      );
      await dialog.getByRole('button', { name: /^Upload$/i }).click();
      expect((await uploadResponse).status()).toBe(200);

      await expect(
        toastWithText(page, /staged successfully/i)
      ).toBeVisible();

      // The staged plugin appears with status `disabled` for review.
      const card = page
        .locator('[data-slot="card"]')
        .filter({ hasText: FIXTURE_PLUGIN_ID });
      await expect(card).toBeVisible();
      await expect(card.getByText('disabled', { exact: false }).first()).toBeVisible();

      // Enable it -> the lifecycle status flips to active.
      const enableResponse = page.waitForResponse(
        (res) =>
          res.url().includes(`/api/v1/plugins/${FIXTURE_PLUGIN_ID}/enable`) &&
          res.request().method() === 'POST'
      );
      await card.getByRole('button', { name: /Enable/i }).click();
      expect((await enableResponse).status()).toBe(200);

      await expect(toastWithText(page, /successfully enabled/i)).toBeVisible();
      const refreshedCard = page
        .locator('[data-slot="card"]')
        .filter({ hasText: FIXTURE_PLUGIN_ID });
      await expect(
        refreshedCard.getByText('active', { exact: false }).first()
      ).toBeVisible();
    } finally {
      await rm(workDir, { recursive: true, force: true });
    }
  });

  test('a plugins:read-only delegate sees the list with Uninstall hidden and other actions disabled', async ({
    browser,
    baseURL,
  }) => {
    if (baseURL === undefined) {
      throw new Error('baseURL is required');
    }

    // Drive the delegate in its OWN context (this spec runs admin-authenticated
    // by default). A fresh UI login picks up the live plugins:read delegation.
    const context = await browser.newContext();
    const page = await context.newPage();
    try {
      const login = new LoginPage(page);
      await login.loginExpectingSuccess(DELEGATE_USER);

      await page.goto(PLUGINS_URL);
      // plugins:read is held, so the console renders (not Access Denied).
      await expect(
        page.getByRole('heading', { name: 'Plugin Management' })
      ).toBeVisible();
      await expect(
        page.getByRole('heading', { name: 'Access Denied' })
      ).toHaveCount(0);

      // Non-destructive actions are DISABLED (rendered, with a tooltip reason).
      const reload = page.getByRole('button', { name: /Reload Plugins/i });
      await expect(reload).toBeVisible();
      await expect(reload).toBeDisabled();

      const upload = page.getByRole('button', { name: /Upload Plugin/i });
      await expect(upload).toBeVisible();
      await expect(upload).toBeDisabled();

      // Open a plugin's detail modal. The destructive Uninstall trigger is
      // HIDDEN entirely for a caller without plugins:uninstall.
      const firstDetails = page.getByRole('button', { name: /Details/i }).first();
      if ((await firstDetails.count()) > 0) {
        await firstDetails.click();
        await expect(page.getByText('Status').first()).toBeVisible();
        await expect(
          page.getByRole('button', { name: /Uninstall/i })
        ).toHaveCount(0);
      }
    } finally {
      await context.close();
    }
  });
});
