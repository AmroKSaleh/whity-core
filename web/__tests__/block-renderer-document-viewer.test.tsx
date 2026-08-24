/**
 * #947 item 4: the `documentViewer` block (web renderer).
 *
 * What these tests are FOR. The viewer's whole claim is that the reader always
 * knows which artifact is on screen, so the assertions are mostly about
 * SENTENCES rather than about pixels: that a corrected document says it was
 * corrected, that an earlier version says it is an earlier version, and that a
 * refusal names its reason instead of rendering an empty rectangle.
 *
 * The PDF frame itself is not exercised beyond its `src` and its absence.
 * jsdom has no PDF renderer, so asserting on the rendering would pin the stub,
 * not the behaviour; what is worth pinning is WHICH artifact's bytes were
 * fetched and whether a frame was offered at all.
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

function jsonResponse(body: unknown, status = 200): Response {
  return {
    ok: status >= 200 && status < 300,
    status,
    json: () => Promise.resolve(body),
  } as unknown as Response;
}

function pdfResponse(marker = 'pdf-bytes'): Response {
  return {
    ok: true,
    status: 200,
    blob: () => Promise.resolve(new Blob([marker], { type: 'application/pdf' })),
  } as unknown as Response;
}

function errorResponse(status: number): Response {
  return { ok: false, status, json: () => Promise.reject(new Error('no body')) } as unknown as Response;
}

/** One artifact as `DocumentPresenter::artifact()` puts it on the wire. */
function artifact(id: number, renderedAt: string) {
  return {
    id,
    document_id: 7,
    content_type: 'application/pdf',
    byte_size: 20480,
    checksum_sha256: `${id}`.repeat(4) + 'a'.repeat(64 - `${id}`.repeat(4).length),
    rendered_at: renderedAt,
    content_url: `/api/v1/documents/7/artifacts/${id}/content`,
  };
}

/** A document with `count` artifacts, newest first — the order core returns. */
function documentWith(count: number) {
  const dates = ['2026-03-01T09:00:00Z', '2026-04-14T11:30:00Z', '2026-05-20T16:45:00Z'];
  const artifacts = Array.from({ length: count }, (_, i) => artifact(100 + i, dates[i] ?? dates[0]))
    .reverse();
  return {
    id: 7,
    title: 'Purchase Order 4412',
    template_name: 'Purchase order',
    created_at: '2026-03-01T09:00:00Z',
    artifacts,
  };
}

/** The tree under test: a viewer bound to the route's own record id. */
function viewerTree(overrides: Record<string, unknown> = {}): Block[] {
  return [
    {
      type: 'documentViewer',
      documentIdFrom: 'record',
      ...overrides,
    } as unknown as Block,
  ];
}

function renderTree(blocks: Block[], record?: string) {
  return render(
    <ToastProvider>
      <BlockRenderer blocks={blocks} record={record} />
    </ToastProvider>
  );
}

/** The version bar's own position line — the superseded alert repeats the text. */
function positionLine(): HTMLElement | null {
  return document.querySelector('[data-slot="document-viewer-position"]');
}

async function findPosition(text: RegExp): Promise<HTMLElement> {
  return waitFor(() => {
    const el = positionLine();
    expect(el).not.toBeNull();
    expect(el as HTMLElement).toHaveTextContent(text);
    return el as HTMLElement;
  });
}

let objectUrls = 0;

beforeAll(() => {
  // jsdom implements neither. The viewer's contract with them is narrow — one
  // URL per artifact, revoked when that artifact goes away — so counting calls
  // is enough to pin it.
  Object.defineProperty(URL, 'createObjectURL', {
    configurable: true,
    value: jest.fn(() => `blob:test/${++objectUrls}`),
  });
  Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: jest.fn() });
});

beforeEach(() => {
  mockApiClient.mockReset();
  (URL.createObjectURL as jest.Mock).mockClear();
  (URL.revokeObjectURL as jest.Mock).mockClear();
  objectUrls = 0;
});

/** Route the mock by URL: the record fetch answers JSON, content answers bytes. */
function serve(document: unknown, contentStatus = 200) {
  mockApiClient.mockImplementation((url: string) => {
    if (url.includes('/content')) {
      return Promise.resolve(contentStatus === 200 ? pdfResponse(url) : errorResponse(contentStatus));
    }
    return Promise.resolve(jsonResponse({ data: document }));
  });
}

