/**
 * WC-532 A6: referenceSelect form input.
 *
 * A `referenceSelect` populates its dropdown from a plugin-owned collection
 * endpoint (`source`) via usePluginData, mapping each row {value: valueField,
 * label: labelField}. It surfaces loading / error(retry) states and submits the
 * chosen valueField string like a static `select`.
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

// Radix Select uses Pointer Capture + scrollIntoView, which jsdom does not
// implement — polyfill them so the dropdown can open in tests.
beforeAll(() => {
  window.HTMLElement.prototype.hasPointerCapture = jest.fn();
  window.HTMLElement.prototype.setPointerCapture = jest.fn();
  window.HTMLElement.prototype.releasePointerCapture = jest.fn();
  window.HTMLElement.prototype.scrollIntoView = jest.fn();
});

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

function refFormWith(extra?: Partial<Record<string, unknown>>): Block {
  return {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [
      {
        type: 'referenceSelect',
        name: 'ownerId',
        label: 'Owner',
        source: '/api/v1/people',
        valueField: 'id',
        labelField: 'name',
        ...extra,
      } as Block,
      { type: 'submitButton', label: 'Save' } as Block,
    ],
  } as Block;
}

describe('BlockRenderer — WC-532 A6 referenceSelect', () => {
  it('fetches its source and enables the select once loaded', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Linus' }] })
    );
    renderWrapped(<BlockRenderer blocks={[refFormWith()]} />);

    // The collection endpoint is fetched.
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/people', expect.anything())
    );
    // Trigger becomes enabled (no longer the loading placeholder).
    await waitFor(() =>
      expect(screen.getByRole('combobox', { name: /owner/i })).not.toBeDisabled()
    );
  });

  it('opening the dropdown shows the fetched rows as options', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(true, 200, { data: [{ id: 1, name: 'Ada' }, { id: 2, name: 'Linus' }] })
    );
    renderWrapped(<BlockRenderer blocks={[refFormWith()]} />);

    await waitFor(() =>
      expect(screen.getByRole('combobox', { name: /owner/i })).not.toBeDisabled()
    );
    await userEvent.click(screen.getByRole('combobox', { name: /owner/i }));
    expect(await screen.findByText('Ada')).toBeInTheDocument();
    expect(screen.getByText('Linus')).toBeInTheDocument();
  });

  it('renders a retry affordance when the source fails', async () => {
    mockApiClient.mockResolvedValue(stubResponse(false, 500, {}));
    renderWrapped(<BlockRenderer blocks={[refFormWith()]} />);

    expect(await screen.findByText(/failed to load options/i)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /retry/i })).toBeInTheDocument();
  });

  it('outside a form, degrades to a placeholder (no fetch)', () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'referenceSelect',
            name: 'ownerId',
            label: 'Owner',
            source: '/api/v1/people',
            valueField: 'id',
            labelField: 'name',
          } as Block,
        ]}
      />
    );
    expect(screen.queryByRole('combobox', { name: /owner/i })).not.toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });
});
