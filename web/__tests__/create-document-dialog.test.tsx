import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The create-document dialog — the front door's client half (#947 item 1).
 *
 * WHY THE REQUEST BODY IS THE THING UNDER TEST HERE
 * -------------------------------------------------
 * For a component whose whole job is to compose one POST, the request it sends
 * IS its outcome; what the server then does with it is pinned by
 * `tests/Api/DocumentCreateApiRealEngineTest.php`, and an e2e spec cannot help
 * because there is no `DELETE /api/documents/{id}` and a spec that created one
 * would litter the shared database permanently (the same reason
 * `document-record.spec.ts` writes nothing).
 *
 * The assertion that earns this file is
 * {@link 'sends every placeholder the template declares, blanks included'}. If
 * the dialog omitted the untouched fields — the obvious "don't send empty
 * values" tidy-up — the server would read the absent `dataRows` as "use the
 * template's SAMPLES" and issue the document reading `DEMO-0001` where its
 * reference number belongs. Every visible thing about that path succeeds: 201,
 * a document appears, no error anywhere. It surfaces when a recipient reads the
 * circular.
 *
 * The other two are about not lying: a document with no PDF is reported as a
 * document with no PDF and a reason (never as a failure — on a default install
 * it is the only outcome available), and Send is offered only to somebody who
 * may actually route.
 */

const push = jest.fn();
jest.mock('next/navigation', () => ({ useRouter: () => ({ push }) }));

const mockApiClient = jest.fn();
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => mockApiClient(...args),
}));

import {
  CreateDocumentDialog,
  type CreatableTemplate,
} from '@/components/documents/create-document-dialog';

const CIRCULAR: CreatableTemplate = {
  id: 5,
  name: 'Demo faculty circular',
  placeholders: [
    { key: 'reference', label: 'Reference', sample: 'DEMO-0001' },
    { key: 'date', label: 'Date', sample: '2026-01-15' },
  ],
};

/** A template with nothing to fill in — the case that must not render a form. */
const BARE: CreatableTemplate = { id: 9, name: 'Blank sheet', placeholders: [] };

function ok(body: unknown, status = 201) {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: async () => body,
  };
}

function mount(overrides: Partial<React.ComponentProps<typeof CreateDocumentDialog>> = {}) {
  const onCreated = jest.fn();
  const onSend = jest.fn();
  const props = {
    open: true,
    onOpenChange: jest.fn(),
    templates: [CIRCULAR, BARE],
    templatesLoading: false,
    templatesError: null,
    onCreated,
    canRoute: true,
    onSend,
    ...overrides,
  };
  render(<CreateDocumentDialog {...props} />);
  return { onCreated, onSend, props };
}

/**
 * Pick a template from the Radix select, and wait for the listbox to go.
 *
 * The wait matters because the next line usually asserts on the form the choice
 * REVEALS: returning while the popover is still mounted makes every following
 * query race the close animation. (Radix's select-inside-dialog combination also
 * logs an unacted-update warning here that this does not silence; it is
 * cosmetic, comes from the primitive rather than from this component, and the
 * assertions below are unaffected by it.)
 */
async function chooseTemplate(name: string) {
  await userEvent.click(screen.getByRole('combobox'));
  await userEvent.click(await screen.findByRole('option', { name }));
  await waitFor(() => expect(screen.queryByRole('option', { name })).not.toBeInTheDocument());
}

/** The JSON body of the POST the dialog issued. */
function submitted(): Record<string, unknown> | undefined {
  const call = [...mockApiClient.mock.calls]
    .reverse()
    .find(([url, init]) => url === '/api/v1/documents' && (init as RequestInit | undefined)?.method === 'POST');
  const body = (call?.[1] as RequestInit | undefined)?.body;
  return typeof body === 'string' ? (JSON.parse(body) as Record<string, unknown>) : undefined;
}

beforeEach(() => {
  mockApiClient.mockReset();
  push.mockReset();
});

describe('what the dialog sends', () => {
  it('sends every placeholder the template declares, blanks included', async () => {
    mockApiClient.mockResolvedValue(
      ok({ data: { id: 42, title: 'Demo faculty circular' }, render: { attempted: false, stored: false, reason: 'disabled' } })
    );
    mount();

    await chooseTemplate('Demo faculty circular');
    // Only ONE of the two fields is filled in. The other is left exactly as the
    // user found it, which is the case that matters.
    await userEvent.type(screen.getByLabelText('Reference'), 'FAC-2026-014');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    await waitFor(() => expect(submitted()).toBeDefined());
    const body = submitted()!;
    expect(body.document_template_id).toBe(5);
    // BOTH keys, and the untouched one as an empty string rather than absent.
    // Absent `dataRows` (or an absent key inside it) is the server's signal to
    // substitute the template's demonstration text — see this file's docblock.
    expect(body.dataRows).toEqual([{ reference: 'FAC-2026-014', date: '' }]);
    // No `render` key: "render if you can". Sending `true` would turn a
    // perfectly good document into a 503 on every install with the render tier
    // off, which is the default.
    expect(body).not.toHaveProperty('render');
  });

  it('omits a blank title so the server names the document after its template', async () => {
    mockApiClient.mockResolvedValue(ok({ data: { id: 42, title: 'Demo faculty circular' }, render: {} }));
    mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    await waitFor(() => expect(submitted()).toBeDefined());
    expect(submitted()).not.toHaveProperty('title');
  });

  it('carries a typed title through verbatim, trimmed', async () => {
    mockApiClient.mockResolvedValue(ok({ data: { id: 42, title: 'x' }, render: {} }));
    mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.type(screen.getByLabelText('Title'), '  تعميم الفصل الدراسي  ');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    await waitFor(() => expect(submitted()).toBeDefined());
    expect(submitted()!.title).toBe('تعميم الفصل الدراسي');
  });

  it('abandons values keyed to a template the author moved away from', async () => {
    mockApiClient.mockResolvedValue(ok({ data: { id: 42, title: 'x' }, render: {} }));
    mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.type(screen.getByLabelText('Reference'), 'FAC-1');
    await chooseTemplate('Blank sheet');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    await waitFor(() => expect(submitted()).toBeDefined());
    // The bare template declares no placeholders, so the row is empty — NOT
    // `{reference: 'FAC-1'}`, which the server refuses by name and which would
    // be a baffling way to discover that switching template kept the old values.
    expect(submitted()!.dataRows).toEqual([{}]);
  });
});

