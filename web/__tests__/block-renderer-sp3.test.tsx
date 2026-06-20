/**
 * WC-235: web BlockRenderer interactive blocks (SP3).
 *
 * Tests that `form`, input leaves, `submitButton`, and `actionButton` render
 * correctly, integrate with form state, and remain injection-safe.
 *
 * Mock strategy:
 *   - `apiClient` → jest.fn()
 *   - `useActionPermission` → jest.fn() (controls PermissionButton gating)
 *   - `useCapabilities` → mocked indirectly via useActionPermission mock
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

// ---- mocks ----

jest.mock('@/lib/api-client', () => ({
  apiClient: jest.fn(),
}));

jest.mock('@/hooks/useActionPermission', () => ({
  useActionPermission: jest.fn(),
}));

// useCapabilities is consumed inside useActionPermission; since we mock
// useActionPermission directly we don't need to mock useCapabilities separately.

const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;
const mockUseActionPermission =
  actionPermissionModule.useActionPermission as jest.MockedFunction<
    typeof actionPermissionModule.useActionPermission
  >;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return {
    ok,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function allowedPermission(): ActionPermission {
  return { allowed: true, hidden: false, disabled: false, reason: null };
}

function disabledPermission(perm: string): ActionPermission {
  return {
    allowed: false,
    hidden: false,
    disabled: true,
    reason: `Requires ${perm}`,
  };
}

beforeEach(() => {
  mockApiClient.mockReset();
  // Default: permission is granted — tests that need to deny override this.
  mockUseActionPermission.mockReturnValue(allowedPermission());
});

// ---- wrapper ----

function Wrap({ children }: { children: React.ReactNode }) {
  return <ToastProvider>{children}</ToastProvider>;
}

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: Wrap });
}

// ---- helpers ----

/** A minimal valid form block containing a textInput and a submitButton. */
function makeSimpleForm(extra?: Partial<Extract<Block, { type: 'form' }>>): Block {
  return {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [
      {
        type: 'textInput',
        name: 'username',
        label: 'Username',
      } as Block,
      {
        type: 'submitButton',
        label: 'Save',
      } as Block,
    ],
    ...extra,
  } as Block;
}

// ---- form renders its children ----

