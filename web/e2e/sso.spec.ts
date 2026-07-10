import { test, expect, type APIRequestContext } from '@playwright/test';
import { SYSTEM_ADMIN, REGULAR_USER } from './support/constants';
import { createAuthedApi } from './support/api';
import { systemStatePath } from './support/storage';
import { LoginPage } from './support/pages';

/**
 * E2E for the SSO / identity-providers admin page and the "Sign in with …"
 * login button (WC-7b3d9f2c), driven against the full live stack
 * (browser → Next proxy → backend).
 *
 * Providers are created/removed through the real API for deterministic,
 * re-runnable setup. We use the `oidc` and `microsoft` provider keys and never
 * touch `google`, so a provider the backend team is exercising in parallel is
 * left alone. All writes go through the SYSTEM-TENANT admin (tenant 0), which
 * holds `auth_providers:manage`.
 */

interface ProviderRow {
  id: number;
  provider_key: string;
}

async function listProviders(api: APIRequestContext): Promise<ProviderRow[]> {
  const res = await api.get('/api/v1/identity-providers');
  if (!res.ok()) return [];
  const body = (await res.json()) as { data?: ProviderRow[] };
  return body.data ?? [];
}

/** Best-effort removal of every provider with the given key (keeps runs clean). */
async function deleteProvidersByKey(api: APIRequestContext, key: string): Promise<void> {
  for (const p of await listProviders(api)) {
    if (p.provider_key === key) {
      await api.delete(`/api/v1/identity-providers/${p.id}`).catch(() => undefined);
    }
  }
}

test.describe('SSO providers — admin CRUD (system-tenant operator)', () => {
  test.use({ storageState: systemStatePath });

  const DISPLAY = 'E2E OIDC';

  test.beforeAll(async ({ baseURL }) => {
    if (!baseURL) return;
    const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
    await deleteProvidersByKey(api, 'oidc');
    await api.dispose();
  });

  test.afterAll(async ({ baseURL }) => {
    if (!baseURL) return;
    const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
    await deleteProvidersByKey(api, 'oidc');
    await api.dispose();
  });

  test('adds a provider (redirect URI + write-only secret), toggles it, deletes it', async ({
    page,
  }) => {
    await page.goto('/admin/settings/sso');
    await expect(page.getByRole('heading', { name: /single sign-on/i })).toBeVisible();
    // Global vs per-tenant split: the system tenant manages the operator scope.
    await expect(page.getByText('Operator (global)', { exact: true })).toBeVisible();

    // --- Add ---
    await page.getByTestId('sso-add-provider').click();
    await expect(page.getByTestId('sso-form')).toBeVisible();
    await page.locator('#sso-provider-key').selectOption('oidc');

    // The redirect URI to register with the provider is shown and follows the
    // documented shape <origin>/api/v1/auth/sso/<key>/callback.
    await expect(
      page.getByTestId('sso-form').getByText('/api/v1/auth/sso/oidc/callback')
    ).toBeVisible();

    await page.locator('#sso-display-name').fill(DISPLAY);
    await page.locator('#sso-client-id').fill('e2e-oidc-client');
    await page.locator('#sso-client-secret').fill('e2e-oidc-secret');
    await page.locator('#sso-issuer').fill('https://id.example.com');

    const created = page.waitForResponse(
      (r) => r.url().includes('/api/v1/identity-providers') && r.request().method() === 'POST'
    );
    await page.getByTestId('sso-save-provider').click();
    expect((await created).status(), 'POST should create the provider').toBe(201);
    await expect(page.getByText('Identity provider added.')).toBeVisible();

    // The new row shows the display name, an Enabled badge and a "Secret set" badge
    // (the secret is never echoed back — only its presence).
    const row = page.locator('[data-testid^="sso-provider-"]').filter({ hasText: DISPLAY });
    await expect(row).toHaveCount(1);
    await expect(row.getByText('Enabled', { exact: true })).toBeVisible();
    await expect(row.getByText('Secret set', { exact: true })).toBeVisible();

    // --- Edit: the secret field is write-only (placeholder marks it unchanged),
    // and toggling Enabled off persists. ---
    await row.getByRole('button', { name: 'Edit' }).click();
    await expect(page.getByTestId('sso-form')).toBeVisible();
    await expect(page.locator('#sso-client-secret')).toHaveAttribute('placeholder', /unchanged/i);

    const toggle = page.getByTestId('sso-enabled-switch');
    await expect(toggle).toHaveAttribute('aria-checked', 'true');
    await toggle.click();
    await expect(toggle).toHaveAttribute('aria-checked', 'false');

    const updated = page.waitForResponse(
      (r) => r.url().includes('/api/v1/identity-providers/') && r.request().method() === 'PATCH'
    );
    await page.getByTestId('sso-save-provider').click();
    expect((await updated).status(), 'PATCH should update the provider').toBe(200);
    await expect(page.getByText('Identity provider updated.')).toBeVisible();

    const rowAfter = page.locator('[data-testid^="sso-provider-"]').filter({ hasText: DISPLAY });
    await expect(rowAfter.getByText('Disabled', { exact: true })).toBeVisible();

    // --- Delete ---
    await rowAfter.getByRole('button', { name: 'Delete' }).click();
    await rowAfter.getByRole('button', { name: 'Yes, delete' }).click();
    await expect(page.getByText('Identity provider deleted.')).toBeVisible();
    await expect(
      page.locator('[data-testid^="sso-provider-"]').filter({ hasText: DISPLAY })
    ).toHaveCount(0);
  });
});

