import React from 'react';
import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const mockApiGet = jest.fn();
const mockApiPatch = jest.fn();

jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
  },
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('@/components/ui/dialog', () => ({
  Dialog: ({ children, open }: React.PropsWithChildren<{ open?: boolean }>) =>
    open ? <div data-testid="dialog">{children}</div> : null,
  DialogContent: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
  DialogHeader: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
  DialogTitle: ({ children }: React.PropsWithChildren) => <h2>{children}</h2>,
  DialogDescription: ({ children }: React.PropsWithChildren) => <p>{children}</p>,
  DialogFooter: ({ children }: React.PropsWithChildren) => <div>{children}</div>,
}));

jest.mock('@/components/ui/button', () => ({
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

jest.mock('@/components/ui/input', () => ({
  Input: (props: React.ComponentProps<'input'>) => <input {...props} />,
}));

// Context object shared between Select / SelectContent / SelectItem so the
// native <select> can be built from the SelectItem children.
const SelectContext = React.createContext<{
  value?: string;
  onValueChange?: (v: string) => void;
  registerOption: (value: string, label: string) => void;
} | null>(null);

// The actual <select> is rendered here; SelectContent/SelectItem just register.
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
      {/* Render children so SelectItem registers options */}
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

function MockSelectItem({
  children,
  value,
}: React.PropsWithChildren<{ value: string }>) {
  const ctx = React.useContext(SelectContext);
  React.useEffect(() => {
    if (ctx) {
      ctx.registerOption(value, String(children));
    }
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value, children]);
  return null;
}

jest.mock('@/components/ui/select', () => ({
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
import type { User } from '@/app/(protected)/admin/users/page';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
const mockUser: User = {
  id: 42,
  name: 'alice',
  email: 'alice@example.com',
  role: 'user',
  tenantId: 1,
  ou_id: null,
  createdAt: '2025-01-01T00:00:00Z',
};

const mockUserWithOu: User = {
  ...mockUser,
  ou_id: 7,
};

const mockRolesResponse = {
  data: { data: [{ name: 'user' }, { name: 'admin' }] },
  error: undefined,
};

const mockOusResponse = {
  data: {
    data: [
      { id: 5, name: 'Engineering', tenant_id: 1, parent_id: null, slug: 'engineering', description: null, created_at: null },
      { id: 7, name: 'HR', tenant_id: 1, parent_id: null, slug: 'hr', description: null, created_at: null },
    ],
  },
  error: undefined,
};

const mockPatchSuccess = {
  data: { data: { ...mockUser, role: 'user', ou_id: null } },
  error: undefined,
  response: { ok: true },
};

// ---------------------------------------------------------------------------
// Helper
// ---------------------------------------------------------------------------
function renderModal(user: User = mockUser, isOpen = true) {
  const onOpenChange = jest.fn();
  const onSuccess = jest.fn();
  render(
    <EditUserModal
      isOpen={isOpen}
      onOpenChange={onOpenChange}
      user={user}
      onSuccess={onSuccess}
    />
  );
  return { onOpenChange, onSuccess };
}

// Grab the OU <select> — always the second one (role is first).
function getOuSelect(): HTMLSelectElement {
  const selects = screen.getAllByTestId('select');
  return selects[1] as HTMLSelectElement;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

beforeEach(() => {
  jest.clearAllMocks();

  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/roles') return Promise.resolve(mockRolesResponse);
    if (path === '/api/v1/ous') return Promise.resolve(mockOusResponse);
    return Promise.resolve({ data: undefined, error: 'unknown path' });
  });

  mockApiPatch.mockResolvedValue(mockPatchSuccess);
});

describe('EditUserModal – OU picker', () => {
  test('renders an Organisational Unit label and picker with OU options', async () => {
    renderModal();

    await waitFor(() => {
      expect(screen.getByText('Organisational Unit')).toBeInTheDocument();
    });

    // Wait for OU options to register (async fetch)
    await waitFor(() => {
      const ouSelect = getOuSelect();
      const optionValues = Array.from(ouSelect.options).map((o) => o.value);
      expect(optionValues).toContain('5'); // Engineering
      expect(optionValues).toContain('7'); // HR
      expect(optionValues).toContain('__none__');
    });
  });

  test('pre-fills the picker with the user current ou_id', async () => {
    renderModal(mockUserWithOu);

    await waitFor(() => {
      const ouSelect = getOuSelect();
      expect(ouSelect.value).toBe('7');
    });
  });

  test('selecting a different OU includes ou_id in the PATCH payload', async () => {
    renderModal();

    // Wait for OUs to load
    await waitFor(() => {
      const ouSelect = getOuSelect();
      const optionValues = Array.from(ouSelect.options).map((o) => o.value);
      expect(optionValues).toContain('5');
    });

    // Change to Engineering (id=5)
    const ouSelect = getOuSelect();
    fireEvent.change(ouSelect, { target: { value: '5' } });

    const saveButton = screen.getByRole('button', { name: /save changes/i });
    await act(async () => {
      fireEvent.click(saveButton);
    });

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/users/{id}',
        expect.objectContaining({
          body: expect.objectContaining({ ou_id: 5 }),
        })
      );
    });
  });

  test('clearing the OU sends ou_id: null in the PATCH payload', async () => {
    renderModal(mockUserWithOu);

    // Wait for OUs to load
    await waitFor(() => {
      const ouSelect = getOuSelect();
      const optionValues = Array.from(ouSelect.options).map((o) => o.value);
      expect(optionValues).toContain('__none__');
    });

    // Clear to "None (root)"
    const ouSelect = getOuSelect();
    fireEvent.change(ouSelect, { target: { value: '__none__' } });

    const saveButton = screen.getByRole('button', { name: /save changes/i });
    await act(async () => {
      fireEvent.click(saveButton);
    });

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/users/{id}',
        expect.objectContaining({
          body: expect.objectContaining({ ou_id: null }),
        })
      );
    });
  });
});
