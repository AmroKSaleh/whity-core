'use client';

/**
 * The document viewer (#947 item 4) — the host-owned shell a plugin mounts
 * through the `documentViewer` block.
 *
 * #947 item 1 gave core an issued document: a `documents` record with an
 * identity, and one IMMUTABLE `document_artifacts` row per render hanging off
 * it. This is the surface that shows one. It fetches core's own
 * `/api/v1/documents/{id}` and artifact-content routes under the caller's own
 * session — the plugin ships no JavaScript and names no path (see the SDK's
 * `documentViewer` docblock for why composing an existing data-bound block was
 * not available).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHICH ARTIFACT IS ON SCREEN, AND WHY IT IS ALWAYS SAID OUT LOUD
 * ─────────────────────────────────────────────────────────────────────────────
 * A re-render APPENDS. A document corrected twice therefore has three sets of
 * bytes, each still fetchable at its own permanent URL, each still true of the
 * moment it was issued. "Show the document" is not a well-formed request against
 * that record, so this component never treats it as one:
 *
 *   - The version bar is not optional chrome. It states version N of M and the
 *     date, on every render, including the M = 1 case — because a viewer that
 *     only mentions versions when there are several teaches a reader that
 *     silence means "one", and silence is also what a bug looks like.
 *   - A SUPERSEDED artifact carries a warning that names the newer one. Without
 *     it, an old artifact is pixel-identical to a current one and the reader has
 *     no way at all to tell.
 *   - `artifactIdFrom` PINS the opening artifact. It does not hide the others:
 *     a plugin that could suppress "this was corrected twice" would be deciding
 *     what the reader of an audit record may know, which is not a presentation
 *     choice. The pin decides where the viewer OPENS; the bar always says where
 *     it currently IS.
 *   - A pinned artifact that is not on the record is a REFUSAL, never a silent
 *     fall back to the current one. Substituting would answer a question about
 *     the past with a fact about the present, which is the one failure this
 *     whole subsystem exists to prevent.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW THE BYTES ARE RENDERED, AND WHAT WAS REJECTED
 * ─────────────────────────────────────────────────────────────────────────────
 * Artifacts are PDFs. The bytes are fetched with the app's own authenticated
 * client, wrapped in a `blob:` URL, and handed to an `<iframe>` — the browser's
 * built-in PDF viewer. Three alternatives were considered and dropped:
 *
 *  1. `<iframe src="/api/v1/documents/{id}/content">` — pointing the frame
 *     straight at the API. It CANNOT WORK as things stand and should not be made
 *     to: `SecurityHeaders` puts `X-Frame-Options: DENY` and
 *     `Content-Security-Policy: frame-ancestors 'none'` on every single API
 *     response, so the browser refuses the frame. Making it work means carving a
 *     framing exemption into the clickjacking headers of a route that serves
 *     tenant-confidential bytes — the WC-246 `screen: 'embed'` mechanism exists
 *     and is sanctioned, and spending it here would be trading a real defense
 *     for an implementation shortcut. A frame fed from a same-origin blob needs
 *     no exemption at all, because the page constructed the URL itself and no
 *     network response is being framed.
 *  2. pdf.js / react-pdf — a JavaScript PDF renderer. It is the richer option
 *     and it is the wrong one twice over. It wants `script-src blob:` and
 *     `worker-src blob:`, and `web/next.config.ts` records that the frontend's
 *     omitted CSP directives are meant to be layered in later behind a nonce
 *     strategy; shipping a viewer that structurally requires `blob:` script
 *     execution poisons that migration before it starts. It is also ~1MB of
 *     JavaScript plus a worker to reimplement what every target browser already
 *     ships, on a platform whose desktop story is explicitly offline.
 *  3. Server-side rasterisation to images. It would need a second render path
 *     beside `render-service/`, and would hand the reader PIXELS OF a document
 *     instead of the document — no text selection, no search, no accessibility
 *     tree, and a new opportunity for the render to drift from the artifact.
 *
 * The native path has one real gap and it is handled explicitly rather than
 * hoped away: a browser with no built-in PDF support (WebKitGTK, which is what a
 * Linux desktop shell runs on) renders a blank frame. `navigator.pdfViewerEnabled`
 * is the standard answer to exactly that question, so an explicit `false` gets
 * the download-only rendering with a sentence explaining why. Blank is the one
 * outcome that is never acceptable (#951, #756).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * PREVIEW IS NOT VIEW
 * ─────────────────────────────────────────────────────────────────────────────
 * The designer previews an UNSAVED template: a canvas mode with its own chrome,
 * client-side, no id, no bytes on a server, nothing anybody was ever issued.
 * This component views a PERSISTED artifact. They are kept apart structurally,
 * not by convention: the only input here is a document ID. There is no prop, and
 * no exported entry point, that accepts raw bytes, a blob or a template — so an
 * unsaved render cannot be routed through this shell however hard a caller
 * tries, and the record chrome (identity, version, issued date, checksum) can
 * never end up wrapped around something that was never issued.
 */

