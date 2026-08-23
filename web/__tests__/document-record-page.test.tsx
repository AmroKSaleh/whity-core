import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

/**
 * The document RECORD page (#993) — `/admin/document-library/[id]`.
 *
 * Four things this file exists to fail a build over:
 *
 *  1. THE PAGE IS BUILT FROM THE URL AND NOTHING ELSE (#960's bar). Every test
 *     here mounts the ROUTE FILE with only `params` set — there is no click, no
 *     navigation and no handed-over state — which is the same starting condition
 *     a hard reload gives the browser. The e2e spec proves the reload for real;
 *     this proves the component can be built from an address alone, which is the
 *     part a unit test can actually pin down.
 *  2. THE SERVER DECIDES WHICH REGIONS EXIST. A region absent from `sections` is
 *     absent from the DOM and its endpoint is never called. Both halves matter:
 *     a screen that hid a region and fetched it anyway would be a gate with a
 *     network request around the side of it.
 *  3. NO BLANK OR INVENTED STATE. A document with no route says so, in the
 *     server's own words, rather than showing an empty trail that reads as
 *     "nothing happened" (#756, #951).
 *  4. THE VIEWER IS THE ONE THAT SHIPPED. #986's component is mounted directly,
 *     opened at the CURRENT artifact, and still says version N of M — so this
 *     page cannot have quietly defaulted to something else.
 */

// ---------------------------------------------------------------------------
// Provider seams. The route owns exactly these; the real screen runs underneath.
// ---------------------------------------------------------------------------

const push = jest.fn();
let params: Record<string, string> = {};
jest.mock('next/navigation', () => ({
  useParams: () => params,
  useRouter: () => ({ push }),
}));

const rawApiClient = jest.fn();
let authUser: { id: number; tenant_id: number } | null = { id: 10, tenant_id: 1 };
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ user: authUser, apiClient: (...args: unknown[]) => rawApiClient(...args) }),
}));
// The VIEWER imports the module-level client rather than taking it from context,
// so both specifiers route to the same spy — otherwise the viewer's own two
// requests would be invisible to these assertions.
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => rawApiClient(...args),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

import DocumentRecordPage from '@/app/(protected)/admin/document-library/[id]/page';

/** The viewer wraps its bytes in a blob URL; jsdom has neither helper. */
beforeAll(() => {
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: jest.fn(() => 'blob:test/1'),
  });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: jest.fn() });
});

beforeEach(() => {
  jest.clearAllMocks();
  params = {};
  authUser = { id: 10, tenant_id: 1 };
});

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

function jsonResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function pdfResponse(): Response {
  return {
    ok: true,
    status: 200,
    blob: () => Promise.resolve(new Blob(['pdf-bytes'], { type: 'application/pdf' })),
  } as unknown as Response;
}

const ARTIFACT = {
  id: 900,
  document_id: 5,
  content_type: 'application/pdf',
  byte_size: 2048,
  checksum_sha256: 'a'.repeat(64),
  rendered_at: '2026-08-01T09:00:00Z',
  content_url: '/api/v1/documents/5/artifacts/900/content',
};

/** Every region editable — the fully-privileged, fully-circulated case. */
const ALL_EDITABLE = {
  document: { state: 'editable', denial: null },
  trail: { state: 'editable', denial: null },
  recipients: { state: 'editable', denial: null },
};

function documentBody(sections: unknown) {
  return {
    data: {
      id: 5,
      template_name: 'Purchase Order',
      title: 'PO 4471',
      origin_ou_id: 7,
      created_by: 10,
      created_at: '2026-08-01T09:00:00Z',
      content_url: '/api/v1/documents/5/content',
      artifacts: [ARTIFACT],
      sections,
    },
  };
}

const TRAIL_EVENT = {
  id: 1,
  document_id: 5,
  route_id: 30,
  step_id: 40,
  actor_profile_id: 10,
  action: 'forwarded',
  from_ou_id: 7,
  to_ou_id: 8,
  note: 'Please check the totals.',
  occurred_at: '2026-08-02T10:00:00Z',
};

