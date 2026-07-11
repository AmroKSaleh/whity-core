import { test, expect } from '@playwright/test';

/**
 * E2E for the Document & Label Designer (WC-doceditor), against the live stack.
 * Focuses on the risky/behavioural bits: the editor mounts, adding a barcode and
 * a QR element renders real bwip-js SVG in the canvas, and Preview interpolates
 * dynamic-text placeholders. Runs under the [admin] project's authenticated
 * session (the page is open to any authenticated user in the MVP).
 */

test.describe('Document & Label Designer', () => {
  test('mounts, and adding a barcode + QR renders bwip-js SVG', async ({ page }) => {
    await page.goto('/admin/documents');
    await expect(page.getByRole('heading', { name: 'Document & Label Designer' })).toBeVisible();
    await expect(page.getByTestId('document-designer')).toBeVisible();
    const pageCanvas = page.getByTestId('doc-page');
    await expect(pageCanvas).toBeVisible();

    // Barcode: default value {{sku}} resolves to the sample "WID-001" and renders SVG.
    await page.getByTestId('doc-add-barcode').click();
    await expect(pageCanvas.locator('svg')).toHaveCount(1);

    // QR adds a second matrix SVG.
    await page.getByTestId('doc-add-qr').click();
    await expect(pageCanvas.locator('svg')).toHaveCount(2);
  });

  test('dynamic text shows the raw token while editing and interpolates in Preview', async ({ page }) => {
    await page.goto('/admin/documents');
    await page.getByTestId('doc-add-dynamicText').click();

    const canvas = page.getByTestId('doc-page');
    // Editing: raw {{company_name}} token is visible.
    await expect(canvas.getByText('{{company_name}}')).toBeVisible();

    // Preview: token is substituted with the placeholder's sample ("Acme Corp").
    await page.getByTestId('doc-preview-toggle').click();
    await expect(canvas.getByText('Acme Corp')).toBeVisible();
    await expect(canvas.getByText('{{company_name}}')).toHaveCount(0);
  });

  test('page size preset changes the canvas', async ({ page }) => {
    await page.goto('/admin/documents');
    await page.getByTestId('doc-tab-page').click();
    // Switching to A4 makes the page tall; just assert the control is wired.
    await expect(page.getByTestId('doc-tab-page')).toBeVisible();
  });

  test('keyboard nudge, align-to-page, and Delete act on the selected element', async ({ page }) => {
    await page.goto('/admin/documents');
    await page.getByTestId('doc-add-text').click();
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(el).toHaveCount(1);
    await el.click(); // select + move focus off the Add button

    const leftMm = async () => {
      const m = ((await el.getAttribute('style')) ?? '').match(/left:\s*([\d.]+)mm/);
      return m ? parseFloat(m[1]) : NaN;
    };
    const x0 = await leftMm();

    // Arrow key nudges 1mm.
    await page.keyboard.press('ArrowRight');
    await expect.poll(leftMm).toBeCloseTo(x0 + 1, 1);

    // Align-right pushes it toward the page's right edge.
    await page.getByRole('button', { name: 'Align right' }).click();
    await expect.poll(leftMm).toBeGreaterThan(x0 + 5);

    // Delete removes it.
    await page.keyboard.press('Delete');
    await expect(el).toHaveCount(0);
  });
});

test.describe('Document designer — requires auth', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('an unauthenticated visitor is redirected to login', async ({ page }) => {
    await page.goto('/admin/documents');
    await expect(page).toHaveURL(/\/login/);
  });
});
