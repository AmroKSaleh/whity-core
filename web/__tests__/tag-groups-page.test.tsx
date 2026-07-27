/**
 * WC-621 — Tag Groups admin page (bespoke) tests.
 *
 * Mocks useAuth (apiClient), useToast, useCapabilities. Verifies list render,
 * capability gating, and — the regression that the CrudScreen version silently
 * failed — that the create dialog actually renders editable fields and submits
 * the right payload.
 */

import React from 'react';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ permissions: [], loading: false, hasPermission }),
}));

import TagGroupsPage from '@/app/(protected)/admin/tag-groups/page';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const GROUPS = [
  { id: 1, key: 'priority', display_name: { ar: 'الأولوية', en: 'Priority' } },
  { id: 2, key: 'dept', display_name: { en: 'Department' } },
];

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

describe('TagGroupsPage', () => {
  it('renders tag groups with their display label', async () => {
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: GROUPS }));

    render(<TagGroupsPage />);

    await waitFor(() => expect(screen.getByText('priority')).toBeInTheDocument());
    expect(screen.getByText('Priority')).toBeInTheDocument();
    expect(screen.getByText('Department')).toBeInTheDocument();
  });

  it('hides the create control without tags:manage', async () => {
    hasPermission.mockReturnValue(false);
    mockApiClient.mockImplementation(() => jsonResponse(200, { data: GROUPS }));

    render(<TagGroupsPage />);

    await waitFor(() => expect(screen.getByText('priority')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /create tag group/i })).not.toBeInTheDocument();
  });

  it('opens the create dialog with editable fields and submits the payload', async () => {
    mockApiClient.mockImplementation((url: string, options?: { method?: string }) => {
      if (url === '/api/v1/tag-groups' && (options?.method ?? 'GET') === 'GET') {
        return jsonResponse(200, { data: GROUPS });
      }
      if (url === '/api/v1/tag-groups' && options?.method === 'POST') {
        return jsonResponse(201, { data: { id: 3 } });
      }
      return jsonResponse(404, {});
    });

    render(<TagGroupsPage />);
    await waitFor(() => expect(screen.getByText('priority')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /create tag group/i }));

    // The dialog must render real, editable fields (the CrudScreen version rendered NONE).
    const keyInput = await screen.findByLabelText('Key');
    expect(keyInput).toBeInTheDocument();
    expect(screen.getByLabelText(/arabic/i)).toBeInTheDocument();
    expect(screen.getByLabelText(/english/i)).toBeInTheDocument();

    fireEvent.change(keyInput, { target: { value: 'severity' } });
    fireEvent.change(screen.getByLabelText(/english/i), { target: { value: 'Severity' } });
    fireEvent.click(screen.getByRole('button', { name: /^create$/i }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/tag-groups', expect.objectContaining({ method: 'POST' }))
    );
    const postCall = mockApiClient.mock.calls.find(
      (c) => c[0] === '/api/v1/tag-groups' && c[1]?.method === 'POST'
    );
    expect(JSON.parse(postCall![1].body)).toEqual({ key: 'severity', display_name: { ar: '', en: 'Severity' } });
    await waitFor(() => expect(addToast).toHaveBeenCalledWith('Tag group created', 'success'));
  });

  it('surfaces a duplicate-key conflict (409) inline', async () => {
    mockApiClient.mockImplementation((url: string, options?: { method?: string }) => {
      if (url === '/api/v1/tag-groups' && (options?.method ?? 'GET') === 'GET') {
        return jsonResponse(200, { data: GROUPS });
      }
      if (url === '/api/v1/tag-groups' && options?.method === 'POST') return jsonResponse(409, {});
      return jsonResponse(404, {});
    });

    render(<TagGroupsPage />);
    await waitFor(() => expect(screen.getByText('priority')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /create tag group/i }));
    fireEvent.change(await screen.findByLabelText('Key'), { target: { value: 'priority' } });
    fireEvent.click(screen.getByRole('button', { name: /^create$/i }));

    await waitFor(() => expect(screen.getByText(/already exists/i)).toBeInTheDocument());
  });
});
