/**
 * WC-block-modal-drawer: modal / drawer overlay blocks.
 *
 * A `modal` (→ Dialog) and `drawer` (→ Sheet) hold overlay content. A dataTable
 * `rowActions` entry of kind `open` publishes the clicked row into the shared
 * master-detail context under the target's id; the overlay's content reads it —
 * a form input via `defaultFrom`, a data-bound child via a dotted `params.from`
 * (`{targetId}.{field}`). A form inside closes the overlay + refetches the tree
 * on submit-success; a plain dismiss does neither.
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
  mockApiClient.mockResolvedValue(
    stubResponse(true, 200, { data: [{ name: 'Ada Lovelace', role: 'Engineer' }, { name: 'Bob', role: 'Editor' }] })
  );
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

/** A table whose rows open an edit modal (seeded via defaultFrom) and a detail drawer. */
const overlayTree: Block[] = [
  {
    type: 'dataTable',
    source: '/api/v1/people',
    columns: [{ key: 'name', label: 'Name' }],
    rowActions: [
      { label: 'Edit', open: 'edit-modal' },
      { label: 'Details', open: 'detail-drawer' },
    ],
  } as Block,
  {
    type: 'modal',
    id: 'edit-modal',
    title: 'Edit person',
    trigger: 'New person',
    children: [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/people/update' },
        children: [
          { type: 'textInput', name: 'name', label: 'Name', defaultFrom: 'edit-modal.name' },
          { type: 'submitButton', label: 'Save' },
        ],
      },
    ],
  } as Block,
  {
    type: 'drawer',
    id: 'detail-drawer',
    title: 'Person detail',
    side: 'right',
    children: [
      {
        type: 'dataStat',
        source: '/api/v1/people/metric',
        label: 'Role',
        valueField: 'role',
        params: [{ param: 'name', from: 'detail-drawer.name' }],
      },
    ],
  } as Block,
];

describe('BlockRenderer — WC-block-modal-drawer overlays', () => {
  it('renders a modal trigger button and keeps the overlay closed until opened', async () => {
    renderWrapped(<BlockRenderer blocks={overlayTree} />);
    // The self-trigger is present; the modal title is not yet in the document.
    expect(await screen.findByRole('button', { name: 'New person' })).toBeInTheDocument();
    expect(screen.queryByText('Edit person')).not.toBeInTheDocument();
  });

  it('a row `open` action opens the modal and seeds the form via defaultFrom', async () => {
    renderWrapped(<BlockRenderer blocks={overlayTree} />);

    const editButtons = await screen.findAllByRole('button', { name: 'Edit' });
    await userEvent.click(editButtons[0]);

    // Modal opened, and the input is seeded from the clicked row (Ada Lovelace).
    expect(await screen.findByText('Edit person')).toBeInTheDocument();
    expect(await screen.findByDisplayValue('Ada Lovelace')).toBeInTheDocument();
  });

  it('submitting the overlay form POSTs, closes the overlay, and refetches the table', async () => {
    renderWrapped(<BlockRenderer blocks={overlayTree} />);

    const editButtons = await screen.findAllByRole('button', { name: 'Edit' });
    await userEvent.click(editButtons[0]);
    await screen.findByText('Edit person');

    mockApiClient.mockClear();
    // The submit call resolves ok.
    mockApiClient.mockResolvedValueOnce(stubResponse(true, 200, {}));
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));

    // The form POSTed to the submit endpoint...
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/people/update',
        expect.objectContaining({ method: 'POST' })
      )
    );
    // ...the overlay closed...
    await waitFor(() => expect(screen.queryByText('Edit person')).not.toBeInTheDocument());
    // ...and the table refetched its source (refresh signal on submit-success).
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/people', expect.anything())
    );
  });

  it('a row `open` action into a drawer binds the published row to a dotted params.from', async () => {
    renderWrapped(<BlockRenderer blocks={overlayTree} />);

    const detailButtons = await screen.findAllByRole('button', { name: 'Details' });
    await userEvent.click(detailButtons[0]);

    // The drawer's data-bound child fetches its source with the row's value
    // appended as the whitelisted query param (dotted {targetId}.{field}).
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/people/metric?name=Ada%20Lovelace',
        expect.anything()
      )
    );
  });

  it('an edit form PATCHes a context-templated endpoint for the opened row', async () => {
    // WC-block-submit-templating: the submit endpoint carries a {targetId.field}
    // token resolved from the published row at submit time (edit-in-place).
    const patchTree: Block[] = [
      {
        type: 'dataTable',
        source: '/api/v1/people',
        columns: [{ key: 'name', label: 'Name' }],
        rowActions: [{ label: 'Edit', open: 'patch-modal' }],
      } as Block,
      {
        type: 'modal',
        id: 'patch-modal',
        title: 'Edit details',
        children: [
          {
            type: 'form',
            submit: { method: 'PATCH', endpoint: '/api/v1/people/{patch-modal.name}' },
            children: [
              { type: 'textInput', name: 'name', label: 'Name', defaultFrom: 'patch-modal.name' },
              { type: 'submitButton', label: 'Save' },
            ],
          },
        ],
      } as Block,
    ];

    renderWrapped(<BlockRenderer blocks={patchTree} />);
    await userEvent.click((await screen.findAllByRole('button', { name: 'Edit' }))[0]);
    await screen.findByText('Edit details');

    mockApiClient.mockClear();
    mockApiClient.mockResolvedValueOnce(stubResponse(true, 200, {}));
    await userEvent.click(screen.getByRole('button', { name: 'Save' }));

    // The row's `name` is interpolated (URL-encoded) into the PATCH endpoint.
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/people/Ada%20Lovelace',
        expect.objectContaining({ method: 'PATCH' })
      )
    );
  });
});