test.describe('SSO login button + return markers (unauthenticated)', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  const DISPLAY = 'E2E Microsoft';

  test.beforeAll(async ({ baseURL }) => {
    if (!baseURL) throw new Error('baseURL required');
    const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
    await deleteProvidersByKey(api, 'microsoft');
    const res = await api.post('/api/v1/identity-providers', {
      data: {
        provider_key: 'microsoft',
        display_name: DISPLAY,
        client_id: 'e2e-ms-client',
        client_secret: 'e2e-ms-secret',
        issuer: 'https://login.microsoftonline.com/common/v2.0',
        enabled: true,
      },
    });
    expect(res.status(), 'seed microsoft provider should return 201').toBe(201);
    await api.dispose();
  });

  test.afterAll(async ({ baseURL }) => {
    if (!baseURL) return;
    const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
    await deleteProvidersByKey(api, 'microsoft');
    await api.dispose();
  });

  test('renders a "Sign in with …" button linking to the provider start route', async ({ page }) => {
    await page.goto('/login');
    const button = page.getByTestId('sso-start-microsoft');
    await expect(button).toBeVisible();
    await expect(button).toHaveText(new RegExp(`Sign in with ${DISPLAY}`));
    await expect(button).toHaveAttribute('href', '/api/v1/auth/sso/microsoft/start');
  });

  test('surfaces an sso_error marker as a friendly toast and strips it from the URL', async ({
    page,
  }) => {
    await page.goto('/login?sso_error=denied');
    await expect(page.getByText('Sign-in was cancelled.')).toBeVisible();
    // The marker is removed so a refresh/back does not re-toast.
    await expect(page).toHaveURL(/\/login$/);
  });

  test('the Next proxy RELAYS SSO redirects to the browser (does not follow them)', async ({
    page,
  }) => {
    // Regression guard: the hosted-login flow needs the proxy to pass 3xx through.
    // A callback with no flow-state cookie bounces to /login?sso_error=expired —
    // a same-origin redirect (no provider/network needed). If the proxy followed
    // it (undici default), the browser would get a 200 with the cookie swallowed
    // and the flow would hang; here it must see the raw 302 + Location.
    const res = await page.request.get('/api/v1/auth/sso/google/callback', {
      maxRedirects: 0,
    });
    expect(res.status(), 'proxy must relay the backend 302, not follow it').toBe(302);
    expect(res.headers()['location']).toContain('/login?sso_error=');
  });
});

test.describe('SSO providers — permission gating', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('a user without auth_providers:manage is denied the admin page', async ({ page }) => {
    await new LoginPage(page).loginExpectingSuccess(REGULAR_USER);
    await page.goto('/admin/settings/sso');
    await expect(page.getByRole('heading', { name: 'Access Denied' })).toBeVisible();
    await expect(page.getByTestId('sso-add-provider')).toHaveCount(0);
  });
});
