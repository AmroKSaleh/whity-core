/**
 * #949: a form's `dataSource.path` is interpolated, and NOT FETCHED AT ALL
 * until every `{token}` in it is bound.
 *
 * The path was previously handed to `apiClient` verbatim, so a form declared on
 * a record pane fetched `/api/v1/people/%7Brecord%7D` and pre-populated with
 * nothing. What makes that worse than a broken read is what an empty form
 * means: it is indistinguishable from a record that genuinely holds no values
 * yet, and against an update endpoint that replaces rather than merges,
 * submitting it writes blanks over every field the user did not retype — and
 * returns success.
 *
 * So the assertions here come in pairs, and the negative half is the load
 * bearing one. It is not enough that the resolved path is right; the
 * UNRESOLVED path must produce no request. `/api/v1/people/{record}` truncated
 * to `/api/v1/people/` is the collection, and that request would not fail — it
 * would succeed and prefill the form with the first thing in the list.
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

function stubResponse(body: unknown): Response {
  return { ok: true, status: 200, json: () => Promise.resolve(body) } as unknown as Response;
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
  mockApiClient.mockImplementation((url: string) => {
    // The selector's own options.
    if (url.startsWith('/api/v1/people?') || url === '/api/v1/people') {
      return Promise.resolve(
        stubResponse({ data: [{ id: '7', name: 'Ada' }, { id: '8', name: 'Grace' }] })
      );
    }
    // Any ONE person: the stored record the form is there to edit.
    if (url.startsWith('/api/v1/people/')) {
      // #981: the `{ data: … }` envelope, which is what core's handlers return
      // and what the desktop twin has always required.
      return Promise.resolve(stubResponse({ data: { full_name: 'Ada Lovelace', title: 'Engineer' } }));
    }
    return Promise.resolve(stubResponse({}));
  });
});

function renderWrapped(ui: React.ReactElement) {
  return render(ui, { wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider> });
}

/**
 * The edit form at the heart of the issue: one record, named by a token.
 *
 * `token` is which binding names it — `record` is the reserved one a record
 * route seeds, anything else is a `selector` on the page. The form is the same
 * form either way, which is the property being tested.
 */
function editForm(token = 'record'): Block {
  return {
    type: 'form',
    submit: { method: 'PATCH', endpoint: `/api/v1/people/{${token}}` },
    dataSource: { method: 'GET', path: `/api/v1/people/{${token}}` },
    children: [
      { type: 'textInput', name: 'full_name', label: 'Full name' },
      { type: 'submitButton', label: 'Save' },
    ],
  } as unknown as Block;
}

/** Every path the form's own fetch could have used, resolved or not. */
function recordFetches(): string[] {
  return mockApiClient.mock.calls
    .map(([url]) => url as string)
    .filter((url) => url.startsWith('/api/v1/people/') || url === '/api/v1/people/');
}

// ---------------------------------------------------------------------------

describe('#949 — an unbound token means no fetch', () => {
  it('does not fetch while the token naming the record is unresolved', async () => {
    // No `record` prop and no selection: nothing has said which person.
    renderWrapped(<BlockRenderer blocks={[editForm()]} />);

    await screen.findByRole('textbox', { name: /full name/i });
    // Give any effect that wanted to fire the chance to have fired.
    await waitFor(() => expect(screen.getByRole('button', { name: /save/i })).toBeInTheDocument());

    expect(recordFetches()).toEqual([]);
    expect(mockApiClient).not.toHaveBeenCalled();
  });

  it('never sends the truncated path that would return the collection', async () => {
    // The failure mode this whole issue is about: '' for the missing token
    // turns /people/{person} into /people/, which succeeds and returns a list.
    renderWrapped(<BlockRenderer blocks={[editForm()]} />);

    await screen.findByRole('textbox', { name: /full name/i });
    for (const [url] of mockApiClient.mock.calls) {
      expect(url).not.toBe('/api/v1/people/');
      expect(url).not.toBe('/api/v1/people');
    }
  });

  it('never sends the token through un-substituted either, which is what it used to do', async () => {
    renderWrapped(<BlockRenderer blocks={[editForm()]} />);

    await screen.findByRole('textbox', { name: /full name/i });
    for (const [url] of mockApiClient.mock.calls) {
      expect(url).not.toContain('{');
      expect(url).not.toContain('%7B');
    }
  });

  it('leaves the form disabled and says why, rather than looking like an empty record', async () => {
    // The pair that matters: an enabled empty form is a data-loss path, because
    // submitting it blanks every field the user did not retype.
    renderWrapped(<BlockRenderer blocks={[editForm()]} />);

    expect(await screen.findByRole('textbox', { name: /full name/i })).toBeDisabled();
    expect(screen.getByText(/no record selected/i)).toBeInTheDocument();
  });
});

