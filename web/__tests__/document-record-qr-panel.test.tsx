import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * THE VERIFICATION-CODE PANEL on the document record page (#1052).
 *
 * A QR printed on paper is a bearer token in the physical world, and paper
 * cannot be recalled — so withdrawing a code is the only control an organisation
 * keeps over one already in circulation, and it is irreversible. Everything in
 * this file is about that asymmetry.
 *
 * FIVE THINGS THIS FILE EXISTS TO FAIL A BUILD OVER
 *
 *  1. THE ONE-WAY LATCH IS ENFORCED WHERE A USER MEETS IT. `revoked_at` is set
 *     under `WHERE revoked_at IS NULL`, so a second withdrawal is a server-side
 *     no-op — which means the SERVER cannot tell anybody they have made a
 *     mistake. The screen has to. So: no withdraw control exists when no code is
 *     live, no DELETE is ever sent in that state, and nothing on screen offers
 *     an un-withdraw. Mutation-checked — see the note on
 *     `never offers a withdrawal once the code is already withdrawn`.
 *
 *  2. `token: null` IS TWO FACTS AND THE SCREEN TELLS THEM APART. "Never minted"
 *     and "withdrawn" render differently, because reporting a withdrawal as an
 *     absence is a true-sounding sentence that hides the state the whole feature
 *     exists for: paper in the field carrying a dead symbol.
 *
 *  3. THE DESTRUCTIVE ACT IS LEGIBLE BEFORE THE CLICK, not explained after it —
 *     what stops working, how much paper carries it, and that issuing a code
 *     later is a DIFFERENT code. And nothing is sent while the confirm is open.
 *
 *  4. THE SCAN TRAIL NEVER IMPLIES A VISITOR. Nothing about a public scanner is
 *     stored — there are no columns for it — so a public scan must not render as
 *     an unnamed person, and the panel must say the absence is structural.
 *
 *  5. THE PANEL IS NOT BLANKED BY ITS OWN REFETCH. #1041's browser pass found a
 *     panel next door that unmounted on refetch and discarded the answer; the
 *     assertion here holds the DOM node across a mutation and requires it to
 *     still be the same node.
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
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({
    user: { id: 10, tenant_id: 1 },
    apiClient: (...args: unknown[]) => rawApiClient(...args),
  }),
}));
jest.mock('@/lib/api-client', () => ({
  apiClient: (...args: unknown[]) => rawApiClient(...args),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({ useToast: () => ({ addToast }) }));

// bwip-js draws the symbol through a canvas-free SVG path, but it is still a
// heavy dependency to run per test and its OUTPUT is not what this file is
// about — that the panel hands it the SERVER'S url, unmodified, is. So it is
// stubbed to something assertable.
jest.mock('bwip-js/browser', () => ({
  toSVG: (opts: { bcid: string; text: string }) =>
    `<svg width="10" height="10" data-bcid="${opts.bcid}" data-text="${opts.text}"></svg>`,
}));

import DocumentRecordPage from '@/app/(protected)/admin/document-library/[id]/page';

beforeAll(() => {
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: jest.fn(() => 'blob:test/1'),
  });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: jest.fn() });
});

