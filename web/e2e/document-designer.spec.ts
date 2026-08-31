import { test, expect, type Page } from '@playwright/test';

/**
 * E2E for the Document & Label Designer (WC-doceditor), against the live stack.
 * Focuses on the risky/behavioural bits: the editor mounts, adding a barcode and
 * a QR element renders real bwip-js SVG in the canvas, and Preview interpolates
 * dynamic-text placeholders. Runs under the [admin] project's authenticated
 * session (the page is open to any authenticated user in the MVP).
 *
 * Commands live in the menu bar (`menu-<id>` ▸ `menu-item-<id>`) with the
 * frequent ones mirrored on the icon toolbar (`toolbar-<id>`); these helpers
 * drive the real chrome rather than reaching past it.
 */

/**
 * Open a top-level menu, waiting until it really is open.
 *
 * Radix hands focus back to the trigger when an item is chosen, and a click
 * that lands during that hand-off is swallowed — so a second visit to the same
 * menu in one test can silently no-op. Retry against the trigger's own
 * `data-state` rather than assuming one click did it.
 */
async function openMenu(page: Page, menu: string) {
  const trigger = page.getByTestId(`menu-${menu}`);
  await expect(async () => {
    if ((await trigger.getAttribute('data-state')) !== 'open') {
      await trigger.click();
    }
    await expect(trigger).toHaveAttribute('data-state', 'open', { timeout: 1_000 });
  }).toPass({ timeout: 15_000 });
}

/** Click a top-level menu, then one of its items. */
async function chooseMenu(page: Page, menu: string, item: string) {
  await openMenu(page, menu);
  await page.getByTestId(`menu-item-${item}`).click();
}

/**
 * Flip a checkbox item (View ▸ Grid, Rulers, …). Those deliberately keep the
 * menu open so several can be toggled in one visit, so close it afterwards —
 * otherwise the open menu's dismissable layer swallows the next click.
 */
async function toggleMenuOption(page: Page, menu: string, item: string) {
  await openMenu(page, menu);
  await page.getByTestId(`menu-item-${item}`).click();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId(`menu-item-${item}`)).toHaveCount(0);
}

/** Same, for an item nested one submenu deep. */
async function chooseSubmenu(page: Page, menu: string, sub: string, item: string) {
  await openMenu(page, menu);
  await page.getByTestId(`menu-item-${sub}`).click();
  await page.getByTestId(`menu-item-${item}`).click();
}

/** Insert an element from the toolbar's Insert group. */
function addElement(page: Page, type: string) {
  return page.getByTestId(`toolbar-insert-${type}`).click();
}

/**
 * Show the Layers tab. Inserting an element switches the rail to that
 * element's properties, so anything reaching for the layer list (or the block
 * library that shares that tab) has to come back — exactly as a user does.
 */
function openLayers(page: Page) {
  return page.getByTestId('doc-tab-layers').click();
}

/** Start a new blank document (File ▸ New document). */
function newDocument(page: Page) {
  return chooseMenu(page, 'file', 'new');
}

/**
 * Remove a block from the library again. Blocks are tenant-wide, so without
 * this every run of this spec leaves another auto-named "Block N" behind.
 * Takes the block's own id, as {@link saveSelectionAsBlock} returns it.
 */
async function deleteBlock(page: Page, blockId: string) {
  await openLayers(page);
  await page.getByTestId(`doc-block-delete-${blockId}`).click();
  await expect(page.getByTestId(`doc-block-insert-${blockId}`)).toHaveCount(0);
}

/**
 * One block, as it appears under ONE scope group of the rail's library.
 *
 * The rail groups the library by visibility tier (System / Personal /
 * Tenant-wide / Global) and a group is rendered only while it has a block in
 * it, so "this block is under Tenant-wide and no longer under Personal" is
 * exactly what a user means by published — and after a reload it is a
 * statement about the row the SERVER returned, not about local state.
 *
 * The group is located by its testid rather than by its heading: the heading
 * is translated (`palette.scope.*`), so matching the text "Tenant-wide" would
 * assert the editor is in English as much as it asserts where the block sits.
 */
function blockInGroup(page: Page, scope: 'system' | 'personal' | 'tenant' | 'global', blockId: string) {
  return page.getByTestId(`doc-block-group-${scope}`).getByTestId(`doc-block-insert-${blockId}`);
}