const OPEN_RECIPIENT = {
  id: 60,
  document_id: 5,
  route_id: 30,
  step_id: 40,
  profile_id: 11,
  ou_id: 8,
  parent_recipient_id: null,
  created_by_event_id: 1,
  closed_by_event_id: null,
  open: true,
  created_at: '2026-08-02T10:00:00Z',
};

/**
 * Route the one spy by URL, so a test says what the SERVER answers and the
 * assertions are about what the screen did with it.
 */
function serve(options: {
  sections?: unknown;
  /**
   * Send NO `sections` key at all — a host with no resolver.
   *
   * A separate flag rather than `sections: undefined`, because `undefined` is
   * indistinguishable from "not passed" and would silently take the default.
   * The distinction is the entire subject of one of the tests below, so the
   * fixture must be able to express it.
   */
  noSections?: boolean;
  trail?: unknown[];
  recipients?: unknown[];
  directoryStatus?: number;
}) {
  const {
    sections = ALL_EDITABLE,
    noSections = false,
    trail = [TRAIL_EVENT],
    recipients = [OPEN_RECIPIENT],
    directoryStatus = 200,
  } = options;

  rawApiClient.mockImplementation((url: string) => {
    if (url.includes('/content')) return Promise.resolve(pdfResponse());
    if (url.includes('/trail')) {
      return Promise.resolve(
        jsonResponse({
          data: trail,
          pagination: { page: 1, perPage: 25, total: trail.length, totalPages: 1 },
        })
      );
    }
    if (url.includes('/recipients')) return Promise.resolve(jsonResponse({ data: recipients }));
    if (url.startsWith('/api/v1/users')) {
      return Promise.resolve(
        directoryStatus === 200
          ? jsonResponse({
              data: [
                { id: 10, name: 'amira', email: 'amira@example.test' },
                { id: 11, name: 'yusuf', email: 'yusuf@example.test' },
              ],
              pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 },
            })
          : jsonResponse({ error: 'nope' }, directoryStatus)
      );
    }
    if (url.startsWith('/api/v1/ous')) {
      return Promise.resolve(
        directoryStatus === 200
          ? jsonResponse({
              data: [
                { id: 7, name: 'Registry' },
                { id: 8, name: 'Finance' },
              ],
              pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 },
            })
          : jsonResponse({ error: 'nope' }, directoryStatus)
      );
    }
    // The record itself. Matched LAST so the more specific sub-resources above
    // win — `/api/v1/documents/5` is a prefix of every one of them.
    if (url.startsWith('/api/v1/documents/5')) {
      const body = documentBody(sections);
      if (noSections) {
        delete (body.data as { sections?: unknown }).sections;
      }
      return Promise.resolve(jsonResponse(body));
    }
    return Promise.resolve(jsonResponse({ error: 'unexpected' }, 404));
  });
}

/** Every path the spy was asked for, for the "never fetched" assertions. */
function requestedPaths(): string[] {
  return rawApiClient.mock.calls.map((call) => String(call[0]));
}

// ---------------------------------------------------------------------------
// The address is the whole input
// ---------------------------------------------------------------------------

describe('the record is built from the URL alone', () => {
  it('renders the document named by the address, with no navigation of any kind', async () => {
    params = { id: '5' };
    serve({});

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record')).toBeInTheDocument();
    expect(screen.getByText('PO 4471')).toBeInTheDocument();
    // The record's own request carried the id from the URL and nothing else.
    expect(requestedPaths()).toContain('/api/v1/documents/5');
    // Nothing navigated. A record page that pushed a route to render itself
    // would work on a click and break on a reload.
    expect(push).not.toHaveBeenCalled();
  });

  it('states the facts the server gave, and never a version count it inferred', async () => {
    params = { id: '5' };
    serve({});

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record')).toBeInTheDocument();
    // One artifact on the wire, one version in the strip. Counted from the
    // array the server sent, never from a `revision` column — #947 item 1
    // refused to store one precisely so position IS issue order.
    expect(screen.getByTestId('document-record-stat-versions')).toHaveTextContent('1');
    expect(screen.getByTestId('document-record-stat-unit')).toHaveTextContent('Registry');
  });

  it('never fetches for a non-numeric segment', async () => {
    params = { id: 'not-a-number' };
    serve({});

    render(<DocumentRecordPage />);

    expect(screen.getByTestId('document-record-bad-id')).toBeInTheDocument();
    expect(rawApiClient).not.toHaveBeenCalled();
  });

  it('keeps the URL when the document is not available to this caller', async () => {
    params = { id: '5' };
    rawApiClient.mockImplementation(() => Promise.resolve(jsonResponse({ error: 'gone' }, 404)));

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-missing')).toBeInTheDocument();
    // A 404 is not a redirect. The address the reader pasted stays theirs, so
    // they can see WHICH document was refused.
    expect(push).not.toHaveBeenCalled();
  });

  it('goes back to the library by pushing, never by walking history', async () => {
    params = { id: '5' };
    serve({});

    render(<DocumentRecordPage />);

    const back = await screen.findByRole('button', { name: 'Back to documents' });
    back.click();

    expect(push).toHaveBeenCalledWith('/admin/document-library');
  });
});

