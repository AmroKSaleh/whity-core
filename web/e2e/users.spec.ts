import { test, expect } from './support/fixtures';
import { uniqueSuffix } from './support/constants';
import { deleteUser, findUserIdByEmail } from './support/api';

/**
 * Users list + CRUD against the live backend.
 *
 * Discipline: the seeded admin/user accounts are READ-ONLY. The create/edit/
 * delete flow operates exclusively on a fresh, uniquely-named user that is
 * removed in afterEach via the API.
 *
 * NOTE: the users list API derives `name` from the email local-part (there is
 * no users.name column) and exposes a camelCase `tenantId` (WC-100), so the
 * table's "Name" column and the record page state the same derived value.
 * Rows are still located by email for stability.
 *
 * #882 moved where editing happens: the list's Edit action now opens
 * `/admin/users/{id}` — the user RECORD page — instead of a dialog. The edit
 * MODAL is untouched and still mounted (one constant flips the list back to it),
 * so what changed here is which surface these specs drive, not whether the modal
 * works. Every row also carries a SECOND button now — the person's name, which
 * opens their record — so the actions trigger is addressed by its accessible
 * name rather than as "the button in this row".
 *
 * Role choice: the edit flows switch between the seeded base roles `user` and
 * `admin` (both exist in the demo seed and resolve server-side). The role
 * dropdown is now driven from the live `GET /api/roles` list (WC-121), so it
 * only ever offers roles that actually exist for the tenant — the old static
 * "Moderator" option (which had no backing seed role and 404'd on submit) is
 * gone.
 */