/**
 * Content this run authored, unique to it.
 *
 * Canvas text is what proves an inserted instance is the one this spec made
 * rather than whatever else the library offers, so it must not be a string
 * another block could also carry.
 */
function uniqueText(label: string): string {
  return `${label} ${Date.now()}`;
}

/** The two blocks every tenant has out of the box, by the name both sources give them. */
const STARTER_HEADER = 'Company header';
const STARTER_FOOTER = 'Company footer';

/**
 * A starter block in the rail, located by NAME rather than by the client's
 * symbolic id.
 *
 * The id is not stable across environments and must not be asserted on. The
 * client ships `sys-header`/`sys-footer` as a fallback library so the Blocks
 * panel is never empty, and the SERVER seeds the same two blocks for every
 * tenant (#1012) with real numeric ids. The designer dedupes them by NAME and
 * prefers the server row, so `doc-block-insert-sys-header` exists on a tenant
 * that was never seeded and does NOT exist on one that was — and a spec naming
 * it asserts which of the two this environment happens to be rather than that
 * the block is there. The name is the same either way, and being there is the
 * requirement.
 */
function starterBlock(page: Page, name: string) {
  return page.locator('[data-testid^="doc-block-insert-"]').filter({ hasText: name }).first();
}

/**
 * Save the current selection as a block and return the id the SERVER gave it.
 *
 * The id is read from the create call's OWN response — `POST
 * /api/v1/document-blocks` answers 201 with the row it just wrote — so it names
 * this spec's block whatever else the library holds. Every later step keys off
 * that id: `doc-block-insert-<id>`, `doc-block-scope-<id>`,
 * `doc-block-delete-<id>`, `menu-item-insert-block-<id>`.
 *
 * This deliberately does NOT diff the library before and after. Blocks are
 * tenant-wide and auto-named "Block N", so a diff was the only way to tell
 * which row was ours — but a diff is a claim about the whole library: it needs
 * the list to be settled before the snapshot (so it had to wait on a starter it
 * did not create), it credits us with any row that appears for any other reason
 * in the window, and it is the one thing here that could not survive a second
 * writer. The id needs none of that: it comes from the answer to this spec's
 * own request.
 */