describe('what the dialog renders', () => {
  it('shows the placeholder sample as a hint and never as a value', async () => {
    mount();
    await chooseTemplate('Demo faculty circular');

    const reference = screen.getByLabelText('Reference') as HTMLInputElement;
    expect(reference.value).toBe('');
    expect(reference.placeholder).toBe('DEMO-0001');
    // A pre-filled sample is the sample that gets issued.
  });

  it('marks user-supplied content as bidi-neutral (Arabic is a hard requirement)', async () => {
    mount();
    await chooseTemplate('Demo faculty circular');

    // The VALUE boxes carry their own direction, so an Arabic reference reads
    // right-to-left inside an English interface. The chrome around them does
    // not — it follows the reader's own setting.
    expect(screen.getByLabelText('Reference')).toHaveAttribute('dir', 'auto');
    expect(screen.getByLabelText('Title')).toHaveAttribute('dir', 'auto');
  });

  it('offers no fields, and says so, for a template that declares none', async () => {
    mount();
    await chooseTemplate('Blank sheet');

    expect(screen.getByText(/no fields to fill in/i)).toBeInTheDocument();
    expect(screen.queryByLabelText('Reference')).not.toBeInTheDocument();
  });

  it('states that no templates are available rather than offering an empty picker', () => {
    mount({ templates: [] });

    expect(screen.getByText(/no templates available/i)).toBeInTheDocument();
    expect(screen.queryByRole('combobox')).not.toBeInTheDocument();
  });

  it('tells a template load FAILURE apart from having no templates', () => {
    mount({ templates: [], templatesError: 'Failed to load the full template list' });

    expect(screen.getByText('Failed to load the full template list')).toBeInTheDocument();
    expect(screen.queryByText(/no templates available/i)).not.toBeInTheDocument();
  });
});

describe('after the document exists', () => {
  it('reports a document with no PDF as a document with no PDF, and why', async () => {
    mockApiClient.mockResolvedValue(
      ok({
        data: { id: 42, title: 'Semester circular' },
        render: { attempted: false, stored: false, reason: 'disabled' },
      })
    );
    const { onCreated } = mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    // Never "failed": the document is real, routable and auditable, and on an
    // instance with the render tier off this is the ONLY outcome available.
    expect(await screen.findByText(/server-side rendering is switched off/i)).toBeInTheDocument();
    expect(screen.getByText('Semester circular')).toBeInTheDocument();
    await waitFor(() =>
      expect(onCreated).toHaveBeenCalledWith(
        expect.objectContaining({ id: 42, rendered: false, renderReason: 'disabled' })
      )
    );
  });

  it('says nothing about rendering when the PDF was produced', async () => {
    mockApiClient.mockResolvedValue(
      ok({ data: { id: 42, title: 'Semester circular' }, render: { attempted: true, stored: true, reason: null } })
    );
    mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    expect(await screen.findByText('Semester circular')).toBeInTheDocument();
    expect(screen.queryByText(/switched off|could not be reached|without a PDF/i)).not.toBeInTheDocument();
  });

  it('hands off to routing, and only for somebody who may route', async () => {
    mockApiClient.mockResolvedValue(
      ok({ data: { id: 42, title: 'Semester circular' }, render: { attempted: true, stored: true, reason: null } })
    );
    const { onSend } = mount({ canRoute: true });

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));
    await userEvent.click(await screen.findByRole('button', { name: /send it/i }));

    expect(onSend).toHaveBeenCalledWith(42);
  });

  it('offers no Send to somebody who may not route', async () => {
    mockApiClient.mockResolvedValue(
      ok({ data: { id: 42, title: 'Semester circular' }, render: { stored: true, reason: null } })
    );
    mount({ canRoute: false });

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    // A Send that 403s is worse than no Send at all.
    expect(await screen.findByText('Semester circular')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /send it/i })).not.toBeInTheDocument();
  });
});

describe('when the server refuses', () => {
  it("shows the server's own words rather than a paraphrase", async () => {
    mockApiClient.mockResolvedValue(
      ok({ error: 'These fields are not placeholders on this template: refrence' }, 422)
    );
    const { onCreated } = mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    expect(
      await screen.findByText('These fields are not placeholders on this template: refrence')
    ).toBeInTheDocument();
    // And it stays on the form, with the values still there to correct.
    expect(screen.getByLabelText('Reference')).toBeInTheDocument();
    expect(onCreated).not.toHaveBeenCalled();
  });

  it('does not claim success for a 201 that identifies no document', async () => {
    // There would be nothing to send and nothing to link to, so reporting this
    // as done would strand the author with a document they cannot find.
    mockApiClient.mockResolvedValue(ok({ data: {}, render: { stored: false } }));
    const { onCreated } = mount();

    await chooseTemplate('Demo faculty circular');
    await userEvent.click(screen.getByRole('button', { name: /create document/i }));

    expect(await screen.findByText(/did not identify it/i)).toBeInTheDocument();
    expect(onCreated).not.toHaveBeenCalled();
  });
});
