import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

/**
 * Unit/behaviour tests for the admin Website Settings page
 * (`app/(protected)/admin/settings/page.tsx`).
 *
 * The page consumes the already-merged Website Settings backend through the
 * typed client (`api.GET`/`api.PATCH`) and gates its UI on the three settings
 * permissions surfaced by `useCapabilities()`:
 *   - settings:read   → may view the page at all (else Access Denied)
 *   - settings:write  → may edit the CURRENT tenant's overrides (else read-only)
 *   - settings:manage → may edit the GLOBAL defaults (else the section is hidden)
 *
 * The typed client and the capabilities hook are mocked so each gate and the
 * save/clear/refetch flow can be driven directly. The native form controls
 * (label-associated <input>/<select>) keep the assertions accessibility-first.
 */

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

const hasPermission = jest.fn<boolean, [string]>();
const mockCapabilities = { loading: false, permissions: [] as string[], hasPermission };
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => mockCapabilities,
}));

// Mock useAuth so BrandingSettings (mounted inside AdminSettingsPage) can call
// useAuth() without an AuthProvider in the tree. The production app wraps the
// page in AuthProvider via the root layout; this is purely a test-setup stub.
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, email: 'admin@example.com', role: 'admin', tenant_id: 0 } }),
}));

// Keep the timezone select small and deterministic regardless of the host ICU
// data: stub Intl.supportedValuesOf so the option list is stable in CI.
beforeAll(() => {
  (Intl as unknown as { supportedValuesOf: (key: string) => string[] }).supportedValuesOf = (
    key: string
  ) => (key === 'timeZone' ? ['UTC', 'Europe/Berlin', 'America/New_York'] : []);
});

import AdminSettingsPage from '@/app/(protected)/admin/settings/page';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const EFFECTIVE = {
  site_name: 'Acme',
  timezone: 'UTC',
  locale: 'en',
  support_email: 'help@acme.test',
};

const REGISTRY = [
  { key: 'site_name', type: 'string', default: 'Whity' },
  { key: 'timezone', type: 'string', default: 'UTC' },
  { key: 'locale', type: 'string', default: 'en' },
  { key: 'support_email', type: 'string', default: '' },
];

const GLOBAL = {
  site_name: 'Whity',
  timezone: 'UTC',
  locale: 'en',
  support_email: '',
};

function settingsResponse(overridden: string[] = ['site_name'], tenantOverridable = true) {
  return {
    data: {
      data: { effective: EFFECTIVE, registry: REGISTRY, overridden, tenant_overridable: tenantOverridable },
    },
    error: undefined,
  };
}

function globalResponse() {
  return { data: { data: { global: GLOBAL, registry: REGISTRY } }, error: undefined };
}

/**
 * Default GET router: `/api/v1/settings` → tenant payload,
 * `/api/v1/settings/global` → global payload.
 */
function routeGet(overridden: string[] = ['site_name'], tenantOverridable = true) {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/settings/global') return Promise.resolve(globalResponse());
    return Promise.resolve(settingsResponse(overridden, tenantOverridable));
  });
}

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockApiPatch.mockResolvedValue({ data: { data: EFFECTIVE }, error: undefined });
  routeGet();
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('AdminSettingsPage — RBAC gating', () => {
  it('renders Access Denied when the caller lacks settings:read', () => {
    grant(); // no permissions
    render(<AdminSettingsPage />);
    expect(screen.getByRole('heading', { name: /access denied/i })).toBeInTheDocument();
    // The page prefetches /api/v1/settings unconditionally (to derive
    // tenantOverridable for BrandingSettings) so we no longer assert
    // mockApiGet was never called; we only assert that the protected
    // global-defaults endpoint is never hit without settings:manage.
    expect(mockApiGet).not.toHaveBeenCalledWith('/api/v1/settings/global');
  });

  it('renders the tenant form for a settings:read caller', async () => {
    grant('settings:read');
    render(<AdminSettingsPage />);
    expect(await screen.findByLabelText(/site name/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/timezone/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/locale/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/support email/i)).toBeInTheDocument();
  });

  it('makes the tenant form read-only without settings:write', async () => {
    grant('settings:read');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/site name/i);
    expect(siteName).toBeDisabled();
    // No tenant save control is offered to a read-only caller.
    expect(screen.queryByRole('button', { name: /save tenant settings/i })).not.toBeInTheDocument();
  });

  it('enables the tenant form and save with settings:write', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/site name/i);
    expect(siteName).toBeEnabled();
    expect(screen.getByRole('button', { name: /save tenant settings/i })).toBeInTheDocument();
  });

  it('hides the Global defaults section without settings:manage', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    await screen.findByLabelText(/site name/i);
    expect(screen.queryByRole('heading', { name: /global defaults/i })).not.toBeInTheDocument();
    // The global endpoint is never hit when the caller cannot manage globals.
    expect(mockApiGet).not.toHaveBeenCalledWith('/api/v1/settings/global');
  });

  it('renders and loads the Global defaults section with settings:manage', async () => {
    grant('settings:read', 'settings:write', 'settings:manage');
    render(<AdminSettingsPage />);
    expect(await screen.findByRole('heading', { name: /global defaults/i })).toBeInTheDocument();
    await waitFor(() =>
      expect(mockApiGet).toHaveBeenCalledWith('/api/v1/settings/global')
    );
  });
});

