/**
 * A `fieldArray` with a `source` seeds its rows from what is already stored,
 * and its form submits a REPLACEMENT of that stored set.
 *
 * THAT IS THE ENTIRE REASON THIS FILE EXISTS. The endpoint behind such a form
 * (`PUT /api/v1/forms/{id}/fields`, and any endpoint shaped like it) reconciles
 * by key and DELETES every stored row the payload omits. So an editor that
 * rendered zero rows and then saved would not merely fail to show the
 * questions — it would destroy them, and the answers already given to them
 * would lose their labels, and the request would return 200.
 *
 * Every state in which this block has no rows on screen therefore has to be
 * told apart from the single state in which "no rows" is TRUE:
 *
 *   loading          → must not submit   (`refusesToSubmitWhileLoading`)
 *   failed           → must not submit   (`refusesToSubmitAfterAFailedLoad`)
 *   nothing bound    → must not fetch or submit
 *   genuinely empty  → MUST submit — otherwise the guard is just a lock
 *
 * The refusal tests assert that NO WRITE REQUEST WAS ISSUED AT ALL, not that a
 * message appeared. A message is a claim about the UI; a request that never
 * left is the property the data depends on.
 */

import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block, FormBlock } from '@/lib/plugin-features';
import { FormProvider, useFormBlockContext } from '@/components/plugin/blocks/form-context';
import { apiClient } from '@/lib/api-client';
import { ToastProvider } from '@/lib/toast-context';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

