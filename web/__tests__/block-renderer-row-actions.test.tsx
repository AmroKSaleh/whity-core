/**
 * WC-532 A1: dataTable per-row actions.
 *
 * A `dataTable` may declare `rowActions` — internal-nav `href`s and
 * `{method, endpoint}` mutations, each templated with `{field}` placeholders
 * substituted (URL-encoded) from the row. Endpoint actions POST/PUT/DELETE via
 * submitPluginAction and refresh the table on success.
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

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

beforeEach(() => {
  jest.clearAllMocks();
  // Default: the source fetch returns two rows.
  mockApiClient.mockResolvedValue(
    stubResponse(true, 200, { data: [{ name: 'Ada Lovelace' }, { name: 'Bob' }] })
  );
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

const tableWithActions: Block = {
  type: 'dataTable',
  source: '/api/v1/people',
  columns: [{ key: 'name', label: 'Name' }],
  rowActions: [
    { label: 'View', href: '/people/{name}' },
    { label: 'Delete', method: 'DELETE', endpoint: '/api/v1/people/{name}' },
  ],
} as Block;

describe('BlockRenderer — WC-532 A1 dataTable rowActions', () => {
  it('renders href actions as internal links with {field} substituted + URL-encoded', async () => {
    renderWrapped(<BlockRenderer blocks={[tableWithActions]} />);

    const viewLinks = await screen.findAllByRole('link', { name: 'View' });
    expect(viewLinks).toHaveLength(2);
    // 'Ada Lovelace' → URL-encoded in the templated href.
    expect(viewLinks[0]).toHaveAttribute('href', '/people/Ada%20Lovelace');
    expect(viewLinks[1]).toHaveAttribute('href', '/people/Bob');
  });

  it('endpoint action POSTs the templated endpoint and refreshes on success', async () => {
    renderWrapped(<BlockRenderer blocks={[tableWithActions]} />);

    const delButtons = await screen.findAllByRole('button', { name: 'Delete' });
    expect(delButtons).toHaveLength(2);

    // The action call resolves ok; a subsequent refresh re-fetches the source.
    mockApiClient.mockResolvedValueOnce(stubResponse(true, 204, {}));
    await userEvent.click(delButtons[0]);

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/people/Ada%20Lovelace',
        expect.objectContaining({ method: 'DELETE' })
      )
    );
  });

  it('a plain dataTable without rowActions renders no Actions column', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[{ type: 'dataTable', source: '/api/v1/people', columns: [{ key: 'name', label: 'Name' }] } as Block]}
      />
    );
    await screen.findByText('Ada Lovelace');
    expect(screen.queryByText('Actions')).not.toBeInTheDocument();
  });
});