describe('AdminSettingsPage — overridden vs inherited indicator', () => {
  it('marks overridden keys distinctly from inherited ones', async () => {
    grant('settings:read', 'settings:write');
    routeGet(['site_name']); // only site_name is overridden
    render(<AdminSettingsPage />);
    await screen.findByLabelText(/site name/i);
    // site_name is overridden; timezone is inherited from the global/default.
    expect(screen.getByTestId('status-site_name')).toHaveTextContent(/overridden/i);
    expect(screen.getByTestId('status-timezone')).toHaveTextContent(/inherited/i);
  });
});

describe('AdminSettingsPage — save flow', () => {
  it('PATCHes /api/v1/settings with the changed value and refetches', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/site name/i);

    fireEvent.change(siteName, { target: { value: 'New Name' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() => expect(mockApiPatch).toHaveBeenCalled());
    expect(mockApiPatch).toHaveBeenCalledWith(
      '/api/v1/settings',
      expect.objectContaining({ body: { settings: expect.objectContaining({ site_name: 'New Name' }) } })
    );

    // Success toast and a refetch of the tenant settings after the save.
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(expect.any(String), 'success')
    );
    await waitFor(() => {
      const getCalls = mockApiGet.mock.calls.filter((c) => c[0] === '/api/v1/settings');
      expect(getCalls.length).toBeGreaterThanOrEqual(2);
    });
  });

  it('sends an empty string to clear an overridden field', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/site name/i);

    fireEvent.change(siteName, { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() => expect(mockApiPatch).toHaveBeenCalled());
    const body = mockApiPatch.mock.calls[0][1].body as { settings: Record<string, string | null> };
    // Clearing a field sends an empty/null value so the backend drops the override.
    expect(body.settings.site_name === '' || body.settings.site_name === null).toBe(true);
  });

  it('blocks save and surfaces a validation error for a malformed email', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const email = await screen.findByLabelText(/support email/i);

    fireEvent.change(email, { target: { value: 'not-an-email' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(expect.any(String), 'error')
    );
    expect(mockApiPatch).not.toHaveBeenCalled();
  });

  it('surfaces a server error envelope from a failed PATCH', async () => {
    grant('settings:read', 'settings:write');
    mockApiPatch.mockResolvedValue({ data: undefined, error: { error: 'Validation failed' } });
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/site name/i);

    fireEvent.change(siteName, { target: { value: 'Another' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('Validation failed', 'error')
    );
  });
});

describe('AdminSettingsPage — system-tenant gating (WC-224)', () => {
  it('hides the editable tenant form and Save when tenant_overridable is false', async () => {
    // The system tenant (0) has globals only; the server reports
    // tenant_overridable=false. The tenant section must NOT render the editable
    // fields or the Save button (so the user can never trigger the 422), and
    // must instead point at Global defaults.
    grant('settings:read', 'settings:write', 'settings:manage');
    routeGet(['site_name'], false);
    render(<AdminSettingsPage />);

    // The explanatory notice is shown and references Global defaults.
    expect(await screen.findByTestId('tenant-no-override-notice')).toHaveTextContent(
      /global defaults/i
    );
    // No editable tenant inputs and no tenant Save button.
    expect(screen.queryByLabelText(/^site name$/i)).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /save tenant settings/i })
    ).not.toBeInTheDocument();
    // The Global defaults section is still present for the superuser.
    expect(screen.getByRole('heading', { name: /global defaults/i })).toBeInTheDocument();
  });

  it('renders the editable tenant form when tenant_overridable is true', async () => {
    // A regular tenant keeps the existing editable form (gated on settings:write).
    grant('settings:read', 'settings:write');
    routeGet(['site_name'], true);
    render(<AdminSettingsPage />);

    expect(await screen.findByLabelText(/^site name$/i)).toBeEnabled();
    expect(
      screen.getByRole('button', { name: /save tenant settings/i })
    ).toBeInTheDocument();
    expect(screen.queryByTestId('tenant-no-override-notice')).not.toBeInTheDocument();
  });
});

describe('AdminSettingsPage — errorMessage details fallback (WC-224)', () => {
  it('surfaces a per-field detail message from a 422 envelope, not the generic error', async () => {
    grant('settings:read', 'settings:write');
    mockApiPatch.mockResolvedValue({
      data: undefined,
      error: {
        error: 'Validation failed',
        details: {
          site_name: 'The system tenant has no per-tenant override layer; edit the global default instead.',
        },
      },
    });
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/^site name$/i);

    fireEvent.change(siteName, { target: { value: 'Another' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    // The toast carries the helpful field message, NOT the generic top-level one.
    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(
        'The system tenant has no per-tenant override layer; edit the global default instead.',
        'error'
      )
    );
    expect(addToast).not.toHaveBeenCalledWith('Validation failed', 'error');
  });
});

describe('AdminSettingsPage — global save flow', () => {
  it('PATCHes /api/v1/settings/global from the Global defaults form', async () => {
    grant('settings:read', 'settings:write', 'settings:manage');
    render(<AdminSettingsPage />);
    await screen.findByRole('heading', { name: /global defaults/i });

    const globalSiteName = await screen.findByLabelText(/global site name/i);
    fireEvent.change(globalSiteName, { target: { value: 'Platform' } });
    fireEvent.click(screen.getByRole('button', { name: /save global defaults/i }));

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/settings/global',
        expect.objectContaining({ body: { settings: expect.objectContaining({ site_name: 'Platform' }) } })
      )
    );
  });
});