function stubResponse(ok: boolean, status: number, body: unknown): Response {
  return { ok, status, json: () => Promise.resolve(body) } as unknown as Response;
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
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

/**
 * Every call that would MUTATE something — the thing a save actually is.
 *
 * `usePluginData` fetches with no `method`, so a GET is not counted; anything
 * carrying a method and a body is a write, whatever its verb or URL. Written
 * this way on purpose: a regression that submitted the destructive payload to
 * some other path would still be caught.
 */
function writes(): { url: string; body: unknown }[] {
  return mockApiClient.mock.calls
    .filter(([, options]) => {
      const o = options as { method?: string; body?: string } | undefined;
      return typeof o?.method === 'string' && o.method !== 'GET';
    })
    .map(([url, options]) => {
      const o = options as { body?: string };
      return { url: String(url), body: o.body === undefined ? undefined : JSON.parse(o.body) };
    });
}

/** The three questions a form already holds, as the flat read returns them. */
const STORED = [
  {
    id: 7,
    form_id: 1,
    field_key: 'full_name',
    field_type: 'text',
    label: { en: 'Full name', ar: 'الاسم' },
    help_text: null,
    is_required: true,
    options: [],
    validation: {},
    prefill_source: 'profile.display_name',
    section_key: null,
    position: 1,
  },
  {
    id: 8,
    form_id: 1,
    field_key: 'department',
    field_type: 'select',
    label: { en: 'Department' },
    help_text: 'Pick one',
    is_required: false,
    // NOTHING IN THE TEMPLATE EDITS THIS. It must still come back out.
    options: [{ value: 'ops', label: { en: 'Operations' } }],
    validation: {},
    prefill_source: null,
    section_key: 'about',
    position: 2,
  },
  {
    id: 9,
    form_id: 1,
    field_key: 'notes',
    field_type: 'textarea',
    label: { en: 'Notes' },
    help_text: null,
    is_required: false,
    options: [],
    validation: {},
    prefill_source: null,
    section_key: null,
    position: 3,
  },
];

/**
 * The builder's Fields section in miniature: a selector naming the form, and a
 * form whose fieldArray both reads that form's questions and replaces them.
 */
function editor(params?: { param: string; from: string }[]): Block[] {
  return [
    {
      type: 'selector',
      name: 'builderForm',
      label: 'Form',
      source: '/api/v1/forms',
      valueField: 'id',
      labelField: 'form_key',
    } as Block,
    {
      type: 'form',
      submit: { method: 'PUT', endpoint: '/api/v1/forms/{builderForm}/fields' },
      children: [
        {
          type: 'fieldArray',
          name: 'fields',
          label: 'Questions',
          itemLabel: 'Question',
          source: '/api/v1/form-fields',
          params: params ?? [{ param: 'form_id', from: 'builderForm' }],
          children: [
            { type: 'textInput', name: 'field_key', label: 'Key', required: true },
            { type: 'textArea', name: 'help_text', label: 'Help text', rows: 2 },
            { type: 'checkbox', name: 'is_required', label: 'Required' },
          ],
        },
        { type: 'submitButton', label: 'Save questions' },
      ],
    } as Block,
  ];
}

/** Answer the selector's list, and the field read, and nothing else. */
function routeApi(fields: unknown, opts?: { fieldsOk?: boolean }) {
  mockApiClient.mockImplementation((url: string, options?: unknown) => {
    const method = (options as { method?: string } | undefined)?.method;
    if (typeof method === 'string' && method !== 'GET') {
      return Promise.resolve(stubResponse(true, 200, { data: [] }));
    }
    if (url.startsWith('/api/v1/forms?') || url === '/api/v1/forms') {
      return Promise.resolve(stubResponse(true, 200, { data: [{ id: '1', form_key: 'onboarding' }, { id: '2', form_key: 'exit' }] }));
    }
    if (url.startsWith('/api/v1/form-fields')) {
      return opts?.fieldsOk === false
        ? Promise.resolve(stubResponse(false, 500, {}))
        : Promise.resolve(stubResponse(true, 200, { data: fields }));
    }
    return Promise.resolve(stubResponse(true, 200, { data: [] }));
  });
}

/**
 * Press Save and assert nothing left the browser.
 *
 * The button is disabled here, so this is the real gesture a real person makes
 * and it is a no-op — which is the user-facing half of the guarantee. The other
 * half, that `submit()` ITSELF refuses even when reached directly, cannot be
 * shown through the DOM: React reads `disabled` off the element's PROPS when it
 * decides whether to dispatch a click, so stripping the attribute changes
 * nothing. It is proven instead against `FormProvider` directly, in the last
 * describe block in this file.
 */
async function saveIsRefused(): Promise<void> {
  const save = screen.getByRole('button', { name: /save questions/i });
  expect(save).toBeDisabled();
  await userEvent.click(save);
  expect(writes()).toEqual([]);
}

/** Choose a form in the selector, which is what binds the array's `params`. */
async function pickForm(name: string) {
  await waitFor(() => expect(screen.getByRole('combobox', { name: /form/i })).not.toBeDisabled());
  await userEvent.click(screen.getByRole('combobox', { name: /form/i }));
  await userEvent.click(await screen.findByRole('option', { name }));
}

describe('fieldArray with a source — seeding', () => {
  it('seeds one card per stored row, filled in', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');

    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));
    const keys = screen.getAllByLabelText('Key') as HTMLInputElement[];
    expect(keys.map((i) => i.value)).toEqual(['full_name', 'department', 'notes']);

    // Shapes the template can actually edit: a null help_text is '' and not the
    // four-letter word 'null'; a boolean is a checked box.
    const help = screen.getAllByLabelText('Help text') as HTMLTextAreaElement[];
    expect(help.map((i) => i.value)).toEqual(['', 'Pick one', '']);
    const required = screen.getAllByLabelText('Required') as HTMLInputElement[];
    expect(required[0]).toBeChecked();
    expect(required[1]).not.toBeChecked();
  });

  it('fetches with the bound record appended, and submits to the endpoint that names it', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/form-fields?form_id=1', expect.anything())
    );

    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));
    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));

    await waitFor(() => expect(writes()).toHaveLength(1));
    expect(writes()[0].url).toBe('/api/v1/forms/1/fields');
  });

  it('submits the seeded set back in the order the cards are in, losing nothing', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));

    // Move question 3 up twice → notes, full_name, department.
    await userEvent.click(screen.getByRole('button', { name: /move question 3 up/i }));
    await userEvent.click(screen.getByRole('button', { name: /move question 2 up/i }));

    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));
    await waitFor(() => expect(writes()).toHaveLength(1));

    const payload = writes()[0].body as { fields: { field_key: string }[] };
    expect(payload.fields.map((f) => f.field_key)).toEqual(['notes', 'full_name', 'department']);
    // Reordering must not lose a question. This is the assertion that would
    // have caught a "reorder" that was really a replace-with-what-is-visible.
    expect(payload.fields).toHaveLength(3);
  });

  it('carries back facts the editor never rendered an input for', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));

    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));
    await waitFor(() => expect(writes()).toHaveLength(1));

    const payload = writes()[0].body as { fields: Record<string, unknown>[] };
    const department = payload.fields.find((f) => f.field_key === 'department');
    // `options` has no input in the template. Dropping it here would make the
    // server reject the whole save (a select must have options) — or worse,
    // under a laxer endpoint, silently empty a question's choices.
    expect(department?.options).toEqual([{ value: 'ops', label: { en: 'Operations' } }]);
    expect(department?.field_type).toBe('select');
  });

  it('an edit to one card changes only that card', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));

    await userEvent.clear(screen.getAllByLabelText('Help text')[0]);
    await userEvent.type(screen.getAllByLabelText('Help text')[0], 'As on your ID');

    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));
    await waitFor(() => expect(writes()).toHaveLength(1));

    const payload = writes()[0].body as { fields: Record<string, unknown>[] };
    expect(payload.fields[0].help_text).toBe('As on your ID');
    expect(payload.fields[1].help_text).toBe('Pick one');
  });

  it('adding and removing cards is what adds and withdraws questions', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));

    await userEvent.click(screen.getByRole('button', { name: /remove question 2/i }));
    await userEvent.click(screen.getByRole('button', { name: /add question/i }));
    await userEvent.type(screen.getAllByLabelText('Key')[2], 'start_date');

    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));
    await waitFor(() => expect(writes()).toHaveLength(1));

    const payload = writes()[0].body as { fields: { field_key: string }[] };
    expect(payload.fields.map((f) => f.field_key)).toEqual(['full_name', 'notes', 'start_date']);
  });
});