// ---------------------------------------------------------------------------
// The server decides which regions exist
// ---------------------------------------------------------------------------

describe('per-region verdicts (#975) decide what is on the page', () => {
  it('renders all three regions when the server reports all three', async () => {
    params = { id: '5' };
    serve({});

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-section-document')).toBeInTheDocument();
    expect(screen.getByTestId('document-record-section-trail')).toBeInTheDocument();
    expect(screen.getByTestId('document-record-section-recipients')).toBeInTheDocument();
  });

  it('leaves a region OUT of the DOM when its key is absent, and never requests its data', async () => {
    params = { id: '5' };
    // `recipients` withheld. Absence is the whole wire representation of hidden
    // — there is no `{state: "hidden"}` to send.
    serve({
      sections: {
        document: { state: 'editable', denial: null },
        trail: { state: 'editable', denial: null },
      },
    });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-section-trail')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-section-recipients')).not.toBeInTheDocument();
    // The second half of the same rule: a hidden region's endpoint is not
    // called. A screen that hid the panel and fetched anyway would be a gate
    // with a request around the side of it.
    await waitFor(() => {
      expect(requestedPaths().some((path) => path.includes('/recipients'))).toBe(false);
    });
  });

  it('treats an ABSENT sections map as every region hidden, not as everything allowed', async () => {
    params = { id: '5' };
    // A host built without a resolver sends no `sections` key at all. Failing
    // OPEN here would render a full record page on a half-wired deployment.
    serve({ noSections: true });

    render(<DocumentRecordPage />);

    // The header still renders — the record itself was served — and the body
    // does not. That is the honest rendering of "there is nothing here for you".
    expect(await screen.findByTestId('document-record')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-section-document')).not.toBeInTheDocument();
    expect(screen.queryByTestId('document-record-section-trail')).not.toBeInTheDocument();
    expect(screen.queryByTestId('document-record-section-recipients')).not.toBeInTheDocument();
  });

  it('renders a read-only region WITH ITS REASON rather than hiding it', async () => {
    params = { id: '5' };
    serve({
      sections: {
        document: {
          state: 'read-only',
          denial: {
            code: 'permission',
            reason: 'Server prose that the client should override.',
            detail: "changing this requires the 'documents:render' permission",
          },
        },
        trail: { state: 'editable', denial: null },
        recipients: { state: 'editable', denial: null },
      },
    });

    render(<DocumentRecordPage />);

    const section = await screen.findByTestId('document-record-section-document');
    expect(section).toHaveAttribute('data-section-state', 'read-only');
    // The screen's own localized sentence wins over the server's prose, and the
    // operator-grade `detail` the server chose to send is appended verbatim —
    // the client never re-decides that audience (#968).
    const note = screen.getByTestId('document-record-section-document-readonly');
    expect(note).toHaveTextContent('Issuing a corrected version');
    expect(note).toHaveTextContent("changing this requires the 'documents:render' permission");
  });

  it('falls back to the server sentence for a denial code it has never heard of', async () => {
    params = { id: '5' };
    serve({
      sections: {
        document: {
          state: 'read-only',
          denial: { code: 'some-future-code', reason: 'A newer server said this.', detail: null },
        },
        trail: { state: 'editable', denial: null },
        recipients: { state: 'editable', denial: null },
      },
    });

    render(<DocumentRecordPage />);

    // Correctly read-only with a vague explanation beats correctly read-only
    // with a blank space where the explanation goes.
    expect(
      await screen.findByTestId('document-record-section-document-readonly')
    ).toHaveTextContent('A newer server said this.');
  });
});

