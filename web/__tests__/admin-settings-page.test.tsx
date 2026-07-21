import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

/**
 * Unit/behaviour tests for the settings pages under
 * `app/(protected)/admin/settings/`.
 *
 * The settings area was reorganized (WC-tabs-nav follow-up): General (site
 * name / timezone / support email), Branding (locale + logos/colors), Sign-up
 * governance, Storage, Single sign-on, and Email are now separate routes
 * sharing a tab bar, replacing the old Website Settings / Global Settings
 * split. Locale moved from General to Branding; "Global defaults" is gone —
 * its General/Integrations fields fold into a "Platform defaults" card on
 * the General page itself (system-tenant + settings:manage only), and its
 * Sign-up/Storage sections became their own top-level pages.
 *
 * Each page consumes the typed client (`api.GET`/`api.PATCH`) and gates its
 * UI on the settings permissions surfaced by `useCapabilities()`:
 *   - settings:read   → may view the page at all (else Access Denied)
 *   - settings:write  → may edit the CURRENT tenant's overrides (else read-only)
 *   - settings:manage → may edit the GLOBAL defaults, AND only from the
 *     system tenant (WC-235) — `setTenant()` below lets a test simulate a
 *     regular tenant admin who also happens to hold settings:manage, to prove
 *     that gate still holds even though a system-tenant admin now sees
 *     platform-wide fields folded onto the same page as their own.
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

// Configurable tenant id (default: system tenant, 0) so a test can simulate a
// REGULAR tenant admin who also holds settings:manage (WC-235's target case)
// without a whole separate mock module per scenario.
let mockTenantId = 0;
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, email: 'admin@example.com', role: 'admin', tenant_id: mockTenantId },
  }),
}));

function setTenant(id: number) {
  mockTenantId = id;
}

// Keep the timezone select small and deterministic regardless of the host ICU
// data: stub Intl.supportedValuesOf so the option list is stable in CI.
beforeAll(() => {
  (Intl as unknown as { supportedValuesOf: (key: string) => string[] }).supportedValuesOf = (
    key: string
  ) => (key === 'timeZone' ? ['UTC', 'Europe/Berlin', 'America/New_York'] : []);
});

import AdminSettingsPage from '@/app/(protected)/admin/settings/page';
import BrandingSettingsPage from '@/app/(protected)/admin/settings/branding/page';
import SignupSettingsPage from '@/app/(protected)/admin/settings/signup/page';

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

function globalResponse(registry = REGISTRY, global = GLOBAL) {
  return { data: { data: { global, registry } }, error: undefined };
}

const BRANDING_NO_ASSETS = {
  siteName: 'Acme',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
};

function brandingResponse() {
  return { data: { data: BRANDING_NO_ASSETS }, error: undefined };
}

/** `<SettingsTabs>`'s own fetch (WC-tabs-nav-be) — a fixed, RBAC-filtered-server-side
 * tab list. The exact set doesn't matter to these page-level tests (nothing here
 * asserts on the tab bar itself), so a static General+Branding pair is enough to
 * keep `SettingsTabs` from crashing on the wrong response shape.
 */
function settingsTabsResponse() {
  return {
    data: {
      data: [
        { id: 'general', href: '/admin/settings', label: 'General' },
        { id: 'branding', href: '/admin/settings/branding', label: 'Branding' },
      ],
    },
    error: undefined,
  };
}

/**
 * Default GET router: `/api/v1/settings` → tenant payload,
 * `/api/v1/settings/global` → global payload, `/api/v1/branding` → a
 * no-assets branding payload (BrandingSettings' own fetch, mounted inside
 * BrandingSettingsPage — separately covered by branding-settings.test.tsx),
 * `/api/v1/settings/tabs` → the settings tab bar's own fetch.
 */
function routeGet(overridden: string[] = ['site_name'], tenantOverridable = true, registry = REGISTRY, global = GLOBAL) {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/settings/global') return Promise.resolve(globalResponse(registry, global));
    if (path === '/api/v1/branding') return Promise.resolve(brandingResponse());
    if (path === '/api/v1/settings/tabs') return Promise.resolve(settingsTabsResponse());
    return Promise.resolve(settingsResponse(overridden, tenantOverridable));
  });
}

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  mockTenantId = 0;
  mockApiPatch.mockResolvedValue({ data: { data: EFFECTIVE }, error: undefined });
  routeGet();
});

// ---------------------------------------------------------------------------
// General (app/(protected)/admin/settings/page.tsx)
// ---------------------------------------------------------------------------