// =========================================================================
// Nothing has said which document yet
// =========================================================================

describe('an unresolved document binding', () => {
  it('renders the author’s emptyText and asks core nothing', async () => {
    renderTree(viewerTree({ emptyText: 'This request has no work order yet.' }));

    expect(await screen.findByText('This request has no work order yet.')).toBeInTheDocument();
    expect(mockApiClient).not.toHaveBeenCalled();
    // The distinction that matters: an empty STATE, not an empty frame.
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();
  });

  it('says so in its own words when the author supplied none', async () => {
    renderTree(viewerTree());

    expect(await screen.findByText('No document selected.')).toBeInTheDocument();
  });
});

// =========================================================================
// Which artifact is on screen, and how the reader learns about the others
// =========================================================================

describe('a document with one artifact', () => {
  it('names the version anyway, and frames the bytes it fetched', async () => {
    serve(documentWith(1));
    renderTree(viewerTree(), '7');

    // Stated even at one of one: a viewer that only mentions versions when
    // there are several teaches the reader that silence means "one".
    expect(await screen.findByText(/Version 1 of 1/)).toBeInTheDocument();
    expect(screen.getByText('Current')).toBeInTheDocument();
    expect(screen.queryByText(/earlier version/i)).not.toBeInTheDocument();

    const frame = await waitFor(() => {
      const el = document.querySelector('[data-slot="document-viewer-frame"]');
      expect(el).not.toBeNull();
      return el as HTMLIFrameElement;
    });
    expect(frame.getAttribute('src')).toMatch(/^blob:test\//);
    expect(mockApiClient).toHaveBeenCalledWith('/api/v1/documents/7', expect.anything());
    expect(mockApiClient).toHaveBeenCalledWith(
      '/api/v1/documents/7/artifacts/100/content',
      expect.anything()
    );
  });

  it('offers no version picker, because there is nothing to pick between', async () => {
    serve(documentWith(1));
    renderTree(viewerTree(), '7');

    await screen.findByText(/Version 1 of 1/);
    expect(screen.queryByLabelText('Choose a version')).not.toBeInTheDocument();
  });

  it('names the download after the document and the version, not after the id', async () => {
    serve(documentWith(1));
    renderTree(viewerTree(), '7');

    const link = await waitFor(() => {
      const el = document.querySelector('[data-slot="document-viewer-download"]');
      expect(el).not.toBeNull();
      return el as HTMLAnchorElement;
    });
    expect(link.getAttribute('download')).toBe('purchase-order-4412-v1.pdf');
  });
});

describe('a document that has been corrected twice', () => {
  it('opens on the current artifact and says how many others exist', async () => {
    serve(documentWith(3));
    renderTree(viewerTree(), '7');

    expect(await screen.findByText(/Version 3 of 3/)).toBeInTheDocument();
    expect(screen.getByText('Current')).toBeInTheDocument();
    // The newest is artifact 102 — the list arrives newest-first.
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/documents/7/artifacts/102/content',
        expect.anything()
      )
    );
  });

  it('offers every version, newest first, each with its issue date', async () => {
    serve(documentWith(3));
    renderTree(viewerTree(), '7');

    await screen.findByText(/Version 3 of 3/);
    await userEvent.click(screen.getByLabelText('Choose a version'));

    const options = await screen.findAllByRole('option');
    expect(options).toHaveLength(3);
    expect(options[0]).toHaveTextContent(/Version 3/);
    expect(options[1]).toHaveTextContent(/Version 2/);
    expect(options[2]).toHaveTextContent(/Version 1/);
  });

  it('warns, and names the newer version, when the reader moves to an earlier one', async () => {
    serve(documentWith(3));
    renderTree(viewerTree(), '7');

    await screen.findByText(/Version 3 of 3/);
    await userEvent.click(screen.getByLabelText('Choose a version'));
    await userEvent.click(await screen.findByRole('option', { name: /Version 1/ }));

    // The whole point of the block: an earlier artifact is pixel-identical to a
    // current one, so the only thing that can tell them apart is this sentence.
    expect(await screen.findByText('You are looking at an earlier version')).toBeInTheDocument();
    expect(positionLine()).toHaveTextContent(/Version 1 of 3/);
    expect(screen.getByText('Superseded')).toBeInTheDocument();

    // And it fetched the OLD bytes, not the current ones.
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/documents/7/artifacts/100/content',
        expect.anything()
      )
    );
  });
});

