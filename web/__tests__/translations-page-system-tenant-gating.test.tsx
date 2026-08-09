/**
 * WC-583: the Translations admin page shows the system-default and
 * tenant-override columns SIDE BY SIDE but only one is ever editable for a
 * given caller — the system tenant (id 0) edits only the system default, a
 * regular tenant edits only its own override (mirrors the write-access
 * asymmetry TranslationsApiHandler enforces server-side: 404 for a regular
 * tenant touching the global row, 422 for the system tenant touching a
 * per-tenant override).
 */

import React from 'react';
import { render, screen } from '@testing-library/react';

const mockApiGet = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
    POST: jest.fn(),
    PATCH: jest.fn(),
    DELETE: jest.fn(),
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

import TranslationsPage from '@/app/(protected)/admin/translations/page';
import { TRANSLATIONS_MANAGE } from '@/lib/capabilities';

const ROWS = [
  {
    key: 'greeting',
    system_default: { id: 100, translation: 'Hello' },
    tenant_override: { id: 200, translation: 'A-Hello' },
  },
];

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/languages') {
      return Promise.resolve({ data: { languages: [{ code: 'en', name: 'English' }] }, error: undefined });
    }
    return Promise.resolve({ data: { data: ROWS }, error: undefined });
  });
});

describe('TranslationsPage write-access column gating (WC-583)', () => {
  it('shows Access Denied when the caller lacks translations:manage', async () => {
    grant();
    mockUseAuth.mockReturnValue({ user: { tenant_id: 1 } });

    render(<TranslationsPage />);

    expect(await screen.findByText('Access Denied')).toBeInTheDocument();
  });

  it('marks the SYSTEM DEFAULT column editable for the system tenant, and the override read-only', async () => {
    grant(TRANSLATIONS_MANAGE);
    mockUseAuth.mockReturnValue({ user: { tenant_id: 0 } });

    render(<TranslationsPage />);

    await screen.findByText('greeting');
    expect(screen.getByText('Hello')).toBeInTheDocument();
    expect(
      screen.getByText(/system tenant has no per-tenant override layer/i)
    ).toBeInTheDocument();
    // Only ONE "editable" badge (the system-default column) for the system tenant.
    expect(screen.getAllByText('editable')).toHaveLength(1);
  });

  it('marks the TENANT OVERRIDE column editable for a regular tenant, and the system default read-only', async () => {
    grant(TRANSLATIONS_MANAGE);
    mockUseAuth.mockReturnValue({ user: { tenant_id: 1 } });

    render(<TranslationsPage />);

    await screen.findByText('greeting');
    expect(screen.getByText('Hello')).toBeInTheDocument();
    expect(screen.getByText('A-Hello')).toBeInTheDocument();
    expect(screen.queryByText(/system tenant has no per-tenant override layer/i)).not.toBeInTheDocument();
    expect(screen.getAllByText('editable')).toHaveLength(1);
  });
});
