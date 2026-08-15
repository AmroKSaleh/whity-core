import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();
const mockApiPatch = jest.fn();
const mockApiDelete = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
    DELETE: (...args: unknown[]) => mockApiDelete(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('@amroksaleh/ui/dialog', () => ({
  Dialog: ({ children, open }: React.PropsWithChildren<{ open?: boolean }>) =>
    open ? <div data-testid="dialog">{children}</div> : null,
  DialogContent: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
  DialogHeader: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
  DialogTitle: ({ children }: React.PropsWithChildren) => <h2>{children}</h2>,
  DialogDescription: ({ children }: React.PropsWithChildren) => <p>{children}</p>,
  DialogFooter: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

jest.mock('@amroksaleh/ui/button', () => ({
  Button: ({
    children,
    variant,
    size,
    ...props
  }: React.ComponentProps<'button'> & { variant?: string; size?: string }) => {
    void variant;
    void size;
    return <button {...props}>{children}</button>;
  },
}));

jest.mock('@amroksaleh/ui/input', () => ({
  Input: (props: React.ComponentProps<'input'>) => <input {...props} />,
}));

// A minimal Select that renders a native <select> built from its SelectItems,
// so a role change is a plain fireEvent.change (mirrors edit-user-modal-ou).
const SelectContext = React.createContext<{
  value?: string;
  onValueChange?: (v: string) => void;
  registerOption: (value: string, label: string) => void;
} | null>(null);

function MockSelect({
  children,
  onValueChange,
  value,
}: React.PropsWithChildren<{ onValueChange?: (v: string) => void; value?: string }>) {
  const [options, setOptions] = React.useState<Array<{ value: string; label: string }>>([]);
  const registerOption = React.useCallback((v: string, label: string) => {
    setOptions((prev) => (prev.some((o) => o.value === v) ? prev : [...prev, { value: v, label }]));
  }, []);

  return (
    <SelectContext.Provider value={{ value, onValueChange, registerOption }}>
      <div style={{ display: 'none' }}>{children}</div>
      <select
        data-testid="select"
        value={value ?? ''}
        onChange={(e) => onValueChange?.(e.target.value)}
      >
        {options.map((o) => (
          <option key={o.value} value={o.value}>
            {o.label}
          </option>
        ))}
      </select>
    </SelectContext.Provider>
  );
}

function MockSelectItem({ children, value }: React.PropsWithChildren<{ value: string }>) {
  const ctx = React.useContext(SelectContext);
  React.useEffect(() => {
    ctx?.registerOption(value, String(children));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, children]);
  return null;
}

jest.mock('@amroksaleh/ui/select', () => ({
  Select: MockSelect,
  SelectTrigger: ({ children }: React.PropsWithChildren) => <>{children}</>,
  SelectValue: ({ placeholder }: { placeholder?: string }) => <span>{placeholder}</span>,
  SelectContent: ({ children }: React.PropsWithChildren) => <>{children}</>,
  SelectItem: MockSelectItem,
}));

// ---------------------------------------------------------------------------
// Imports under test (after all mocks)
// ---------------------------------------------------------------------------
import { EditUserModal } from '@/app/(protected)/admin/users/edit-modal';
import { DeleteUserModal } from '@/app/(protected)/admin/users/delete-modal';
import type { User } from '@/app/(protected)/admin/users/page';

const COVERAGE_PATH = '/api/v1/password-resets/approver-coverage';

const soleApprover: User = {
  id: 42,
  name: 'alice',
  email: 'alice@example.com',
  role: 'admin',
  tenantId: 1,
  ou_id: null,
  createdAt: '2025-01-01T00:00:00Z',
};

/** Tenant with exactly one approver — the state §4a exists to warn about. */
const strandableCoverage = {
  data: {
    data: {
      tenant_id: 1,
      minimum_recommended: 2,
      approval_required: true,
      approver_count: 1,
      approver_profile_ids: [42],
      approver_role_names: ['admin'],
      below_minimum: true,
    },
  },
  error: undefined,
};

const healthyCoverage = {
  data: {
    data: {
      ...strandableCoverage.data.data,
      approver_count: 3,
      approver_profile_ids: [42, 43, 44],
      below_minimum: false,
    },
  },
  error: undefined,
};

function mockGets(coverage: unknown) {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/roles') {
      return Promise.resolve({ data: { data: [{ name: 'user' }, { name: 'admin' }] }, error: undefined });
    }
    if (path === '/api/v1/ous') return Promise.resolve({ data: { data: [] }, error: undefined });
    if (path === COVERAGE_PATH) return Promise.resolve(coverage);
    return Promise.resolve({ data: undefined, error: 'unknown path' });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockGets(strandableCoverage);
  mockApiPost.mockResolvedValue({ data: { data: { status: 'sent', profile_id: 42 } }, error: undefined, response: { ok: true } });
  mockApiPatch.mockResolvedValue({ data: { data: soleApprover }, error: undefined, response: { ok: true } });
  mockApiDelete.mockResolvedValue({ data: {}, error: undefined, response: { ok: true } });
});

describe('EditUserModal – admin-triggered reset link', () => {
  test('offers a reset link rather than a password field', async () => {
    render(
      <EditUserModal isOpen onOpenChange={jest.fn()} user={soleApprover} onSuccess={jest.fn()} />
    );

    expect(await screen.findByTestId('edit-user-send-reset-link')).toBeInTheDocument();
    // The whole point of the shape: no password input anywhere on this form.
    expect(document.querySelector('input[type="password"]')).toBeNull();
  });

  test('sends the reset link for the edited profile and confirms it', async () => {
    render(
      <EditUserModal isOpen onOpenChange={jest.fn()} user={soleApprover} onSuccess={jest.fn()} />
    );

    fireEvent.click(await screen.findByTestId('edit-user-send-reset-link'));

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/api/v1/users/{id}/password-reset', {
        params: { path: { id: 42 } },
      });
    });
    await waitFor(() => {
      expect(addToast).toHaveBeenCalledWith(expect.stringContaining('alice@example.com'), 'success');
    });
  });

  test('surfaces a failure instead of claiming the link was sent', async () => {
    mockApiPost.mockResolvedValue({
      data: undefined,
      error: { error: 'Password-reset emails are disabled for this instance' },
      response: { ok: false },
    });

    render(
      <EditUserModal isOpen onOpenChange={jest.fn()} user={soleApprover} onSuccess={jest.fn()} />
    );
    fireEvent.click(await screen.findByTestId('edit-user-send-reset-link'));

    await waitFor(() => {
      expect(addToast).toHaveBeenCalledWith(
        'Password-reset emails are disabled for this instance',
        'error'
      );
    });
  });
});

