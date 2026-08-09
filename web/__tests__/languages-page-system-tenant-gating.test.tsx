/**
 * WC-583: the Languages admin page is a PLATFORM capability — languages carry
 * no tenant_id column at all, so create/update/enable/disable is restricted
 * to the SYSTEM tenant (id 0) even for a caller holding `languages:manage`
 * (mirrors the WC-222/WC-224 System-Tenant Context pattern). Verifies the
 * page-level gates (permission + system-tenant) and the enable/disable toggle.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

const mockApiGet = jest.fn();
const mockApiPatch = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    PATCH: (...args: unknown[]) => mockApiPatch(...args),
    POST: jest.fn(),
  },
}));

const mockUseAuth = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => mockUseAuth(),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import LanguagesPage from '@/app/(protected)/admin/languages/page';
import { LANGUAGES_MANAGE } from '@/lib/capabilities';

const LANGUAGES = [
  { id: 1, code: 'en', name: 'English', enabled: true, created_at: '2026-01-01', updated_at: '2026-01-01' },
  { id: 2, code: 'ar', name: 'العربية', enabled: false, created_at: '2026-01-01', updated_at: '2026-01-01' },
];

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockApiGet.mockResolvedValue({ data: { data: LANGUAGES }, error: undefined });
  mockApiPatch.mockResolvedValue({
    data: { data: { ...LANGUAGES[1], enabled: true } },
    error: undefined,
  });
});

describe('LanguagesPage system-tenant-only gating (WC-583)', () => {
  it('shows Access Denied when the caller lacks languages:manage', async () => {
    grant();
    mockUseAuth.mockReturnValue({ user: { tenant_id: 0 } });

    render(<LanguagesPage />);

    expect(await screen.findByText('Access Denied')).toBeInTheDocument();
  });

  it('shows Access Denied for a REGULAR tenant even when it holds languages:manage', async () => {
    grant(LANGUAGES_MANAGE);
    mockUseAuth.mockReturnValue({ user: { tenant_id: 1 } });

    render(<LanguagesPage />);

    expect(await screen.findByText(/restricted to the system tenant/i)).toBeInTheDocument();
  });

  it('renders the language list and toggles enabled/disabled for the SYSTEM tenant', async () => {
    grant(LANGUAGES_MANAGE);
    mockUseAuth.mockReturnValue({ user: { tenant_id: 0 } });
    const user = userEvent.setup();

    render(<LanguagesPage />);

    await screen.findByText('English');
    expect(screen.getByText('العربية')).toBeInTheDocument();

    const switches = screen.getAllByRole('switch');
    // English (enabled) is the first row; Arabic (disabled) is the second.
    await user.click(switches[1]);

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/languages/{id}',
        expect.objectContaining({
          params: { path: { id: 2 } },
          body: { enabled: true },
        })
      )
    );
    expect(addToast).toHaveBeenCalledWith(expect.stringContaining('enabled'), 'success');
  });
});
