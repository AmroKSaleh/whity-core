/**
 * #847: same block tree + same master-detail context ⇒ same submitted payload,
 * whichever renderer draws it.
 *
 * The web and desktop block renderers are hand-written twins of one SDK
 * contract, and nothing compared them. They diverged on the question that
 * matters most against a full-row-replace endpoint — WHICH FIELDS a form
 * submits — and no test noticed, because each side's tests only ever ran its
 * own renderer. This one runs both over the same tree, opens the same row into
 * both, and diffs the two payloads.
 *
 * Both transports are mocked at the module boundary (`apiClient` for web,
 * `@tauri-apps/api` for desktop); both renderers are otherwise the shipping
 * code.
 *
 * #867 added the other half of the same question — same tree ⇒ same REQUESTS,
 * not just the same payload — after both renderers turned out to render page 1
 * of a paginated source as the whole set. A twin that fetches differently is
 * the same class of divergence as one that submits differently, and it stayed
 * invisible for the same reason.
 *
 * KNOWN DIVERGENCES, deliberately not asserted here — each is a contract
 * question rather than a renderer bug, and each deserves its own change:
 *  - `numberInput`/`slider` values: web stores strings, desktop stores numbers,
 *    for TOUCHED values too. `equivalent()` below compares them stringified so
 *    this test still covers whether those fields are present and what they
 *    hold; it is the only tolerance in here.
 *  - `bilingualText` and a zero-`min` `fieldArray`: web seeds `{}` / `[]` for
 *    every one of them, declared default or not; desktop seeds only what the
 *    form actually shows. See `collectDefaults` in the desktop renderer.
 *  - required-field validation: web blocks a submit on an empty required
 *    input, desktop has no client-side check at all. Nothing here is marked
 *    required, so the two agree on this tree.
 *  - what an `open` row action publishes: web projects the fetched row down to
 *    the table's DECLARED COLUMNS first (`String(row[col.key] ?? '')`), desktop
 *    publishes the row whole. So on web a `defaultFrom` — or a `{target.id}`
 *    submit token — only resolves for a field the table happens to show. The
 *    tree below declares every field as a column so both renderers start from
 *    the same context and this test measures the defaults path, not that one.
 */

import React from 'react';
import { cleanup, render, screen, waitFor } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { BlockRenderer as WebBlockRenderer } from '@/components/plugin/blocks/block-renderer';
import type { Block as WebBlock } from '@/lib/plugin-features';
import { apiClient } from '@/lib/api-client';
import { ToastProvider } from '@/lib/toast-context';
import { BlockRenderer as DesktopBlockRenderer } from '../../templates/tauri-desktop/src/plugin-blocks/block-renderer';
import type {
  Block as DesktopBlock,
  PluginFeature,
} from '../../templates/tauri-desktop/src/plugin-blocks/types';

jest.mock('@/lib/api-client', () => ({ apiClient: jest.fn() }));
jest.mock('@tauri-apps/api/core', () => ({ invoke: jest.fn() }));

const mockInvoke = jest.requireMock('@tauri-apps/api/core').invoke as jest.Mock;
const mockApiClient = apiClient as jest.MockedFunction<typeof apiClient>;

/** The row both renderers open, with a value of every seedable shape. */
const ROW = {
  id: 7,
  displayName: 'Ada Lovelace',
  bio: 'Analytical engine',
  memo: 'countess of lovelace',
  kind: 'engineer',
  birthDate: '1815-12-10',
  color: '#00ff00',
  deceased: true,
  rating: 4,
  weight: 70,
};

/** One tree, fed to both renderers. `nickname` declares no default and must
 * therefore be absent from BOTH payloads — an agreed-on empty string would be
 * parity at the wrong value. */
