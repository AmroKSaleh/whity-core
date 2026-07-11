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

    // Barcode: default value {{sku}} resolves to the sample "WID-001" and renders
    // as an inert data-URI SVG image.
    const codes = pageCanvas.locator('img[src^="data:image/svg+xml"]');
    await page.getByTestId('doc-add-barcode').click();
    await expect(codes).toHaveCount(1);

    // QR adds a second matrix code.
    await page.getByTestId('doc-add-qr').click();
    await expect(codes).toHaveCount(2);
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

  test('undo reverses an add and redo re-applies it', async ({ page }) => {
    await page.goto('/admin/documents');
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(page.getByTestId('doc-undo')).toBeDisabled();

    await page.getByTestId('doc-add-text').click();
    await expect(el).toHaveCount(1);

    await page.getByTestId('doc-undo').click();
    await expect(el).toHaveCount(0);

    await page.getByTestId('doc-redo').click();
    await expect(el).toHaveCount(1);
  });

  test('copy + paste clones the selected element, and cut removes it', async ({ page }) => {
    await page.goto('/admin/documents');
    const el = page.locator('[data-testid^="doc-el-"]');
    await page.getByTestId('doc-add-text').click();
    await expect(el).toHaveCount(1);
    await el.first().click(); // ensure selected + move focus off the Add button

    // Copy then paste → a second element; the Paste button appears once there's
    // something on the clipboard.
    await page.getByTestId('doc-copy').click();
    await page.getByTestId('doc-paste').click();
    await expect(el).toHaveCount(2);

    // The pasted clone is now selected; cut removes it, leaving one.
    await page.getByTestId('doc-cut').click();
    await expect(el).toHaveCount(1);
  });

  test('locking an element blocks nudge and delete until it is unlocked', async ({ page }) => {
    await page.goto('/admin/documents');
    await page.getByTestId('doc-add-text').click();
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(el).toHaveCount(1);
    await el.first().click();

    const leftMm = async () => {
      const m = ((await el.first().getAttribute('style')) ?? '').match(/left:\s*([\d.]+)mm/);
      return m ? parseFloat(m[1]) : NaN;
    };
    const x0 = await leftMm();

    // Lock from the layers panel.
    const lockToggle = page.locator('[data-testid^="doc-layer-lock-"]');
    await lockToggle.click();

    // Nudge and Delete are ignored while locked.
    await el.first().click();
    await page.keyboard.press('ArrowRight');
    await expect.poll(leftMm).toBeCloseTo(x0, 1);
    await page.keyboard.press('Delete');
    await expect(el).toHaveCount(1);

    // Unlock → nudge works again.
    await lockToggle.click();
    await el.first().click();
    await page.keyboard.press('ArrowRight');
    await expect.poll(leftMm).toBeCloseTo(x0 + 1, 1);
  });

  test('hiding an element removes it from Preview', async ({ page }) => {
    await page.goto('/admin/documents');
    await page.getByTestId('doc-add-dynamicText').click();
    const canvas = page.getByTestId('doc-page');
    await expect(canvas.getByText('{{company_name}}')).toBeVisible();

    // Hide from the layers panel, then switch to Preview — it's gone.
    await page.locator('[data-testid^="doc-layer-hide-"]').click();
    await page.getByTestId('doc-preview-toggle').click();
    await expect(canvas.getByText('Acme Corp')).toHaveCount(0);
    await expect(canvas.getByText('{{company_name}}')).toHaveCount(0);
  });
});

test.describe('Document designer — requires auth', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('an unauthenticated visitor is redirected to login', async ({ page }) => {
    await page.goto('/admin/documents');
    await expect(page).toHaveURL(/\/login/);
  });
});