import * as React from 'react';
import { IconDownload, IconFileText } from '@tabler/icons-react';
import { apiClient } from '@/lib/api-client';
import { formatRecordDateTime } from '@amroksaleh/features/record';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Alert, AlertDescription, AlertTitle } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';

/** One immutable artifact, as `DocumentPresenter::artifact()` puts it on the wire. */
export interface DocumentArtifact {
  id: number;
  document_id: number;
  content_type: string;
  byte_size: number;
  checksum_sha256: string;
  rendered_at: string;
  /** An API path — never a storage key and never a signed URL. See DocumentPresenter. */
  content_url: string;
}

/** One issued document, as `DocumentPresenter::document()` puts it on the wire. */
export interface DocumentRecord {
  id: number;
  title: string;
  template_name: string;
  created_at: string;
  /** Newest first — `DocumentArtifactRepository::listForDocument` orders by id DESC. */
  artifacts: DocumentArtifact[];
}

/**
 * Why the viewer cannot show a document. Each value is a DIFFERENT sentence to
 * the reader, which is the whole reason they are separate values: "not yours",
 * "not there yet" and "the storage backend is having a moment" are three
 * different things to do next, and collapsing them into one "failed to load"
 * is how a reader ends up retrying something that will never succeed.
 */
type ViewerFailure =
  /** 404 from core: removed, or not visible to this caller — core answers both alike on purpose. */
  | 'unavailable'
  /** The record is real; its bytes could not be read right now. Retry is meaningful. */
  | 'temporarily-unavailable'
  /** The record exists and has no artifact at all — only reachable via a partial restore. */
  | 'no-content'
  /** `artifactIdFrom` named an artifact this document does not have. */
  | 'pinned-missing';

/** What one record fetch settled on. `loading` is derived, never stored. */
type LoadResult =
  | { ok: true; document: DocumentRecord }
  | { ok: false; failure: ViewerFailure; retryable: boolean };

/** What one content fetch settled on, plus whether this browser can show it inline. */
type ContentResult =
  | { ok: true; url: string; inlineSupported: boolean }
  | { ok: false };

const DOCUMENTS_PATH = '/api/v1/documents';

/**
 * Whether this browser will display a PDF inline.
 *
 * `undefined` (an older browser that never implemented the property) is treated
 * as SUPPORTED and the frame is attempted, because the frame degrading is
 * recoverable — the download control is right there — whereas refusing to render
 * for every browser that has not implemented a capability query would take the
 * viewer away from people whose browser is perfectly capable.
 *
 * Read from inside an effect, never during render: this is a client-only fact,
 * and reading it while rendering would make the server's HTML and the client's
 * first paint disagree.
 */
function browserShowsPdfInline(): boolean {
  const supported = (navigator as Navigator & { pdfViewerEnabled?: boolean }).pdfViewerEnabled;
  return supported !== false;
}

/**
 * A filename-safe slug — the TypeScript twin of `DocumentPresenter::slugify()`,
 * so a document saved from this viewer is named the way the same document
 * downloaded straight from the API is named.
 */
function slugify(name: string): string {
  const slug = name.trim().toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  return slug !== '' ? slug : 'document';
}