const TREE = [
  {
    type: 'dataTable',
    source: '/api/v1/people',
    columns: Object.keys(ROW).map((key) => ({ key, label: key })),
    rowActions: [{ label: 'Edit', open: 'edit-person' }],
  },
  {
    type: 'modal',
    id: 'edit-person',
    title: 'Edit person',
    children: [
      {
        type: 'form',
        submit: { method: 'PATCH', endpoint: '/api/v1/people/{edit-person.id}' },
        children: [
          { type: 'textInput', name: 'displayName', label: 'Name', defaultFrom: 'edit-person.displayName' },
          { type: 'textArea', name: 'bio', label: 'Bio', defaultFrom: 'edit-person.bio' },
          { type: 'richTextInput', name: 'memo', label: 'Memo', defaultFrom: 'edit-person.memo' },
          {
            type: 'select',
            name: 'kind',
            label: 'Kind',
            defaultFrom: 'edit-person.kind',
            options: [
              { value: 'engineer', label: 'Engineer' },
              { value: 'analyst', label: 'Analyst' },
            ],
          },
          { type: 'dateInput', name: 'birthDate', label: 'Born', defaultFrom: 'edit-person.birthDate' },
          { type: 'colorInput', name: 'color', label: 'Colour', defaultFrom: 'edit-person.color' },
          { type: 'checkbox', name: 'deceased', label: 'Deceased', defaultFrom: 'edit-person.deceased' },
          { type: 'slider', name: 'rating', label: 'Rating', min: 0, max: 10, defaultFrom: 'edit-person.rating' },
          { type: 'numberInput', name: 'weight', label: 'Weight', defaultFrom: 'edit-person.weight' },
          { type: 'textInput', name: 'nickname', label: 'Nickname' },
          { type: 'submitButton', label: 'Save' },
        ],
      },
    ],
  },
];

type Payload = Record<string, unknown>;

/** Stringifies numbers so the documented numeric-type divergence doesn't mask
 * the thing under test — which keys are sent and what they hold. */
function equivalent(payload: Payload): Payload {
  return Object.fromEntries(
    Object.entries(payload).map(([key, value]) => [
      key,
      typeof value === 'number' ? String(value) : value,
    ])
  );
}

// ---- web ----

function stubResponse(status: number, body: unknown): Response {
  return { ok: status >= 200 && status < 300, status, json: () => Promise.resolve(body) } as unknown as Response;
}

/** Opens the row into the web renderer, optionally edits, and returns the
 * payload it submitted. */
async function webPayload(edit?: (name: HTMLElement) => Promise<void>): Promise<Payload> {
  // Both renderers run inside one test, so the previous one has to be gone
  // before `screen` can name a button unambiguously.
  cleanup();
  mockApiClient.mockResolvedValue(stubResponse(200, { data: [ROW] }));
  render(<WebBlockRenderer blocks={TREE as unknown as WebBlock[]} />, {
    wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
  });

  await userEvent.click((await screen.findAllByRole('button', { name: 'Edit' }))[0]);
  await screen.findByText('Edit person');
  if (edit) await edit(await screen.findByDisplayValue('Ada Lovelace'));

  mockApiClient.mockClear();
  await userEvent.click(screen.getByRole('button', { name: 'Save' }));

  const isWrite = (call: Parameters<typeof apiClient>) => call[1]?.method !== 'GET';
  await waitFor(() => expect(mockApiClient.mock.calls.some(isWrite)).toBe(true));
  const body = mockApiClient.mock.calls.find(isWrite)![1]?.body;
  return JSON.parse(String(body)) as Payload;
}

// ---- desktop ----

const FEATURE: PluginFeature = {
  id: 'people',
  plugin: 'demo',
  label: 'People',
  icon: null,
  group: 'Demo',
  order: 1,
  screen: 'blocks',
  requiredPermission: '',
  blocks: TREE as unknown as DesktopBlock[],
};

