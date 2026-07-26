/**
 * WC-532 A3: conditional field/section visibility (`visibleWhen`).
 *
 * A block carrying `visibleWhen: {field, equals|in}` is hidden by the renderer
 * unless the referenced sibling form field matches. It is presentational only:
 * a hidden input still contributes its seeded default to the submitted payload
 * (the server stays authoritative on validation), and `visibleWhen` outside a
 * form is a no-op.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import * as actionPermissionModule from '@/hooks/useActionPermission';
import type { ActionPermission } from '@/hooks/useActionPermission';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@/hooks/useActionPermission', () => ({ useActionPermission: jest.fn() }));

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const mockUseActionPermission =
  actionPermissionModule.useActionPermission as jest.MockedFunction<
    typeof actionPermissionModule.useActionPermission
  >;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseActionPermission.mockReturnValue({
    allowed: true,
    hidden: false,
    disabled: false,
    reason: null,
  } as ActionPermission);
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

describe('BlockRenderer — WC-532 A3 visibleWhen', () => {
  it('checkbox (equals: true) toggles a dependent input in and out', async () => {
    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          { type: 'checkbox', name: 'advanced', label: 'Advanced' } as Block,
          {
            type: 'textInput',
            name: 'tuning',
            label: 'Tuning',
            visibleWhen: { field: 'advanced', equals: true },
          } as Block,
          { type: 'submitButton', label: 'Save' } as Block,
        ],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    // Unchecked by default → dependent input hidden.
    expect(screen.queryByText('Tuning')).not.toBeInTheDocument();

    await userEvent.click(screen.getByRole('checkbox', { name: /advanced/i }));
    expect(screen.getByText('Tuning')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('checkbox', { name: /advanced/i }));
    expect(screen.queryByText('Tuning')).not.toBeInTheDocument();
  });

  it('section (in: [...]) is shown or hidden by a select default', () => {
    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          {
            type: 'select',
            name: 'kind',
            label: 'Kind',
            default: 'org',
            options: [
              { value: 'person', label: 'Person' },
              { value: 'org', label: 'Organisation' },
            ],
          } as Block,
          {
            type: 'section',
            title: 'Shown Section',
            visibleWhen: { field: 'kind', in: ['person', 'org'] },
            children: [{ type: 'text', value: 'in-view' } as Block],
          } as Block,
          {
            type: 'section',
            title: 'Hidden Section',
            visibleWhen: { field: 'kind', equals: 'ghost' },
            children: [{ type: 'text', value: 'never' } as Block],
          } as Block,
          { type: 'submitButton', label: 'Save' } as Block,
        ],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    expect(screen.getByText('in-view')).toBeInTheDocument();
    expect(screen.queryByText('never')).not.toBeInTheDocument();
  });

  it('outside a form, visibleWhen is a no-op (renders)', () => {
    const blocks: Block[] = [
      {
        type: 'section',
        title: 'Top Level',
        visibleWhen: { field: 'nothing', equals: 'x' },
        children: [{ type: 'text', value: 'still-here' } as Block],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    expect(screen.getByText('still-here')).toBeInTheDocument();
  });

  it('a hidden input still submits its seeded default (presentational only)', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          { type: 'checkbox', name: 'advanced', label: 'Advanced' } as Block,
          {
            type: 'textInput',
            name: 'tuning',
            label: 'Tuning',
            default: 'seeded',
            visibleWhen: { field: 'advanced', equals: true },
          } as Block,
          { type: 'submitButton', label: 'Save' } as Block,
        ],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    // 'tuning' is hidden (advanced unchecked) but still submits its default.
    expect(screen.queryByText('Tuning')).not.toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['tuning']).toBe('seeded');
  });
});
