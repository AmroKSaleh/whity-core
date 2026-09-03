/**
 * WC-532 item 3: `variant` / `variantCase` — discriminated sub-forms.
 *
 * THE ONE BEHAVIOUR THAT MATTERS IS THE PAYLOAD.
 *
 * Rendering the right branch is the visible half and the easy half. The reason
 * this is a new block rather than a `visibleWhen` flag is that a hidden input
 * KEEPS its value and still submits — that is the documented convention, and it
 * is right, because hiding is a display decision and the server is authoritative
 * over what it accepts.
 *
 * A discriminated union means the opposite: the branches that were not chosen
 * do not exist. `{kind:'number', value: 5}` — never that plus the text branch's
 * fields riding along for the server to sort out.
 *
 * So every test here that renders a branch is paired with one that asserts what
 * reached the wire. A test that only checked which inputs were on screen would
 * pass just as happily against thirteen `visibleWhen` sections, which is the
 * thing this feature exists because it cannot do.
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
  mockApiClient.mockResolvedValue(stubResponse(true, 200, {}));
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

/**
 * Both branches declare a field named `value` on purpose — that is the shape a
 * discriminated union has on the wire, and the validator permits it precisely
 * because the cases are mutually exclusive.
 */
function variantForm(opts: { numberRequired?: boolean } = {}): Block {
  return {
    type: 'form',
    submit: { method: 'POST', endpoint: '/api/v1/x/save' },
    children: [
      { type: 'textInput', name: 'title', label: 'Title' },
      // A TEXT input as the discriminator, not the `select` the showcase uses.
      // A discriminator is simply a field name in the same form, so any input
      // type works; the host's `select` is a Radix listbox rather than a native
      // <select>, and driving it in jsdom would test the widget instead of the
      // branching. The showcase carries the realistic select shape and the
      // contract test validates it.
      { type: 'textInput', name: 'kind', label: 'Kind' },
      {
        type: 'variant',
        discriminator: 'kind',
        children: [
          {
            type: 'variantCase',
            when: 'number',
            children: [
              { type: 'numberInput', name: 'value', label: 'Expected value' },
              // The required flag goes on `tolerance`, which exists ONLY in
              // this branch. Putting it on `value` would prove nothing: the
              // text branch declares `value` too, so its entry satisfies the
              // required check and the test passes with or without the
              // exemption.
              {
                type: 'numberInput',
                name: 'tolerance',
                label: 'Tolerance',
                ...(opts.numberRequired ? { required: true } : {}),
              },
            ],
          },
          {
            type: 'variantCase',
            when: 'text',
            children: [{ type: 'textInput', name: 'value', label: 'Expected text' }],
          },
        ],
      },
      { type: 'submitButton', label: 'Save' },
    ],
  } as unknown as Block;
}

/** The JSON body the form actually sent. */
function sentBody(): Record<string, unknown> {
  const call = mockApiClient.mock.calls.at(-1);
  const init = call?.[1] as { body?: string } | undefined;
  return JSON.parse(init?.body ?? '{}') as Record<string, unknown>;
}

describe('variant — which branch renders', () => {
  it('renders nothing until a discriminator value is chosen', () => {
    renderWrapped(<BlockRenderer blocks={[variantForm()]} />);

    // A union with no type selected has no shape yet. Defaulting to the first
    // branch would put its fields into the payload for a record whose type
    // nobody picked.
    expect(screen.queryByLabelText('Expected value')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Expected text')).not.toBeInTheDocument();
  });

  it('renders only the matching case, and swaps when the discriminator changes', async () => {
    const user = userEvent.setup();
    renderWrapped(<BlockRenderer blocks={[variantForm()]} />);

    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'number');
    expect(screen.getByLabelText('Expected value')).toBeInTheDocument();
    expect(screen.queryByLabelText('Expected text')).not.toBeInTheDocument();

    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'text');
    expect(screen.getByLabelText('Expected text')).toBeInTheDocument();
    expect(screen.queryByLabelText('Expected value')).not.toBeInTheDocument();
  });
});