describe('EditUserModal – approver demotion warning', () => {
  test('warns when the sole approver is moved to a role without the permission', async () => {
    render(
      <EditUserModal isOpen onOpenChange={jest.fn()} user={soleApprover} onSuccess={jest.fn()} />
    );

    await waitFor(() => expect(mockApiGet).toHaveBeenCalledWith(COVERAGE_PATH));
    expect(screen.queryByTestId('edit-user-approver-warning')).toBeNull();

    const roleSelect = screen.getAllByTestId('select')[0];
    await waitFor(() =>
      expect(Array.from((roleSelect as HTMLSelectElement).options).map((o) => o.value)).toContain('user')
    );
    fireEvent.change(roleSelect, { target: { value: 'user' } });

    expect(await screen.findByTestId('edit-user-approver-warning')).toBeInTheDocument();
  });

  test('stays quiet when the tenant has approvers to spare', async () => {
    mockGets(healthyCoverage);
    render(
      <EditUserModal isOpen onOpenChange={jest.fn()} user={soleApprover} onSuccess={jest.fn()} />
    );

    await waitFor(() => expect(mockApiGet).toHaveBeenCalledWith(COVERAGE_PATH));
    const roleSelect = screen.getAllByTestId('select')[0];
    await waitFor(() =>
      expect(Array.from((roleSelect as HTMLSelectElement).options).map((o) => o.value)).toContain('user')
    );
    fireEvent.change(roleSelect, { target: { value: 'user' } });

    await waitFor(() => expect(screen.getAllByTestId('select')[0]).toBeInTheDocument());
    expect(screen.queryByTestId('edit-user-approver-warning')).toBeNull();
  });
});

describe('DeleteUserModal – approver removal warning', () => {
  test('warns before removing the account that would leave nobody to approve', async () => {
    render(
      <DeleteUserModal isOpen onOpenChange={jest.fn()} user={soleApprover} onSuccess={jest.fn()} />
    );

    expect(await screen.findByTestId('delete-user-approver-warning')).toBeInTheDocument();
  });

  test('does not warn about a profile that cannot approve anything', async () => {
    render(
      <DeleteUserModal
        isOpen
        onOpenChange={jest.fn()}
        user={{ ...soleApprover, id: 99 }}
        onSuccess={jest.fn()}
      />
    );

    await waitFor(() => expect(mockApiGet).toHaveBeenCalledWith(COVERAGE_PATH));
    expect(screen.queryByTestId('delete-user-approver-warning')).toBeNull();
  });
});
