/**
 * WC-532 A4: bilingualText form input.
 *
 * A `bilingualText` block renders the shared AR/EN `BilingualInput` and submits
 * a `{ar?, en?}` object under its `name` — the same LocalizedText convention
 * the schema-driven CRUD screens use. A required bilingual field is satisfied
 * by at least one language.
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

function formWith(field: Block): Block {
  return {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [field, { type: 'submitButton', label: 'Save' } as Block],
  } as Block;
}

describe('BlockRenderer — WC-532 A4 bilingualText', () => {
  it('renders paired AR and EN inputs inside a form', () => {
    renderWrapped(
      <BlockRenderer blocks={[formWith({ type: 'bilingualText', name: 'title', label: 'Title' } as Block)]} />
    );

    expect(screen.getByText('Title')).toBeInTheDocument();
    expect(screen.getByLabelText('Arabic')).toBeInTheDocument();
    expect(screen.getByLabelText('English')).toBeInTheDocument();
  });

  it('submits a {ar, en} object under the field name', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
    renderWrapped(
      <BlockRenderer blocks={[formWith({ type: 'bilingualText', name: 'title', label: 'Title' } as Block)]} />
    );

    await userEvent.type(screen.getByLabelText('Arabic'), 'arabic');
    await userEvent.type(screen.getByLabelText('English'), 'english');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['title']).toEqual({ ar: 'arabic', en: 'english' });
  });

  it('honours custom arLabel / enLabel', () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          formWith({
            type: 'bilingualText',
            name: 'title',
            label: 'Title',
            arLabel: 'العنوان',
            enLabel: 'Heading',
          } as Block),
        ]}
      />
    );

    expect(screen.getByLabelText('العنوان')).toBeInTheDocument();
    expect(screen.getByLabelText('Heading')).toBeInTheDocument();
  });

  it('required + empty blocks submit; one language satisfies it', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
    renderWrapped(
      <BlockRenderer
        blocks={[formWith({ type: 'bilingualText', name: 'title', label: 'Title', required: true } as Block)]}
      />
    );

    // Empty → blocked, error shown, no request.
    await userEvent.click(screen.getByRole('button', { name: /save/i }));
    expect(screen.getByText(/title is required/i)).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();

    // Fill only English → now submits.
    await userEvent.type(screen.getByLabelText('English'), 'ok');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['title']).toEqual({ en: 'ok' });
  });

  it('outside a form, degrades to a placeholder', () => {
    renderWrapped(
      <BlockRenderer blocks={[{ type: 'bilingualText', name: 'x', label: 'X' } as Block]} />
    );
    // No AR/EN inputs render — the block degrades rather than throwing.
    expect(screen.queryByLabelText('Arabic')).not.toBeInTheDocument();
  });
});
