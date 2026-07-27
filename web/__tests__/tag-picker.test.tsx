/**
 * WC-621 — TagPicker component tests.
 *
 * Mocks useAuth (apiClient) and useCapabilities — no server, no providers.
 * Covers the data load, the per-toggle attach/detach mutation + optimistic
 * rollback, read-only gating on tags:manage, and the error/retry path.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

// Stable reference across renders — the real useAuth wraps apiClient in
// useCallback, so a fresh wrapper each render would refetch on every render
// (see EmailAddressesSettings test). Pass the jest.fn() itself.
const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

import { TagPicker } from '@/components/taxonomy/tag-picker';

// Radix Select (inside TagInput) needs these jsdom shims to mount.
beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  });
}

const ALL_TAGS = [
  { id: 1, tenant_id: 1, group_id: 1, name: 'High' },
  { id: 2, tenant_id: 1, group_id: 1, name: 'Low' },
  { id: 3, tenant_id: 1, group_id: 2, name: 'Sales' },
];

/** Route the mock by URL + method, mirroring the two GETs then the mutations. */
function wireApi(current: Array<{ id: number; name: string }>) {
  mockApiClient.mockImplementation((url: string, options?: { method?: string }) => {
    const method = options?.method ?? 'GET';
    if (url === '/api/v1/tags') return jsonResponse(200, { data: ALL_TAGS });
    if (url.startsWith('/api/v1/entity-tags?')) return jsonResponse(200, { data: current });
    if (url === '/api/v1/entity-tags' && method === 'POST') return jsonResponse(201, { data: {} });
    if (url === '/api/v1/entity-tags' && method === 'DELETE') return jsonResponse(204, {});
    return jsonResponse(404, {});
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true); // tags:manage by default
});

describe('TagPicker', () => {
  it('loads and renders the entity’s current tags as chips', async () => {
    wireApi([{ id: 1, name: 'High' }]);

    render(<TagPicker entityType="invoice" entityId={42} />);

    await waitFor(() => expect(screen.getByText('High')).toBeInTheDocument());
    // A tag NOT attached is not shown as a chip.
    expect(screen.queryByLabelText('Remove Low')).not.toBeInTheDocument();
    // The current-associations fetch carried the entity coordinates.
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/entity-tags?entity_type=invoice&entity_id=42'
    );
  });

  it('detaches a tag with a DELETE carrying the association body', async () => {
    wireApi([{ id: 1, name: 'High' }]);
    render(<TagPicker entityType="invoice" entityId={42} />);
    await waitFor(() => expect(screen.getByLabelText('Remove High')).toBeInTheDocument());

    fireEvent.click(screen.getByLabelText('Remove High'));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/entity-tags', {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ entity_type: 'invoice', entity_id: 42, tag_id: 1 }),
      })
    );
    // Optimistically removed from the UI.
    await waitFor(() => expect(screen.queryByLabelText('Remove High')).not.toBeInTheDocument());
  });

  it('rolls back the optimistic removal when the mutation fails', async () => {
    mockApiClient.mockImplementation((url: string, options?: { method?: string }) => {
      const method = options?.method ?? 'GET';
      if (url === '/api/v1/tags') return jsonResponse(200, { data: ALL_TAGS });
      if (url.startsWith('/api/v1/entity-tags?')) return jsonResponse(200, { data: [{ id: 1, name: 'High' }] });
      if (url === '/api/v1/entity-tags' && method === 'DELETE') return jsonResponse(500, {});
      return jsonResponse(404, {});
    });
    render(<TagPicker entityType="invoice" entityId={42} />);
    await waitFor(() => expect(screen.getByLabelText('Remove High')).toBeInTheDocument());

    fireEvent.click(screen.getByLabelText('Remove High'));

    // After the failed DELETE the chip returns.
    await waitFor(() => expect(screen.getByLabelText('Remove High')).toBeInTheDocument());
  });

  it('is read-only without tags:manage (no remove controls)', async () => {
    hasPermission.mockReturnValue(false);
    wireApi([{ id: 1, name: 'High' }]);

    render(<TagPicker entityType="invoice" entityId={42} />);

    await waitFor(() => expect(screen.getByText('High')).toBeInTheDocument());
    expect(screen.queryByLabelText('Remove High')).not.toBeInTheDocument();
  });

  it('shows an error state with retry when loading fails', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(500, {}));

    render(<TagPicker entityType="invoice" entityId={42} />);

    await waitFor(() => expect(screen.getByText('Failed to load tags.')).toBeInTheDocument());

    // Retry re-runs the load; wire a success this time.
    wireApi([]);
    fireEvent.click(screen.getByRole('button', { name: 'Retry' }));
    await waitFor(() => expect(screen.queryByText('Failed to load tags.')).not.toBeInTheDocument());
  });
});
