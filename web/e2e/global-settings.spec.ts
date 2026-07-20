import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import { SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi } from './support/api';
import { systemStatePath } from './support/storage';

/**
 * E2E for the Sign-up governance page + first-run onboarding wizard
 * (WC-2b9d4f6a / WC-tabs-nav follow-up), driven against the full live stack
 * (browser → Next proxy → backend). These prove the REAL path, not a mocked
 * client.
 *
 * The standalone "Global Settings" page was split up: its "General"/
 * "Integrations" registry sections folded into a "Platform defaults" card on
 * the General tab (/admin/settings), and "Sign-up governance" became its own
 * top-level page (/admin/settings/signup) — the only piece this spec still
 * covers directly, since it's the one carrying the toggled-and-persisted
 * governance flag.
 *
 * Coverage:
 *  1. System-tenant operator loads /admin/settings/signup, TOGGLES a
 *     governance flag, saves, and the new value is persisted (verified via
 *     the API, then across a reload).
 *  2. A regular-tenant admin is DENIED the Sign-up page (403 → Access
 *     Denied), even though it holds settings:manage in its own tenant.
 *  3. Regression for the system-tenant per-tenant gate (WC-224): the operator
 *     is shown the "no per-tenant overrides" notice on /admin/settings, never
 *     an editable tenant form that would 422.
 *  4. The onboarding wizard renders, steps through, and finishing with no
 *     changes lands the operator on General settings (no mutation, re-runnable).
 *
 * The governance key exercised is a literal-boolean setting; we read its current
 * value from the API first and restore it in a finally block so the shared dev
 * database stays clean and the suite is re-runnable.
 */

const GOVERNANCE_KEY = 'auth.self_registration_enabled';

async function readGlobal(api: APIRequestContext, key: string): Promise<string> {
  const res = await api.get('/api/v1/settings/global');
  expect(res.status(), 'GET /api/v1/settings/global should return 200').toBe(200);
  const body = (await res.json()) as { data: { global: Record<string, string> } };
  return body.data.global[key] ?? 'false';
}

async function writeGlobal(api: APIRequestContext, key: string, value: string): Promise<void> {
  await api
    .patch('/api/v1/settings/global', { data: { settings: { [key]: value } } })
    .catch(() => undefined);
}

/**
 * Resolve a governance flag's control, tolerating whichever the registry drives
 * it as: a toggle when the backend reports `type:"bool"`, or a text input
 * (holding 'true'/'false') while it still reports `type:"string"`.
 *
 * Crucially this AWAITS the control before deciding which kind it is: after a
 * reload (or during the initial fetch) the page shows a loading skeleton with
 * neither control, so a non-waiting `.count()` check would race — see it as
 * "no switch", fall through to the input branch, and then never find an input in
 * the bool case. `.or(...).first()` blocks until whichever control actually
 * rendered is visible, so the branch below is always taken against real DOM.
 */
async function resolveFlag(page: Page, key: string) {
  const toggle = page.getByTestId(`setting-switch-${key}`);
  const input = page.getByTestId(`setting-row-${key}`).locator('input');
  await expect(toggle.or(input).first()).toBeVisible();
  return { toggle, input, isToggle: (await toggle.count()) > 0 };
}

/** Set a literal-boolean governance flag through whichever control renders it. */
async function setBooleanFlag(page: Page, key: string, value: 'true' | 'false'): Promise<void> {
  const { toggle, input, isToggle } = await resolveFlag(page, key);
  if (isToggle) {
    if ((await toggle.getAttribute('aria-checked')) !== value) {
      await toggle.click();
    }
    await expect(toggle).toHaveAttribute('aria-checked', value);
    return;
  }
  await input.fill(value);
  await expect(input).toHaveValue(value);
}

/** Assert a governance flag reads back as `value`, whichever control renders it. */
async function expectBooleanFlag(page: Page, key: string, value: 'true' | 'false'): Promise<void> {
  const { toggle, input, isToggle } = await resolveFlag(page, key);
  if (isToggle) {
    await expect(toggle).toHaveAttribute('aria-checked', value);
    return;
  }
  await expect(input).toHaveValue(value);
}