describe('AdminSettingsPage (General) — RBAC gating', () => {
  it('renders Access Denied when the caller lacks settings:read', () => {
    grant(); // no permissions
    render(<AdminSettingsPage />);
    expect(screen.getByRole('heading', { name: /access denied/i })).toBeInTheDocument();
    expect(mockApiGet).not.toHaveBeenCalledWith('/api/v1/settings/global');
  });

  it('renders the tenant form for a settings:read caller (site name, timezone, support email — not locale)', async () => {
    grant('settings:read');
    render(<AdminSettingsPage />);
    expect(await screen.findByLabelText(/^site name$/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/^timezone$/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/^support email$/i)).toBeInTheDocument();
    // Locale moved to the Branding tab.
    expect(screen.queryByLabelText(/^locale$/i)).not.toBeInTheDocument();
  });

  it('makes the tenant form read-only without settings:write', async () => {
    grant('settings:read');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/^site name$/i);
    expect(siteName).toBeDisabled();
    expect(screen.queryByRole('button', { name: /save tenant settings/i })).not.toBeInTheDocument();
  });

  it('enables the tenant form and save with settings:write', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/^site name$/i);
    expect(siteName).toBeEnabled();
    expect(screen.getByRole('button', { name: /save tenant settings/i })).toBeInTheDocument();
  });
});

describe('AdminSettingsPage — Platform defaults (WC-235 successor)', () => {
  it('never renders Platform defaults for a non-system tenant, even with settings:manage', async () => {
    setTenant(5); // a regular tenant's own admin can hold settings:manage
    grant('settings:read', 'settings:write', 'settings:manage');
    render(<AdminSettingsPage />);
    await screen.findByLabelText(/^site name$/i);
    expect(screen.queryByRole('heading', { name: /platform defaults/i })).not.toBeInTheDocument();
    expect(mockApiGet).not.toHaveBeenCalledWith('/api/v1/settings/global');
  });

  it('hides Platform defaults for the system tenant without settings:manage', async () => {
    setTenant(0);
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    await screen.findByLabelText(/^site name$/i);
    expect(screen.queryByRole('heading', { name: /platform defaults/i })).not.toBeInTheDocument();
    expect(mockApiGet).not.toHaveBeenCalledWith('/api/v1/settings/global');
  });

  it('renders Platform defaults for the system tenant with settings:manage, on the SAME page', async () => {
    setTenant(0);
    grant('settings:read', 'settings:write', 'settings:manage');
    render(<AdminSettingsPage />);
    await screen.findByLabelText(/^site name$/i, { selector: '#tenant-site_name' });
    expect(await screen.findByRole('heading', { name: /platform defaults/i })).toBeInTheDocument();
    expect(mockApiGet).toHaveBeenCalledWith('/api/v1/settings/global');
  });

  it('PATCHes /api/v1/settings/global with ONLY the changed key', async () => {
    setTenant(0);
    grant('settings:read', 'settings:write', 'settings:manage');
    render(<AdminSettingsPage />);
    const platformSiteName = await screen.findByLabelText(/^site name$/i, { selector: '#platform-site_name' });
    fireEvent.change(platformSiteName, { target: { value: 'Platform' } });
    fireEvent.click(screen.getByRole('button', { name: /save platform defaults/i }));

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/settings/global',
        expect.objectContaining({ body: { settings: { site_name: 'Platform' } } })
      )
    );
  });
});

describe('AdminSettingsPage — overridden vs inherited indicator', () => {
  it('marks overridden keys distinctly from inherited ones', async () => {
    grant('settings:read', 'settings:write');
    routeGet(['site_name']); // only site_name is overridden
    render(<AdminSettingsPage />);
    await screen.findByLabelText(/^site name$/i);
    expect(screen.getByTestId('status-site_name')).toHaveTextContent(/overridden/i);
    expect(screen.getByTestId('status-timezone')).toHaveTextContent(/inherited/i);
  });
});

describe('AdminSettingsPage — save flow', () => {
  it('PATCHes /api/v1/settings with the changed value and refetches', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const siteName = await screen.findByLabelText(/^site name$/i);

    fireEvent.change(siteName, { target: { value: 'New Name' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() => expect(mockApiPatch).toHaveBeenCalled());
    expect(mockApiPatch).toHaveBeenCalledWith(
      '/api/v1/settings',
      expect.objectContaining({ body: { settings: expect.objectContaining({ site_name: 'New Name' }) } })
    );

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
    const siteName = await screen.findByLabelText(/^site name$/i);

    fireEvent.change(siteName, { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() => expect(mockApiPatch).toHaveBeenCalled());
    const body = mockApiPatch.mock.calls[0][1].body as { settings: Record<string, string | null> };
    expect(body.settings.site_name === '' || body.settings.site_name === null).toBe(true);
  });

  it('blocks save and surfaces a validation error for a malformed email', async () => {
    grant('settings:read', 'settings:write');
    render(<AdminSettingsPage />);
    const email = await screen.findByLabelText(/^support email$/i);

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
    const siteName = await screen.findByLabelText(/^site name$/i);

    fireEvent.change(siteName, { target: { value: 'Another' } });
    fireEvent.click(screen.getByRole('button', { name: /save tenant settings/i }));

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('Validation failed', 'error')
    );
  });
});

describe('AdminSettingsPage — system-tenant gating (WC-224)', () => {
  it('hides the editable tenant form and Save when tenant_overridable is false', async () => {
    grant('settings:read', 'settings:write', 'settings:manage');
    routeGet(['site_name'], false);
    render(<AdminSettingsPage />);

    expect(await screen.findByTestId('tenant-no-override-notice')).toBeInTheDocument();
    // Scoped to the tenant field's id: settings:manage is also granted here,
    // so the (correctly-shown) Platform defaults card has its own "Site name".
    expect(document.getElementById('tenant-site_name')).not.toBeInTheDocument();
    expect(
      screen.queryByRole('button', { name: /save tenant settings/i })
    ).not.toBeInTheDocument();
  });

  it('renders the editable tenant form when tenant_overridable is true', async () => {
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

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith(
        'The system tenant has no per-tenant override layer; edit the global default instead.',
        'error'
      )
    );
    expect(addToast).not.toHaveBeenCalledWith('Validation failed', 'error');
  });
});

