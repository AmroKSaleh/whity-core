import { test, expect } from '@playwright/test';

/**
 * App-wide RTL support: the direction toggle in the sidebar flips <html dir>
 * for the whole UI and persists the choice across reloads (Arabic support).
 */
test.describe('Interface direction (RTL)', () => {
  test('the sidebar toggle flips <html dir> and the choice persists', async ({ page }) => {
    await page.goto('/dashboard');
    const html = page.locator('html');

    // Defaults to LTR once the provider applies on mount.
    await expect(html).toHaveAttribute('dir', 'ltr');

    // Toggling switches the whole document to RTL.
    await page.getByTestId('direction-toggle').click();
    await expect(html).toHaveAttribute('dir', 'rtl');

    // The preference survives a reload.
    await page.reload();
    await expect(html).toHaveAttribute('dir', 'rtl');

    // And toggles back to LTR.
    await page.getByTestId('direction-toggle').click();
    await expect(html).toHaveAttribute('dir', 'ltr');
  });
});