test.describe('Sign-up governance — system-tenant operator (settings:manage, tenant 0)', () => {
  // Sign-up governance is system-tenant-only (WC-235): drive it as the seeded
  // system-tenant admin, overriding the [admin] project's tenant-admin session.
  test.use({ storageState: systemStatePath });

  test('renders the registry-driven section and toggles a governance flag that persists', async ({
    page,
    baseURL,
  }) => {
    if (!baseURL) test.skip();
    const api = await createAuthedApi(baseURL!, SYSTEM_ADMIN);
    const original = await readGlobal(api, GOVERNANCE_KEY);
    const target = original === 'true' ? 'false' : 'true';

    try {
      await page.goto('/admin/settings/signup');
      await expect(page.getByRole('heading', { name: 'Sign-up', exact: true })).toBeVisible();
      await expect(page.getByTestId('settings-section-signup')).toBeVisible();

      await expectBooleanFlag(page, GOVERNANCE_KEY, original as 'true' | 'false');

      // Save is disabled until something changes.
      const save = page.getByTestId('signup-settings-save');
      await expect(save).toBeDisabled();

      await setBooleanFlag(page, GOVERNANCE_KEY, target as 'true' | 'false');
      await expect(save).toBeEnabled();

      const patch = page.waitForResponse(
        (res) =>
          res.url().includes('/api/v1/settings/global') && res.request().method() === 'PATCH'
      );
      await save.click();
      expect((await patch).status(), 'PATCH /api/v1/settings/global should succeed').toBe(200);
      await expect(page.getByText('Sign-up settings saved.')).toBeVisible();

      // Persisted at the source of truth…
      expect(await readGlobal(api, GOVERNANCE_KEY)).toBe(target);

      // …and reflected after a fresh load.
      await page.reload();
      await expectBooleanFlag(page, GOVERNANCE_KEY, target as 'true' | 'false');
    } finally {
      await writeGlobal(api, GOVERNANCE_KEY, original);
      await api.dispose();
    }
  });

  test('system tenant sees the per-tenant "no overrides" notice, not an editable form (WC-224)', async ({
    page,
  }) => {
    await page.goto('/admin/settings');
    await expect(page.getByRole('heading', { name: 'General' })).toBeVisible();
    // The system tenant has globals only: the editable tenant form is hidden and
    // a notice points at the Platform defaults card (rendered on this same page)
    // instead of a form that 422s.
    await expect(page.getByTestId('tenant-no-override-notice')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save tenant settings' })).toHaveCount(0);
  });

  test('onboarding wizard steps through and finishing with defaults lands on General settings', async ({
    page,
  }) => {
    await page.goto('/onboarding');
    await expect(page.getByRole('heading', { name: 'Set up your instance' })).toBeVisible();
    // First step is the intro; its steps beyond depend on which keys the backend
    // publishes (a step for absent keys is skipped), so advance until Finish shows.
    await expect(page.getByRole('heading', { name: 'Welcome' })).toBeVisible();

    const next = page.getByTestId('onboarding-next');
    const finish = page.getByTestId('onboarding-finish');
    // Bounded so a bug can never spin forever; the wizard has at most ~6 steps.
    for (let i = 0; i < 8 && (await next.count()) > 0; i++) {
      await next.click();
    }
    await expect(finish).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Review & finish' })).toBeVisible();

    // No changes made → finishing performs no write and routes to General settings.
    await finish.click();
    await expect(page).toHaveURL(/\/admin\/settings$/);
    await expect(page.getByRole('heading', { name: 'General' })).toBeVisible();
  });
});

test.describe('Sign-up governance — regular-tenant admin is denied (WC-235)', () => {
  // Inherits the [admin] project's tenant-admin session (admin@example.com,
  // tenant 1). It holds settings:manage in its OWN tenant but must never reach
  // the platform-wide defaults.

  test('is shown Access Denied on the Sign-up page and is not seeded a form', async ({ page }) => {
    await page.goto('/admin/settings/signup');
    await expect(page.getByRole('heading', { name: 'Access Denied' })).toBeVisible();
    await expect(page.getByTestId('signup-settings-save')).toHaveCount(0);
    await expect(page.getByTestId('settings-section-signup')).toHaveCount(0);
  });

  test('onboarding wizard refuses a non-operator', async ({ page }) => {
    await page.goto('/onboarding');
    await expect(page.getByRole('heading', { name: 'Setup is operator-only' })).toBeVisible();
    await expect(page.getByTestId('onboarding-next')).toHaveCount(0);
  });
});
