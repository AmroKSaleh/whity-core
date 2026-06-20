/**
 * WC-233 Slice 5 — BrandingSettings component tests.
 *
 * Verifies the three RBAC / tenantOverridable gates and the upload/clear flow
 * without spinning up a server. The branding-upload module and useCapabilities
 * hook are mocked.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

// Mock the branding upload helpers.
const mockUploadBrandingAsset = jest.fn();
const mockClearBrandingAsset = jest.fn();
const mockSetBrandingHost = jest.fn();

jest.mock('@/lib/api/branding-upload', () => ({
  uploadBrandingAsset: (...args: unknown[]) => mockUploadBrandingAsset(...args),
  clearBrandingAsset: (...args: unknown[]) => mockClearBrandingAsset(...args),
  setBrandingHost: (...args: unknown[]) => mockSetBrandingHost(...args),
}));

// Mock the typed API client (used by useFetch inside BrandingSettings for GET /api/v1/branding).
const mockApiGet = jest.fn();
jest.mock('@/lib/api/client', () => ({
  api: {
    GET: (...args: unknown[]) => mockApiGet(...args),
  },
}));

// Mock useAuth so the component can read user?.tenant_id without an AuthProvider.
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: { id: 1, email: 'admin@example.com', role: 'admin', tenant_id: 0 } }),
}));

// Mock useCapabilities so tests can set permissions declaratively.
const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ hasPermission, loading: false, permissions: [] }),
}));

// Mock useToast.
const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

import { BrandingSettings } from '@/components/branding-settings';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

import type { Branding } from '@/lib/api/branding-upload';

const BRANDING_NO_ASSETS: Branding = {
  siteName: 'Acme',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
};

const BRANDING_WITH_WIDE: Branding = {
  siteName: 'Acme',
  logoWideUrl: '/api/v1/branding/asset/1/logo_wide-abc.png',
  logoSquareUrl: null,
  faviconUrl: null,
};

function brandingResponse(branding: Branding = BRANDING_NO_ASSETS) {
  return Promise.resolve({ data: { data: branding }, error: undefined });
}

function grant(...perms: string[]) {
  hasPermission.mockImplementation((slug: string) => perms.includes(slug));
}

beforeEach(() => {
  jest.clearAllMocks();
  // Default: settings endpoint returns branding with no assets.
  mockApiGet.mockImplementation(() => brandingResponse());
  mockUploadBrandingAsset.mockResolvedValue(BRANDING_WITH_WIDE);
  mockClearBrandingAsset.mockResolvedValue(BRANDING_NO_ASSETS);
  mockSetBrandingHost.mockResolvedValue(undefined);
  // Default: no permissions (overridden in each test).
  grant();
});

// ---------------------------------------------------------------------------
// Gate 1: with settings:write, uploaders render and call uploadBrandingAsset
// ---------------------------------------------------------------------------

describe('BrandingSettings — settings:write gate', () => {
  it('renders the asset uploaders when tenantOverridable and settings:write', async () => {
    grant('settings:write');
    render(<BrandingSettings tenantOverridable={true} />);

    // All three upload buttons must be in the document.
    await waitFor(() => {
      expect(screen.getByTestId('branding-upload-btn-logo_wide-tenant')).toBeInTheDocument();
    });
    expect(screen.getByTestId('branding-upload-btn-logo_square-tenant')).toBeInTheDocument();
    expect(screen.getByTestId('branding-upload-btn-favicon-tenant')).toBeInTheDocument();
  });

  it('calls uploadBrandingAsset(tenant, logo_wide, file) on file select', async () => {
    grant('settings:write');
    render(<BrandingSettings tenantOverridable={true} />);

    // Wait for the component to resolve its branding fetch.
    await waitFor(() =>
      expect(screen.getByTestId('branding-file-input-logo_wide-tenant')).toBeInTheDocument()
    );

    const fileInput = screen.getByTestId('branding-file-input-logo_wide-tenant');
    const file = new File(['PNG'], 'logo-wide.png', { type: 'image/png' });

    fireEvent.change(fileInput, { target: { files: [file] } });

    await waitFor(() => {
      expect(mockUploadBrandingAsset).toHaveBeenCalledWith('tenant', 'logo_wide', file);
    });
  });

  it('shows a success toast after a successful upload', async () => {
    grant('settings:write');
    render(<BrandingSettings tenantOverridable={true} />);

    await waitFor(() =>
      expect(screen.getByTestId('branding-file-input-logo_wide-tenant')).toBeInTheDocument()
    );

    const file = new File(['PNG'], 'logo.png', { type: 'image/png' });
    fireEvent.change(screen.getByTestId('branding-file-input-logo_wide-tenant'), {
      target: { files: [file] },
    });

    await waitFor(() => {
      expect(addToast).toHaveBeenCalledWith(
        expect.stringContaining('uploaded'),
        'success'
      );
    });
  });
});

// ---------------------------------------------------------------------------
// Gate 2: !tenantOverridable → notice replaces tenant uploaders
// ---------------------------------------------------------------------------

describe('BrandingSettings — system tenant (!tenantOverridable)', () => {
  it('shows the no-override notice instead of tenant uploaders', async () => {
    grant('settings:write');
    render(<BrandingSettings tenantOverridable={false} />);

    // The notice must appear.
    await waitFor(() =>
      expect(screen.getByTestId('branding-no-override-notice')).toBeInTheDocument()
    );

    // No tenant-scope upload buttons.
    expect(
      screen.queryByTestId('branding-upload-btn-logo_wide-tenant')
    ).not.toBeInTheDocument();
    expect(
      screen.queryByTestId('branding-upload-btn-logo_square-tenant')
    ).not.toBeInTheDocument();
    expect(
      screen.queryByTestId('branding-upload-btn-favicon-tenant')
    ).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Gate 3: custom-host field absent without settings:manage
// ---------------------------------------------------------------------------

describe('BrandingSettings — settings:manage gate', () => {
  it('does not render the custom-host field without settings:manage', async () => {
    grant('settings:write'); // has write but NOT manage
    render(<BrandingSettings tenantOverridable={true} />);

    await waitFor(() =>
      expect(screen.getByTestId('branding-upload-btn-logo_wide-tenant')).toBeInTheDocument()
    );

    expect(screen.queryByTestId('branding-custom-host-input')).not.toBeInTheDocument();
  });

  it('renders the global branding section and custom-host field with settings:manage', async () => {
    grant('settings:write', 'settings:manage');
    render(<BrandingSettings tenantOverridable={true} />);

    await waitFor(() =>
      expect(screen.getByTestId('branding-custom-host-input')).toBeInTheDocument()
    );

    // Global upload buttons must also be present.
    expect(screen.getByTestId('branding-upload-btn-logo_wide-global')).toBeInTheDocument();
  });

  it('calls setBrandingHost on Save host click', async () => {
    grant('settings:write', 'settings:manage');
    render(<BrandingSettings tenantOverridable={true} />);

    await waitFor(() =>
      expect(screen.getByTestId('branding-custom-host-input')).toBeInTheDocument()
    );

    fireEvent.change(screen.getByTestId('branding-custom-host-input'), {
      target: { value: 'app.acme.com' },
    });
    fireEvent.click(screen.getByTestId('branding-custom-host-save'));

    await waitFor(() => {
      expect(mockSetBrandingHost).toHaveBeenCalledWith(0, 'app.acme.com');
    });
  });
});

// ---------------------------------------------------------------------------
// Clear flow
// ---------------------------------------------------------------------------

describe('BrandingSettings — clear flow', () => {
  it('shows a Clear button when an asset URL is set, and calls clearBrandingAsset', async () => {
    grant('settings:write');
    // Return branding with wide logo already set.
    mockApiGet.mockImplementation(() => brandingResponse(BRANDING_WITH_WIDE));

    render(<BrandingSettings tenantOverridable={true} />);

    // Wait for the Clear button to appear (asset is set).
    await waitFor(() =>
      expect(screen.getByTestId('branding-clear-btn-logo_wide-tenant')).toBeInTheDocument()
    );

    fireEvent.click(screen.getByTestId('branding-clear-btn-logo_wide-tenant'));

    await waitFor(() => {
      expect(mockClearBrandingAsset).toHaveBeenCalledWith('tenant', 'logo_wide');
    });
  });
});