// ---------------------------------------------------------------------------
// Branding (app/(protected)/admin/settings/branding/page.tsx)
// ---------------------------------------------------------------------------

describe('BrandingSettingsPage — RBAC gating and locale', () => {
  it('renders Access Denied when the caller lacks settings:read', () => {
    grant();
    render(<BrandingSettingsPage />);
    expect(screen.getByRole('heading', { name: /access denied/i })).toBeInTheDocument();
  });

  it('renders the locale field for a settings:read caller', async () => {
    grant('settings:read');
    render(<BrandingSettingsPage />);
    expect(await screen.findByLabelText(/^locale$/i)).toBeInTheDocument();
  });

  it('PATCHes /api/v1/settings with only locale', async () => {
    setTenant(5); // a regular tenant so the tenant form (not the no-override notice) renders
    grant('settings:read', 'settings:write');
    render(<BrandingSettingsPage />);
    const locale = await screen.findByLabelText(/^locale$/i);

    fireEvent.change(locale, { target: { value: 'fr' } });
    fireEvent.click(screen.getByRole('button', { name: /save language/i }));

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/settings',
        expect.objectContaining({ body: { settings: { locale: 'fr' } } })
      )
    );
  });

  it('does not render the global branding surface for a non-system tenant', async () => {
    setTenant(5);
    grant('settings:read');
    render(<BrandingSettingsPage />);
    await screen.findByLabelText(/^locale$/i);
    // The global BrandingSettings variant is system-tenant only.
    expect(screen.queryByRole('heading', { name: /global branding defaults/i })).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Sign-up (app/(protected)/admin/settings/signup/page.tsx — formerly part of
// the standalone Global Settings page's registry-driven form)
// ---------------------------------------------------------------------------

describe('SignupSettingsPage — RBAC gating (system tenant + settings:manage)', () => {
  it('renders Access Denied for a non-system tenant, even with settings:manage', () => {
    setTenant(5);
    grant('settings:manage');
    render(<SignupSettingsPage />);
    expect(screen.getByRole('heading', { name: /access denied/i })).toBeInTheDocument();
  });

  it('renders Access Denied for the system tenant without settings:manage', () => {
    setTenant(0);
    grant();
    render(<SignupSettingsPage />);
    expect(screen.getByRole('heading', { name: /access denied/i })).toBeInTheDocument();
  });
});

describe('SignupSettingsPage — registry-driven form', () => {
  it('renders a bool-typed registry key as a toggle and sends true/false', async () => {
    setTenant(0);
    grant('settings:manage');
    const registry = [
      ...REGISTRY,
      { key: 'auth.self_registration_enabled', type: 'bool', default: 'false' },
    ];
    const global = { ...GLOBAL, 'auth.self_registration_enabled': 'false' };
    routeGet(['site_name'], true, registry, global);

    render(<SignupSettingsPage />);
    const toggle = await screen.findByTestId('setting-switch-auth.self_registration_enabled');
    expect(toggle).toHaveAttribute('aria-checked', 'false');

    fireEvent.click(toggle);
    expect(toggle).toHaveAttribute('aria-checked', 'true');
    fireEvent.click(screen.getByRole('button', { name: /save sign-up settings/i }));

    await waitFor(() =>
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/settings/global',
        expect.objectContaining({
          body: { settings: { 'auth.self_registration_enabled': 'true' } },
        })
      )
    );
  });

  it('does not render General/Storage/SSO registry sections on the Sign-up page', async () => {
    setTenant(0);
    grant('settings:manage');
    const registry = [
      ...REGISTRY,
      { key: 'auth.self_registration_enabled', type: 'bool', default: 'false' },
      { key: 'storage.driver', type: 'string', default: 'local' },
    ];
    routeGet(['site_name'], true, registry, GLOBAL);
    render(<SignupSettingsPage />);

    await screen.findByTestId('settings-section-signup');
    expect(screen.queryByTestId('settings-section-general')).not.toBeInTheDocument();
    expect(screen.queryByTestId('settings-section-storage')).not.toBeInTheDocument();
  });
});