describe('variant — what reaches the wire', () => {
  it('sends the chosen branch and omits the other entirely', async () => {
    const user = userEvent.setup();
    renderWrapped(<BlockRenderer blocks={[variantForm()]} />);

    await user.type(screen.getByLabelText('Title'), 'Q1');
    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'number');
    await user.type(screen.getByLabelText('Expected value'), '42');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    const body = sentBody();

    expect(body.title).toBe('Q1');
    expect(body.kind).toBe('number');
    // A string, not 42: `numberInput` submits its raw field value. That is
    // pre-existing renderer behaviour and not this feature's to change —
    // asserted as it actually is rather than as it ought to be.
    expect(body.value).toBe('42');
    // The whole point. Under `visibleWhen` the text branch's `value` would have
    // overwritten this one, and `tolerance` would ride along empty.
    expect(Object.keys(body).sort()).toEqual(['kind', 'title', 'value']);
  });

  /**
   * THE TEST THAT ACTUALLY EXERCISES THE EXCLUSION, and the reason it is
   * written around `tolerance` rather than `value`.
   *
   * An input that never rendered never enters the value map, so a branch the
   * user never opened is absent for free — no exclusion logic required. And
   * `value` exists in BOTH branches, so a stale entry is simply overwritten by
   * the chosen branch's own field.
   *
   * `tolerance` is the case neither of those covers: it exists only in the
   * numeric branch, so opening that branch, typing into it, and then switching
   * away leaves a real value in the map under a name the chosen branch does not
   * use. Without the exclusion it rides along into a text answer's payload.
   *
   * An earlier version of this file asserted on `value` and passed against the
   * unfixed code, which is the whole reason this note is here.
   */
  it('drops a field from a branch the user opened and then switched away from', async () => {
    const user = userEvent.setup();
    renderWrapped(<BlockRenderer blocks={[variantForm()]} />);

    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'number');
    await user.type(screen.getByLabelText('Expected value'), '7');
    await user.type(screen.getByLabelText('Tolerance'), '3');

    // Change of mind: this is a free-text answer after all.
    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'text');
    // Cleared first: `value` is declared in BOTH branches, so its entry
    // survives the switch and the text input renders holding '7'. That
    // persistence is deliberate and useful — switch back and the work is still
    // there — but it means typing here would append rather than replace.
    await user.clear(screen.getByLabelText('Expected text'));
    await user.type(screen.getByLabelText('Expected text'), 'hello');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    const body = sentBody();

    expect(body.value).toBe('hello');
    expect(body).not.toHaveProperty('tolerance');
    expect(Object.keys(body).sort()).toEqual(['kind', 'value']);
  });

  it('sends no branch fields at all when no case is selected', async () => {
    const user = userEvent.setup();
    renderWrapped(<BlockRenderer blocks={[variantForm()]} />);

    await user.type(screen.getByLabelText('Title'), 'Untyped');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(Object.keys(sentBody())).toEqual(['title']);
  });
});

describe('variant — required fields follow the chosen branch', () => {
  it('does not block a submit on a required field in an unchosen branch', async () => {
    const user = userEvent.setup();
    renderWrapped(<BlockRenderer blocks={[variantForm({ numberRequired: true })]} />);

    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'text');
    await user.type(screen.getByLabelText('Expected text'), 'hello');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    // The numeric branch's required field is not on screen and not in the
    // payload. Enforcing it would produce a Save button that does nothing while
    // pointing at a field the user cannot see — the worst version of this
    // feature, and the reason validation and payload use one answer.
    await waitFor(() => expect(mockApiClient).toHaveBeenCalled());
    expect(sentBody().value).toBe('hello');
  });

  it('still enforces a required field in the branch that IS chosen', async () => {
    const user = userEvent.setup();
    renderWrapped(<BlockRenderer blocks={[variantForm({ numberRequired: true })]} />);

    await user.clear(screen.getByLabelText('Kind'));
    await user.type(screen.getByLabelText('Kind'), 'number');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    // The control: the exemption above must be scoped to unchosen branches, not
    // a blanket amnesty for anything inside a variant.
    expect(await screen.findByText(/Tolerance is required/i)).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
  });
});