describe('#949 — the same form fetches once the token resolves', () => {
  it('fetches the substituted path when the route names the record', async () => {
    renderWrapped(<BlockRenderer blocks={[editForm()]} record="7" />);

    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /full name/i })).toHaveValue('Ada Lovelace')
    );
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/people/7',
      expect.objectContaining({ method: 'GET' })
    );
    expect(recordFetches()).toEqual(['/api/v1/people/7']);
  });

  it('re-enables the form and drops the unbound notice once the record loads', async () => {
    renderWrapped(<BlockRenderer blocks={[editForm()]} record="7" />);

    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /full name/i })).not.toBeDisabled()
    );
    expect(screen.queryByText(/no record selected/i)).not.toBeInTheDocument();
  });

  it('URL-encodes the resolved value, as every other interpolated path does', async () => {
    renderWrapped(<BlockRenderer blocks={[editForm()]} record="a/b" />);

    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith('/api/v1/people/a%2Fb', expect.anything())
    );
  });

  it('goes from no fetch to exactly one fetch when a selector binds the token', async () => {
    const tree: Block[] = [
      {
        type: 'selector',
        name: 'person',
        label: 'Person',
        source: '/api/v1/people',
        valueField: 'id',
        labelField: 'name',
      } as unknown as Block,
      editForm('person'),
    ];

    renderWrapped(<BlockRenderer blocks={tree} />);

    // Before the choice: the selector has fetched its options, the form has
    // fetched nothing.
    await waitFor(() => expect(screen.getByRole('combobox', { name: /person/i })).not.toBeDisabled());
    expect(recordFetches()).toEqual([]);

    await userEvent.click(screen.getByRole('combobox', { name: /person/i }));
    await userEvent.click(await screen.findByRole('option', { name: 'Ada' }));

    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /full name/i })).toHaveValue('Ada Lovelace')
    );
    // One fetch, at the resolved path — not one per render of the bound form.
    expect(recordFetches()).toEqual(['/api/v1/people/7']);
  });
});

describe('#949 — a path with no tokens is unaffected', () => {
  const settingsForm: Block = {
    type: 'form',
    submit: { method: 'PUT', endpoint: '/api/v1/x/settings' },
    dataSource: { method: 'GET', path: '/api/v1/x/settings' },
    children: [
      { type: 'textInput', name: 'site_name', label: 'Site name' },
      { type: 'submitButton', label: 'Save' },
    ],
  } as unknown as Block;

  it('fetches the literal path on mount and pre-populates from it', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: { site_name: 'Acme Corp' } }));

    renderWrapped(<BlockRenderer blocks={[settingsForm]} />);

    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /site name/i })).toHaveValue('Acme Corp')
    );
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/x/settings',
      expect.objectContaining({ method: 'GET' })
    );
    expect(mockApiClient).toHaveBeenCalledTimes(1);
  });

  it('is never treated as unbound — a form with nothing to resolve is bound already', async () => {
    mockApiClient.mockResolvedValue(stubResponse({ data: { site_name: 'Acme Corp' } }));

    renderWrapped(<BlockRenderer blocks={[settingsForm]} />);

    await waitFor(() =>
      expect(screen.getByRole('textbox', { name: /site name/i })).not.toBeDisabled()
    );
    expect(screen.queryByText(/no record selected/i)).not.toBeInTheDocument();
  });
});