describe('fieldArray with a source — the destructive case', () => {
  it('refusesToSubmitWhileLoading: a save pressed before the rows arrive sends NOTHING', async () => {
    // The field read never settles. This is the shape of a slow backend, and it
    // is exactly what the block looks like at first paint on every load.
    let releaseFields: (() => void) | undefined;
    const pending = new Promise<Response>((resolve) => {
      releaseFields = () => resolve(stubResponse(true, 200, { data: STORED }));
    });
    mockApiClient.mockImplementation((url: string, options?: unknown) => {
      const method = (options as { method?: string } | undefined)?.method;
      if (typeof method === 'string' && method !== 'GET') {
        return Promise.resolve(stubResponse(true, 200, { data: [] }));
      }
      if (url.startsWith('/api/v1/forms?') || url === '/api/v1/forms') {
        return Promise.resolve(stubResponse(true, 200, { data: [{ id: '1', form_key: 'onboarding' }] }));
      }
      if (url.startsWith('/api/v1/form-fields')) return pending;
      return Promise.resolve(stubResponse(true, 200, { data: [] }));
    });

    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');

    // The block says what it is doing, and shows no rows and no "Add".
    await waitFor(() => expect(screen.getByText(/loading what is already saved/i)).toBeInTheDocument());
    expect(screen.queryByLabelText('Key')).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /add question/i })).not.toBeInTheDocument();

    await saveIsRefused();

    // And once the rows do arrive, the same button saves the real set.
    releaseFields?.();
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));
    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));
    await waitFor(() => expect(writes()).toHaveLength(1));
    expect((writes()[0].body as { fields: unknown[] }).fields).toHaveLength(3);
  });

  it('refusesToSubmitAfterAFailedLoad: a failed read never degrades to "no rows"', async () => {
    routeApi(STORED, { fieldsOk: false });
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');

    await waitFor(() =>
      expect(screen.getByText(/could not load what is already saved/i)).toBeInTheDocument()
    );
    expect(screen.queryByRole('button', { name: /add question/i })).not.toBeInTheDocument();

    await saveIsRefused();
  });

  it('does not fetch, and cannot save, until every declared param is bound', async () => {
    routeApi(STORED);
    renderWrapped(<BlockRenderer blocks={editor()} />);

    // No form picked yet. `/api/v1/form-fields` with no form_id answers 200 with
    // an empty list, so fetching here would produce a confident, wrong, EMPTY
    // seed — which is the payload that deletes everything.
    await waitFor(() => expect(mockApiClient).toHaveBeenCalledWith('/api/v1/forms', expect.anything()));
    expect(mockApiClient).not.toHaveBeenCalledWith(
      expect.stringContaining('/api/v1/form-fields'),
      expect.anything()
    );

    // And it says so on screen rather than looking like a form with no rows.
    expect(screen.getByText(/no record selected/i)).toBeInTheDocument();
    await saveIsRefused();
  });

  it('un-seeds when the bound record changes, so one form\'s questions cannot be saved onto another', async () => {
    // Form 1 answers immediately; form 2 hangs. The window between choosing 2
    // and its rows arriving is the dangerous one: the array still holds form 1's
    // rows, and the submit endpoint now names form 2.
    let releaseTwo: (() => void) | undefined;
    const two = new Promise<Response>((resolve) => {
      releaseTwo = () => resolve(stubResponse(true, 200, { data: [] }));
    });
    mockApiClient.mockImplementation((url: string, options?: unknown) => {
      const method = (options as { method?: string } | undefined)?.method;
      if (typeof method === 'string' && method !== 'GET') {
        return Promise.resolve(stubResponse(true, 200, { data: [] }));
      }
      if (url.startsWith('/api/v1/forms?') || url === '/api/v1/forms') {
        return Promise.resolve(stubResponse(true, 200, { data: [{ id: '1', form_key: 'onboarding' }, { id: '2', form_key: 'exit' }] }));
      }
      if (url === '/api/v1/form-fields?form_id=1') {
        return Promise.resolve(stubResponse(true, 200, { data: STORED }));
      }
      if (url === '/api/v1/form-fields?form_id=2') return two;
      return Promise.resolve(stubResponse(true, 200, { data: [] }));
    });

    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));

    await pickForm('exit');

    // Form 1's cards are gone the moment the selection moves — not left on
    // screen looking editable against a form they do not belong to.
    await waitFor(() => expect(screen.queryByLabelText('Key')).not.toBeInTheDocument());

    await saveIsRefused();

    releaseTwo?.();
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /add question/i })).toBeInTheDocument()
    );
  });

  it('a record that genuinely holds no rows IS saveable — the guard is not just a lock', async () => {
    routeApi([]);
    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');

    // Empty, but KNOWN empty: the Add button is back and the save is live.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /add question/i })).toBeInTheDocument()
    );
    // The hold lifts one commit after the rows land — a child cannot write into
    // its parent's state during render — so this waits rather than asserting on
    // the same tick the Add button appeared.
    await waitFor(() =>
      expect(screen.getByRole('button', { name: /save questions/i })).not.toBeDisabled()
    );
    const save = screen.getByRole('button', { name: /save questions/i });

    await userEvent.click(screen.getByRole('button', { name: /add question/i }));
    await userEvent.type(screen.getByLabelText('Key'), 'first_question');
    await userEvent.click(save);

    await waitFor(() => expect(writes()).toHaveLength(1));
    expect((writes()[0].body as { fields: { field_key: string }[] }).fields).toEqual([
      { field_key: 'first_question' },
    ]);
  });

  it('offers a retry after a failed load, and saves normally once it succeeds', async () => {
    let fail = true;
    mockApiClient.mockImplementation((url: string, options?: unknown) => {
      const method = (options as { method?: string } | undefined)?.method;
      if (typeof method === 'string' && method !== 'GET') {
        return Promise.resolve(stubResponse(true, 200, { data: [] }));
      }
      if (url.startsWith('/api/v1/forms?') || url === '/api/v1/forms') {
        return Promise.resolve(stubResponse(true, 200, { data: [{ id: '1', form_key: 'onboarding' }] }));
      }
      if (url.startsWith('/api/v1/form-fields')) {
        if (fail) {
          fail = false;
          return Promise.resolve(stubResponse(false, 500, {}));
        }
        return Promise.resolve(stubResponse(true, 200, { data: STORED }));
      }
      return Promise.resolve(stubResponse(true, 200, { data: [] }));
    });

    renderWrapped(<BlockRenderer blocks={editor()} />);
    await pickForm('onboarding');
    await waitFor(() =>
      expect(screen.getByText(/could not load what is already saved/i)).toBeInTheDocument()
    );

    await userEvent.click(screen.getByRole('button', { name: /retry/i }));
    await waitFor(() => expect(screen.getAllByLabelText('Key')).toHaveLength(3));

    await userEvent.click(screen.getByRole('button', { name: /save questions/i }));
    await waitFor(() => expect(writes()).toHaveLength(1));
    expect((writes()[0].body as { fields: unknown[] }).fields).toHaveLength(3);
  });
});