test.describe('Users (admin)', () => {
  let createdEmail: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdEmail) {
      const id = await findUserIdByEmail(adminApi, createdEmail);
      if (id !== null) {
        await deleteUser(adminApi, id);
      }
      createdEmail = null;
    }
  });

  test('seeded users are listed by email', async ({ adminPage, page }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');
    // Intentionally UNNAMED. Strict mode applies to the final locator, and the
    // chained cell query resolves in exactly one of the page's two tables, so
    // this is unambiguous as written.
    //
    // Naming it `{ name: 'Users' }` was tried and REVERTED: it made this test
    // fail with "element(s) not found" while the identical named locator kept
    // working in navigation.spec. The cause was never established, so the
    // hardening bought nothing and cost a green test.
    const table = page.getByRole('table');
    await expect(table.getByRole('cell', { name: 'admin@example.com' })).toBeVisible();
    await expect(table.getByRole('cell', { name: 'user@example.com' })).toBeVisible();
  });

  test('create, edit and delete a user', async ({ adminPage, page }) => {
    const suffix = uniqueSuffix();
    createdEmail = `e2e-user-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    // --- Create (role: User) ---
    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    // WC-168: the form carries only the fields the API reads (email, password,
    // role) — name is server-derived from the email and tenant comes from the
    // caller's tenant context.
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password', { exact: true }).fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();

    await expect(page.getByText('User created successfully')).toBeVisible();
    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    // The new user starts in the `user` role.
    await expect(row.getByRole('cell', { name: 'user', exact: true })).toBeVisible();

    // --- Edit on the RECORD PAGE (change role user -> admin), persisted ---
    await row.getByRole('button', { name: 'Row actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();

    // The record has an ADDRESS now — that is the whole point of the page, so
    // the URL is asserted rather than only the content.
    await page.waitForURL(/\/admin\/users\/\d+$/);
    await expect(page.getByTestId('user-record')).toBeVisible();
    await expect(page.getByRole('dialog')).toHaveCount(0);

    await page.getByTestId('user-record-role').click();
    await page.getByRole('option', { name: 'admin', exact: true }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

    // WC-113: the change must ACTUALLY persist, not just toast. Back on the
    // list, the row's Role cell reflects `admin`...
    await page.getByRole('button', { name: 'Back to users' }).click();
    await page.waitForURL('**/admin/users');
    const adminRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(adminRow.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

    // ...and re-opening the record shows the new role selected (proves it was
    // read back from the persisted record, not from stale client state).
    await adminRow.getByRole('button', { name: 'Row actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await page.waitForURL(/\/admin\/users\/\d+$/);
    await expect(page.getByTestId('user-record-role')).toContainText('admin');
    await page.getByRole('button', { name: 'Back to users' }).click();
    await page.waitForURL('**/admin/users');

    // --- Delete (still a modal, deliberately — #884 decides per screen) ---
    const updatedRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await updatedRow.getByRole('button', { name: 'Row actions' }).click();
    await page.getByRole('menuitem', { name: 'Delete' }).click();
    const deleteDialog = page.getByRole('dialog');
    await expect(deleteDialog.getByRole('heading', { name: 'Delete User' })).toBeVisible();
    await deleteDialog.getByRole('button', { name: 'Delete User' }).click();

    await expect(page.getByText('User deleted successfully')).toBeVisible();
    await expect(page.getByRole('row', { name: new RegExp(createdEmail) })).toHaveCount(0);

    createdEmail = null; // already deleted; skip afterEach cleanup
  });

  test('role change persists across a full page reload', async ({ adminPage, page, adminApi }) => {
    const suffix = uniqueSuffix();
    createdEmail = `e2e-role-persist-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    // Seed a user in the `user` role through the API used by the app proxy.
    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password', { exact: true }).fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();

    // Change role user -> admin on the record page and save.
    await row.getByRole('button', { name: 'Row actions' }).click();
    await page.getByRole('menuitem', { name: 'Edit' }).click();
    await page.waitForURL(/\/admin\/users\/\d+$/);
    await page.getByTestId('user-record-role').click();
    await page.getByRole('option', { name: 'admin', exact: true }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

    // A record page is DEEP-LINKABLE, so reloading the record's OWN url has to
    // rebuild it from the server rather than depend on how it was reached.
    await page.reload();
    await expect(page.getByTestId('user-record')).toBeVisible();
    await expect(page.getByTestId('user-record-stat-role')).toContainText('admin');

    // Hard reload of the LIST: the table re-fetches from GET /api/users, so a
    // persisted change is the only way the row can still show `admin` (WC-113).
    await page.goto('/admin/users');
    await page.waitForURL('**/admin/users');
    const reloadedRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(reloadedRow.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

    // Independent confirmation straight from the API.
    const id = await findUserIdByEmail(adminApi, createdEmail);
    expect(id).not.toBeNull();
    const res = await adminApi.get('/api/v1/users');
    const body = (await res.json()) as { data?: Array<{ email: string; role: string }> };
    const persisted = (body.data ?? []).find((u) => u.email === createdEmail);
    expect(persisted?.role).toBe('admin');
  });

  // WC-121: creating a user with a chosen role must persist THAT role, not
  // silently default to `user`. Previously the create handler read only
  // `role_id` and ignored the submitted role NAME, so a user created as "admin"
  // was created as "user". This test creates with role=Admin and asserts the
  // persisted role is `admin` via BOTH the refreshed table and the list API.
  test('creating a user with a specific role persists that role', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    const suffix = uniqueSuffix();
    createdEmail = `e2e-create-admin-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password', { exact: true }).fill('e2e-password-123');
    // Pick the non-default `admin` role at creation time.
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'Admin' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();

    await expect(page.getByText('User created successfully')).toBeVisible();

    // The refreshed table shows the new user already in the `admin` role — NOT
    // downgraded to `user` (the WC-121 defect). The assertion is exact so a
    // stale "user" cell cannot satisfy it.
    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    await expect(row.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();

    // Independent confirmation straight from the list API: the persisted role is
    // exactly what was chosen at creation.
    const res = await adminApi.get('/api/v1/users');
    const body = (await res.json()) as { data?: Array<{ email: string; role: string }> };
    const persisted = (body.data ?? []).find((u) => u.email === createdEmail);
    expect(persisted?.role).toBe('admin');
  });
});

test.describe('User record pre-fill (WC-100)', () => {
  let createdEmail: string | null = null;

  test.afterEach(async ({ adminApi }) => {
    if (createdEmail) {
      const id = await findUserIdByEmail(adminApi, createdEmail);
      if (id !== null) {
        await deleteUser(adminApi, id);
      }
      createdEmail = null;
    }
  });

  // WC-100: `name` is derived from the email local-part (there is no users.name
  // column) and is not editable server-side (WC-113). #882: the record page
  // therefore STATES it with the reason rather than showing a disabled input —
  // a greyed-out box invites the reader to look for the permission that would
  // ungrey it. The tenant is stated too, by NAME, in the memberships panel.
  test('the record states the derived name and saves after a role change', async ({
    adminPage,
    page,
  }) => {
    const suffix = uniqueSuffix();
    // The probe's email local-part is what the API surfaces as `name`.
    const localPart = `e2e-user-prefill-${suffix}`;
    createdEmail = `${localPart}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Email').fill(createdEmail);
    await createDialog.getByLabel('Password', { exact: true }).fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    const row = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(row).toBeVisible();
    // The row's own NAME opens the record — the affordance a list gets once its
    // records have addresses.
    await row.getByRole('button', { name: localPart }).click();
    await page.waitForURL(/\/admin\/users\/\d+$/);
    await expect(page.getByTestId('user-record')).toBeVisible();

    // The record titles itself with the derived name and carries the tenant it
    // belongs to, by name, beside the form.
    await expect(page.getByRole('heading', { name: localPart })).toBeVisible();
    await expect(page.getByTestId('user-record-memberships')).toBeVisible();

    // Change the role (user -> admin) and Save: it must succeed and persist.
    await page.getByTestId('user-record-role').click();
    await page.getByRole('option', { name: 'admin', exact: true }).click();
    await page.getByRole('button', { name: 'Save changes' }).click();
    await expect(page.getByText('User updated successfully')).toBeVisible();

    await page.getByRole('button', { name: 'Back to users' }).click();
    await page.waitForURL('**/admin/users');
    const updatedRow = page.getByRole('row', { name: new RegExp(createdEmail) });
    await expect(updatedRow.getByRole('cell', { name: 'admin', exact: true })).toBeVisible();
  });
});