/** The same, through the desktop renderer's PHP-host transport. */
async function desktopPayload(edit?: (name: HTMLElement) => Promise<void>): Promise<Payload> {
  cleanup();
  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { method, path } = args as { method: string; path: string };
    const body = method === 'GET' && path === '/api/v1/people' ? { data: [ROW] } : { data: null };
    return Promise.resolve({ status: 200, body });
  });
  render(<DesktopBlockRenderer feature={FEATURE} />);

  await userEvent.click((await screen.findAllByRole('button', { name: 'Edit' }))[0]);
  await screen.findByText('Edit person');
  if (edit) await edit(await screen.findByDisplayValue('Ada Lovelace'));

  mockInvoke.mockClear();
  await userEvent.click(screen.getByRole('button', { name: 'Save' }));

  await waitFor(() =>
    expect(mockInvoke.mock.calls.some(([, args]) => (args as { method: string }).method !== 'GET')).toBe(true)
  );
  const write = mockInvoke.mock.calls.find(([, args]) => (args as { method: string }).method !== 'GET');
  return (write![1] as { body: Payload }).body;
}

beforeEach(() => {
  jest.clearAllMocks();
});

describe('web ⇄ desktop submitted-payload parity', () => {
  it('agrees on an edit form the user opened and submitted untouched', async () => {
    const web = await webPayload();
    const desktop = await desktopPayload();

    expect(equivalent(desktop)).toEqual(equivalent(web));
    // Pinned literally too, so a shared regression that makes both renderers
    // send nothing still fails this test rather than agreeing on nothing.
    expect(equivalent(web)).toEqual({
      displayName: 'Ada Lovelace',
      bio: 'Analytical engine',
      memo: 'countess of lovelace',
      kind: 'engineer',
      birthDate: '1815-12-10',
      color: '#00ff00',
      deceased: true,
      rating: '4',
      weight: '70',
    });
    expect(Object.keys(web)).not.toContain('nickname');
    expect(Object.keys(desktop)).not.toContain('nickname');
  });

  it('agrees when one field is edited and the rest are left alone', async () => {
    const retype = async (name: HTMLElement) => {
      await userEvent.clear(name);
      await userEvent.type(name, 'Ada King');
    };
    const web = await webPayload(retype);
    const desktop = await desktopPayload(retype);

    expect(equivalent(desktop)).toEqual(equivalent(web));
    expect(desktop.displayName).toBe('Ada King');
    expect(desktop.bio).toBe('Analytical engine');
  });
});

// ---- fetch parity (#867) ----

/** A dataset big enough that one request cannot hold it at any page size. */
const PEOPLE = Array.from({ length: 120 }, (_, i) => ({
  id: i + 1,
  displayName: `Row ${i + 1}`,
}));

/** One page of `dataset`, sliced and clamped exactly as the API clamps it. */
function servePage(dataset: Payload[], url: string) {
  const query = new URLSearchParams(url.split('?')[1] ?? '');
  const perPage = Math.min(Number(query.get('per_page') ?? 25), 100);
  const page = Number(query.get('page') ?? '1');
  const offset = (page - 1) * perPage;
  return {
    data: dataset.slice(offset, offset + perPage),
    pagination: {
      page,
      perPage,
      total: dataset.length,
      totalPages: Math.ceil(dataset.length / perPage),
    },
  };
}

/** A table over a paginated source — the shape every plugin picker uses. */
const TABLE_TREE = [
  {
    type: 'dataTable',
    source: '/api/v1/people',
    columns: [{ key: 'displayName', label: 'Name' }],
  },
];

/** Renders the table in the web renderer and returns the paths it requested. */
async function webRequestPaths(): Promise<string[]> {
  cleanup();
  mockApiClient.mockImplementation((url) =>
    Promise.resolve(stubResponse(200, servePage(PEOPLE, String(url))))
  );
  render(<WebBlockRenderer blocks={TABLE_TREE as unknown as WebBlock[]} />, {
    wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
  });

  // The row that used to be missing — page 1 ended at 25.
  await screen.findByText('Row 120');
  return mockApiClient.mock.calls
    .filter((call) => call[1]?.method === undefined)
    .map((call) => String(call[0]));
}

