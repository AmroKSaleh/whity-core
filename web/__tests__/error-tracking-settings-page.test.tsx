import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

/**
 * Behaviour tests for the error-tracking settings page
 * (`app/(protected)/admin/settings/error-tracking/`, WC-error-tracking).
 *
 * The page is operator-only (system tenant + `settings:manage`, mirroring
 * Email/Storage) and its DSN is WRITE-ONLY: the value never round-trips
 * through the API, so the page can only learn WHETHER one is stored, via
 * `GET /api/v1/settings/error-tracking/status` → `has_dsn`. These tests pin
 * that contract — in particular that the page never renders a DSN value, and
 * that clearing sends an explicit `null` rather than an empty string.
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

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
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

let mockTenantId = 0;
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 1, email: 'admin@example.com', role: 'admin', tenant_id: mockTenantId },
  }),
}));

import ErrorTrackingSettingsPage from '@/app/(protected)/admin/settings/error-tracking/page';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

// Mirrors SettingsRegistry: bools round-trip as the literal 'true'/'false',
// like every other BOOL_KEY in the registry.
const REGISTRY = [
  { key: 'error_tracking.enabled', type: 'bool', default: 'false' },
  { key: 'error_tracking.provider', type: 'enum', default: 'internal', options: ['internal', 'sentry'] },
  { key: 'error_tracking.environment', type: 'string', default: '' },
  { key: 'error_tracking.notify_admins', type: 'bool', default: 'true' },
  { key: 'error_tracking.retention_days', type: 'string', default: '90' },
];

function globalResponse(overrides: Record<string, string> = {}) {
  return {
    data: {
      data: {
        global: {
          'error_tracking.enabled': 'false',
          'error_tracking.provider': 'internal',
          'error_tracking.environment': '',
          'error_tracking.notify_admins': 'true',
          'error_tracking.retention_days': '90',
          ...overrides,
        },
        registry: REGISTRY,
      },
    },
    error: undefined,
  };
}

/**
 * The page renders <SettingsTabs>, which fetches its own tab list through the
 * same typed client — so a single blanket api.GET mock would feed the settings
 * payload to the tab bar and blow up its .map(). Route by path instead.
 */
function routeApiGet(settings: ReturnType<typeof globalResponse>) {
  mockApiGet.mockImplementation((path: string) => {
    if (path === '/api/v1/settings/tabs') {
      return Promise.resolve({ data: { data: [] }, error: undefined });
    }
    return Promise.resolve(settings);
  });
}

/** Mock the write-only status endpoint (`has_dsn` only — never the DSN). */
function mockStatus(hasDsn: boolean) {
  mockApiClient.mockImplementation((path: string) => {
    if (typeof path === 'string' && path.includes('/error-tracking/status')) {
      return Promise.resolve({
        ok: true,
        status: 200,
        json: () => Promise.resolve({ data: { has_dsn: hasDsn } }),
      });
    }
    return Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) });
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  mockTenantId = 0;
  hasPermission.mockImplementation(() => true);
  routeApiGet(globalResponse());
  mockApiPatch.mockResolvedValue({ data: {}, error: undefined });
  mockStatus(false);
});

// ---------------------------------------------------------------------------
// Access gating
// ---------------------------------------------------------------------------