// ---------------------------------------------------------------------------
// Nothing blank, nothing invented
// ---------------------------------------------------------------------------

describe('a document with no route says so', () => {
  it('renders the not-circulated sentence instead of an empty trail', async () => {
    params = { id: '5' };
    serve({
      sections: {
        document: { state: 'editable', denial: null },
        trail: {
          state: 'read-only',
          denial: {
            code: 'record',
            reason: 'This document has not been put into circulation, so there is no trail to add to.',
            detail: null,
          },
        },
        recipients: {
          state: 'read-only',
          denial: { code: 'record', reason: 'This document is not awaiting you.', detail: null },
        },
      },
      trail: [],
      recipients: [],
    });

    render(<DocumentRecordPage />);

    // The actionable half — what would have to happen for there to be a trail.
    expect(await screen.findByTestId('document-record-no-trail')).toHaveTextContent(
      'A trail begins when it is sent into a route'
    );
    // And NOT a timeline. An empty list here would state "nothing happened",
    // which is unfalsifiable from the outside and identical to a failed load.
    expect(screen.queryByTestId('document-record-trail')).not.toBeInTheDocument();

    // The region's own read-only line carries the server's reason.
    expect(screen.getByTestId('document-record-section-trail-readonly')).toHaveTextContent(
      'not been put into circulation'
    );

    // Spending a request to be told the empty set the verdict already stated
    // would be a round trip for nothing.
    await waitFor(() => {
      expect(requestedPaths().some((path) => path.includes('/trail'))).toBe(false);
    });
  });
});