/** The same table through the desktop renderer's PHP-host transport. */
async function desktopRequestPaths(): Promise<string[]> {
  cleanup();
  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { path } = args as { method: string; path: string };
    return Promise.resolve({ status: 200, body: servePage(PEOPLE, path) });
  });
  render(
    <DesktopBlockRenderer
      feature={{ ...FEATURE, blocks: TABLE_TREE as unknown as DesktopBlock[] }}
    />
  );

  await screen.findByText('Row 120');
  return mockInvoke.mock.calls
    .filter(([, args]) => (args as { method: string }).method === 'GET')
    .map(([, args]) => String((args as { path: string }).path));
}

describe('web ⇄ desktop fetched-collection parity', () => {
  it('agrees on the requests a paginated source costs', async () => {
    const web = await webRequestPaths();
    const desktop = await desktopRequestPaths();

    expect(desktop).toEqual(web);
    // Pinned literally too, so a shared regression back to one request still
    // fails here rather than agreeing on the wrong walk.
    expect(web).toEqual([
      '/api/v1/people',
      '/api/v1/people?page=1&per_page=100',
      '/api/v1/people?page=2&per_page=100',
    ]);
  });

  it('agrees that a half-loaded collection is an error, not a short table', async () => {
    // #824 in the block renderer: an operator who cannot see row 26 concludes
    // the row does not exist. Neither renderer may draw the 100 rows it holds.
    const failPageTwo = (url: string) =>
      new URLSearchParams(url.split('?')[1] ?? '').get('page') === '2';

    cleanup();
    mockApiClient.mockImplementation((url) =>
      Promise.resolve(
        failPageTwo(String(url))
          ? stubResponse(500, { error: 'boom' })
          : stubResponse(200, servePage(PEOPLE, String(url)))
      )
    );
    render(<WebBlockRenderer blocks={TABLE_TREE as unknown as WebBlock[]} />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await screen.findByRole('button', { name: 'Retry' });
    expect(screen.queryByText('Row 1')).not.toBeInTheDocument();

    cleanup();
    mockInvoke.mockImplementation((_command: string, args?: unknown) => {
      const { path } = args as { path: string };
      return Promise.resolve(
        failPageTwo(path)
          ? { status: 500, body: { error: 'boom' } }
          : { status: 200, body: servePage(PEOPLE, path) }
      );
    });
    render(
      <DesktopBlockRenderer
        feature={{ ...FEATURE, blocks: TABLE_TREE as unknown as DesktopBlock[] }}
      />
    );
    await screen.findByRole('button', { name: 'Retry' });
    expect(screen.queryByText('Row 1')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// #883: same record tree ⇒ same rendered facts, and the same fields WITHHELD.
//
// The record blocks are where a twin divergence would be worst. The web and
// desktop renderers each hold their own copy of the projection that decides
// which of a payload's fields reach the tree, and that projection IS the #895
// guard's structural half — so one renderer publishing a field the other drops
// is not a cosmetic difference, it is one platform able to state a caller
// permission as a fact and the other not. Both are run over the same tree
// against the same payload here, and both are asserted to withhold the flags.
// ---------------------------------------------------------------------------

/** A record endpoint's honest answer: the record's own fields AND the caller's
 * permissions on it, which is what a real one returns. */
const RECORD = {
  name: 'Ada Lovelace',
  role: 'Administrator',
  status: 'Active',
  manageable: true,
  canEdit: true,
};

/** A record page in miniature: the container, a bound heading/badge/stat, and
 * the description list. `manageable` and `canEdit` are named nowhere. */
const RECORD_TREE = [
  {
    type: 'dataRecord',
    id: 'person',
    source: '/api/v1/people/7',
    fields: [
      { field: 'name', label: 'Name' },
      { field: 'role', label: 'Role' },
      { field: 'status', label: 'Status' },
    ],
    children: [
      { type: 'heading', level: 2, text: 'Untitled record', textFrom: 'person.name' },
      { type: 'badge', variant: 'info', label: 'No role', labelFrom: 'person.role' },
      { type: 'stat', label: 'Status', value: 'Unknown', valueFrom: 'person.status' },
      { type: 'text', value: 'No role', valueFrom: 'person.role' },
      { type: 'recordFields', from: 'person' },
    ],
  },
];

/** What a rendered record tree SAYS, as a sorted list of its text content —
 * enough to compare two renderers that lay the same facts out differently. */
function renderedFacts(container: HTMLElement): string[] {
  return Array.from(container.querySelectorAll('h2, dt, dd, p, span'))
    .map((node) => (node.textContent ?? '').trim())
    .filter((text) => text !== '')
    .sort();
}

describe('web ⇄ desktop record-block parity (#883)', () => {
  it('agrees on the facts a record tree states', async () => {
    cleanup();
    mockApiClient.mockResolvedValue(stubResponse(200, { data: RECORD }));
    const web = render(<WebBlockRenderer blocks={RECORD_TREE as unknown as WebBlock[]} />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await screen.findAllByText('Ada Lovelace');
    const webFacts = renderedFacts(web.container);

    cleanup();
    mockInvoke.mockImplementation(() => Promise.resolve({ status: 200, body: { data: RECORD } }));
    const desktop = render(
      <DesktopBlockRenderer
        feature={{ ...FEATURE, blocks: RECORD_TREE as unknown as DesktopBlock[] }}
      />
    );
    await screen.findAllByText('Ada Lovelace');
    const desktopFacts = renderedFacts(desktop.container);

    // Both must show the record's own values...
    for (const value of ['Ada Lovelace', 'Administrator', 'Active']) {
      expect(webFacts).toContain(value);
      expect(desktopFacts).toContain(value);
    }
    // ...and both must have replaced every declared literal fallback.
    for (const fallback of ['Untitled record', 'No role', 'Unknown']) {
      expect(webFacts).not.toContain(fallback);
      expect(desktopFacts).not.toContain(fallback);
    }
  });

  it('agrees that an undeclared caller-permission field never reaches the tree', async () => {
    // The payload carries `manageable` and `canEdit`; the declaration names
    // neither. Neither renderer may publish them, so neither can render them —
    // this is the runtime half of the #895 guard, asserted on both twins.
    cleanup();
    mockApiClient.mockResolvedValue(stubResponse(200, { data: RECORD }));
    const web = render(<WebBlockRenderer blocks={RECORD_TREE as unknown as WebBlock[]} />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await screen.findAllByText('Ada Lovelace');
    expect(web.container.textContent).not.toMatch(/manageable|canEdit/i);
    // A boolean flag would render as "Yes" through `formatFactValue` — assert on
    // the rendered form too, since the field NAME never appears either way.
    expect(web.container.querySelectorAll('dd')).toHaveLength(3);

    cleanup();
    mockInvoke.mockImplementation(() => Promise.resolve({ status: 200, body: { data: RECORD } }));
    const desktop = render(
      <DesktopBlockRenderer
        feature={{ ...FEATURE, blocks: RECORD_TREE as unknown as DesktopBlock[] }}
      />
    );
    await screen.findAllByText('Ada Lovelace');
    expect(desktop.container.textContent).not.toMatch(/manageable|canEdit/i);
    expect(desktop.container.querySelectorAll('dd')).toHaveLength(3);
  });

  it('agrees that an unresolved record token costs ZERO requests', async () => {
    // The failure this prevents: `/api/v1/people/{record}` with nothing bound
    // becomes `/api/v1/people/`, which is the COLLECTION endpoint — so the block
    // would fetch every record the caller can see and render one of them as "the
    // record this page is about". Both renderers must decline to fetch at all.
    const UNBOUND = [{ ...RECORD_TREE[0], source: '/api/v1/people/{record}' }];

    cleanup();
    mockApiClient.mockResolvedValue(stubResponse(200, { data: RECORD }));
    render(<WebBlockRenderer blocks={UNBOUND as unknown as WebBlock[]} />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await screen.findByText('No record selected.');
    expect(mockApiClient).not.toHaveBeenCalled();

    cleanup();
    mockInvoke.mockClear();
    mockInvoke.mockImplementation(() => Promise.resolve({ status: 200, body: { data: RECORD } }));
    render(
      <DesktopBlockRenderer
        feature={{ ...FEATURE, blocks: UNBOUND as unknown as DesktopBlock[] }}
      />
    );
    await screen.findByText('No record selected');
    expect(mockInvoke).not.toHaveBeenCalled();
  });

  it('agrees on the request a route-bound record costs once the host seeds it', async () => {
    // The page-level record binding (#883 gap 2): the host publishes the route's
    // record id under `record`, and the SAME declaration that fetched nothing
    // above now addresses exactly one resource.
    const UNBOUND = [{ ...RECORD_TREE[0], source: '/api/v1/people/{record}' }];

    cleanup();
    mockApiClient.mockClear();
    mockApiClient.mockResolvedValue(stubResponse(200, { data: RECORD }));
    render(<WebBlockRenderer blocks={UNBOUND as unknown as WebBlock[]} record="7" />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await screen.findAllByText('Ada Lovelace');
    const webPaths = mockApiClient.mock.calls.map((call) => String(call[0]));

    cleanup();
    mockInvoke.mockClear();
    mockInvoke.mockImplementation(() => Promise.resolve({ status: 200, body: { data: RECORD } }));
    render(
      <DesktopBlockRenderer
        feature={{ ...FEATURE, blocks: UNBOUND as unknown as DesktopBlock[] }}
        record="7"
      />
    );
    await screen.findAllByText('Ada Lovelace');
    const desktopPaths = mockInvoke.mock.calls.map(([, args]) => String((args as { path: string }).path));

    expect(webPaths).toEqual(['/api/v1/people/7']);
    expect(desktopPaths).toEqual(['/api/v1/people/7']);
  });
});

// ---------------------------------------------------------------------------
// #909: same gated tree ⇒ same region, on both renderers.
//
// This is the twin divergence that would matter most of all. Each renderer holds
// its own copy of the walk that collects a page's `accessGate` checks, its own
// substitution of their `{token}` endpoints, and its own mapping from an answer
// to a rendering. One twin resolving a gate the other skips is one platform
// showing an editor where the other shows a description list — a permission
// difference between two builds of the same declaration, which is worse than a
// layout difference by every measure.
//
// The mocks answer the permitted-actions batch for both hosts. The paths differ
// (`/api/v1/me/permitted-actions` on the server, `/__whity/permitted-actions`
// offline) and the ANSWER must not.
// ---------------------------------------------------------------------------

const WEB_PERMITTED_ACTIONS = '/api/v1/me/permitted-actions';
const DESKTOP_PERMITTED_ACTIONS = '/__whity/permitted-actions';

/** One record page's editable/read-only pair, plus a leaf gated by condition. */
const GATED_TREE = [
  {
    type: 'dataRecord',
    id: 'person',
    source: '/api/v1/people/{record}',
    fields: [
      { field: 'name', label: 'Name' },
      { field: 'role', label: 'Role' },
    ],
    children: [
      {
        type: 'accessGate',
        id: 'may-write',
        check: { method: 'PATCH', endpoint: '/api/v1/people/{record}' },
        children: [{ type: 'text', value: 'EDITABLE' }],
        otherwise: [{ type: 'text', value: 'READ ONLY' }],
      },
      { type: 'text', value: 'BADGE FOR READERS', visibleWhen: { access: 'may-write', equals: false } },
      { type: 'text', value: 'NOTICE FOR ADMINS', visibleWhen: { from: 'person.role', equals: 'Administrator' } },
    ],
  },
];

/**
 * The batch each host handed over, normalized.
 *
 * Web serializes it into a fetch body (a JSON string); the Tauri bridge passes
 * the object straight through. That difference is transport, not contract — the
 * CHECKS have to be identical, and reading both into the same shape is what lets
 * this file assert that.
 */
function readBatch(body: unknown): { checks: { ref: string }[] } {
  return (typeof body === 'string' ? JSON.parse(body) : body) as { checks: { ref: string }[] };
}

/** The batch bodies each host was asked, and what it rendered. */
async function runGated(
  allowed: string[]
): Promise<{ web: { batches: unknown[]; text: string }; desktop: { batches: unknown[]; text: string } }> {
  const answer = (body: unknown) => ({
    data: readBatch(body).checks.map((c) => ({
      ref: c.ref,
      allowed: allowed.includes(c.ref),
      required: null,
    })),
  });

  cleanup();
  const webBatches: unknown[] = [];
  mockApiClient.mockImplementation((url, init) => {
    if (String(url) === WEB_PERMITTED_ACTIONS) {
      const body = (init as RequestInit | undefined)?.body ?? '{"checks":[]}';
      webBatches.push(readBatch(body));
      return Promise.resolve(stubResponse(200, answer(body)));
    }
    return Promise.resolve(stubResponse(200, { data: RECORD }));
  });
  const web = render(<WebBlockRenderer blocks={GATED_TREE as unknown as WebBlock[]} record="7" />, {
    wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
  });
  await screen.findByText(allowed.includes('may-write') ? 'EDITABLE' : 'READ ONLY');
  const webText = web.container.textContent ?? '';

  cleanup();
  const desktopBatches: unknown[] = [];
  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { path, body } = args as { path: string; body?: unknown };
    if (path === DESKTOP_PERMITTED_ACTIONS) {
      const raw = body ?? { checks: [] };
      desktopBatches.push(readBatch(raw));
      return Promise.resolve({ status: 200, body: answer(raw) });
    }
    return Promise.resolve({ status: 200, body: { data: RECORD } });
  });
  const desktop = render(
    <DesktopBlockRenderer
      feature={{ ...FEATURE, blocks: GATED_TREE as unknown as DesktopBlock[] }}
      record="7"
    />
  );
  await screen.findByText(allowed.includes('may-write') ? 'EDITABLE' : 'READ ONLY');
  const desktopText = desktop.container.textContent ?? '';

  return {
    web: { batches: webBatches, text: webText },
    desktop: { batches: desktopBatches, text: desktopText },
  };
}

describe('web ⇄ desktop access-gate parity (#909)', () => {
  it('agrees on which rendering a refused gate produces', async () => {
    const { web, desktop } = await runGated([]);

    for (const rendered of [web.text, desktop.text]) {
      expect(rendered).toContain('READ ONLY');
      expect(rendered).not.toContain('EDITABLE');
      expect(rendered).toContain('BADGE FOR READERS');
      expect(rendered).toContain('NOTICE FOR ADMINS');
    }
  });

  it('agrees on which rendering a permitted gate produces', async () => {
    const { web, desktop } = await runGated(['may-write']);

    for (const rendered of [web.text, desktop.text]) {
      expect(rendered).toContain('EDITABLE');
      expect(rendered).not.toContain('READ ONLY');
      expect(rendered).not.toContain('BADGE FOR READERS');
    }
  });

  it('agrees on the question asked: one batch, one check, the same substituted path', async () => {
    const { web, desktop } = await runGated(['may-write']);

    const expected = [{ checks: [{ ref: 'may-write', method: 'PATCH', path: '/api/v1/people/7' }] }];
    expect(web.batches).toEqual(expected);
    expect(desktop.batches).toEqual(expected);
  });

  it('agrees that a gate answer is never renderable as a fact about the record', async () => {
    // The #895 seam, on both twins. Each fact binding names the gate; each must
    // fall back to its literal, because the access namespace is not what
    // `…From` resolves against on either platform.
    const FACT_ATTEMPT = [
      { type: 'accessGate', id: 'may-write', check: { method: 'PATCH', endpoint: '/api/v1/people/7' } },
      { type: 'heading', level: 2, text: 'LITERAL HEADING', textFrom: 'may-write' },
      { type: 'badge', variant: 'info', label: 'LITERAL BADGE', labelFrom: 'may-write.allowed' },
    ];
    const permit = (body: unknown) => ({
      data: readBatch(body).checks.map((c) => ({ ref: c.ref, allowed: true, required: null })),
    });

    cleanup();
    mockApiClient.mockImplementation((url, init) =>
      Promise.resolve(
        String(url) === WEB_PERMITTED_ACTIONS
          ? stubResponse(200, permit((init as RequestInit | undefined)?.body ?? '{"checks":[]}'))
          : stubResponse(200, { data: RECORD })
      )
    );
    const web = render(<WebBlockRenderer blocks={FACT_ATTEMPT as unknown as WebBlock[]} />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await screen.findByText('LITERAL HEADING');
    expect(web.container.textContent).toContain('LITERAL BADGE');
    expect(web.container.textContent).not.toMatch(/\btrue\b/);

    cleanup();
    mockInvoke.mockImplementation((_command: string, args?: unknown) => {
      const { path, body } = args as { path: string; body?: unknown };
      return Promise.resolve(
        path === DESKTOP_PERMITTED_ACTIONS
          ? { status: 200, body: permit(body ?? { checks: [] }) }
          : { status: 200, body: { data: RECORD } }
      );
    });
    const desktop = render(
      <DesktopBlockRenderer feature={{ ...FEATURE, blocks: FACT_ATTEMPT as unknown as DesktopBlock[] }} />
    );
    await screen.findByText('LITERAL HEADING');
    expect(desktop.container.textContent).toContain('LITERAL BADGE');
    expect(desktop.container.textContent).not.toMatch(/\btrue\b/);
  });

  it('agrees that a gate with an unresolved token is never asked', async () => {
    const UNBOUND = [
      {
        type: 'accessGate',
        id: 'may-write',
        check: { method: 'PATCH', endpoint: '/api/v1/people/{record}' },
        children: [{ type: 'text', value: 'EDITABLE' }],
        otherwise: [{ type: 'text', value: 'READ ONLY' }],
      },
    ];

    cleanup();
    mockApiClient.mockClear();
    mockApiClient.mockResolvedValue(stubResponse(200, { data: [] }));
    const web = render(<WebBlockRenderer blocks={UNBOUND as unknown as WebBlock[]} />, {
      wrapper: ({ children }) => <ToastProvider>{children}</ToastProvider>,
    });
    await waitFor(() => expect(mockApiClient).not.toHaveBeenCalled());
    // Not a skeleton either: an unasked gate renders nothing, on both twins.
    expect(web.container.querySelector('[data-slot="block-access-pending"]')).toBeNull();
    expect(web.container.textContent).not.toContain('EDITABLE');
    expect(web.container.textContent).not.toContain('READ ONLY');

    cleanup();
    mockInvoke.mockClear();
    mockInvoke.mockResolvedValue({ status: 200, body: { data: [] } });
    const desktop = render(
      <DesktopBlockRenderer feature={{ ...FEATURE, blocks: UNBOUND as unknown as DesktopBlock[] }} />
    );
    await waitFor(() => expect(mockInvoke).not.toHaveBeenCalled());
    expect(desktop.container.querySelector('[data-slot="block-access-pending"]')).toBeNull();
    expect(desktop.container.textContent).not.toContain('EDITABLE');
    expect(desktop.container.textContent).not.toContain('READ ONLY');
  });
});