/** A byte count in the units a person reads. */
function formatBytes(bytes: number): string {
  if (!Number.isFinite(bytes) || bytes < 0) return '';
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/**
 * The version LABEL of an artifact: 1 is the first ever issued.
 *
 * Derived in the client rather than stored, and safe to derive precisely because
 * #947 item 1 refused a `revision` column: artifacts are append-only (the store
 * mints a fresh key per artifact and refuses to overwrite, and the repository
 * has no update and no delete), and the list arrives ordered by id descending.
 * So position in that list IS the issue order, permanently.
 *
 * The label is a label. An artifact's IDENTITY is its id, which is what the URL
 * carries and what a pin names — a display number is not something to address
 * anything by.
 */
function versionOf(artifacts: DocumentArtifact[], index: number): number {
  return artifacts.length - index;
}

/**
 * Fetch one document record. Distinguishes the failures rather than collapsing
 * them — see {@link ViewerFailure}. Never throws.
 */
async function loadDocument(
  documentId: string,
  signal: AbortSignal
): Promise<{ ok: true; document: DocumentRecord } | { ok: false; failure: ViewerFailure; retryable: boolean }> {
  const response = await apiClient(`${DOCUMENTS_PATH}/${encodeURIComponent(documentId)}`, { signal });

  if (response.status === 404 || response.status === 403) {
    // Core reports "you may not see this" as 404 deliberately (a 403 confirms
    // the id exists, which for an enumerable integer leaks the shape of a
    // tenant's activity). The viewer therefore genuinely does not know which of
    // the two it is, and says so rather than guessing at one.
    return { ok: false, failure: 'unavailable', retryable: false };
  }
  if (!response.ok) {
    return { ok: false, failure: 'temporarily-unavailable', retryable: true };
  }

  let body: unknown;
  try {
    body = await response.json();
  } catch {
    return { ok: false, failure: 'temporarily-unavailable', retryable: true };
  }

  const data = (body as { data?: unknown } | null)?.data;
  if (typeof data !== 'object' || data === null) {
    return { ok: false, failure: 'temporarily-unavailable', retryable: true };
  }

  const document = data as DocumentRecord;
  if (!Array.isArray(document.artifacts)) {
    return { ok: false, failure: 'temporarily-unavailable', retryable: true };
  }
  if (document.artifacts.length === 0) {
    // The issuer rolls back rather than leaving a record with no bytes, so this
    // is the restored-from-a-partial-backup case, not a routine one.
    return { ok: false, failure: 'no-content', retryable: false };
  }

  return { ok: true, document };
}

/**
 * The viewer.
 *
 * `documentId` is `null` when nothing on the page has said which document yet —
 * an unresolved `documentIdFrom`. That is a legitimate resting state, not an
 * error, and it renders the author's `emptyText`.
 *
 * WHY THE STATE IS A (key, settled) PAIR rather than a status this component
 * sets as it goes. Writing `setState({status: 'loading'})` from the effect body
 * is a synchronous setState inside an effect — `react-hooks/set-state-in-effect`
 * refuses it, and is right to: it triggers a cascading render, and the same
 * expression reads differently on a re-render. So `loading` is DERIVED (the
 * settled result does not belong to the request currently in flight) exactly as
 * `usePluginData` derives it, and the effect only ever records an ANSWER.
 *
 * WHICH ARTIFACT IS ALSO DERIVED, not stored on arrival. Resolving the pin
 * during render keeps "which artifact" a pure function of (the record, the pin,
 * what the reader has clicked), so there is no ordering in which a stale
 * selection from a previous document can be read as this one's.
 */
export function DocumentViewer({
  documentId,
  pinnedArtifactId,
  emptyText,
}: {
  documentId: string | null;
  pinnedArtifactId: string | null;
  emptyText?: string;
}) {
  const t = useTranslation('plugin');

  const [attempt, setAttempt] = React.useState(0);
  const [settled, setSettled] = React.useState<{ key: string; result: LoadResult } | null>(null);
  /** The reader's own choice, tagged with the request it was made against. */
  const [selection, setSelection] = React.useState<{ key: string; id: number } | null>(null);

  // Everything that changes WHICH request this is. A new key means whatever is
  // settled belongs to a previous question.
  const requestKey = `${documentId ?? ''}|${pinnedArtifactId ?? ''}|${attempt}`;

  React.useEffect(() => {
    if (documentId === null) return;

    const controller = new AbortController();
    let live = true;

    void (async () => {
      let result: LoadResult;
      try {
        result = await loadDocument(documentId, controller.signal);
      } catch {
        // An abort from unmount/re-fetch lands here too; the `live` guard drops
        // the state it would have set.
        result = { ok: false, failure: 'temporarily-unavailable', retryable: true };
      }
      if (!live) return;
      setSettled({ key: requestKey, result });
    })();

    return () => {
      live = false;
      controller.abort();
    };
  }, [documentId, requestKey]);

  const retry = React.useCallback(() => setAttempt((n) => n + 1), []);

  if (documentId === null) {
    return (
      <EmptyState
        data-slot="document-viewer-empty"
        icon={<IconFileText />}
        title={emptyText ?? t('blocks.documentViewer.noSelection', 'No document selected.')}
        description={t(
          'blocks.documentViewer.noSelectionHint',
          'This viewer shows an issued document once something on this page names one.'
        )}
      />
    );
  }

  if (settled === null || settled.key !== requestKey) {
    return <Skeleton className="h-[36rem] w-full rounded-lg" data-slot="document-viewer-loading" />;
  }

  if (!settled.result.ok) {
    return (
      <ViewerFailureState
        failure={settled.result.failure}
        onRetry={settled.result.retryable ? retry : undefined}
      />
    );
  }

  const record = settled.result.document;
  const artifacts = record.artifacts;

  // The pin, resolved against the record that actually arrived. A pin naming an
  // artifact this document does not have is a REFUSAL and never a fall back to
  // the current one: substituting would answer a question about the past with a
  // fact about the present.
  const pinnedArtifact =
    pinnedArtifactId === null
      ? null
      : artifacts.find((a) => String(a.id) === pinnedArtifactId) ?? null;
  if (pinnedArtifactId !== null && pinnedArtifact === null) {
    return <ViewerFailureState failure="pinned-missing" />;
  }

  // Opening position: the pin, else the CURRENT artifact — which the list puts
  // first. The reader's own choice wins over both, but only while it belongs to
  // this request.
  const opening = pinnedArtifact?.id ?? artifacts[0].id;
  const activeId = selection !== null && selection.key === requestKey ? selection.id : opening;
  // `activeId` can only have come from THIS list — either from the opening
  // position or from a selection made against this same request — so the -1 is
  // unreachable rather than tolerated. Clamped anyway, because the alternative
  // to a defensive 0 here is an undefined artifact and a blank render.
  const index = Math.max(
    0,
    artifacts.findIndex((a) => a.id === activeId)
  );
  const artifact = artifacts[index];

  return (
    <div className="space-y-3" data-slot="document-viewer">
      <VersionBar
        document={record}
        artifacts={artifacts}
        artifact={artifact}
        position={index}
        pinned={pinnedArtifact !== null}
        onSelect={(id) => setSelection({ key: requestKey, id })}
      />
      <ArtifactFrame document={record} artifact={artifact} version={versionOf(artifacts, index)} />
    </div>
  );
}

/**
 * The record chrome: what this is, which version of it, and how to reach the
 * others. Rendered above every artifact, in every case — see the file docblock
 * for why it is not conditional on there being more than one.
 */
function VersionBar({
  document,
  artifacts,
  artifact,
  position,
  pinned,
  onSelect,
}: {
  document: DocumentRecord;
  artifacts: DocumentArtifact[];
  artifact: DocumentArtifact;
  position: number;
  pinned: boolean;
  onSelect: (id: number) => void;
}) {
  const t = useTranslation('plugin');
  const total = artifacts.length;
  const version = versionOf(artifacts, position);
  const superseded = position > 0;
  const issuedAt = formatRecordDateTime(artifact.rendered_at) ?? artifact.rendered_at;

  return (
    <div className="space-y-2" data-slot="document-viewer-versions">
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-sm font-medium text-foreground">{document.title}</span>
        <Badge variant={superseded ? 'secondary' : 'default'} data-slot="document-viewer-version-badge">
          {superseded
            ? t('blocks.documentViewer.superseded', 'Superseded')
            : t('blocks.documentViewer.current', 'Current')}
        </Badge>
        <span className="text-xs text-muted-foreground" data-slot="document-viewer-position">
          {t('blocks.documentViewer.versionOf', 'Version {version} of {total}', { version, total })}
          {' · '}
          {issuedAt}
          {artifact.byte_size > 0 ? ` · ${formatBytes(artifact.byte_size)}` : ''}
        </span>
        {total > 1 && (
          <div className="ms-auto">
            {/* Always offered, including when the block pinned the opening
                artifact: the pin says where to OPEN, not what the reader may
                know exists. */}
            <Select value={String(artifact.id)} onValueChange={(v) => onSelect(Number(v))}>
              <SelectTrigger className="h-8 w-56" aria-label={t('blocks.documentViewer.pickVersion', 'Choose a version')}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {artifacts.map((candidate, i) => (
                  <SelectItem key={candidate.id} value={String(candidate.id)}>
                    {t('blocks.documentViewer.versionOption', 'Version {version} — {issued}', {
                      version: versionOf(artifacts, i),
                      issued: formatRecordDateTime(candidate.rendered_at) ?? candidate.rendered_at,
                    })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>
        )}
      </div>

      {/* The checksum is on the wire precisely so a reader can prove the bytes
          are the bytes that were issued. Truncated for the eye, complete in the
          title, so verifying is a copy rather than a second request. */}
      <p className="font-mono text-[0.65rem] text-muted-foreground" title={artifact.checksum_sha256}>
        {t('blocks.documentViewer.checksum', 'SHA-256 {digest}', {
          digest: artifact.checksum_sha256.slice(0, 16) + '…',
        })}
      </p>

      {superseded && (
        <Alert variant="warning" data-slot="document-viewer-superseded">
          <AlertTitle>
            {t('blocks.documentViewer.supersededTitle', 'You are looking at an earlier version')}
          </AlertTitle>
          <AlertDescription>
            {t(
              'blocks.documentViewer.supersededBody',
              'Version {version} of {total}. A later version was issued on {issued} and is the current one. This version stays available and unchanged.',
              {
                version,
                total,
                issued:
                  formatRecordDateTime(artifacts[0].rendered_at) ?? artifacts[0].rendered_at,
              }
            )}
          </AlertDescription>
        </Alert>
      )}
      {pinned && (
        <p className="text-xs text-muted-foreground" data-slot="document-viewer-pinned">
          {t(
            'blocks.documentViewer.pinnedHint',
            'This page opened a specific version of this document.'
          )}
        </p>
      )}
    </div>
  );
}

/**
 * The bytes.
 *
 * Fetched through `apiClient` (cookies + the CSRF header + the silent-refresh
 * retry), wrapped in a same-origin `blob:` URL, and framed. The blob is revoked
 * when the artifact changes or the component unmounts — a viewer left open on a
 * record page would otherwise hold every version the reader flipped through.
 *
 * Same (key, settled) shape as the record fetch above, and for the same reason:
 * `loading` is the absence of an answer to the CURRENT request, which is a
 * derivation, not a state to write from inside an effect.
 */
function ArtifactFrame({
  document,
  artifact,
  version,
}: {
  document: DocumentRecord;
  artifact: DocumentArtifact;
  version: number;
}) {
  const t = useTranslation('plugin');
  const [attempt, setAttempt] = React.useState(0);
  const [settled, setSettled] = React.useState<{ key: string; result: ContentResult } | null>(null);

  const requestKey = `${artifact.content_url}|${attempt}`;

  React.useEffect(() => {
    const controller = new AbortController();
    let live = true;
    let objectUrl: string | null = null;

    void (async () => {
      let result: ContentResult;
      try {
        const response = await apiClient(artifact.content_url, { signal: controller.signal });
        if (!response.ok) {
          // 404 here means the record is visible but its stored object is not;
          // 503 is the storage backend. Both are worth a retry from the reader's
          // side — neither is a statement about their access, which was already
          // settled by the record fetch that got us here.
          result = { ok: false };
        } else {
          const blob = await response.blob();
          objectUrl = URL.createObjectURL(blob);
          result = { ok: true, url: objectUrl, inlineSupported: browserShowsPdfInline() };
        }
      } catch {
        result = { ok: false };
      }
      if (!live) return;
      setSettled({ key: requestKey, result });
    })();

    return () => {
      live = false;
      controller.abort();
      if (objectUrl !== null) URL.revokeObjectURL(objectUrl);
    };
  }, [artifact.content_url, requestKey]);

  const filename = `${slugify(document.title)}-v${version}.pdf`;

  if (settled === null || settled.key !== requestKey) {
    return <Skeleton className="h-[36rem] w-full rounded-lg" data-slot="document-viewer-content-loading" />;
  }

  if (!settled.result.ok) {
    return (
      <EmptyState
        variant="error"
        data-slot="document-viewer-content-error"
        title={t('blocks.documentViewer.contentFailed', 'This version could not be loaded.')}
        description={t(
          'blocks.documentViewer.contentFailedHint',
          'The document is here; its file could not be read just now.'
        )}
        action={
          <Button variant="outline" size="sm" onClick={() => setAttempt((n) => n + 1)}>
            {t('blocks.documentViewer.retry', 'Try again')}
          </Button>
        }
      />
    );
  }

  const content = settled.result;

  const download = (
    <Button asChild variant="outline" size="sm">
      <a href={content.url} download={filename} data-slot="document-viewer-download">
        <IconDownload />
        {t('blocks.documentViewer.download', 'Download')}
      </a>
    </Button>
  );

  if (!content.inlineSupported) {
    // Never a blank frame (#951, #756): this browser has told us it will not
    // render a PDF, so the viewer says that and hands over the file instead of
    // drawing an empty rectangle and letting the reader conclude the document
    // is empty.
    return (
      <EmptyState
        data-slot="document-viewer-no-inline"
        icon={<IconFileText />}
        title={t('blocks.documentViewer.noInline', 'This browser cannot display PDFs in the page.')}
        description={t(
          'blocks.documentViewer.noInlineHint',
          'The document is available — open it with your PDF reader.'
        )}
        action={download}
      />
    );
  }

  return (
    <div className="space-y-2">
      <div className="flex justify-end">{download}</div>
      {/* A4 portrait is 1:√2; sizing by aspect ratio rather than a fixed height
          is what lets the same block sit in a card, a drawer or a full page
          without the declaration asserting pixels it cannot know. */}
      <iframe
        src={content.url}
        title={t('blocks.documentViewer.frameTitle', '{title} — version {version}', {
          title: document.title,
          version,
        })}
        data-slot="document-viewer-frame"
        className="aspect-[1/1.414] w-full rounded-lg border border-border bg-card"
      />
    </div>
  );
}

/** The four ways a document does not appear, each with its own sentence. */
function ViewerFailureState({
  failure,
  onRetry,
}: {
  failure: ViewerFailure;
  onRetry?: () => void;
}) {
  const t = useTranslation('plugin');

  const copy: Record<ViewerFailure, { title: string; description: string }> = {
    unavailable: {
      title: t('blocks.documentViewer.unavailable', 'This document is not available to you.'),
      description: t(
        'blocks.documentViewer.unavailableHint',
        'It may have been removed, or it may not be shared with your account.'
      ),
    },
    'temporarily-unavailable': {
      title: t('blocks.documentViewer.temporary', 'This document could not be loaded.'),
      description: t(
        'blocks.documentViewer.temporaryHint',
        'Something went wrong on the way to the document, not with your access.'
      ),
    },
    'no-content': {
      title: t('blocks.documentViewer.noContent', 'This document has no stored file.'),
      description: t(
        'blocks.documentViewer.noContentHint',
        'The record exists but no rendered file was found for it.'
      ),
    },
    'pinned-missing': {
      title: t('blocks.documentViewer.pinnedMissing', 'That version of this document is not available.'),
      description: t(
        'blocks.documentViewer.pinnedMissingHint',
        'This page asked for one specific version. A different version is not shown in its place.'
      ),
    },
  };

  return (
    <EmptyState
      variant="error"
      data-slot={`document-viewer-${failure}`}
      title={copy[failure].title}
      description={copy[failure].description}
      action={
        onRetry === undefined ? undefined : (
          <Button variant="outline" size="sm" onClick={onRetry}>
            {t('blocks.documentViewer.retry', 'Try again')}
          </Button>
        )
      }
    />
  );
}