// =========================================================================
// A pinned artifact
// =========================================================================

describe('a block that pins one artifact', () => {
  /**
   * The pin comes out of a record like any other binding. A `dataRecord`
   * publishes both ids, which is the shape an append-only trail will use: the
   * event row names the artifact that circulated.
   */
  function pinnedTree(): Block[] {
    return [
      {
        type: 'dataRecord',
        id: 'evt',
        source: '/api/v1/acme/events/1',
        fields: [
          { field: 'documentId', label: 'Document' },
          { field: 'artifactId', label: 'Artifact' },
        ],
        children: [
          {
            type: 'documentViewer',
            documentIdFrom: 'evt.documentId',
            artifactIdFrom: 'evt.artifactId',
          },
        ],
      } as unknown as Block,
    ];
  }

  function servePinned(artifactId: number, artifacts = 3) {
    mockApiClient.mockImplementation((url: string) => {
      if (url.includes('/acme/events/')) {
        return Promise.resolve(jsonResponse({ data: { documentId: 7, artifactId } }));
      }
      if (url.includes('/content')) return Promise.resolve(pdfResponse(url));
      return Promise.resolve(jsonResponse({ data: documentWith(artifacts) }));
    });
  }

  it('opens on the pinned artifact rather than on the current one', async () => {
    servePinned(100);
    renderTree(pinnedTree());

    await findPosition(/Version 1 of 3/);
    expect(screen.getByText('Superseded')).toBeInTheDocument();
    expect(
      screen.getByText('This page opened a specific version of this document.')
    ).toBeInTheDocument();
    await waitFor(() =>
      expect(mockApiClient).toHaveBeenCalledWith(
        '/api/v1/documents/7/artifacts/100/content',
        expect.anything()
      )
    );
  });

  it('still offers the other versions — a pin says where to open, not what may be seen', async () => {
    servePinned(100);
    renderTree(pinnedTree());

    await findPosition(/Version 1 of 3/);
    expect(screen.getByLabelText('Choose a version')).toBeInTheDocument();
  });

  it('refuses, and never substitutes, when the pinned artifact is not on the record', async () => {
    servePinned(999);
    renderTree(pinnedTree());

    expect(
      await screen.findByText('That version of this document is not available.')
    ).toBeInTheDocument();
    expect(
      screen.getByText(/A different version is not shown in its place\./)
    ).toBeInTheDocument();
    // The refusal is the whole assertion: no frame, and no bytes fetched for
    // some other version.
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();
    expect(mockApiClient).not.toHaveBeenCalledWith(
      expect.stringContaining('/artifacts/102/content'),
      expect.anything()
    );
  });
});

// =========================================================================
// The refusals — each one a different sentence
// =========================================================================

