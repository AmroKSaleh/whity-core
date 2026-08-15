import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const mockApiGet = jest.fn();
const mockApiPost = jest.fn();
const mockApiDelete = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: (...args: unknown[]) => mockApiPost(...args),
    DELETE: (...args: unknown[]) => mockApiDelete(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

// The acting administrator's tenant decides the whole shape of this modal, so
// it is a per-test knob rather than a fixed mock.
let actingTenantId = 0;
const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, email: 'ops@example.com', role: 'admin', tenant_id: actingTenantId },
    apiClient: mockApiClient,
  }),
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

jest.mock('@amroksaleh/ui/badge', () => ({
  Badge: ({ children }: React.PropsWithChildren) => <span>{children}</span>,
}));

// Context shared between Select / SelectContent / SelectItem so the native
// <select> can be built from the SelectItem children (mirrors the OU picker
// test — the real Radix select is not driveable in jsdom).
const SelectContext = React.createContext<{
  value?: string;
  onValueChange?: (v: string) => void;
  registerOption: (value: string, label: string) => void;
} | null>(null);

function MockSelect({
  children,
  onValueChange,
  value,
}: React.PropsWithChildren<{
  onValueChange?: (v: string) => void;
  value?: string;
}>) {
  const [options, setOptions] = React.useState<Array<{ value: string; label: string }>>([]);
  const registerOption = React.useCallback((v: string, label: string) => {
    setOptions((prev) => {
      if (prev.some((o) => o.value === v)) return prev;
      return [...prev, { value: v, label }];
    });
  }, []);

  return (
    <SelectContext.Provider value={{ value, onValueChange, registerOption }}>
      <div style={{ display: 'none' }}>{children}</div>
      <select
        data-testid="select"
        value={value ?? ''}
        onChange={(e) => onValueChange?.(e.target.value)}
      >
        <option value="" />
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
    if (ctx) {
      ctx.registerOption(value, String(children));
    }
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
import { MembershipsModal } from '@/app/(protected)/admin/users/memberships-modal';
import type { User } from '@/app/(protected)/admin/users/page';

const mockUser: User = {
  id: 42,
  name: 'alice',
  email: 'alice@example.com',
  role: 'user',
  tenantId: 1,
  ou_id: null,
  createdAt: '2025-01-01T00:00:00Z',
};

const membershipsResponse = {
  data: {
    data: [
      {
        id: 11,
        tenantId: 1,
        tenantName: 'Acme',
        roleId: 2,
        role: 'user',
        ou_id: null,
        isPrimary: true,
        status: 'active',
      },
      {
        id: 12,
        tenantId: 2,
        tenantName: 'Globex',
        roleId: 1,
        role: 'admin',
        ou_id: null,
        isPrimary: false,
        status: 'active',
      },
    ],
  },
  error: undefined,
};

beforeEach(() => {
  jest.clearAllMocks();
  actingTenantId = 0;

  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/users/{id}/memberships') return Promise.resolve(membershipsResponse);
    if (path === '/api/v1/roles') {
      return Promise.resolve({ data: { data: [{ name: 'user' }, { name: 'admin' }] }, error: undefined });
    }
    return Promise.resolve({ data: undefined, error: 'unknown path' });
  });

  mockApiClient.mockResolvedValue({
    ok: true,
    json: async () => ({ data: [{ id: 1, name: 'Acme' }, { id: 2, name: 'Globex' }] }),
  });

  mockApiPost.mockResolvedValue({
    data: { data: { id: 13, tenantId: 2, roleId: 2, isPrimary: false, created: true } },
    error: undefined,
    response: { ok: true },
  });
});

function renderModal(canManage = true) {
  render(
    <MembershipsModal
      isOpen
      onOpenChange={jest.fn()}
      user={mockUser}
      canManage={canManage}
    />
  );
}

describe('MembershipsModal', () => {
  it('lists every tenant the profile belongs to', async () => {
    renderModal();

    // The question that had no surface at all before #797 §2.
    expect(await screen.findByText('Acme')).toBeInTheDocument();
    expect(screen.getByText('Globex')).toBeInTheDocument();
  });

  it('sends tenant_id when a system administrator picks a target tenant', async () => {
    renderModal();
    await screen.findByText('Acme');

    // Two pickers for a system administrator: tenant first, then role. Both
    // are populated by their own fetch, so wait for the options rather than
    // the element — a change event naming an option that has not arrived is
    // silently dropped by jsdom.
    const selects = await waitFor(() => {
      const found = screen.getAllByTestId('select');
      expect(found).toHaveLength(2);
      expect(found[1].querySelectorAll('option')).toHaveLength(3);
      return found;
    });

    fireEvent.change(selects[0], { target: { value: '2' } });
    fireEvent.change(selects[1], { target: { value: 'user' } });
    fireEvent.click(screen.getByText('Grant'));

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled());
    expect(mockApiPost.mock.calls[0][1].body).toEqual({ role: 'user', tenant_id: 2 });
  });

  it('never sends tenant_id for a tenant administrator', async () => {
    actingTenantId = 1;
    renderModal();
    await screen.findByText('Acme');

    // One picker only: the target tenant is not this administrator's to choose,
    // and the server answers 403 to anyone who names one.
    const roleSelect = await waitFor(() => {
      const found = screen.getAllByTestId('select');
      expect(found).toHaveLength(1);
      expect(found[0].querySelectorAll('option')).toHaveLength(3);
      return found[0];
    });

    fireEvent.change(roleSelect, { target: { value: 'admin' } });
    fireEvent.click(screen.getByText('Grant'));

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled());
    expect(mockApiPost.mock.calls[0][1].body).toEqual({ role: 'admin' });
  });

  it('will not let a system administrator grant without naming a tenant', async () => {
    renderModal();
    await screen.findByText('Acme');

    // Omitting tenant_id means "the caller's tenant", which for tenant 0
    // resolves to whichever membership the server reaches first — a grant
    // landing somewhere nobody chose. The button stays disabled instead.
    const selects = await waitFor(() => {
      const found = screen.getAllByTestId('select');
      expect(found).toHaveLength(2);
      expect(found[1].querySelectorAll('option')).toHaveLength(3);
      return found;
    });

    fireEvent.change(selects[1], { target: { value: 'user' } });
    expect(screen.getByText('Grant')).toBeDisabled();
  });

  it('offers Revoke only on the non-primary membership', async () => {
    renderModal();
    await screen.findByText('Acme');

    // The primary row is the person's presence in that tenant; revoking it here
    // would leave them with secondary roles and no answer to "what are they".
    expect(screen.getAllByText('Revoke')).toHaveLength(1);
  });

  it('hides the grant and revoke controls without users:write', async () => {
    renderModal(false);
    await screen.findByText('Acme');

    expect(screen.queryByText('Grant')).not.toBeInTheDocument();
    expect(screen.queryByText('Revoke')).not.toBeInTheDocument();
  });
});
