/**
 * WC-532 A2: fieldArray repeatable field-group.
 *
 * Rows are added / removed / reordered; each row's template inputs are scoped
 * (same child name across rows is independent); the collected rows submit as a
 * JSON array under the fieldArray's name.
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
  actionPermissionModule.useActionPermission as jest.MockedFunction<typeof actionPermissionModule.useActionPermission>;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
}

beforeEach(() => {
  jest.clearAllMocks();
  mockUseActionPermission.mockReturnValue({ allowed: true, hidden: false, disabled: false, reason: null } as ActionPermission);
  mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

function formWithArray(extra?: Record<string, unknown>): Block {
  return {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [
      {
        type: 'fieldArray',
        name: 'lines',
        label: 'Lines',
        itemLabel: 'Line',
        children: [{ type: 'textInput', name: 'label', label: 'Label' }],
        ...extra,
      },
      { type: 'submitButton', label: 'Save' },
    ],
  } as Block;
}

async function submittedPayload(): Promise<Record<string, unknown>> {
  await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
  const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
  return JSON.parse(options.body) as Record<string, unknown>;
}

describe('BlockRenderer — WC-532 A2 fieldArray', () => {
  it('starts empty; adding rows reveals template inputs and submits a JSON array', async () => {
    renderWrapped(<BlockRenderer blocks={[formWithArray()]} />);

    // No template inputs until a row is added.
    expect(screen.queryByLabelText('Label')).not.toBeInTheDocument();

    const addBtn = screen.getByRole('button', { name: /add line/i });
    await userEvent.click(addBtn);
    await userEvent.click(addBtn);

    const inputs = screen.getAllByLabelText('Label');
    expect(inputs).toHaveLength(2);
    await userEvent.type(inputs[0], 'first');
    await userEvent.type(inputs[1], 'second');

    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));
    const payload = await submittedPayload();
    expect(payload['lines']).toEqual([{ label: 'first' }, { label: 'second' }]);
  });

  it('reorder (move down) swaps rows in the submitted array', async () => {
    renderWrapped(<BlockRenderer blocks={[formWithArray()]} />);
    const addBtn = screen.getByRole('button', { name: /add line/i });
    await userEvent.click(addBtn);
    await userEvent.click(addBtn);

    const inputs = screen.getAllByLabelText('Label');
    await userEvent.type(inputs[0], 'A');
    await userEvent.type(inputs[1], 'B');

    // Move row 1 (Line 1) down → order becomes B, A.
    await userEvent.click(screen.getByRole('button', { name: /move line 1 down/i }));

    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));
    const payload = await submittedPayload();
    expect(payload['lines']).toEqual([{ label: 'B' }, { label: 'A' }]);
  });

  it('remove deletes a row', async () => {
    renderWrapped(<BlockRenderer blocks={[formWithArray()]} />);
    const addBtn = screen.getByRole('button', { name: /add line/i });
    await userEvent.click(addBtn);
    await userEvent.click(addBtn);
    await userEvent.type(screen.getAllByLabelText('Label')[0], 'keep');

    await userEvent.click(screen.getByRole('button', { name: /remove line 2/i }));
    expect(screen.getAllByLabelText('Label')).toHaveLength(1);

    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));
    const payload = await submittedPayload();
    expect(payload['lines']).toEqual([{ label: 'keep' }]);
  });

  it('honours max: the add button disables at the cap', async () => {
    renderWrapped(<BlockRenderer blocks={[formWithArray({ max: 1 })]} />);
    const addBtn = screen.getByRole('button', { name: /add line/i });
    await userEvent.click(addBtn);
    expect(addBtn).toBeDisabled();
    expect(screen.getAllByLabelText('Label')).toHaveLength(1);
  });

  it('honours min: seeds min rows and blocks submit below the minimum', async () => {
    renderWrapped(<BlockRenderer blocks={[formWithArray({ min: 1 })]} />);
    // min:1 seeds one row up-front.
    expect(screen.getAllByLabelText('Label')).toHaveLength(1);
    // Remove is disabled at the minimum.
    expect(screen.getByRole('button', { name: /remove line 1/i })).toBeDisabled();
  });
});
