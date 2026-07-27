/**
 * WC-621 — Tags admin page (bespoke) tests.
 *
 * Mocks useAuth (apiClient), useToast, useCapabilities. Verifies list render
 * with group-id → label resolution, capability/empty-state gating, and that the
 * create dialog renders real fields (name + group picker).
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

import TagsPage from '@/app/(protected)/admin/tags/page';

beforeAll(() => {
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

function jsonResponse(status: number, body: unknown) {
  return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) });
}

const TAGS = [{ id: 1, group_id: 5, name: 'high' }];
const GROUPS = [{ id: 5, key: 'priority', display_name: { en: 'Priority' } }];

function wire(tags: unknown[], groups: unknown[]) {
  mockApiClient.mockImplementation((url: string) => {
    if (url === '/api/v1/tags') return jsonResponse(200, { data: tags });
    if (url === '/api/v1/tag-groups') return jsonResponse(200, { data: groups });
    return jsonResponse(404, {});
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

describe('TagsPage', () => {
  it('renders tags and resolves group_id to a readable label', async () => {
    wire(TAGS, GROUPS);

    render(<TagsPage />);

    await waitFor(() => expect(screen.getByText('high')).toBeInTheDocument());
    // group_id 5 → its group's display label, not a raw number.
    expect(screen.getByText('Priority')).toBeInTheDocument();
  });

  it('disables Create Tag until at least one group exists', async () => {
    wire(TAGS, []); // no groups

    render(<TagsPage />);

    await waitFor(() => expect(screen.getByText('high')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /create tag/i })).toBeDisabled();
  });

  it('hides write controls without tags:manage', async () => {
    hasPermission.mockReturnValue(false);
    wire(TAGS, GROUPS);

    render(<TagsPage />);

    await waitFor(() => expect(screen.getByText('high')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /create tag/i })).not.toBeInTheDocument();
  });

  it('opens the create dialog with a name field and a group picker', async () => {
    wire(TAGS, GROUPS);

    render(<TagsPage />);
    await waitFor(() => expect(screen.getByText('high')).toBeInTheDocument());

    fireEvent.click(screen.getByRole('button', { name: /create tag/i }));

    expect(await screen.findByLabelText('Name')).toBeInTheDocument();
    // The group picker (a real dropdown, not a raw numeric input) is present.
    expect(screen.getByLabelText('Group')).toBeInTheDocument();
  });
});
