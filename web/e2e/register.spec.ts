import { test, expect } from './support/fixtures';

/**
 * Self-service registration (WC-235). The `page` fixture is unauthenticated
 * (like auth.spec.ts), so these exercise the logged-out signup flow end to end
 * through the real Next.js proxy → backend path.
 */
test.describe('Self-service registration (WC-235)', () => {
  test('the login page links to registration and back', async ({ page }) => {
    await page.goto('/login');
    await page.getByRole('link', { name: 'Create a workspace' }).click();
    await expect(page).toHaveURL(/\/register$/);
    await expect(page.getByRole('button', { name: 'Create workspace' })).toBeVisible();

    await page.getByRole('link', { name: 'Sign in' }).click();
    await expect(page).toHaveURL(/\/login$/);
  });

  test('empty fields trigger inline client-side validation (no request fired)', async ({
    page,
  }) => {
    await page.goto('/register');
    await page.getByRole('button', { name: 'Create workspace' }).click();

    await expect(page.getByText('Workspace name is required')).toBeVisible();
    await expect(page.getByText('Email is required')).toBeVisible();
    await expect(page.getByText('Password is required')).toBeVisible();
    await expect(page).toHaveURL(/\/register$/);
  });

  test('a short password is rejected client-side', async ({ page }) => {
    await page.goto('/register');
    await page.getByLabel('Workspace name').fill('Shorty WS');
    await page.getByLabel('Email').fill('shorty@e2e.test');
    await page.getByLabel('Password').fill('short');
    await page.getByRole('button', { name: 'Create workspace' }).click();

    await expect(page.getByText('Password must be at least 8 characters')).toBeVisible();
    await expect(page).toHaveURL(/\/register$/);
  });

  test('a new workspace can be created and signs the owner straight in', async ({ page }) => {
    // Unique per run so the tenant name / email never collide (CI runs on a
    // fresh stack; the timestamp keeps retries independent too).
    const stamp = Date.now();
    const email = `owner-${stamp}@e2e.test`;
    const workspace = `E2E Workspace ${stamp}`;

    await page.goto('/register');
    await page.getByLabel('Workspace name').fill(workspace);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password').fill('a-strong-e2e-password');
    await page.getByRole('button', { name: 'Create workspace' }).click();

    // Success provisions the workspace, chains login, and redirects in. Landing
    // on /dashboard (rather than being bounced back to /login) proves the owner
    // was signed in.
    await page.waitForURL('**/dashboard');
    await expect(page).toHaveURL(/\/dashboard$/);
  });
});
