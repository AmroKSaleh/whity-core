import { test } from './support/fixtures';
import { ADMIN, DELEGATE_USER, REGULAR_USER } from './support/constants';

/**
 * Smoke coverage for the WC-173 matrix PATTERN itself, and the reference
 * implementation of the matrix spec contract (see e2e/support/fixtures.ts):
 * this single file runs three times — under `matrix-admin`, `matrix-user` and
 * `matrix-delegate` — and uses the `role` fixture to pick role-dependent
 * expectations.
 *
 * It pins the infrastructure the behavioural matrix specs build on: all three
 * storage states (including the delegation-provisioned third role) exist and
 * carry a live session for the RIGHT account.
 */
const EMAIL_BY_ROLE = {
  admin: ADMIN.email,
  user: REGULAR_USER.email,
  delegate: DELEGATE_USER.email,
} as const;

test.describe('Role matrix smoke', () => {
  test('the project is authenticated as its role account', async ({
    roleSession,
    role,
  }) => {
    await roleSession.shell.expectLoggedInAs(EMAIL_BY_ROLE[role]);
  });
});
