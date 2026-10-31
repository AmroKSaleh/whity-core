import { test, expect } from './support/fixtures';

/**
 * The front door: issuing a document from the library (#947 item 1, editor M4c).
 *
 * WHAT THIS FILE PROVES, AND WHERE IT STOPS
 * -----------------------------------------
 * It walks the real journey — Documents → New document → a chooser filled from
 * the tenant's REAL published editor templates → the editor in issuing mode on
 * that template — and then stops at the Issue button.
 *
 * The stop is not laziness. There is no `DELETE /api/documents/{id}`: a document
 * is an immutable record on purpose, so a spec that created one would leave a
 * row in the shared database on every run, forever, which is the one thing
 * `web/README.md`'s shared-DB discipline forbids outright.
 *
 * So the create ITSELF is proven where it can be proven and undone: the server's
 * API tests drive `POST /api/v1/documents` against a real schema, and
 * `web/__tests__/document-editor-screen.test.tsx` pins what issuing sends.
 *
 * WHY THE STOPPING POINT IS STILL WORTH A BROWSER
 * -----------------------------------------------
 * Everything up to the submit is what a unit test cannot see: that the button
 * is reachable through the real nav for a real signed-in role, that the picker
 * is populated by a live `GET /api/v1/document-sources?kind=template` (published
 * templates only), and that choosing one opens the editor's issuing mode on it.
 */

const LIBRARY = '/admin/document-library';

test.describe('issuing a document', () => {
  test('the library offers New document, and the chooser lists published templates', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);

    // `admin` holds `documents:issue`, the capability the create route requires.
    const newDocument = page.getByRole('button', { name: 'New document' });
    await expect(newDocument).toBeVisible();
    await newDocument.click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('New document')).toBeVisible();

    // Either a list of templates, or the stated reason there is none. A stack
    // with no published template is legitimate (a template is published in the
    // editor), so it is a reported skip.
    const choices = dialog.getByRole('listitem');
    const none = dialog.getByText('No templates available');
    await expect(choices.first().or(none)).toBeVisible();
    test.skip(await none.isVisible(), 'no published editor template in this stack');
    expect(await choices.count()).toBeGreaterThan(0);

    // Nothing was created: the dialog is abandoned.
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(new URL(page.url()).pathname).toBe(LIBRARY);
  });

  test('choosing a template opens it in the editor to issue, without issuing anything', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Documents');
    await page.waitForURL(`**${LIBRARY}`);
    await page.getByRole('button', { name: 'New document' }).click();

    const dialog = page.getByRole('dialog');
    const choices = dialog.getByRole('listitem');
    const none = dialog.getByText('No templates available');
    await expect(choices.first().or(none)).toBeVisible();
    test.skip(await none.isVisible(), 'no published editor template in this stack');

    await choices.first().getByRole('button').click();

    // Issuing mode: the editor on the template, with the issuing bar.
    await page.waitForURL(/\/admin\/document-sources\/\d+\?issue=1$/);
    await expect(page.locator('[data-slot="issue-bar"]')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Issue', exact: true })).toBeVisible();
    // Publish is not offered while issuing.
    await expect(page.getByRole('button', { name: 'Publish' })).toHaveCount(0);

    // A template bound to a record type asks for the record first; leave it.
    const picker = page.getByRole('dialog');
    if (await picker.isVisible()) await page.keyboard.press('Escape');

    // Nothing is issued: there is no DELETE for a document, so this stops here.
    await page.getByRole('button', { name: 'Back' }).click();
    await page.waitForURL(`**${LIBRARY}`);
  });
});