describe('fieldArray with NO source — unchanged', () => {
  it('still starts empty, saves immediately, and never fetches', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, { data: [] }));
    const blocks: Block[] = [
      {
        type: 'form',
        submit: { method: 'POST', endpoint: '/api/v1/x/save' },
        children: [
          {
            type: 'fieldArray',
            name: 'lines',
            label: 'Lines',
            itemLabel: 'Line',
            children: [{ type: 'textInput', name: 'label', label: 'Label' }],
          },
          { type: 'submitButton', label: 'Save' },
        ],
      } as Block,
    ];

    renderWrapped(<BlockRenderer blocks={blocks} />);

    // No hold, no fetch, no pending state: an array with no source is the
    // composer it has always been.
    expect(screen.getByRole('button', { name: /^save$/i })).not.toBeDisabled();
    expect(screen.getByRole('button', { name: /add line/i })).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();

    await userEvent.click(screen.getByRole('button', { name: /add line/i }));
    await userEvent.type(screen.getByLabelText('Label'), 'one');
    await userEvent.click(screen.getByRole('button', { name: /^save$/i }));

    await waitFor(() => expect(writes()).toHaveLength(1));
    expect(writes()[0].body).toEqual({ lines: [{ label: 'one' }] });
  });
});

/**
 * The gate itself, reached directly.
 *
 * Everything above presses a button, and a button can only ever prove what a
 * user can do. What the stored questions actually depend on is that
 * `FormProvider.submit()` REFUSES on its own — so that a future submit path
 * (a keyboard shortcut, an auto-save, a different button) inherits the refusal
 * instead of having to remember it. React will not dispatch a click to a
 * component whose props say `disabled`, so the only way to call it is to hold
 * the context and call it.
 *
 * Two independent layers are pinned here, and either one alone would be enough
 * to stop the destructive payload:
 *
 *  1. a registered hold makes `submit()` send nothing at all;
 *  2. a sourced `fieldArray` is not seeded into the value map, so even a submit
 *     that got past the hold omits the key entirely rather than sending `[]` —
 *     and `PUT /api/v1/forms/{id}/fields` answers an absent `fields` with a 422,
 *     where it answers `[]` by deleting every question and returning 200.
 */