beforeEach(() => {
  jest.clearAllMocks();
  params = { id: '5' };
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

const ALL_EDITABLE = {
  document: { state: 'editable', denial: null },
  trail: { state: 'editable', denial: null },
  recipients: { state: 'editable', denial: null },
  qr: { state: 'editable', denial: null },
};

const LIVE_URL = 'https://records.example.test/verify/' + 'b'.repeat(64);

const LIVE_CODE = {
  reference: '9F2A-4C11-8B03',
  verification_url: LIVE_URL,
  issued_at: '2026-08-02T08:00:00Z',
  issued_by: 10,
};

const WITHDRAWN_CODE = {
  reference: '9F2A-4C11-8B03',
  issued_at: '2026-08-02T08:00:00Z',
  revoked_at: '2026-08-10T12:00:00Z',
  revoked_by: 10,
  reason: 'withdrawn',
};

/** A live code, nothing retired, nothing scanned — the ordinary healthy state. */
function livePanel(overrides: Record<string, unknown> = {}) {
  return {
    enabled: true,
    configured: true,
    token: LIVE_CODE,
    retired: { total: 0, recent: [] },
    scans: { total: 0, recent: [] },
    ...overrides,
  };
}

/** The state the whole feature exists for: withdrawn, with paper still out there. */
function withdrawnPanel(overrides: Record<string, unknown> = {}) {
  return {
    enabled: true,
    configured: true,
    token: null,
    retired: { total: 1, recent: [WITHDRAWN_CODE] },
    scans: { total: 0, recent: [] },
    ...overrides,
  };
}

/**
 * Route the one spy by URL.
 *
 * `qrPanels` is a QUEUE, so a test can say what the SECOND read (the one after a
 * mutation) returns and assert the screen took the new answer rather than
 * keeping the old one. The last entry repeats once the queue drains.
 */
function serve(options: {
  sections?: unknown;
  qrPanels?: unknown[];
  qrMutation?: { status: number; body?: unknown };
  directoryStatus?: number;
} = {}) {
  const {
    sections = ALL_EDITABLE,
    qrPanels = [livePanel()],
    qrMutation = { status: 204 },
    directoryStatus = 200,
  } = options;

  const queue = [...qrPanels];

  rawApiClient.mockImplementation((url: string, init?: RequestInit) => {
    const method = (init?.method ?? 'GET').toUpperCase();

    if (url.endsWith('/qr')) {
      if (method === 'GET') {
        const next = queue.length > 1 ? queue.shift() : queue[0];
        return Promise.resolve(jsonResponse({ data: next }));
      }
      return Promise.resolve(
        qrMutation.status === 204
          ? ({ ok: true, status: 204, json: () => Promise.resolve({}) } as unknown as Response)
          : jsonResponse(qrMutation.body ?? { error: 'nope' }, qrMutation.status)
      );
    }
    if (url.includes('/content')) return Promise.resolve(pdfResponse());
    if (url.includes('/trail')) {
      return Promise.resolve(
        jsonResponse({ data: [], pagination: { page: 1, perPage: 25, total: 0, totalPages: 0 } })
      );
    }
    if (url.includes('/recipients')) return Promise.resolve(jsonResponse({ data: [] }));
    if (url.startsWith('/api/v1/users')) {
      return Promise.resolve(
        directoryStatus === 200
          ? jsonResponse({
              data: [{ id: 10, name: 'amira', email: 'amira@example.test' }],
              pagination: { page: 1, perPage: 100, total: 1, totalPages: 1 },
            })
          : jsonResponse({ error: 'nope' }, directoryStatus)
      );
    }
    if (url.startsWith('/api/v1/ous')) {
      return Promise.resolve(
        jsonResponse({
          data: [{ id: 7, name: 'Registry' }],
          pagination: { page: 1, perPage: 100, total: 1, totalPages: 1 },
        })
      );
    }
    if (url.startsWith('/api/v1/documents/5')) {
      return Promise.resolve(
        jsonResponse({
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
        })
      );
    }
    return Promise.resolve(jsonResponse({ error: 'unexpected' }, 404));
  });
}

/** Every (method, path) pair the spy was asked for. */
function calls(): Array<{ method: string; url: string }> {
  return rawApiClient.mock.calls.map((call) => ({
    url: String(call[0]),
    method: String((call[1] as RequestInit | undefined)?.method ?? 'GET').toUpperCase(),
  }));
}

function qrMutations(): Array<{ method: string; url: string }> {
  return calls().filter((call) => call.url.endsWith('/qr') && call.method !== 'GET');
}

// ---------------------------------------------------------------------------
// The live code
// ---------------------------------------------------------------------------

describe('a document carrying a code', () => {
  it('shows the reference, the symbol and the address the server minted', async () => {
    serve();

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-live')).toBeInTheDocument();
    expect(screen.getByTestId('document-record-qr-reference')).toHaveTextContent('9F2A-4C11-8B03');
    // The symbol encodes the SERVER'S url verbatim. A panel that composed its
    // own would be free to drift from what the server actually honours, and the
    // drift would only show up on somebody's phone.
    const symbol = screen.getByTestId('document-record-qr-live').querySelector('img');
    expect(decodeURIComponent(symbol?.getAttribute('src') ?? '')).toContain(LIVE_URL);
    expect(screen.getByTestId('document-record-qr-url')).toHaveTextContent(LIVE_URL);
  });

  it('says scanning grants nothing, which is the claim the whole feature rests on', async () => {
    serve();

    render(<DocumentRecordPage />);

    // The sentence an operator needs before they agree to print a scannable
    // code on a decision letter. It is #1036's central claim, and the panel is
    // where the person deciding actually reads it.
    expect(await screen.findByTestId('document-record-qr-live')).toHaveTextContent(
      /refused exactly as they are today/i
    );
  });
});

// ---------------------------------------------------------------------------
// 1. The one-way latch, at the surface where a user meets it
// ---------------------------------------------------------------------------

describe('withdrawal is a one-way latch', () => {
  /**
   * THE MUTATION TARGET.
   *
   * Checked by mutation before being committed, because a "the button is absent"
   * assertion is exactly the kind that can be inert: change the withdraw control
   * in `qr.tsx` from `{live_ !== null && <Button …/>}` to an unconditional
   * `<Button …/>` — i.e. offer a withdrawal on an already-withdrawn code — and
   * this test goes red on the query below. Restoring the guard greens it.
   *
   * It matters because the SERVER cannot catch this: `DELETE /qr` answers 204
   * whether or not a code was live, deliberately, so that a double click is not
   * an error and the route cannot be asked whether a document has a code. That
   * makes the screen the only place a user can be stopped from believing a
   * withdrawn code can be withdrawn again — or put back.
   */
  it('never offers a withdrawal once the code is already withdrawn', async () => {
    serve({ qrPanels: [withdrawnPanel()] });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-withdrawn')).toBeInTheDocument();
    // The region is editable — this caller may act — so the absence below is
    // about the STATE of the code and not about the caller's permissions.
    expect(screen.getByTestId('document-record-qr-actions')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-qr-withdraw')).not.toBeInTheDocument();
  });

  it('offers no un-withdraw, and says a new code is a different code', async () => {
    serve({ qrPanels: [withdrawnPanel()] });

    render(<DocumentRecordPage />);

    const notice = await screen.findByTestId('document-record-qr-withdrawn');
    expect(notice).toHaveTextContent(/cannot be reversed/i);
    expect(notice).toHaveTextContent(/does not bring this back/i);
    // The only offered act is minting, and it is named as an issue rather than
    // as a restore or a refresh.
    expect(screen.getByTestId('document-record-qr-mint')).toHaveTextContent('Issue a code');
    expect(screen.queryByRole('button', { name: /restore|undo|re-?enable/i })).not.toBeInTheDocument();
  });

  it('sends no DELETE at all for a document whose code is already withdrawn', async () => {
    serve({ qrPanels: [withdrawnPanel()] });

    render(<DocumentRecordPage />);

    await screen.findByTestId('document-record-qr-withdrawn');
    await waitFor(() => expect(qrMutations()).toEqual([]));
  });
});

// ---------------------------------------------------------------------------
// 2. Two different nulls
// ---------------------------------------------------------------------------

describe('a null code is two different facts', () => {
  it('reports a withdrawal as a withdrawal, never as "this has no code"', async () => {
    serve({ qrPanels: [withdrawnPanel()] });

    render(<DocumentRecordPage />);

    const notice = await screen.findByTestId('document-record-qr-withdrawn');
    expect(notice).toHaveTextContent('9F2A-4C11-8B03');
    expect(notice).toHaveTextContent(/still carries that symbol/i);
    // The "never had one" copy must NOT be what a withdrawn document shows. This
    // is the pairing that makes the assertion above mean something.
    expect(screen.queryByTestId('document-record-qr-none')).not.toBeInTheDocument();
  });

  it('reports a document that never carried one as exactly that', async () => {
    serve({
      qrPanels: [{ enabled: true, configured: true, token: null, retired: { total: 0, recent: [] }, scans: { total: 0, recent: [] } }],
    });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-none')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-qr-withdrawn')).not.toBeInTheDocument();
  });

  it('distinguishes a superseded code from a withdrawn one', async () => {
    serve({
      qrPanels: [
        livePanel({
          retired: {
            total: 1,
            recent: [{ ...WITHDRAWN_CODE, reference: '11AA-22BB-33CC', reason: 'superseded' }],
          },
        }),
      ],
    });

    render(<DocumentRecordPage />);

    const retired = await screen.findByTestId('document-record-qr-retired');
    // "Replaced by a newer code", not "Withdrawn": the two mean opposite things
    // to whoever is holding the sheet.
    expect(retired).toHaveTextContent(/replaced by a newer code/i);
    expect(retired).not.toHaveTextContent(/^Withdrawn/i);
  });
});

// ---------------------------------------------------------------------------
// 3. Legible before the click
// ---------------------------------------------------------------------------

describe('withdrawing is made legible before the click', () => {
  it('names what stops working, and sends nothing while the confirm is open', async () => {
    const user = userEvent.setup();
    serve({ qrPanels: [livePanel({ scans: { total: 12, recent: [] } })] });

    render(<DocumentRecordPage />);

    await user.click(await screen.findByTestId('document-record-qr-withdraw'));

    const dialog = await screen.findByTestId('document-record-qr-withdraw-dialog');
    expect(dialog).toHaveTextContent('PO 4471');
    expect(dialog).toHaveTextContent('9F2A-4C11-8B03');
    expect(dialog).toHaveTextContent(/cannot be undone/i);
    expect(dialog).toHaveTextContent(/never be un-withdrawn/i);
    // Concretely how much paper, and how much has already been checked — a bare
    // "this cannot be undone" is a phrase people click past.
    expect(dialog).toHaveTextContent(/The one issued version of this document carries this code/i);
    expect(screen.getByTestId('document-record-qr-withdraw-scans')).toHaveTextContent('12 scans');

    // Opening a confirm is not the act. Nothing has gone out.
    expect(qrMutations()).toEqual([]);
  });

  it('withdraws only on the confirm, and then reports the new state', async () => {
    const user = userEvent.setup();
    serve({ qrPanels: [livePanel(), withdrawnPanel()] });

    render(<DocumentRecordPage />);

    await user.click(await screen.findByTestId('document-record-qr-withdraw'));
    await user.click(await screen.findByTestId('document-record-qr-withdraw-confirm'));

    await waitFor(() =>
      expect(qrMutations()).toEqual([{ method: 'DELETE', url: '/api/v1/documents/5/qr' }])
    );
    // And the panel now states the withdrawal rather than leaving the old code
    // on screen: the refetched payload is what is rendered.
    expect(await screen.findByTestId('document-record-qr-withdrawn')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-qr-withdraw')).not.toBeInTheDocument();
  });

  it('tells you a new code retires the old one AND appears on nothing already printed', async () => {
    const user = userEvent.setup();
    serve();

    render(<DocumentRecordPage />);

    await user.click(await screen.findByTestId('document-record-qr-mint'));

    const dialog = await screen.findByTestId('document-record-qr-mint-dialog');
    expect(dialog).toHaveTextContent(/current code stops being honoured/i);
    expect(dialog).toHaveTextContent('9F2A-4C11-8B03');
    // The least obvious consequence, and the one that would otherwise void an
    // operator's paper with no replacement printed: minting rotates the token,
    // it does not re-render the document.
    expect(dialog).toHaveTextContent(/does not re-render this document/i);
    expect(qrMutations()).toEqual([]);
  });

  it('never calls the act a refresh, because re-rendering does not rotate', async () => {
    serve();

    render(<DocumentRecordPage />);

    const mint = await screen.findByTestId('document-record-qr-mint');
    expect(mint).toHaveTextContent('Issue a new code');
    expect(mint).not.toHaveTextContent(/refresh|regenerate/i);
  });
});

// ---------------------------------------------------------------------------
// Refused before the request, not after it (#1022's shape)
// ---------------------------------------------------------------------------

describe('a mint that would be refused is refused here first', () => {
  it('disables issuing when the instance has no public address, and says which', async () => {
    serve({ qrPanels: [livePanel({ configured: false })] });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-unconfigured')).toHaveTextContent(
      /APP_URL/
    );
    expect(screen.getByTestId('document-record-qr-mint')).toBeDisabled();
    // And no symbol is drawn: with no public address the minted url is a bare
    // path, and a code encoding it would be a picture of a link that leads
    // nowhere — which looks exactly like a working one.
    expect(screen.getByTestId('document-record-qr-no-symbol')).toBeInTheDocument();
    await waitFor(() => expect(qrMutations()).toEqual([]));
  });

  it('says a live code is still honoured after the switch is turned off', async () => {
    serve({ qrPanels: [livePanel({ enabled: false })] });

    render(<DocumentRecordPage />);

    // The state is real and reachable, and the honest thing to say about it is
    // that the paper already out there still works.
    expect(await screen.findByTestId('document-record-qr-disabled')).toHaveTextContent(
      /still honoured/i
    );
    expect(screen.getByTestId('document-record-qr-mint')).toBeDisabled();
  });
});

// ---------------------------------------------------------------------------
// 4. The scan trail records the act, never the actor
// ---------------------------------------------------------------------------

describe('scan history', () => {
  const SCANS = {
    total: 3,
    recent: [
      {
        id: 3,
        document_id: 5,
        qr_token_id: 77,
        scanner_profile_id: null,
        outcome: 'verified',
        scanned_at: '2026-08-11T09:00:00Z',
      },
      {
        id: 2,
        document_id: 5,
        qr_token_id: 77,
        scanner_profile_id: 10,
        outcome: 'verified',
        scanned_at: '2026-08-10T09:00:00Z',
      },
      {
        id: 1,
        document_id: 5,
        qr_token_id: 77,
        scanner_profile_id: null,
        outcome: 'refused',
        scanned_at: '2026-08-09T09:00:00Z',
      },
    ],
  };

  it('renders a public scan as a public scan, never as an unnamed person', async () => {
    serve({ qrPanels: [livePanel({ scans: SCANS })] });

    render(<DocumentRecordPage />);

    const anonymous = await screen.findByTestId('document-record-qr-scan-3');
    expect(anonymous).toHaveTextContent('A member of the public');
    // Not "unknown", not "anonymous user", not an empty cell — each of those
    // reads as a person the system failed to name.
    expect(anonymous).not.toHaveTextContent(/unknown|unidentified|guest/i);
    // A signed-in scanner IS named, so the line above is a statement about what
    // is stored rather than a blanket refusal to name anybody.
    expect(screen.getByTestId('document-record-qr-scan-2')).toHaveTextContent('amira@example.test');
  });

  it('says the absence of a visitor identity is structural, not a gap', async () => {
    serve({ qrPanels: [livePanel({ scans: SCANS })] });

    render(<DocumentRecordPage />);

    const trail = await screen.findByTestId('document-record-qr-scans');
    expect(trail).toHaveTextContent(/no address, no device, no location/i);
    expect(trail).toHaveTextContent(/no column that could hold it/i);
    // The coalescing window, so nobody reads the count as a request log.
    expect(trail).toHaveTextContent(/within a minute count once/i);
  });

  it('explains a refused scan without claiming what the scanner was told', async () => {
    serve({ qrPanels: [livePanel({ scans: SCANS })] });

    render(<DocumentRecordPage />);

    const note = await screen.findByTestId('document-record-qr-refused-note');
    expect(note).toHaveTextContent(/did not confirm the document/i);
    // A revoked code and an unknown one are the same public answer by default.
    // The panel must not surface a distinction the tenant has not opted into.
    expect(note).not.toHaveTextContent(/told|shown that it was withdrawn/i);
  });

  it('states an exact total beside a capped list', async () => {
    serve({
      qrPanels: [livePanel({ scans: { total: 300, recent: SCANS.recent } })],
    });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-scan-total')).toHaveTextContent(
      'Scanned 300 times'
    );
    // A truncated list with no total reads as the whole list.
    expect(screen.getByTestId('document-record-qr-scans-more')).toHaveTextContent(
      'Showing the 3 most recent of 300'
    );
  });

  /**
   * THE BROWSER PASS FOUND THIS ONE, and no type and no assertion elsewhere
   * could have.
   *
   * The panel rendered "Scanned 1 times." on a real document with one real
   * scan. `t()` on this platform interpolates and does no plural selection, so
   * a single string cannot cover both cases — the component has to choose the
   * key, and this is the pin that says so. The negative assertion is the one
   * that matters: it fails the moment somebody collapses the two branches back
   * into one string.
   */
  it('agrees with its own numbers rather than rendering "1 times"', async () => {
    serve({
      qrPanels: [
        livePanel({ scans: { total: 1, recent: [SCANS.recent[1]] } }),
      ],
    });

    render(<DocumentRecordPage />);

    const total = await screen.findByTestId('document-record-qr-scan-total');
    expect(total).toHaveTextContent('Scanned once.');
    expect(total).not.toHaveTextContent(/1 times/);
  });

  it('says a document has not been scanned rather than showing an empty table', async () => {
    serve();

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-scan-total')).toHaveTextContent(
      'has not been scanned'
    );
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
  });

  it('numbers a scanner it may not name, and says which permission would name them', async () => {
    serve({ qrPanels: [livePanel({ scans: SCANS })], directoryStatus: 403 });

    render(<DocumentRecordPage />);

    // Never a blank and never a guess: the id, plus the sentence naming the
    // permission, so a reader can tell a policy boundary from a data bug.
    expect(await screen.findByTestId('document-record-qr-scan-2')).toHaveTextContent('Account #10');
    expect(
      within(screen.getByTestId('document-record-section-qr')).getByTestId(
        'document-record-directory-people'
      )
    ).toBeInTheDocument();
  });

  /**
   * ...AND WITHHOLDS THAT SENTENCE WHEN IT NUMBERS NOBODY.
   *
   * A document with no code and no signed-in scanner shows no person at all.
   * "People are shown by account number" above a panel showing no account
   * numbers is a true sentence about nothing in front of the reader, which is
   * the same class of defect as a false one: it sends somebody looking for a
   * permission that would change nothing they can see.
   */
  it('says nothing about the directory when it names nobody', async () => {
    serve({
      qrPanels: [
        {
          enabled: true,
          configured: true,
          token: null,
          retired: { total: 0, recent: [] },
          scans: { total: 0, recent: [] },
        },
      ],
      directoryStatus: 403,
    });

    render(<DocumentRecordPage />);

    await screen.findByTestId('document-record-qr-none');
    expect(
      within(screen.getByTestId('document-record-section-qr')).queryByTestId(
        'document-record-directory-people'
      )
    ).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// 5. The panel is not blanked by its own refetch (#1041's defect)
// ---------------------------------------------------------------------------

describe('the panel survives its own refetch', () => {
  it('replaces its payload without unmounting the region', async () => {
    const user = userEvent.setup();
    serve({ qrPanels: [livePanel(), withdrawnPanel()] });

    render(<DocumentRecordPage />);

    const panel = await screen.findByTestId('document-record-qr');

    await user.click(screen.getByTestId('document-record-qr-withdraw'));
    await user.click(await screen.findByTestId('document-record-qr-withdraw-confirm'));

    await screen.findByTestId('document-record-qr-withdrawn');
    // The SAME node. A panel that went back through a loading state would have
    // been torn down and rebuilt, taking any open confirm and the answer with
    // it — which is what #1041 found next door.
    expect(panel).toBeInTheDocument();
    expect(screen.getByTestId('document-record-qr')).toBe(panel);
  });

  it('keeps the code on screen when the act fails, and says why', async () => {
    const user = userEvent.setup();
    serve({
      qrPanels: [livePanel()],
      qrMutation: { status: 409, body: { error: 'QR verification is switched off for this template' } },
    });

    render(<DocumentRecordPage />);

    await user.click(await screen.findByTestId('document-record-qr-mint'));
    await user.click(await screen.findByTestId('document-record-qr-mint-confirm'));

    // The server's own sentence, which says WHICH switch — more useful than
    // "that did not work" — and the live code is still on screen.
    expect(await screen.findByTestId('document-record-qr-act-error')).toHaveTextContent(
      'switched off for this template'
    );
    expect(screen.getByTestId('document-record-qr-live')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// The server decides whether this region exists at all
// ---------------------------------------------------------------------------

describe('the region is the server’s decision', () => {
  it('is absent from the DOM, and never requested, when the verdict withholds it', async () => {
    serve({
      sections: {
        document: { state: 'editable', denial: null },
        trail: { state: 'editable', denial: null },
        recipients: { state: 'editable', denial: null },
      },
    });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-section-document')).toBeInTheDocument();
    expect(screen.queryByTestId('document-record-section-qr')).not.toBeInTheDocument();
    // The second half of the same rule. Here it holds structurally rather than
    // by a flag: the panel owns its request, and a hidden region is never
    // mounted, so there is no effect to run.
    await waitFor(() => {
      expect(calls().some((call) => call.url.endsWith('/qr'))).toBe(false);
    });
  });

  /**
   * A PAYLOAD FROM AN OLDER BACKEND MUST NOT BLANK THE RECORD PAGE.
   *
   * `retired` arrived with #1052, so a web build deployed against a backend that
   * predates it receives a payload without the key. Reading `retired.recent` off
   * that throws during render — and a throw in one region is not contained: it
   * takes the document, its versions and its trail down with it. Defaulting is
   * the cheaper failure by a wide margin, and the defaults are chosen to
   * withhold controls rather than to offer broken ones.
   */
  it('survives a payload from a backend that predates the retired list', async () => {
    serve({
      qrPanels: [
        {
          enabled: true,
          configured: true,
          token: LIVE_CODE,
          scans: { total: 0, recent: [] },
          // no `retired` key at all
        },
      ],
    });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-live')).toBeInTheDocument();
    expect(screen.getByTestId('document-record-qr-reference')).toHaveTextContent('9F2A-4C11-8B03');
    // Absent, not empty-and-broken: there is nothing retired to list.
    expect(screen.queryByTestId('document-record-qr-retired')).not.toBeInTheDocument();
    // The rest of the record survived, which is the point.
    expect(screen.getByTestId('document-record-section-trail')).toBeInTheDocument();
  });

  /**
   * And an ABSENT flag withholds the control rather than offering it. An
   * unanswered `configured` must not read as "yes, this instance has an
   * address" — the direction that offers a mint the server would refuse.
   */
  it('treats an unanswered configuration flag as no, never as yes', async () => {
    serve({
      qrPanels: [{ token: null, scans: { total: 0, recent: [] }, retired: { total: 0, recent: [] } }],
    });

    render(<DocumentRecordPage />);

    expect(await screen.findByTestId('document-record-qr-unconfigured')).toBeInTheDocument();
    expect(screen.getByTestId('document-record-qr-mint')).toBeDisabled();
  });

  it('shows the code and the scans read-only when a permission refuses the writes', async () => {
    serve({
      sections: {
        ...ALL_EDITABLE,
        qr: {
          state: 'read-only',
          denial: {
            code: 'permission',
            reason: 'Server prose the client should override.',
            detail: "changing this requires the 'documents:render' permission",
          },
        },
      },
      qrPanels: [livePanel({ scans: { total: 4, recent: [] } })],
    });

    render(<DocumentRecordPage />);

    // Awaited on the PANEL rather than on the section: the section renders as
    // soon as the record lands, and the panel's own request is still in flight
    // at that moment. Asserting against the section alone would be asserting
    // against a half-loaded region.
    expect(await screen.findByTestId('document-record-qr-reference')).toBeInTheDocument();
    const section = screen.getByTestId('document-record-section-qr');
    expect(section).toHaveAttribute('data-section-state', 'read-only');
    // Reading is not the thing being refused: the code and the count are still
    // there, because a reader auditing a document needs both.
    expect(screen.getByTestId('document-record-qr-scan-total')).toHaveTextContent('4');
    // What is gone is the ability to act.
    expect(screen.queryByTestId('document-record-qr-actions')).not.toBeInTheDocument();
    const note = screen.getByTestId('document-record-section-qr-readonly');
    expect(note).toHaveTextContent(/Issuing a new one, or withdrawing it/i);
    expect(note).toHaveTextContent("documents:render");
  });

  it('gives a record refusal a cause-neutral sentence and lets the panel name the cause', async () => {
    serve({
      sections: {
        ...ALL_EDITABLE,
        qr: {
          state: 'read-only',
          denial: {
            code: 'record',
            reason: 'Server prose the client should override.',
            detail: null,
          },
        },
      },
      qrPanels: [
        {
          enabled: false,
          configured: true,
          token: null,
          retired: { total: 0, recent: [] },
          scans: { total: 0, recent: [] },
        },
      ],
    });

    render(<DocumentRecordPage />);

    // The panel, which holds `enabled` and `configured`, names the actual cause.
    expect(await screen.findByTestId('document-record-qr-disabled')).toHaveTextContent(
      /off for this template or for this organisation/i
    );
    // The region-level sentence commits to no cause, because the server's
    // predicate is false for three of them and naming one would be wrong in two.
    expect(screen.getByTestId('document-record-section-qr-readonly')).toHaveTextContent(
      /The reason is below/i
    );
  });
});