async function saveSelectionAsBlock(page: Page): Promise<string> {
  const created = page.waitForResponse(
    (r) => r.url().includes('/api/v1/document-blocks') && r.request().method() === 'POST',
    { timeout: 15_000 }
  );
  await chooseMenu(page, 'format', 'save-as-block');

  const response = await created;
  expect(response.status(), 'saving a selection should create a block').toBe(201);
  const blockId = String(((await response.json()) as { data: { id: number | string } }).data.id);

  // The chrome confirms the save, and the rail's library now offers this block.
  await expect(page.getByRole('status').filter({ hasText: 'Saved block' })).toBeVisible();
  await openLayers(page);
  await expect(page.getByTestId(`doc-block-insert-${blockId}`)).toBeVisible();
  return blockId;
}

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
    await addElement(page, 'barcode');
    await expect(codes).toHaveCount(1);

    // QR adds a second matrix code.
    await addElement(page, 'qr');
    await expect(codes).toHaveCount(2);
  });

  test('dynamic text shows the raw token while editing and interpolates in Preview', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'dynamicText');

    const canvas = page.getByTestId('doc-page');
    // Editing: raw {{company_name}} token is visible.
    await expect(canvas.getByText('{{company_name}}')).toBeVisible();

    // Preview: token is substituted with the placeholder's sample ("Acme Corp").
    await page.getByTestId('doc-preview-toggle').click();
    await expect(canvas.getByText('Acme Corp')).toBeVisible();
    await expect(canvas.getByText('{{company_name}}')).toHaveCount(0);
  });

  test('Page ▸ Page setup opens the page tab in the side rail', async ({ page }) => {
    await page.goto('/admin/documents');

    // The menu is the discoverable route to a rail tab: choosing Page setup
    // switches the rail to Page, where the size presets live.
    await chooseMenu(page, 'page', 'page-setup');
    await expect(page.getByTestId('doc-tab-page')).toHaveAttribute('aria-current', 'true');
    await expect(page.getByLabel('Size preset')).toBeVisible();
  });

  test('keyboard nudge, align-to-page, and Delete act on the selected element', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(el).toHaveCount(1);
    await el.click(); // select + move focus off the toolbar button

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
    await expect(page.getByTestId('toolbar-undo')).toBeDisabled();

    await addElement(page, 'text');
    await expect(el).toHaveCount(1);

    await page.getByTestId('toolbar-undo').click();
    await expect(el).toHaveCount(0);

    await page.getByTestId('toolbar-redo').click();
    await expect(el).toHaveCount(1);
  });

  test('copy + paste clones the selected element, and cut removes it', async ({ page }) => {
    await page.goto('/admin/documents');
    const el = page.locator('[data-testid^="doc-el-"]');
    await addElement(page, 'text');
    await expect(el).toHaveCount(1);
    await el.first().click(); // ensure selected + move focus off the toolbar button

    // Copy then paste → a second element; Paste enables once there's something
    // on the clipboard.
    await page.getByTestId('toolbar-copy').click();
    await page.getByTestId('toolbar-paste').click();
    await expect(el).toHaveCount(2);

    // The pasted clone is now selected; cut removes it, leaving one.
    await page.getByTestId('toolbar-cut').click();
    await expect(el).toHaveCount(1);
  });

  test('Edit menu mirrors the toolbar: copy/paste from the menu bar', async ({ page }) => {
    await page.goto('/admin/documents');
    const el = page.locator('[data-testid^="doc-el-"]');
    await addElement(page, 'rect');
    await el.first().click();

    await chooseMenu(page, 'edit', 'copy');
    await chooseMenu(page, 'edit', 'paste');
    await expect(el).toHaveCount(2);

    // Undo from the menu backs the paste out again.
    await chooseMenu(page, 'edit', 'undo');
    await expect(el).toHaveCount(1);
  });

  test('locking an element blocks nudge and delete until it is unlocked', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(el).toHaveCount(1);
    await el.first().click();

    const leftMm = async () => {
      const m = ((await el.first().getAttribute('style')) ?? '').match(/left:\s*([\d.]+)mm/);
      return m ? parseFloat(m[1]) : NaN;
    };
    const x0 = await leftMm();

    // Lock from the layers panel.
    await openLayers(page);
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

  test('dragging near the page origin shows an alignment guide and snaps to it', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    const el = page.locator('[data-testid^="doc-el-"]').first();
    await expect(el).toBeVisible();

    const box = await el.boundingBox();
    if (!box) throw new Error('element has no bounding box');

    // Grab near the top-left and drag toward the page origin — the left/top
    // edges come to rest ~1mm off 0, inside the snap tolerance, so they snap
    // exactly onto the page-origin alignment target.
    await page.mouse.move(box.x + 6, box.y + 6);
    await page.mouse.down();
    await page.mouse.move(box.x - 20, box.y - 20, { steps: 10 });

    // A vertical alignment guide is drawn while dragging (integration of the
    // snap engine → guide overlay).
    await expect(page.getByTestId('doc-guide-v').first()).toBeAttached();
    await page.mouse.up();

    // The left/top edges are pulled onto the page-origin guide — the exact
    // snap arithmetic is covered by the geometry unit tests; here we just prove
    // the drag reaches the origin alignment zone (well past its 8mm start).
    const style = (await el.getAttribute('style')) ?? '';
    const left = parseFloat(/left:\s*([\d.]+)mm/.exec(style)?.[1] ?? 'NaN');
    const top = parseFloat(/top:\s*([\d.]+)mm/.exec(style)?.[1] ?? 'NaN');
    expect(left).toBeLessThanOrEqual(1);
    expect(top).toBeLessThanOrEqual(1);
  });

  test('supports multiple pages with independent elements and print output', async ({ page }) => {
    await page.goto('/admin/documents');
    const el = page.locator('[data-testid^="doc-el-"]');

    // Page 1: add a text element.
    await addElement(page, 'text');
    await expect(el).toHaveCount(1);

    // Add a second page — it starts empty and becomes the current page.
    await page.getByTestId('doc-add-page').click();
    await expect(page.getByTestId('doc-page-tab-1')).toBeVisible();
    await expect(el).toHaveCount(0);

    // Add an element on page 2.
    await addElement(page, 'qr');
    await expect(el).toHaveCount(1);

    // Switch back to page 1 — its own element is still there (pages are independent).
    await page.getByTestId('doc-page-tab-0').click();
    await expect(el).toHaveCount(1);

    // The print document renders both pages.
    await expect(page.getByTestId('doc-print-page')).toHaveCount(2);

    // Deleting page 2 leaves a single page.
    await page.getByTestId('doc-page-tab-1').click();
    await page.getByTestId('doc-delete-page').click();
    await expect(page.getByTestId('doc-print-page')).toHaveCount(1);
  });

  test('serial batch: generate a sequence, preview rows, and print one copy per row', async ({ page }) => {
    await page.goto('/admin/documents');

    // A dynamic-text element bound to {{sku}} so the generated serial shows.
    await addElement(page, 'dynamicText');
    await page.getByTestId('doc-text-value').fill('{{sku}}');

    // Configure and generate a 3-row serial sequence into `sku`.
    await page.getByTestId('doc-tab-batch').click();
    await page.getByTestId('doc-batch-key').selectOption('sku');
    await page.getByTestId('doc-batch-prefix').fill('SN-');
    await page.getByTestId('doc-batch-start').fill('1');
    await page.getByTestId('doc-batch-count').fill('3');
    await page.getByTestId('doc-batch-generate').click();

    // Batch badge reflects ×3 and the print doc has 3 pages (1 page × 3 rows).
    await expect(page.getByTestId('doc-batch-badge')).toHaveText('×3');
    await expect(page.getByTestId('doc-print-page')).toHaveCount(3);

    // Preview shows the first serial; Next advances to the second row.
    await page.getByTestId('doc-preview-toggle').click();
    const canvas = page.getByTestId('doc-page');
    await expect(canvas.getByText('SN-0001')).toBeVisible();
    await page.getByTestId('doc-batch-next').click();
    await expect(canvas.getByText('SN-0002')).toBeVisible();

    // Clearing the batch returns to a single print page.
    await page.getByTestId('doc-batch-clear').click();
    await expect(page.getByTestId('doc-print-page')).toHaveCount(1);
  });

  test('batch from pasted rows: load CSV rows and print one label each', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'dynamicText');
    await page.getByTestId('doc-text-value').fill('{{sku}}');

    // Data ▸ Variable data opens the Batch tab → Paste mode → paste a 2-row CSV.
    await chooseMenu(page, 'data', 'batch');
    await page.getByTestId('doc-batch-mode-paste').click();
    await page.getByTestId('doc-batch-paste').fill('sku,model\nABC-1,Widget\nABC-2,Gadget');
    await page.getByTestId('doc-batch-load-paste').click();

    // Two rows → ×2 badge and 2 print pages.
    await expect(page.getByTestId('doc-batch-badge')).toHaveText('×2');
    await expect(page.getByTestId('doc-print-page')).toHaveCount(2);

    // Preview shows the first row's sku.
    await page.getByTestId('doc-preview-toggle').click();
    await expect(page.getByTestId('doc-page').getByText('ABC-1')).toBeVisible();
  });

  test('label sheet: tiles batch rows N-up onto one sheet page', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'dynamicText');
    await page.getByTestId('doc-text-value').fill('{{sku}}');

    // A 4-row batch → 4 individual pages before tiling.
    await page.getByTestId('doc-tab-batch').click();
    await page.getByTestId('doc-batch-mode-paste').click();
    await page.getByTestId('doc-batch-paste').fill('sku\nA\nB\nC\nD');
    await page.getByTestId('doc-batch-load-paste').click();
    await expect(page.getByTestId('doc-print-page')).toHaveCount(4);

    // Enable a 2×2 label sheet → 4 cells fit on a single sheet page.
    await page.getByTestId('doc-tab-sheet').click();
    await page.getByTestId('doc-sheet-enable').click();
    await page.getByTestId('doc-sheet-cols').fill('2');
    await page.getByTestId('doc-sheet-rows').fill('2');
    await expect(page.getByTestId('doc-print-page')).toHaveCount(1);
    await expect(page.getByTestId('doc-sheet-cell')).toHaveCount(4);
  });

  test('saves and restores the sheet + sequence settings with the template', async ({ page }) => {
    await page.goto('/admin/documents');
    // Saved templates persist tenant-wide in the shared dev database, so a
    // fixed name would collide with every earlier run of this spec and the
    // "Open saved" entry would stop being unique. Name it per-run and delete it
    // again at the end.
    const name = `Device label persist test ${Date.now()}`;
    await page.getByTestId('doc-name').fill(name);

    // Configure a serial prefix and enable a 4-column label sheet.
    await page.getByTestId('doc-tab-batch').click();
    await page.getByTestId('doc-batch-prefix').fill('DEV-');
    await page.getByTestId('doc-tab-sheet').click();
    await page.getByTestId('doc-sheet-enable').click();
    await page.getByTestId('doc-sheet-cols').fill('4');
    await page.getByTestId('doc-save').click();
    // Saving is a round-trip; wait for it to land rather than racing the next
    // step against it.
    await expect(page.getByRole('status').filter({ hasText: 'Template saved.' })).toBeVisible();

    // New resets everything: the sheet is off (its fields disappear) and the
    // serial prefix returns to the default.
    await newDocument(page);
    await page.getByTestId('doc-tab-sheet').click();
    await expect(page.getByTestId('doc-sheet-cols')).toHaveCount(0);
    await page.getByTestId('doc-tab-batch').click();
    await expect(page.getByTestId('doc-batch-prefix')).toHaveValue('SN-');

    // Templates ▸ Open saved ▸ <name> restores both settings.
    await openMenu(page, 'templates');
    await page.getByTestId('menu-item-open-saved').click();
    await page.getByRole('menuitem', { name }).click();
    await page.getByTestId('doc-tab-sheet').click();
    await expect(page.getByTestId('doc-sheet-cols')).toHaveValue('4');
    await page.getByTestId('doc-tab-batch').click();
    await expect(page.getByTestId('doc-batch-prefix')).toHaveValue('DEV-');

    // Clean up: the template just loaded is the current one, so Delete removes
    // it and leaves the shared library as this test found it.
    await chooseMenu(page, 'templates', 'delete-saved');
    await openMenu(page, 'templates');
    await page.getByTestId('menu-item-open-saved').click();
    await expect(page.getByRole('menuitem', { name })).toHaveCount(0);
  });

  test('grid overlay toggles from the View menu and the selected element shows a size readout', async ({ page }) => {
    await page.goto('/admin/documents');

    // Grid overlay is off by default; the View menu's checkbox shows it.
    await expect(page.getByTestId('doc-grid')).toHaveCount(0);
    await toggleMenuOption(page, 'view', 'grid');
    await expect(page.getByTestId('doc-grid')).toBeVisible();

    // Selecting an element reveals a live size readout in millimetres.
    await addElement(page, 'rect');
    const readout = page.getByTestId('doc-readout');
    await expect(readout).toBeVisible();
    await expect(readout).toContainText('mm');
  });

  test('text elements support direction (auto/RTL) for Arabic content', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    await page.getByTestId('doc-text-value').fill('مرحبا SN-001');

    const canvas = page.getByTestId('doc-page');
    await expect(canvas.getByText('مرحبا SN-001')).toBeVisible();

    // Defaults to dir="auto" (per-paragraph inference for mixed Arabic/Latin).
    await expect(canvas.locator('div[dir="auto"]').first()).toBeVisible();

    // Switching to RTL sets the base direction on the rendered text box.
    await page.getByTestId('doc-text-direction').selectOption('rtl');
    await expect(canvas.locator('div[dir="rtl"]').first()).toBeVisible();
  });

  test('multi-select: shift-click selects several elements, then group delete removes them', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    await addElement(page, 'rect');
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(el).toHaveCount(2);

    // Select the first layer, then shift-click the second → both selected.
    await openLayers(page);
    const layers = page.locator('[data-testid^="doc-layer-select-"]');
    await layers.nth(0).click();
    await layers.nth(1).click({ modifiers: ['Shift'] });
    await expect(page.getByTestId('doc-selection-count')).toHaveText('2 selected');

    // Delete acts on the whole selection.
    await page.keyboard.press('Delete');
    await expect(el).toHaveCount(0);
  });

  test('Edit ▸ Select all picks up every element on the page', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    await addElement(page, 'rect');
    await addElement(page, 'qr');

    await chooseMenu(page, 'edit', 'select-all');
    await expect(page.getByTestId('doc-selection-count')).toHaveText('3 selected');
  });

  test('reusable blocks: save a selection as a block and insert it into a fresh document', async ({ page }) => {
    await page.goto('/admin/documents');
    // Text unique to this run, so the instance found on the canvas at the end
    // can only be the block this spec saved.
    const marker = uniqueText('Reusable block');
    await addElement(page, 'text');
    await page.getByTestId('doc-text-value').fill(marker);
    await addElement(page, 'rect');

    // Select both elements and save them as a reusable block.
    await openLayers(page);
    const layers = page.locator('[data-testid^="doc-layer-select-"]');
    await layers.nth(0).click();
    await layers.nth(1).click({ modifiers: ['Shift'] });
    const blockId = await saveSelectionAsBlock(page);

    // Start a fresh document — the block persists in the library (stored once).
    await newDocument(page);
    await expect(page.locator('[data-testid^="doc-el-"]')).toHaveCount(0);

    // Insert THIS spec's block → a single instance carrying its content.
    await openLayers(page);
    const insertBtn = page.getByTestId(`doc-block-insert-${blockId}`);
    await expect(insertBtn).toBeVisible();
    await insertBtn.click();
    await expect(page.locator('[data-testid^="doc-el-"]')).toHaveCount(1);
    await expect(page.getByTestId('doc-page').getByText(marker)).toBeVisible();

    await deleteBlock(page, blockId);
  });

  test('block edit mode: editing a block propagates to every instance', async ({ page }) => {
    await page.goto('/admin/documents');
    const canvas = page.getByTestId('doc-page');

    // Make a block from a text element reading a marker unique to this run
    // (inserting selects it, which is what Save-as-block acts on).
    const original = uniqueText('LOGO');
    const edited = uniqueText('BRAND');
    await addElement(page, 'text');
    await page.getByTestId('doc-text-value').fill(original);
    const blockId = await saveSelectionAsBlock(page);

    // Fresh doc, insert THIS spec's block twice → two instances, both showing
    // the original marker.
    await newDocument(page);
    await openLayers(page);
    const insert = page.getByTestId(`doc-block-insert-${blockId}`);
    await insert.click();
    await insert.click();
    await expect(canvas.getByText(original)).toHaveCount(2);

    // Select one instance and open block edit mode from the Format menu.
    await page.locator('[data-testid^="doc-layer-select-"]').first().click();
    await chooseMenu(page, 'format', 'edit-block');
    await expect(page.getByTestId('doc-block-edit-banner')).toBeVisible();

    // Page management is meaningless inside a block, so it is hidden.
    await expect(page.getByTestId('doc-add-page')).toHaveCount(0);

    // Edit the block's text to the second marker and finish. Picking a layer
    // does not move the rail, so step across to its properties.
    await page.locator('[data-testid^="doc-layer-select-"]').first().click();
    await page.getByTestId('doc-tab-element').click();
    await page.getByTestId('doc-text-value').fill(edited);
    await page.getByTestId('doc-block-edit-done').click();

    // Both instances now show the edited content — the edit propagated.
    await expect(canvas.getByText(edited)).toHaveCount(2);
    await expect(canvas.getByText(original)).toHaveCount(0);

    await deleteBlock(page, blockId);
  });

  test('block scoping: a saved block defaults to Personal and publishing it tenant-wide is accepted by the server', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    const blockId = await saveSelectionAsBlock(page);

    // Pin the block by its own id. Blocks are tenant-wide and this spec's
    // earlier tests leave look-alikes behind, and publishing moves a block
    // between scope groups — so any position-based locator would drift onto a
    // different block halfway through.
    const scope = page.getByTestId(`doc-block-scope-${blockId}`);
    await expect(scope).toHaveValue('personal');
    await expect(blockInGroup(page, 'personal', blockId)).toBeVisible();

    // Publishing is the PATCH, not the click. `documents:publish` gates moving
    // a block into a shared tier and the backend answers 403 without it, so
    // wait for the server's own answer: the select is a controlled input whose
    // value the click has already set, and a refused publish never re-renders
    // it — reading it back here would pass whatever the server said. Waiting
    // also keeps the reload below from cancelling the request in flight.
    const published = page.waitForResponse(
      (r) => r.url().includes(`/api/v1/document-blocks/${blockId}`) && r.request().method() === 'PATCH',
      { timeout: 15_000 }
    );
    await scope.selectOption('tenant');
    expect((await published).status(), 'publishing a block tenant-wide should be allowed').toBe(200);

    // Reload, so the library on screen is what `GET /document-blocks` just
    // returned rather than anything this browser was holding: the block is now
    // offered under Tenant-wide and is gone from Personal, which is what the
    // rest of the tenant will see.
    await page.reload();
    await openLayers(page);
    await expect(blockInGroup(page, 'tenant', blockId)).toBeVisible();
    await expect(blockInGroup(page, 'personal', blockId)).toHaveCount(0);

    await deleteBlock(page, blockId);
  });

  test('text typography: line height is configurable and applied to the text box', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');

    const textbox = page.getByTestId('doc-page').getByTestId('doc-textbox').first();
    await expect(textbox).toHaveAttribute('style', /line-height:\s*1\.2\b/);

    await page.getByTestId('doc-line-height').fill('2');
    await expect(textbox).toHaveAttribute('style', /line-height:\s*2\b/);
  });

  test('barcodes: an extended symbology and a QR error-correction level render', async ({ page }) => {
    await page.goto('/admin/documents');
    const codes = page.getByTestId('doc-page').locator('img[src^="data:image/svg+xml"]');

    // Barcode → switch to an extended symbology (Code 93) — still renders.
    await addElement(page, 'barcode');
    await expect(codes).toHaveCount(1);
    await page.getByTestId('doc-tab-element').click();
    await page.getByLabel('Symbology').selectOption('code93');
    await expect(codes).toHaveCount(1);

    // QR → set error-correction to High — still renders.
    await addElement(page, 'qr');
    await expect(codes).toHaveCount(2);
    await page.getByTestId('doc-qr-eclevel').selectOption('H');
    await expect(codes).toHaveCount(2);
  });

  test('distribute evenly spaces three selected elements', async ({ page }) => {
    await page.goto('/admin/documents');

    // Three text elements at uneven X (centres 25, 125, 70 for width 50).
    await addElement(page, 'text');
    await page.getByTestId('doc-field-x').fill('0');
    await addElement(page, 'text');
    await page.getByTestId('doc-field-x').fill('100');
    await addElement(page, 'text');
    await page.getByTestId('doc-field-x').fill('20');

    // Select all three and distribute horizontally.
    await openLayers(page);
    const layers = page.locator('[data-testid^="doc-layer-select-"]');
    await layers.nth(0).click();
    await layers.nth(1).click({ modifiers: ['Shift'] });
    await layers.nth(2).click({ modifiers: ['Shift'] });
    await page.getByTestId('toolbar-distribute-h').click();

    // Outer centres are 25 and 125 → the middle element's centre lands at 75,
    // i.e. left = 75 - 25 = 50mm. (Ends stay at 0mm and 100mm.)
    const canvas = page.getByTestId('doc-page');
    await expect(canvas.locator('[data-testid^="doc-el-"][style*="left: 50mm"]')).toHaveCount(1);
    await expect(canvas.locator('[data-testid^="doc-el-"][style*="left: 0mm"]')).toHaveCount(1);
    await expect(canvas.locator('[data-testid^="doc-el-"][style*="left: 100mm"]')).toHaveCount(1);
  });

  test('align uses the selection bounding box when multiple are selected', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'text');
    await page.getByTestId('doc-field-x').fill('10');
    await addElement(page, 'text');
    await page.getByTestId('doc-field-x').fill('60');

    // Select both and align left → both go to the selection's left edge (10mm),
    // NOT the page edge (0mm).
    await openLayers(page);
    const layers = page.locator('[data-testid^="doc-layer-select-"]');
    await layers.nth(0).click();
    await layers.nth(1).click({ modifiers: ['Shift'] });
    await page.getByRole('button', { name: 'Align left' }).click();

    const canvas = page.getByTestId('doc-page');
    await expect(canvas.locator('[data-testid^="doc-el-"][style*="left: 10mm"]')).toHaveCount(2);
  });

  test('rulers toggle on from the View menu and show mm ticks around the page', async ({ page }) => {
    await page.goto('/admin/documents');

    // Off by default; the View menu's checkbox reveals the top + left rulers.
    await expect(page.getByTestId('doc-ruler-x')).toHaveCount(0);
    await toggleMenuOption(page, 'view', 'rulers');
    await expect(page.getByTestId('doc-ruler-x')).toBeVisible();
    await expect(page.getByTestId('doc-ruler-y')).toBeVisible();
    // Ticks are labelled in millimetres (0 and a 10mm mark present).
    await expect(page.getByTestId('doc-ruler-x').getByText('10', { exact: true })).toBeVisible();
  });

  test('starter templates: "Start from" populates a ready document (no white sheet)', async ({ page }) => {
    await page.goto('/admin/documents');
    const el = page.locator('[data-testid^="doc-el-"]');
    await expect(el).toHaveCount(0);

    // Templates ▸ Start from ▸ Invoice → the canvas fills with a ready layout.
    await chooseSubmenu(page, 'templates', 'start-from', 'start-from-invoice');
    await expect(page.getByTestId('doc-name')).toHaveValue('Invoice');
    expect(await el.count()).toBeGreaterThan(5);
    await expect(page.getByTestId('doc-page').getByText('INVOICE').first()).toBeVisible();
  });

  test('starter blocks: company header/footer are available out of the box', async ({ page }) => {
    await page.goto('/admin/documents');

    // The Blocks panel is populated on a fresh document (System starters) —
    // whether they came from the per-tenant seed or the client fallback, which
    // is why these are located by name. See starterBlock().
    await expect(starterBlock(page, STARTER_HEADER)).toBeVisible();
    await expect(starterBlock(page, STARTER_FOOTER)).toBeVisible();

    // Insert the header → an instance carrying the company name renders.
    await starterBlock(page, STARTER_HEADER).click();
    await expect(page.locator('[data-testid^="doc-el-"]')).toHaveCount(1);
    await expect(page.getByTestId('doc-page').getByText('Acme Corp').first()).toBeVisible();
  });

  test('Insert ▸ Block offers the same library as the side rail', async ({ page }) => {
    await page.goto('/admin/documents');

    // "The same library" is a claim about a row being in BOTH places, so prove
    // it with a row this spec put there: saving asserts the block is offered in
    // the rail, and it is inserted here from the menu without the rail being
    // touched. A starter alone would prove it only for a row somebody else made.
    const marker = uniqueText('Menu parity block');
    await addElement(page, 'text');
    await page.getByTestId('doc-text-value').fill(marker);
    const blockId = await saveSelectionAsBlock(page);

    await newDocument(page);
    await expect(page.locator('[data-testid^="doc-el-"]')).toHaveCount(0);

    await openMenu(page, 'insert');
    await page.getByTestId('menu-item-insert-block').click();
    // Both scope groups the menu builds are populated from that one library:
    // the System starter (by its menu LABEL, which is the block's name — see
    // starterBlock()) and this spec's own Personal block, by its own id.
    await expect(page.getByRole('menuitem', { name: STARTER_HEADER })).toBeVisible();
    await page.getByTestId(`menu-item-insert-block-${blockId}`).click();

    // What landed on the canvas is this spec's block, not another one.
    await expect(page.locator('[data-testid^="doc-el-"]')).toHaveCount(1);
    await expect(page.getByTestId('doc-page').getByText(marker)).toBeVisible();

    await deleteBlock(page, blockId);
  });

  test('setting element opacity applies it on the canvas', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'rect');
    const el = page.locator('[data-testid^="doc-el-"]').first();
    await expect(el).toBeVisible();
    await expect(el).toHaveCSS('opacity', '1');

    const opacity = page.getByTestId('doc-opacity');
    await opacity.fill('40');
    await opacity.blur();
    await expect(el).toHaveCSS('opacity', '0.4');
  });

  test('hiding an element removes it from Preview', async ({ page }) => {
    await page.goto('/admin/documents');
    await addElement(page, 'dynamicText');
    const canvas = page.getByTestId('doc-page');
    await expect(canvas.getByText('{{company_name}}')).toBeVisible();

    // Hide from the layers panel, then switch to Preview — it's gone.
    await openLayers(page);
    await page.locator('[data-testid^="doc-layer-hide-"]').click();
    await page.getByTestId('doc-preview-toggle').click();
    await expect(canvas.getByText('Acme Corp')).toHaveCount(0);
    await expect(canvas.getByText('{{company_name}}')).toHaveCount(0);
  });

  test('the side rail collapses and comes back without the View menu', async ({ page }) => {
    await page.goto('/admin/documents');
    await expect(page.getByTestId('doc-side-rail')).toBeVisible();

    await page.getByTestId('doc-rail-collapse').click();
    await expect(page.getByTestId('doc-side-rail')).toHaveCount(0);

    // The collapsed strip keeps a one-click way back.
    await page.getByTestId('doc-rail-expand').click();
    await expect(page.getByTestId('doc-side-rail')).toBeVisible();
  });

  test('Help ▸ Keyboard shortcuts opens the shortcut reference', async ({ page }) => {
    await page.goto('/admin/documents');
    await chooseMenu(page, 'help', 'shortcuts');
    await expect(page.getByTestId('doc-shortcuts-dialog')).toBeVisible();
  });
});

test.describe('Document designer — requires auth', () => {
  test.use({ storageState: { cookies: [], origins: [] } });

  test('an unauthenticated visitor is redirected to login', async ({ page }) => {
    await page.goto('/admin/documents');
    await expect(page).toHaveURL(/\/login/);
  });
});