describe('the refusal, reached without a button', () => {
  function Probe() {
    const ctx = useFormBlockContext();
    if (ctx === null) return null;
    return (
      <>
        <button type="button" onClick={() => ctx.holdSubmit('fields', 'not ready')}>hold</button>
        <button type="button" onClick={() => ctx.holdSubmit('fields', null)}>release</button>
        <button type="button" onClick={() => ctx.submit()}>probe submit</button>
        <span data-testid="held">{String(ctx.submitHeld)}</span>
        <span data-testid="error">{ctx.errors['fields'] ?? ''}</span>
      </>
    );
  }

  const formBlock = {
    type: 'form',
    submit: { method: 'PUT', endpoint: '/api/v1/forms/1/fields' },
    children: [
      {
        type: 'fieldArray',
        name: 'fields',
        label: 'Questions',
        source: '/api/v1/form-fields',
        params: [{ param: 'form_id', from: 'builderForm' }],
        children: [{ type: 'textInput', name: 'field_key', label: 'Key' }],
      },
      { type: 'submitButton', label: 'Save questions' },
    ],
  } as FormBlock;

  function renderProbe() {
    return render(
      <ToastProvider>
        <FormProvider block={formBlock}>
          <Probe />
        </FormProvider>
      </ToastProvider>
    );
  }

  it('a hold makes submit() send nothing, and says why', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, { data: [] }));
    renderProbe();

    await userEvent.click(screen.getByRole('button', { name: 'hold' }));
    expect(screen.getByTestId('held')).toHaveTextContent('true');

    await userEvent.click(screen.getByRole('button', { name: 'probe submit' }));

    expect(writes()).toEqual([]);
    expect(screen.getByTestId('error')).toHaveTextContent('not ready');
  });

  it('and once released, the same submit goes — carrying NO `fields` key at all', async () => {
    mockApiClient.mockResolvedValue(stubResponse(true, 200, { data: [] }));
    renderProbe();

    await userEvent.click(screen.getByRole('button', { name: 'hold' }));
    await userEvent.click(screen.getByRole('button', { name: 'release' }));
    expect(screen.getByTestId('held')).toHaveTextContent('false');

    await userEvent.click(screen.getByRole('button', { name: 'probe submit' }));
    await waitFor(() => expect(writes()).toHaveLength(1));

    // THE SECOND LINE OF DEFENCE. Nothing seeded this array, so its key is
    // absent from the payload — not present and empty. `[]` is the byte
    // sequence that deletes every question; an absent key is the one the server
    // refuses with a 422.
    const body = writes()[0].body as Record<string, unknown>;
    expect(body).not.toHaveProperty('fields');
    expect(JSON.stringify(body)).not.toContain('"fields":[]');
  });
});
