import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { PermissionButton } from '@/components/rbac/permission-button';
import * as actionPermission from '@/hooks/useActionPermission';
import type { ActionPermission } from '@/hooks/useActionPermission';

/**
 * `<PermissionButton>` renders the HYBRID gating decision from
 * `useActionPermission`:
 *   - allowed    → a normal Button that forwards onClick/props
 *   - disabled   → a disabled Button wrapped so the `reason` shows on hover
 *   - hidden     → nothing at all
 * The hook is mocked so each case is driven directly; the hook's own policy
 * is covered by useActionPermission.test.ts.
 */

jest.mock('@/hooks/useActionPermission', () => ({
  useActionPermission: jest.fn(),
}));

const mockedUseActionPermission =
  actionPermission.useActionPermission as jest.MockedFunction<
    typeof actionPermission.useActionPermission
  >;

function setDecision(decision: ActionPermission): void {
  mockedUseActionPermission.mockReturnValue(decision);
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('PermissionButton', () => {
  it('renders a normal, enabled button and forwards onClick when allowed', async () => {
    setDecision({ allowed: true, hidden: false, disabled: false, reason: null });
    const onClick = jest.fn();
    const user = userEvent.setup();

    render(
      <PermissionButton permission="users:write" onClick={onClick}>
        Save
      </PermissionButton>
    );

    const button = screen.getByRole('button', { name: 'Save' });
    expect(button).toBeEnabled();

    await user.click(button);
    expect(onClick).toHaveBeenCalledTimes(1);
  });

  it('forwards arbitrary button props (type, data-*) when allowed', () => {
    setDecision({ allowed: true, hidden: false, disabled: false, reason: null });

    render(
      <PermissionButton permission="users:write" type="submit" data-testid="save-btn">
        Save
      </PermissionButton>
    );

    const button = screen.getByTestId('save-btn');
    expect(button).toHaveAttribute('type', 'submit');
  });

  it('renders a disabled button with the reason exposed on a wrapper (non-destructive, lacks permission)', async () => {
    setDecision({
      allowed: false,
      hidden: false,
      disabled: true,
      reason: 'Requires users:write',
    });
    const onClick = jest.fn();
    const user = userEvent.setup();

    render(
      <PermissionButton permission="users:write" onClick={onClick}>
        Save
      </PermissionButton>
    );

    const button = screen.getByRole('button', { name: 'Save' });
    expect(button).toBeDisabled();

    // The reason is surfaced (tooltip text / title) so a user understands why.
    expect(screen.getByText('Requires users:write')).toBeInTheDocument();

    // A disabled control must not invoke its handler even if a click slips through.
    await user.click(button);
    expect(onClick).not.toHaveBeenCalled();
  });

  it('does not invoke onClick when disabled even via direct dispatch', () => {
    setDecision({
      allowed: false,
      hidden: false,
      disabled: true,
      reason: 'Requires users:write',
    });
    const onClick = jest.fn();

    render(
      <PermissionButton permission="users:write" onClick={onClick}>
        Save
      </PermissionButton>
    );

    const button = screen.getByRole('button', { name: 'Save' });
    button.click();
    expect(onClick).not.toHaveBeenCalled();
  });

  it('renders nothing when the decision is hidden (destructive, lacks permission)', () => {
    setDecision({
      allowed: false,
      hidden: true,
      disabled: false,
      reason: 'Requires users:delete',
    });

    const { container } = render(
      <PermissionButton permission="users:delete" destructive>
        Delete
      </PermissionButton>
    );

    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(container).toBeEmptyDOMElement();
  });

  it('passes permission + destructive through to the hook', () => {
    setDecision({
      allowed: false,
      hidden: true,
      disabled: false,
      reason: 'Requires users:delete',
    });

    render(
      <PermissionButton permission="users:delete" destructive>
        Delete
      </PermissionButton>
    );

    expect(mockedUseActionPermission).toHaveBeenCalledWith('users:delete', {
      destructive: true,
    });
  });
});
