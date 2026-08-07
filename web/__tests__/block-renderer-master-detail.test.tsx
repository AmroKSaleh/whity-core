/**
 * WC-532 A7: master-detail. A `selector` publishes its choice into a shared
 * context; a sibling data-bound block appends it to its `source` as a query
 * param (via `params`), re-fetching when the selection changes. The base source
 * stays a plain owned route.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

function stubResponse(body: unknown): Response {
  return { ok: true, status: 200, json: () => Promise.resolve(body) } as unknown as Response;
}

// Radix Select needs Pointer Capture + scrollIntoView, absent in jsdom.
beforeAll(() => {
  window.HTMLElement.prototype.hasPointerCapture = jest.fn();
  window.HTMLElement.prototype.setPointerCapture = jest.fn();
  window.HTMLElement.prototype.releasePointerCapture = jest.fn();
  window.HTMLElement.prototype.scrollIntoView = jest.fn();
});

beforeEach(() => {
  jest.clearAllMocks();
  // Route responses by URL: teams for the selector, members for the table.
  mockApiClient.mockImplementation((url: string) => {
    if (url.startsWith('/api/v1/teams')) {
      return Promise.resolve(stubResponse({ data: [{ id: '1', name: 'Alpha' }, { id: '2', name: 'Beta' }] }));
    }
    if (url.startsWith('/api/v1/members')) {
      return Promise.resolve(stubResponse({ data: [{ person: 'Ada' }] }));
    }
    return Promise.resolve(stubResponse({ data: [] }));
  });
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

const masterDetail: Block[] = [
  { type: 'selector', name: 'team', label: 'Team', source: '/api/v1/teams', valueField: 'id', labelField: 'name' } as Block,
  {
    type: 'dataTable',
    source: '/api/v1/members',
    columns: [{ key: 'person', label: 'Person' }],
    params: [{ param: 'teamId', from: 'team' }],
  } as Block,
];

describe('BlockRenderer — WC-532 A7 master-detail', () => {
  it('fetches the detail with the base source until a selection is made', async () => {
    renderWrapped(<BlockRenderer blocks={masterDetail} />);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/members', expect.anything()));
    // No param appended before a selection.
    expect(mockApiClient).not.toHaveBeenCalledWith(expect.stringContaining('teamId='), expect.anything());
  });

  it('re-fetches the detail with the selected value appended as a query param', async () => {
    renderWrapped(<BlockRenderer blocks={masterDetail} />);

    // Open the selector and choose Alpha (value '1').
    await waitFor(() => expect(screen.getByRole('combobox', { name: /team/i })).not.toBeDisabled());
    await userEvent.click(screen.getByRole('combobox', { name: /team/i }));
    await userEvent.click(await screen.findByRole('option', { name: 'Alpha' }));

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/members?teamId=1', expect.anything())
    );
  });
});