describe('error-tracking settings access', () => {
  it('denies a tenant admin who is not on the system tenant', async () => {
    mockTenantId = 7;
    render(<ErrorTrackingSettingsPage />);
    expect(await screen.findByText(/settings:manage/i)).toBeInTheDocument();
    expect(screen.queryByTestId('error-tracking-provider-card')).not.toBeInTheDocument();
  });

  it('denies a system-tenant admin without settings:manage', async () => {
    hasPermission.mockImplementation((p: string) => p !== 'settings:manage');
    render(<ErrorTrackingSettingsPage />);
    expect(await screen.findByText(/settings:manage/i)).toBeInTheDocument();
  });

  it('renders for a system-tenant admin holding settings:manage', async () => {
    render(<ErrorTrackingSettingsPage />);
    expect(await screen.findByTestId('error-tracking-provider-card')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Provider-conditional DSN card
// ---------------------------------------------------------------------------

describe('DSN card visibility', () => {
  it('hides the DSN card while the provider is the built-in inbox', async () => {
    routeApiGet(
      globalResponse({ 'error_tracking.enabled': 'true', 'error_tracking.provider': 'internal' })
    );
    render(<ErrorTrackingSettingsPage />);
    await screen.findByTestId('error-tracking-provider-card');
    expect(screen.queryByTestId('error-tracking-dsn-card')).not.toBeInTheDocument();
  });

  it('shows the DSN card once the provider is sentry', async () => {
    routeApiGet(
      globalResponse({ 'error_tracking.enabled': 'true', 'error_tracking.provider': 'sentry' })
    );
    render(<ErrorTrackingSettingsPage />);
    expect(await screen.findByTestId('error-tracking-dsn-card')).toBeInTheDocument();
  });

  it('hides the DSN card when tracking is disabled entirely', async () => {
    routeApiGet(
      globalResponse({ 'error_tracking.enabled': 'false', 'error_tracking.provider': 'sentry' })
    );
    render(<ErrorTrackingSettingsPage />);
    await screen.findByTestId('error-tracking-provider-card');
    expect(screen.queryByTestId('error-tracking-dsn-card')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Write-only DSN
// ---------------------------------------------------------------------------

describe('write-only DSN', () => {
  const sentryEnabled = () =>
    globalResponse({ 'error_tracking.enabled': 'true', 'error_tracking.provider': 'sentry' });

  it('reports only WHETHER a DSN is stored, never its value', async () => {
    routeApiGet(sentryEnabled());
    mockStatus(true);
    render(<ErrorTrackingSettingsPage />);

    const badge = await screen.findByTestId('error-tracking-dsn-status');
    await waitFor(() => expect(badge).toHaveTextContent(/DSN is set/i));

    // The input stays empty — a stored DSN is never rendered back.
    const input = screen.getByLabelText('DSN') as HTMLInputElement;
    expect(input.value).toBe('');
    expect(input).toHaveAttribute('type', 'password');
  });

  it('warns that events are dropped when sentry is on with no DSN', async () => {
    routeApiGet(sentryEnabled());
    mockStatus(false);
    render(<ErrorTrackingSettingsPage />);
    expect(await screen.findByTestId('error-tracking-dsn-warning')).toHaveTextContent(
      /nothing is being sent/i
    );
  });

  it('does not warn once a DSN is stored', async () => {
    routeApiGet(sentryEnabled());
    mockStatus(true);
    render(<ErrorTrackingSettingsPage />);
    await screen.findByTestId('error-tracking-dsn-card');
    await waitFor(() =>
      expect(screen.queryByTestId('error-tracking-dsn-warning')).not.toBeInTheDocument()
    );
  });

  it('PUTs the DSN to its dedicated endpoint, not the settings API', async () => {
    routeApiGet(sentryEnabled());
    render(<ErrorTrackingSettingsPage />);
    await screen.findByTestId('error-tracking-dsn-card');

    fireEvent.change(screen.getByLabelText('DSN'), {
      target: { value: 'https://key@sentry.example/42' },
    });
    fireEvent.click(screen.getByTestId('error-tracking-save-dsn'));

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/settings/error-tracking/dsn',
        expect.objectContaining({
          method: 'PUT',
          body: JSON.stringify({ dsn: 'https://key@sentry.example/42' }),
        })
      );
    });
    // The credential must never leak into the general settings PATCH.
    expect(mockApiPatch).not.toHaveBeenCalled();
  });

  it('sends an explicit null to clear, so the backend deletes rather than stores ""', async () => {
    routeApiGet(sentryEnabled());
    mockStatus(true);
    render(<ErrorTrackingSettingsPage />);
    await screen.findByTestId('error-tracking-dsn-card');

    fireEvent.click(screen.getByTestId('error-tracking-save-dsn'));

    await waitFor(() => {
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/settings/error-tracking/dsn',
        expect.objectContaining({ body: JSON.stringify({ dsn: null }) })
      );
    });
  });
});

// ---------------------------------------------------------------------------
// Saving the non-secret settings
// ---------------------------------------------------------------------------

describe('saving settings', () => {
  it('keeps Save disabled until something changes', async () => {
    render(<ErrorTrackingSettingsPage />);
    const save = await screen.findByTestId('error-tracking-save');
    expect(save).toBeDisabled();
  });

  it('PATCHes only the changed keys to the global settings API', async () => {
    routeApiGet(globalResponse({ 'error_tracking.enabled': 'true' }));
    render(<ErrorTrackingSettingsPage />);
    await screen.findByTestId('error-tracking-provider-card');

    fireEvent.change(screen.getByLabelText('Retention (days)'), { target: { value: '30' } });
    fireEvent.click(screen.getByTestId('error-tracking-save'));

    await waitFor(() => {
      expect(mockApiPatch).toHaveBeenCalledWith(
        '/api/v1/settings/global',
        { body: { settings: { 'error_tracking.retention_days': '30' } } }
      );
    });
  });
});