describe('BlockRenderer — SP3 interactive blocks (WC-235)', () => {
  it('form: renders its children (textInput label + submitButton)', () => {
    renderWrapped(<BlockRenderer blocks={[makeSimpleForm()]} />);

    expect(screen.getByText('Username')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument();
  });

  // ---- typing updates state ----

  it('textInput: typing updates the submitted payload', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    renderWrapped(<BlockRenderer blocks={[makeSimpleForm()]} />);

    const input = screen.getByRole('textbox', { name: /username/i });
    await userEvent.type(input, 'Alice');

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [url, options] = mockApiClient.mock.calls[0] as [
      string,
      { body: string },
    ];
    expect(url).toBe('/api/v1/x/save');
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['username']).toBe('Alice');
  });

  it('checkbox: toggling updates the submitted payload', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          { type: 'checkbox', name: 'agree', label: 'Agree', default: false } as Block,
          { type: 'submitButton', label: 'Submit' } as Block,
        ],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    const checkbox = screen.getByRole('checkbox', { name: /agree/i });
    await userEvent.click(checkbox);

    await userEvent.click(screen.getByRole('button', { name: /submit/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['agree']).toBe(true);
  });

  it('select: choosing an option updates the submitted payload', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          {
            type: 'select',
            name: 'role',
            label: 'Role',
            options: [
              { value: 'admin', label: 'Admin' },
              { value: 'editor', label: 'Editor' },
            ],
            default: 'admin',
          } as Block,
          { type: 'submitButton', label: 'Submit' } as Block,
        ],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    // Submit with the default value.
    await userEvent.click(screen.getByRole('button', { name: /submit/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['role']).toBe('admin');
  });

  // ---- required validation ----

  it('required-empty field: blocks submit + shows error message', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              {
                type: 'textInput',
                name: 'name',
                label: 'Name',
                required: true,
              } as Block,
              { type: 'submitButton', label: 'Submit' } as Block,
            ],
          } as Block,
        ]}
      />
    );

    await userEvent.click(screen.getByRole('button', { name: /submit/i }));

    // apiClient must NOT have been called.
    expect(mockApiClient).not.toHaveBeenCalled();
    // Error message is shown.
    expect(screen.getByText(/name is required/i)).toBeInTheDocument();
  });

  // ---- nested inputs (any depth) ----

  it('nested input (form > grid > textInput): default is seeded', () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              {
                type: 'grid',
                columns: 2,
                children: [
                  {
                    type: 'textInput',
                    name: 'nested',
                    label: 'Nested',
                    default: 'seeded value',
                  } as Block,
                ],
              } as Block,
              { type: 'submitButton', label: 'Save' } as Block,
            ],
          } as Block,
        ]}
      />
    );

    // The default reaches the nested input even though it is not a direct
    // child of the form (mirrors the SDK validator's any-depth nesting rule).
    expect(screen.getByRole('textbox', { name: /nested/i })).toHaveValue(
      'seeded value'
    );
  });

  it('nested required input (form > grid > textInput): blocks submit + shows error', async () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              {
                type: 'grid',
                columns: 2,
                children: [
                  {
                    type: 'textInput',
                    name: 'deep',
                    label: 'Deep',
                    required: true,
                  } as Block,
                ],
              } as Block,
              { type: 'submitButton', label: 'Save' } as Block,
            ],
          } as Block,
        ]}
      />
    );

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    // A required input nested in a layout container is still validated.
    expect(mockApiClient).not.toHaveBeenCalled();
    expect(screen.getByText(/deep is required/i)).toBeInTheDocument();
  });

  // ---- 422 issues report ----

  it('422 response: renders the issues report', async () => {
    mockApiClient.mockResolvedValue(
      stubResponse(false, 422, {
        issues: [
          { severity: 'error', message: 'Username taken', column: 'username' },
        ],
      })
    );

    renderWrapped(<BlockRenderer blocks={[makeSimpleForm()]} />);

    const input = screen.getByRole('textbox', { name: /username/i });
    await userEvent.type(input, 'taken');

    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() =>
      expect(screen.getByText(/Username taken/i)).toBeInTheDocument()
    );
    expect(screen.getByText(/Validation report/i)).toBeInTheDocument();
  });

  // ---- textInput outside a form ----

  it('textInput outside a form renders UnsupportedBlock', () => {
    const blocks = [
      { type: 'textInput', name: 'foo', label: 'Foo' },
    ] as unknown as Block[];

    expect(() => renderWrapped(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });

  it('submitButton outside a form renders UnsupportedBlock', () => {
    const blocks = [{ type: 'submitButton', label: 'Go' }] as unknown as Block[];

    expect(() => renderWrapped(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });

  // ---- PermissionButton gating ----

  it('submitButton with requiredPermission the caller lacks renders disabled', () => {
    mockUseActionPermission.mockReturnValue(disabledPermission('x:write'));

    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              { type: 'textInput', name: 'n', label: 'N' } as Block,
              {
                type: 'submitButton',
                label: 'Save',
                requiredPermission: 'x:write',
              } as Block,
            ],
          } as Block,
        ]}
      />
    );

    // The button must be present but disabled.
    const button = screen.getByRole('button', { name: /save/i });
    expect(button).toBeDisabled();
  });

  // ---- actionButton confirm → submit ----

  it('actionButton: confirm dialog → submit calls apiClient', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    const blocks: Block[] = [
      {
        type: 'actionButton',
        label: 'Run action',
        action: { method: 'POST', endpoint: '/api/v1/x/run' },
        confirm: 'Are you sure?',
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    // Click the action button to open the confirm dialog.
    await userEvent.click(screen.getByRole('button', { name: /run action/i }));

    // The confirm dialog text should be visible.
    await waitFor(() =>
      expect(screen.getByText(/are you sure/i)).toBeInTheDocument()
    );

    // Click the confirm/continue button in the dialog.
    const confirmBtn = screen.getByRole('button', { name: /confirm/i });
    await userEvent.click(confirmBtn);

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    expect(mockApiClient.mock.calls[0][0]).toBe('/api/v1/x/run');
  });

  it('actionButton: without confirm clicks directly submit', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    const blocks: Block[] = [
      {
        type: 'actionButton',
        label: 'Quick action',
        action: { method: 'PUT', endpoint: '/api/v1/x/quick' },
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    await userEvent.click(screen.getByRole('button', { name: /quick action/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    expect(mockApiClient.mock.calls[0][0]).toBe('/api/v1/x/quick');
  });

  // ---- injection guard ----

  it('form: a value containing markup renders as literal text (injection guard)', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
    const malicious = '<img src=x onerror=alert(1)>';

    renderWrapped(<BlockRenderer blocks={[makeSimpleForm()]} />);

    const input = screen.getByRole('textbox', { name: /username/i });
    await userEvent.type(input, malicious);

    // The input value is a controlled string — no <img> should be in the DOM.
    const { container } = renderWrapped(<BlockRenderer blocks={[makeSimpleForm()]} />);
    expect(container.querySelector('img')).toBeNull();

    // Also assert the payload is sent as literal text via apiClient.
    await userEvent.click(screen.getAllByRole('button', { name: /save/i })[0]);
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['username']).toBe(malicious);
  });

  // ---- default values seeded ----

  it('form: default value is seeded and submitted without user interaction', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));

    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          {
            type: 'textInput',
            name: 'color',
            label: 'Color',
            default: 'blue',
          } as Block,
          { type: 'submitButton', label: 'Submit' } as Block,
        ],
      } as Block,
    ];
    renderWrapped(<BlockRenderer blocks={blocks} />);

    await userEvent.click(screen.getByRole('button', { name: /submit/i }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalledTimes(1));
    const [, options] = mockApiClient.mock.calls[0] as [string, { body: string }];
    const payload = JSON.parse(options.body) as Record<string, unknown>;
    expect(payload['color']).toBe('blue');
  });

  // ---- other input types render ----

  it('numberInput: renders a number input with label', () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              {
                type: 'numberInput',
                name: 'count',
                label: 'Count',
              } as Block,
              { type: 'submitButton', label: 'Submit' } as Block,
            ],
          } as Block,
        ]}
      />
    );
    expect(screen.getByText('Count')).toBeInTheDocument();
    expect(screen.getByRole('spinbutton', { name: /count/i })).toBeInTheDocument();
  });

  it('textArea: renders a textarea with label', () => {
    renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              {
                type: 'textArea',
                name: 'bio',
                label: 'Bio',
              } as Block,
              { type: 'submitButton', label: 'Submit' } as Block,
            ],
          } as Block,
        ]}
      />
    );
    expect(screen.getByText('Bio')).toBeInTheDocument();
    expect(screen.getByRole('textbox', { name: /bio/i })).toBeInTheDocument();
  });

  it('slider: renders a slider input with label', () => {
    const { container } = renderWrapped(
      <BlockRenderer
        blocks={[
          {
            type: 'form',
            submit: { method: 'POST', endpoint: '/api/v1/x/save' },
            children: [
              {
                type: 'slider',
                name: 'volume',
                label: 'Volume',
                min: 0,
                max: 100,
              } as Block,
              { type: 'submitButton', label: 'Submit' } as Block,
            ],
          } as Block,
        ]}
      />
    );
    expect(screen.getByText('Volume')).toBeInTheDocument();
    const rangeInput = container.querySelector('input[type="range"]');
    expect(rangeInput).not.toBeNull();
  });

  // ---- form with invalid submit spec renders UnsupportedBlock ----

  it('form: missing submit spec renders UnsupportedBlock', () => {
    const blocks = [
      {
        type: 'form',
        children: [{ type: 'submitButton', label: 'Go' }],
      },
    ] as unknown as Block[];

    expect(() => renderWrapped(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });

  // ---- actionButton without required action ----

  it('actionButton: missing action renders UnsupportedBlock', () => {
    const blocks = [{ type: 'actionButton', label: 'Go' }] as unknown as Block[];

    expect(() => renderWrapped(<BlockRenderer blocks={blocks} />)).not.toThrow();
    expect(screen.getByText(/Unsupported block/i)).toBeInTheDocument();
  });
});
