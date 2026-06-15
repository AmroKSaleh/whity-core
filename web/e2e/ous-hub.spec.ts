import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import { deleteOu, findOuIdByName, listOus } from './support/api';
import type { APIRequestContext } from '@playwright/test';

/**
 * OU Management Hub (WC-44) E2E against the live stack.
 *
 * Covers the tree + graph views and the detail drawer (roles add/remove,
 * members list, move-to-parent picker, create-child, rename, delete guard).
 *
 * Shared-DB discipline:
 *  - Every OU is created with a unique, attributable name and removed via the
 *    API in afterEach (children first, then parents — the backend blocks
 *    deleting a non-empty OU).
 *  - The suite never mutates seeded OUs/tenants destructively.
 */

/** Create an OU through the API and return its id. */
async function createOu(
  api: APIRequestContext,
  name: string,
  parentId?: number
): Promise<number> {
  const res = await api.post('/api/v1/v1/ous', {
    data: parentId ? { name, parent_id: parentId } : { name },
  });
  expect(res.ok(), `create OU ${name} should succeed`).toBeTruthy();
  return ((await res.json()) as { data: { id: number } }).data.id;
}

test.describe('OU Management Hub (admin)', () => {
  // Track created OU ids (children appended after parents so we can delete in
  // reverse — leaves first) for best-effort cleanup.
  let created: number[] = [];

  test.afterEach(async ({ adminApi }) => {
    // Delete in reverse creation order so children go before their parents.
    for (const id of [...created].reverse()) {
      await deleteOu(adminApi, id);
    }
    created = [];
  });

  async function gotoHub(adminPage: { shell: { clickNav: (l: string) => Promise<void> } }, page: import('@playwright/test').Page) {
    await adminPage.shell.clickNav('Organizational Units');
    await page.waitForURL('**/admin/ous');
    await expect(page.getByRole('heading', { name: 'Organizational Units' }).first()).toBeVisible();
    // The hub renders a Tree | Graph toggle.
    await expect(page.getByRole('group', { name: 'View mode' })).toBeVisible();
  }

  test('tree renders a seeded nested hierarchy and expand/collapse works', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const root = `e2e-hub-root-${suffix}`;
    const child = `e2e-hub-child-${suffix}`;
    const rootId = await createOu(adminApi, root);
    created.push(rootId);
    const childId = await createOu(adminApi, child, rootId);
    created.push(childId);

    await gotoHub(adminPage, page);

    const tree = page.getByRole('tree', { name: 'Organizational unit hierarchy' });
    await expect(tree).toBeVisible();
    // Both the root and (expanded by default) child are visible.
    await expect(tree.getByText(root, { exact: true })).toBeVisible();
    await expect(tree.getByText(child, { exact: true })).toBeVisible();

    // Collapse the root -> the child disappears; expand -> it returns.
    await tree.getByRole('button', { name: `Collapse ${root}` }).click();
    await expect(tree.getByText(child, { exact: true })).toBeHidden();
    await tree.getByRole('button', { name: `Expand ${root}` }).click();
    await expect(tree.getByText(child, { exact: true })).toBeVisible();
  });

  test('create a child OU under a node via the action menu', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const root = `e2e-hub-parent-${suffix}`;
    const childName = `e2e-hub-newchild-${suffix}`;
    const rootId = await createOu(adminApi, root);
    created.push(rootId);

    await gotoHub(adminPage, page);
    const tree = page.getByRole('tree');
    await tree.getByRole('button', { name: `Actions for ${root}` }).click();
    await page.getByRole('menuitem', { name: 'Create child OU' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create Organizational Unit')).toBeVisible();
    await dialog.getByPlaceholder('e.g., Engineering').fill(childName);
    await dialog.getByRole('button', { name: 'Create', exact: true }).click();
    await expect(page.getByText('Organizational unit created successfully')).toBeVisible();

    // The new child appears nested under the root.
    await expect(page.getByRole('tree').getByText(childName, { exact: true })).toBeVisible();

    const newId = await findOuIdByName(adminApi, childName);
    if (newId !== null) created.push(newId);
  });

  test('rename an OU from the action menu', async ({ adminApi, adminPage, page }) => {
    const suffix = uniqueSuffix();
    const name = `e2e-hub-rename-${suffix}`;
    const renamed = `e2e-hub-renamed-${suffix}`;
    const id = await createOu(adminApi, name);
    created.push(id);

    await gotoHub(adminPage, page);
    const tree = page.getByRole('tree');
    await tree.getByRole('button', { name: `Actions for ${name}` }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Edit Organizational Unit' })).toBeVisible();
    const nameField = dialog.getByPlaceholder('e.g., Engineering');
    await expect(nameField).toHaveValue(name);
    await nameField.fill(renamed);
    await dialog.getByRole('button', { name: 'Update' }).click();

    await expect(page.getByText('Organizational unit updated successfully')).toBeVisible();
    await expect(page.getByRole('tree').getByText(renamed, { exact: true })).toBeVisible();
  });

  test('delete guard surfaces an error for a non-empty OU', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const root = `e2e-hub-guard-${suffix}`;
    const child = `e2e-hub-guardchild-${suffix}`;
    const rootId = await createOu(adminApi, root);
    created.push(rootId);
    const childId = await createOu(adminApi, child, rootId);
    created.push(childId);

    await gotoHub(adminPage, page);
    const tree = page.getByRole('tree');
    await tree.getByRole('button', { name: `Actions for ${root}` }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();

    const dialog = page.getByRole('dialog');
    await expect(dialog.getByRole('heading', { name: 'Delete Organizational Unit' })).toBeVisible();
    await dialog.getByRole('button', { name: /Delete/ }).click();

    // The backend rejects deleting an OU with children (409); the UI surfaces an
    // error toast. The delete dialog stays open (Radix marks the background
    // aria-hidden), so close it before asserting the OU is still in the tree.
    await expect(page.getByText(/Cannot delete organizational unit with .* child/)).toBeVisible();
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('tree').getByText(root, { exact: true })).toBeVisible();
  });

  test('move-to picker omits the OU itself and its descendants', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const root = `e2e-hub-mvroot-${suffix}`;
    const child = `e2e-hub-mvchild-${suffix}`;
    const sibling = `e2e-hub-mvsibling-${suffix}`;
    const rootId = await createOu(adminApi, root);
    created.push(rootId);
    const childId = await createOu(adminApi, child, rootId);
    created.push(childId);
    const siblingId = await createOu(adminApi, sibling);
    created.push(siblingId);

    await gotoHub(adminPage, page);
    const tree = page.getByRole('tree');

    // Open the move (edit) dialog for the root.
    await tree.getByRole('button', { name: `Actions for ${root}` }).click();
    await page.getByRole('menuitem', { name: /Move to/ }).click();

    const dialog = page.getByRole('dialog');
    await dialog.getByRole('combobox', { name: 'Move to parent' }).click();
    const listbox = page.getByRole('listbox');

    // The sibling is a valid target; the root itself and its child are excluded.
    await expect(listbox.getByRole('option', { name: sibling })).toBeVisible();
    await expect(listbox.getByRole('option', { name: root, exact: true })).toHaveCount(0);
    await expect(listbox.getByRole('option', { name: child, exact: true })).toHaveCount(0);

    // Perform the move under the sibling and confirm it persists.
    await listbox.getByRole('option', { name: sibling }).click();
    await dialog.getByRole('button', { name: 'Update' }).click();
    await expect(page.getByText('Organizational unit updated successfully')).toBeVisible();

    const ous = (await listOus(adminApi)) as Array<{ id: number; name: string }>;
    expect(ous.some((o) => o.name === root)).toBeTruthy();
  });

  test('switch to the graph view and select a node to open the drawer', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const name = `e2e-hub-gv-${suffix}`;
    const id = await createOu(adminApi, name);
    created.push(id);

    await gotoHub(adminPage, page);
    await page.getByRole('button', { name: 'Graph', exact: true }).click();

    // The react-flow canvas renders (lazy-loaded) with a node for the OU.
    const graph = page.getByTestId('ou-graph');
    await expect(graph).toBeVisible();
    const node = graph.getByText(name, { exact: true });
    await expect(node).toBeVisible();

    // Selecting the node opens the detail drawer for that OU.
    await node.click();
    const drawer = page.getByRole('dialog');
    await expect(drawer.getByRole('heading', { name })).toBeVisible();
    await expect(drawer.getByRole('heading', { name: 'Roles', exact: true })).toBeVisible();
    await expect(drawer.getByRole('heading', { name: 'Members', exact: true })).toBeVisible();
  });

  test('assign a role to an OU in the drawer, see it listed, then remove it', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const name = `e2e-hub-roles-${suffix}`;
    const id = await createOu(adminApi, name);
    created.push(id);

    await gotoHub(adminPage, page);
    const tree = page.getByRole('tree');
    // Select the OU to open the drawer (the select button's name is exactly the
    // OU name; the action button is "Actions for {name}", so use exact match).
    await tree.getByRole('button', { name, exact: true }).click();

    const drawer = page.getByRole('dialog');
    await expect(drawer.getByRole('heading', { name })).toBeVisible();
    await expect(drawer.getByText('No roles assigned to this OU.')).toBeVisible();

    // Pick a role and assign it.
    await drawer.getByRole('combobox', { name: 'Select a role to assign' }).click();
    await page.getByRole('listbox').getByRole('option', { name: 'admin' }).click();
    await drawer.getByRole('button', { name: 'Assign' }).click();

    await expect(page.getByText('Role assigned')).toBeVisible();
    // The assigned role is now listed with a remove control.
    const removeBtn = drawer.getByRole('button', { name: 'Remove role admin' });
    await expect(removeBtn).toBeVisible();

    // Remove it and confirm the empty state returns.
    await removeBtn.click();
    await expect(page.getByText('Role removed')).toBeVisible();
    await expect(drawer.getByText('No roles assigned to this OU.')).toBeVisible();
  });

  test('members section renders (read-only) for a selected OU', async ({
    adminApi,
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    const name = `e2e-hub-members-${suffix}`;
    const id = await createOu(adminApi, name);
    created.push(id);

    await gotoHub(adminPage, page);
    await page.getByRole('tree').getByRole('button', { name, exact: true }).click();

    const drawer = page.getByRole('dialog');
    await expect(drawer.getByRole('heading', { name: 'Members', exact: true })).toBeVisible();
    // A freshly created OU has no members; the read-only note is shown.
    await expect(drawer.getByText('No users are assigned to this OU.')).toBeVisible();
    await expect(
      drawer.getByText(/Members are read-only here/)
    ).toBeVisible();
  });
});