describe('the trail and the recipients render the ids they were given', () => {
  it('names people and units, and shows the movement between them', async () => {
    params = { id: '5' };
    serve({});

    render(<DocumentRecordPage />);

    const trail = await screen.findByTestId('document-record-trail');
    expect(trail).toHaveTextContent('Forwarded');
    expect(trail).toHaveTextContent('amira@example.test');
    expect(trail).toHaveTextContent('Registry');
    expect(trail).toHaveTextContent('Finance');
    expect(trail).toHaveTextContent('Please check the totals.');
  });

  it('shows ids WITH THE REASON when the directory is refused, never a bare number', async () => {
    params = { id: '5' };
    serve({ directoryStatus: 403 });

    render(<DocumentRecordPage />);

    const trail = await screen.findByTestId('document-record-trail');
    expect(trail).toHaveTextContent('Account #10');
    // #951: a screen that degrades silently makes a permission boundary
    // indistinguishable from a data bug, and the person who cannot tell them
    // apart is usually the one who could have granted it.
    expect(screen.getAllByTestId('document-record-directory-people')[0]).toHaveTextContent(
      'users:read'
    );
    expect(screen.getByTestId('document-record-directory-units')).toHaveTextContent('ous:read');
  });

  it('lists who it is awaiting and counts who has already acted', async () => {
    params = { id: '5' };
    serve({
      recipients: [
        OPEN_RECIPIENT,
        {
          ...OPEN_RECIPIENT,
          id: 61,
          profile_id: 10,
          closed_by_event_id: 1,
          open: false,
        },
      ],
    });

    render(<DocumentRecordPage />);

    const panel = await screen.findByTestId('document-record-recipients');
    expect(panel).toHaveTextContent('yusuf@example.test');
    // A closed row is something already done, and is counted rather than listed
    // — the trail below has what each of them actually did.
    expect(screen.getByTestId('document-record-recipients-closed')).toHaveTextContent('1');
    // The open count reaches the stat strip. `filter(open)`, not `length`.
    expect(screen.getByTestId('document-record-stat-awaiting')).toHaveTextContent('1');
  });

  it('reports "everyone has acted" separately from "never sent to anyone"', async () => {
    params = { id: '5' };
    serve({
      recipients: [{ ...OPEN_RECIPIENT, closed_by_event_id: 1, open: false }],
    });

    render(<DocumentRecordPage />);

    // Two different facts about a document, and they must not collapse into one
    // empty panel: this one WAS circulated and is finished.
    expect(await screen.findByTestId('document-record-recipients-settled')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-no-recipients')).not.toBeInTheDocument();
  });

  it('says the document is awaiting YOU only when the server said the region is editable', async () => {
    params = { id: '5' };
    authUser = { id: 11, tenant_id: 1 };
    serve({});

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-awaiting-you')).toBeInTheDocument();
    expect(screen.getByTestId('document-record-recipient-you')).toBeInTheDocument();
  });

  it('does not claim the document is awaiting you when the server said read-only', async () => {
    params = { id: '5' };
    authUser = { id: 11, tenant_id: 1 };
    serve({
      sections: {
        document: { state: 'editable', denial: null },
        trail: { state: 'editable', denial: null },
        recipients: {
          state: 'read-only',
          denial: {
            code: 'record',
            reason: 'This document is not awaiting you.',
            detail: null,
          },
        },
      },
    });

    render(<DocumentRecordPage />);

    // The row is still listed — the reader may see who has it — but the page
    // makes no claim about their own standing that the server did not make.
    expect(await screen.findByTestId('document-record-recipients')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-awaiting-you')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// The viewer is the one that shipped
// ---------------------------------------------------------------------------

describe('the document region mounts #986 and opens at the current artifact', () => {
  it('renders the version bar and frames the bytes', async () => {
    params = { id: '5' };
    serve({});

    render(<DocumentRecordPage />);

    await waitFor(() => {
      expect(document.querySelector('[data-slot="document-viewer-position"]')).not.toBeNull();
    });
    // The bar states version N of M on EVERY render, including M = 1 — a viewer
    // that only mentioned versions when there were several would teach a reader
    // that silence means one, and silence is also what a bug looks like.
    expect(document.querySelector('[data-slot="document-viewer-position"]')?.textContent).toContain(
      'Version 1 of 1'
    );
    await waitFor(() => {
      expect(document.querySelector('[data-slot="document-viewer-frame"]')).not.toBeNull();
    });
    // Opened at the current artifact, so no superseded warning and no pin hint.
    expect(document.querySelector('[data-slot="document-viewer-superseded"]')).toBeNull();
    expect(document.querySelector('[data-slot="document-viewer-pinned"]')).toBeNull();
  });

  it('opens a re-rendered document at the CURRENT version, not the first one', async () => {
    params = { id: '5' };
    const corrected = {
      ...ARTIFACT,
      id: 901,
      checksum_sha256: 'b'.repeat(64),
      rendered_at: '2026-08-05T09:00:00Z',
      content_url: '/api/v1/documents/5/artifacts/901/content',
    };
    rawApiClient.mockImplementation((url: string) => {
      if (url.includes('/content')) return Promise.resolve(pdfResponse());
      if (url.includes('/trail')) {
        return Promise.resolve(
          jsonResponse({ data: [], pagination: { page: 1, perPage: 25, total: 0, totalPages: 1 } })
        );
      }
      if (url.includes('/recipients')) return Promise.resolve(jsonResponse({ data: [] }));
      if (url.startsWith('/api/v1/users') || url.startsWith('/api/v1/ous')) {
        return Promise.resolve(
          jsonResponse({ data: [], pagination: { page: 1, perPage: 100, total: 0, totalPages: 1 } })
        );
      }
      return Promise.resolve(
        jsonResponse({
          data: {
            ...documentBody(ALL_EDITABLE).data,
            // Newest first, as the artifact repository orders them.
            artifacts: [corrected, ARTIFACT],
          },
        })
      );
    });

    render(<DocumentRecordPage />);

    await waitFor(() => {
      expect(document.querySelector('[data-slot="document-viewer-position"]')).not.toBeNull();
    });
    // Version 2 of 2 — the CURRENT one. The record page passes no pin precisely
    // so it cannot open at a superseded artifact, which is the one failure the
    // whole documents subsystem exists to prevent.
    expect(document.querySelector('[data-slot="document-viewer-position"]')?.textContent).toContain(
      'Version 2 of 2'
    );
    expect(document.querySelector('[data-slot="document-viewer-superseded"]')).toBeNull();
    // The stat strip counts both, so a reader sees the document was corrected
    // without having to open the picker.
    expect(screen.getByTestId('document-record-stat-versions')).toHaveTextContent('2');
  });
});