describe('when the document cannot be shown', () => {
  it('does not claim to know whether a 404 means removed or not-yours', async () => {
    mockApiClient.mockResolvedValue(errorResponse(404));
    renderTree(viewerTree(), '7');

    expect(await screen.findByText('This document is not available to you.')).toBeInTheDocument();
    expect(
      screen.getByText('It may have been removed, or it may not be shared with your account.')
    ).toBeInTheDocument();
    // Nothing to retry: the answer will not change on a second ask.
    expect(screen.queryByRole('button', { name: 'Try again' })).not.toBeInTheDocument();
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();
  });

  it('separates a transport failure from an access one, and offers a retry', async () => {
    mockApiClient.mockResolvedValue(errorResponse(503));
    renderTree(viewerTree(), '7');

    expect(await screen.findByText('This document could not be loaded.')).toBeInTheDocument();
    expect(
      screen.getByText(/not with your access/)
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Try again' })).toBeInTheDocument();
  });

  it('retries the record fetch when asked', async () => {
    mockApiClient.mockResolvedValueOnce(errorResponse(503));
    mockApiClient.mockImplementation((url: string) =>
      Promise.resolve(url.includes('/content') ? pdfResponse(url) : jsonResponse({ data: documentWith(1) }))
    );
    renderTree(viewerTree(), '7');

    await userEvent.click(await screen.findByRole('button', { name: 'Try again' }));

    expect(await screen.findByText(/Version 1 of 1/)).toBeInTheDocument();
  });

  it('says a record with no stored file has no stored file', async () => {
    serve({ ...documentWith(1), artifacts: [] });
    renderTree(viewerTree(), '7');

    expect(await screen.findByText('This document has no stored file.')).toBeInTheDocument();
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();
  });

  it('keeps the record on screen when only the bytes fail', async () => {
    serve(documentWith(2), 503);
    renderTree(viewerTree(), '7');

    // The version bar survives: the record IS available, and saying otherwise
    // would send the reader looking for a document that is right there.
    expect(await screen.findByText(/Version 2 of 2/)).toBeInTheDocument();
    expect(await screen.findByText('This version could not be loaded.')).toBeInTheDocument();
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();
  });
});

// =========================================================================
// A browser that cannot draw a PDF
// =========================================================================

describe('a browser with no inline PDF support', () => {
  afterEach(() => {
    Reflect.deleteProperty(window.navigator, 'pdfViewerEnabled');
  });

  it('says so and hands over the file instead of framing nothing', async () => {
    Object.defineProperty(window.navigator, 'pdfViewerEnabled', {
      configurable: true,
      value: false,
    });
    serve(documentWith(1));
    renderTree(viewerTree(), '7');

    expect(
      await screen.findByText('This browser cannot display PDFs in the page.')
    ).toBeInTheDocument();
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();
    // The document is still reachable — this is a rendering limit, not an
    // access one.
    expect(document.querySelector('[data-slot="document-viewer-download"]')).not.toBeNull();
  });

  it('attempts the frame when the browser never implemented the capability query', async () => {
    // jsdom leaves `pdfViewerEnabled` undefined, which is the old-browser case:
    // unknown means try, because the download control is right there if it
    // degrades.
    serve(documentWith(1));
    renderTree(viewerTree(), '7');

    await waitFor(() =>
      expect(document.querySelector('[data-slot="document-viewer-frame"]')).not.toBeNull()
    );
  });
});

describe('an artifact that is not a PDF', () => {
  it('says what it is and offers it, rather than framing bytes nothing can draw', async () => {
    // `document_artifacts.content_type` is a column, not a constant. Today the
    // renderer only ever writes application/pdf; the schema does not promise it
    // forever, and a frame handed a spreadsheet draws nothing at all.
    const record = documentWith(1);
    record.artifacts[0].content_type = 'application/vnd.ms-excel';
    serve(record);
    renderTree(viewerTree(), '7');

    expect(
      await screen.findByText('This file cannot be displayed in the page.')
    ).toBeInTheDocument();
    expect(screen.getByText('It was issued as application/vnd.ms-excel.')).toBeInTheDocument();
    expect(document.querySelector('[data-slot="document-viewer-frame"]')).toBeNull();

    // And it is still saveable, under an extension that is not a lie.
    const link = document.querySelector('[data-slot="document-viewer-download"]');
    expect(link).not.toBeNull();
    expect((link as HTMLAnchorElement).getAttribute('download')).toBe('purchase-order-4412-v1.bin');
  });
});

// =========================================================================
// Housekeeping
// =========================================================================

describe('the blob it creates', () => {
  it('revokes the object URL when the viewer goes away', async () => {
    serve(documentWith(1));
    const { unmount } = renderTree(viewerTree(), '7');

    await waitFor(() =>
      expect(document.querySelector('[data-slot="document-viewer-frame"]')).not.toBeNull()
    );
    expect(URL.createObjectURL).toHaveBeenCalledTimes(1);

    unmount();
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:test/1');
  });

  it('makes exactly one blob per artifact the reader visits', async () => {
    serve(documentWith(3));
    renderTree(viewerTree(), '7');

    await screen.findByText(/Version 3 of 3/);
    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalledTimes(1));

    await userEvent.click(screen.getByLabelText('Choose a version'));
    await userEvent.click(await screen.findByRole('option', { name: /Version 2/ }));

    await waitFor(() => expect(URL.createObjectURL).toHaveBeenCalledTimes(2));
    // The one it left behind is released rather than held for the session.
    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:test/1');
  });
});
