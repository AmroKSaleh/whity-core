import { test, expect } from '@playwright/test';

/**
 * App-wide RTL support: interface direction is a PROPERTY OF THE CHOSEN
 * LANGUAGE, not a toggle of its own. Picking Arabic in the sidebar's language
 * switcher flips <html dir> for the whole UI, and the choice persists because
 * it is written to the user's profile (PATCH /api/v1/settings/language).
 *
 * The old standalone direction toggle is gone: a language and a direction that
 * disagree is not a state a user can usefully be in.
 */
test.describe('Interface direction follows the language', () => {
  test.afterEach(async ({ page }) => {
    // Leave the shared account in English so the next spec starts LTR.
    await page.request.patch('/api/v1/settings/language', {
      data: { language_code: 'en' },
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    });
  });

  test('choosing Arabic flips <html dir>, and the choice survives a reload', async ({ page }) => {
    await page.goto('/dashboard');
    const html = page.locator('html');
    const switcher = page.getByTestId('language-switcher').locator('select');

    // Defaults to LTR once the provider resolves English.
    await expect(html).toHaveAttribute('dir', 'ltr');

    // Choosing Arabic mirrors the whole document — no separate control.
    await switcher.selectOption('ar');
    await expect(html).toHaveAttribute('dir', 'rtl');
    await expect(html).toHaveAttribute('lang', 'ar');

    // The preference lives on the profile, so it survives a reload.
    await page.reload();
    await expect(html).toHaveAttribute('dir', 'rtl');

    // And switching back to English un-mirrors it.
    await switcher.selectOption('en');
    await expect(html).toHaveAttribute('dir', 'ltr');
  });

  test('no standalone direction toggle remains in the UI', async ({ page }) => {
    await page.goto('/dashboard');

    await expect(page.getByTestId('direction-toggle')).toHaveCount(0);
    await expect(page.getByLabel('Toggle interface direction')).toHaveCount(0);
  });
});
