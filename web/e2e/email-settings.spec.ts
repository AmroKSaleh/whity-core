import { test, expect, type APIRequestContext } from '@playwright/test';
import { SYSTEM_ADMIN } from './support/constants';
import { createAuthedApi } from './support/api';
import { systemStatePath } from './support/storage';

/**
 * E2E for the Email (SMTP) settings admin page (WC-3ac81b7e), against the live
 * stack (browser → Next proxy → backend).
 *
 * The non-secret fields are GLOBAL settings and the secret/test actions use
 * dedicated endpoints. The backend for those is built in a parallel session; the
 * UI-behaviour and gating tests run unconditionally (they exercise client state
 * and access control, no mail backend needed), while the round-trip tests
 * (persist fields, set password, send test) are GATED on the mail backend being
 * live — detected via GET /api/v1/settings/mail/status — and skip cleanly until
 * it lands, at which point they activate with no further change.
 */

async function mailBackendLive(baseURL: string): Promise<boolean> {
  const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
  const res = await api.get('/api/v1/settings/mail/status');
  const ok = res.ok();
  await api.dispose();
  return ok;
}

test.describe('Email settings — UI behaviour + gating (system-tenant operator)', () => {
  test.use({ storageState: systemStatePath });

  test('renders and reveals SMTP fields conditionally by transport', async ({ page }) => {
    await page.goto('/admin/settings/email');
    await expect(page.getByRole('heading', { name: 'Email', exact: true })).toBeVisible();

    const transport = page.locator('#email-mail-transport');
    await expect(transport).toBeVisible();
    const smtpCard = page.getByTestId('email-smtp-card');
    const sendTest = page.getByTestId('email-send-test');

    // none → disabled note, no SMTP card, test disabled.
    await transport.selectOption('none');
    await expect(page.getByTestId('email-transport-note')).toContainText(/disabled/i);
    await expect(smtpCard).toHaveCount(0);
    await expect(sendTest).toBeDisabled();

    // log → server-log note, no SMTP card, test enabled.
    await transport.selectOption('log');
    await expect(page.getByTestId('email-transport-note')).toContainText(/server log/i);
    await expect(smtpCard).toHaveCount(0);
    await expect(sendTest).toBeEnabled();

    // smtp → SMTP card + host field + write-only password status; test enabled.
    await transport.selectOption('smtp');
    await expect(smtpCard).toBeVisible();
    await expect(page.locator('#email-mail-smtp-host')).toBeVisible();
    await expect(page.getByTestId('email-password-status')).toBeVisible();
    await expect(page.locator('#email-smtp-password')).toHaveValue('');
    await expect(sendTest).toBeEnabled();
  });
});

test.describe('Email settings — save / password / send-test round-trip', () => {
  test.use({ storageState: systemStatePath });

  let live = false;
  test.beforeAll(async ({ baseURL }) => {
    if (baseURL) live = await mailBackendLive(baseURL);
  });

  test.afterAll(async ({ baseURL }) => {
    if (!baseURL || !live) return;
    // Reset to a clean baseline (transport off, password cleared).
    const api = await createAuthedApi(baseURL, SYSTEM_ADMIN);
    await api
      .patch('/api/v1/settings/global', { data: { settings: { 'mail.transport': 'none' } } })
      .catch(() => undefined);
    await api
      .put('/api/v1/settings/mail/smtp-password', { data: { password: '' } })
      .catch(() => undefined);
    await api.dispose();
  });

  test('configures SMTP, sets a password, saves, and sends a test email', async ({ page, baseURL }) => {
    test.skip(!live, 'mail backend (…/settings/mail/*) not deployed yet');
    if (!baseURL) test.skip();

    await page.goto('/admin/settings/email');
    await page.locator('#email-mail-transport').selectOption('smtp');
    // The BACKEND opens the SMTP connection from inside the compose network, so
    // the host is the Mailpit service name, not localhost.
    await page.locator('#email-mail-smtp-host').fill('mailpit');
    await page.locator('#email-mail-smtp-port').fill('1025'); // Mailpit
    await page.locator('#email-mail-smtp-encryption').selectOption('none');
    await page.locator('#email-mail-from_address').fill('noreply@example.test');
    await page.locator('#email-mail-from_name').fill('E2E Mailer');

    const saved = page.waitForResponse(
      (r) => r.url().includes('/api/v1/settings/global') && r.request().method() === 'PATCH'
    );
    await page.getByTestId('email-save').click();
    expect((await saved).status(), 'PATCH global should persist mail fields').toBe(200);
    await expect(page.getByText('Email settings saved.')).toBeVisible();

    // Write-only password.
    await page.locator('#email-smtp-password').fill('e2e-smtp-secret');
    const pwSaved = page.waitForResponse(
      (r) => r.url().includes('/api/v1/settings/mail/smtp-password') && r.request().method() === 'PUT'
    );
    await page.getByTestId('email-save-password').click();
    expect((await pwSaved).status()).toBe(204);
    await expect(page.getByText('SMTP password saved.')).toBeVisible();
    await expect(page.getByTestId('email-password-status')).toContainText(/is set/i);

    // Reload: values persist; the password never round-trips (input stays blank).
    await page.reload();
    await expect(page.locator('#email-mail-transport')).toHaveValue('smtp');
    await expect(page.locator('#email-mail-smtp-host')).toHaveValue('localhost');
    await expect(page.locator('#email-smtp-password')).toHaveValue('');
    await expect(page.getByTestId('email-password-status')).toContainText(/is set/i);

    // Send a test — in dev it lands in Mailpit (http://localhost:8025).
    await page.getByLabel('Test recipient').fill('inbox@example.test');
    const sent = page.waitForResponse(
      (r) => r.url().includes('/api/v1/settings/mail/test') && r.request().method() === 'POST'
    );
    await page.getByTestId('email-send-test').click();
    expect((await sent).status(), 'test send should succeed').toBe(200);
    await expect(page.getByText(/Test email sent/i)).toBeVisible();
  });
});

test.describe('Email settings — non-system-tenant admin is denied', () => {
  // Inherits the [admin] project's tenant-admin session (tenant 1). Email is a
  // system-tenant-only surface, so it must be Access Denied here.
  test('a regular tenant admin cannot reach the email settings', async ({ page }) => {
    await page.goto('/admin/settings/email');
    await expect(page.getByRole('heading', { name: 'Access Denied' })).toBeVisible();
    await expect(page.getByTestId('email-transport-card')).toHaveCount(0);
  });
});