/**
 * Create User form: client-side validation and dialog lifecycle.
 *
 * The create form is zod-validated (valid email, password >= 8, role required —
 * WC-168 removed the dead Name/Tenant inputs the API never read). These tests
 * assert the field-level errors surface and that no user is created, so they
 * never touch the database.
 */
test.describe('Create User validation + dialog (admin)', () => {
  test('shows validation errors and does not submit when fields are empty/invalid', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New User')).toBeVisible();

    // Submit fully empty: email/password/role errors appear.
    await dialog.getByRole('button', { name: 'Create User' }).click();
    await expect(dialog.getByText('Invalid email address')).toBeVisible();
    await expect(dialog.getByText('Password must be at least 8 characters')).toBeVisible();
    await expect(dialog.getByText('Role is required')).toBeVisible();

    // Invalid email + too-short password keep their field errors after another
    // submit attempt.
    await dialog.getByLabel('Email').fill('not-an-email');
    await dialog.getByLabel('Password', { exact: true }).fill('short');
    await dialog.getByRole('button', { name: 'Create User' }).click();
    await expect(dialog.getByText('Invalid email address')).toBeVisible();
    await expect(dialog.getByText('Password must be at least 8 characters')).toBeVisible();

    // Nothing was created.
    await expect(page.getByText('User created successfully')).toHaveCount(0);
    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
  });

  test('create dialog closes on Escape without creating a user', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const dialog = page.getByRole('dialog');
    await expect(dialog.getByText('Create New User')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();
  });
});

/**
 * WC-121: the phantom "Moderator" dropdown option is GONE.
 *
 * The role dropdown used to offer a static third option, "Moderator", that had
 * NO backing role in the seed; once the backend began validating role NAMES
 * (WC-113) selecting it 404'd. WC-121 drives the dropdown from the live
 * `GET /api/roles` list, so it now only offers roles that actually exist for the
 * tenant (`User` and `Admin` in the demo seed). This was a `test.fixme` for the
 * known bug; it is now a real, passing assertion that the dropdown contains the
 * seeded roles and NOT "Moderator".
 */
test.describe('Users role dropdown is driven from real roles (WC-121)', () => {
  test('the create dialog offers only the seeded roles and no phantom Moderator', async ({
    adminPage,
    page,
  }) => {
    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await expect(createDialog.getByText('Create New User')).toBeVisible();

    await createDialog.getByRole('combobox').click();

    // The real seeded roles are offered...
    await expect(page.getByRole('option', { name: 'User' })).toBeVisible();
    await expect(page.getByRole('option', { name: 'Admin' })).toBeVisible();
    // ...and the phantom "Moderator" option is no longer present.
    await expect(page.getByRole('option', { name: 'Moderator' })).toHaveCount(0);

    await page.keyboard.press('Escape'); // close the listbox
    await createDialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(createDialog).toBeHidden();
  });

  test('the record page offers only the seeded roles and no phantom Moderator', async ({
    adminPage,
    page,
    adminApi,
  }) => {
    // The record page needs an existing row; open it on a throwaway user and
    // clean up via the API afterwards.
    const suffix = uniqueSuffix();
    const probeEmail = `e2e-dropdown-${suffix}@example.com`;

    await adminPage.shell.clickNav('Users');
    await page.waitForURL('**/admin/users');

    await page.getByRole('button', { name: 'Create User' }).click();
    const createDialog = page.getByRole('dialog');
    await createDialog.getByLabel('Email').fill(probeEmail);
    await createDialog.getByLabel('Password', { exact: true }).fill('e2e-password-123');
    await createDialog.getByRole('combobox').click();
    await page.getByRole('option', { name: 'User' }).click();
    await createDialog.getByRole('button', { name: 'Create User' }).click();
    await expect(page.getByText('User created successfully')).toBeVisible();

    try {
      const row = page.getByRole('row', { name: new RegExp(probeEmail) });
      await row.getByRole('button', { name: 'Row actions' }).click();
      await page.getByRole('menuitem', { name: 'Edit' }).click();
      await page.waitForURL(/\/admin\/users\/\d+$/);
      await expect(page.getByTestId('user-record')).toBeVisible();

      // Role names are tenant DATA, not source strings, so the record page
      // renders them verbatim (the same rule permission slugs follow) rather
      // than title-cased the way the create dialog does.
      await page.getByTestId('user-record-role').click();
      await expect(page.getByRole('option', { name: 'user', exact: true })).toBeVisible();
      await expect(page.getByRole('option', { name: 'admin', exact: true })).toBeVisible();
      await expect(page.getByRole('option', { name: 'moderator', exact: true })).toHaveCount(0);
    } finally {
      const id = await findUserIdByEmail(adminApi, probeEmail);
      if (id !== null) await deleteUser(adminApi, id);
    }
  });
});
