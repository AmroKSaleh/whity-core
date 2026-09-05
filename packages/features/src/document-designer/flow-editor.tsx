import { useRef } from 'react';
import {
  DEFAULT_MAX_FIGURE_BYTES,
  FIGURE_MIME_TYPES,
  flowBlockSummary,
  judgeFigureFile,
  type FlowBlock,
  type FlowContent,
  type FlowHeadingLevel,
} from '@amroksaleh/ui/documents/flow';
import { Button } from '@amroksaleh/ui/button';
import { Input } from './ui-i18n';
import { Textarea } from '@amroksaleh/ui/textarea';
import {
  IconChevronDown,
  IconChevronUp,
  IconTrash,
} from '@tabler/icons-react';
import { useTranslation } from '../i18n';

/**
 * DOCUMENT MODE: blocks one below another, the way a word processor works
 * (#1186 slice 1).
 *
 * The counterpart of `Canvas`, not a replacement for it. The canvas answers
 * "put this exactly here"; this answers "then say this, then this" — and a
 * report written by placing every paragraph at a millimetre offset is the
 * reason it exists.
 *
 * WHAT IT EMITS is the vocabulary the render service already validates: the
 * same block list `render-service/src/flow/document.js` enumerates and
 * `renderBlock()` switches on. Nothing here is a shape that needs translating
 * before it can print, which is the whole architectural point of #1186 — the
 * printer is the constraint, so the editor speaks the printer's language.
 *
 * NO CANVAS GEOMETRY. There is deliberately no x/y/z, no drag, no resize: a
 * block's position is its index, and its width is the text column. Every
 * pixel-level affordance stays in canvas mode, which is what makes the two
 * modes worth having separately rather than one mode doing both badly.
 */

export interface FlowEditorProps {
  content: FlowContent;
  onChange: (next: FlowContent) => void;
  /** Index of the block being edited, or null. Owned by the caller so the `/` palette can aim inserts. */
  selected: number | null;
  onSelect: (index: number | null) => void;
  /**
   * Largest image a figure will accept, in bytes of the source file. Defaults
   * to {@link DEFAULT_MAX_FIGURE_BYTES}; raise it where the deployment raised
   * `documents.render_max_template_bytes`.
   */
  maxFigureBytes?: number;
  /**
   * Told why a chosen image was refused.
   *
   * A CALLBACK RATHER THAN A SWALLOWED FAILURE. The alternative is a file
   * picker that closes and changes nothing, which is indistinguishable from the
   * editor ignoring the click — and this whole mode exists because the figure
   * block previously could not be filled in at all.
   */
  onError?: (message: string) => void;
}

export function FlowEditor({
  content,
  onChange,
  selected,
  onSelect,
  maxFigureBytes = DEFAULT_MAX_FIGURE_BYTES,
  onError,
}: FlowEditorProps) {
  const t = useTranslation('documents');
  const listRef = useRef<HTMLDivElement>(null);

  const blocks = content.blocks;

  const replace = (index: number, block: FlowBlock) => {
    onChange({ ...content, blocks: blocks.map((b, i) => (i === index ? block : b)) });
  };

  const move = (index: number, delta: number) => {
    const to = index + delta;
    if (to < 0 || to >= blocks.length) return;
    const next = [...blocks];
    [next[index], next[to]] = [next[to], next[index]];
    onChange({ ...content, blocks: next });
    // The selection follows the BLOCK, not the position. Anything else means
    // pressing "move down" twice moves two different blocks.
    onSelect(to);
  };

  const remove = (index: number) => {
    onChange({ ...content, blocks: blocks.filter((_, i) => i !== index) });
    onSelect(null);
  };

  if (blocks.length === 0) {
    return (
      <div
        className="flex h-full items-center justify-center p-8 text-center text-sm text-muted-foreground"
        data-testid="flow-editor-empty"
      >
        {/* Names the key rather than describing a button, because the `/`
            palette IS the inserter here — there is no toolbar of block types to
            point at, and an empty state that says "add a block" without saying
            how is a dead end. */}
        {t('flow.empty', 'Nothing here yet. Press / to add a heading, paragraph, table or image.')}
      </div>
    );
  }

  return (
    <div
      ref={listRef}
      className="mx-auto flex w-full max-w-[46rem] flex-col gap-2 p-6"
      data-testid="flow-editor"
    >
      {blocks.map((block, index) => (
        <div
          key={index}
          data-testid={`flow-block-${index}`}
          data-block-type={block.type}
          onFocusCapture={() => onSelect(index)}
          className={`group rounded-md border px-3 py-2 ${
            selected === index ? 'border-primary bg-primary/5' : 'border-transparent hover:border-border'
          }`}
        >
          <div className="mb-1 flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100 focus-within:opacity-100">
            <span className="text-[0.625rem] uppercase tracking-wide text-muted-foreground">
              {blockLabel(t, block)}
            </span>
            <span className="ms-auto flex items-center gap-0.5">
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={t('flow.moveUp', 'Move up')}
                data-testid={`flow-up-${index}`}
                disabled={index === 0}
                onClick={() => move(index, -1)}
              >
                <IconChevronUp className="h-3.5 w-3.5" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={t('flow.moveDown', 'Move down')}
                data-testid={`flow-down-${index}`}
                disabled={index === blocks.length - 1}
                onClick={() => move(index, 1)}
              >
                <IconChevronDown className="h-3.5 w-3.5" />
              </Button>
              <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                aria-label={t('flow.remove', 'Remove block')}
                data-testid={`flow-remove-${index}`}
                onClick={() => remove(index)}
              >
                <IconTrash className="h-3.5 w-3.5 text-destructive" />
              </Button>
            </span>
          </div>

          <BlockBody
            block={block}
            index={index}
            onChange={(b) => replace(index, b)}
            maxFigureBytes={maxFigureBytes}
            onError={onError}
          />
        </div>
      ))}
    </div>
  );
}

/** The type's own name, for the hover strip above each block. */
function blockLabel(t: ReturnType<typeof useTranslation>, block: FlowBlock): string {
  switch (block.type) {
    case 'heading':
      return t('flow.type.heading', 'Heading {level}', { level: String(block.level) });
    case 'paragraph':
      return t('flow.type.paragraph', 'Paragraph');
    case 'table':
      return t('flow.type.table', 'Table');
    case 'figure':
      return t('flow.type.figure', 'Image');
    case 'pageBreak':
      return t('flow.type.pageBreak', 'Page break');
    case 'spacer':
      return t('flow.type.spacer', 'Spacer');
    default: {
      const exhaustive: never = block;
      return String(exhaustive);
    }
  }
}

/**
 * The editable body of one block.
 *
 * Each type edits AS ITSELF — a heading is a single line at heading size, a
 * paragraph is a growing text area — rather than every type being a form of
 * labelled fields. That difference is most of why this mode feels like writing
 * rather than filling in a record.
 */
function BlockBody({
  block,
  index,
  onChange,
  maxFigureBytes,
  onError,
}: {
  block: FlowBlock;
  index: number;
  onChange: (block: FlowBlock) => void;
  maxFigureBytes: number;
  onError?: (message: string) => void;
}) {
  const t = useTranslation('documents');

  switch (block.type) {
    case 'heading':
      return (
        <div className="flex items-center gap-2">
          <select
            aria-label={t('flow.headingLevel', 'Heading level')}
            data-testid={`flow-heading-level-${index}`}
            className="h-7 rounded-md border border-input bg-transparent px-1 text-xs"
            value={block.level}
            onChange={(e) => onChange({ ...block, level: Number(e.target.value) as FlowHeadingLevel })}
          >
            {[1, 2, 3, 4, 5, 6].map((l) => (
              <option key={l} value={l}>
                H{l}
              </option>
            ))}
          </select>
          <Input
            value={block.text}
            aria-label={t('flow.headingText', 'Heading text')}
            data-testid={`flow-input-${index}`}
            placeholder={t('flow.headingPlaceholder', 'Heading')}
            onChange={(e) => onChange({ ...block, text: e.target.value })}
            className="border-transparent bg-transparent text-lg font-semibold hover:border-input focus-visible:border-ring"
          />
        </div>
      );

    case 'paragraph':
      return (
        <Textarea
          value={block.text}
          aria-label={t('flow.paragraphText', 'Paragraph text')}
          data-testid={`flow-input-${index}`}
          placeholder={t('flow.paragraphPlaceholder', 'Write something…')}
          rows={3}
          onChange={(e) => onChange({ ...block, text: e.target.value })}
          className="resize-y border-transparent bg-transparent hover:border-input focus-visible:border-ring"
        />
      );

    case 'table':
      return (
        <TableBody block={block} index={index} onChange={onChange} />
      );

    case 'figure':
      return (
        <FigureBody
          block={block}
          index={index}
          onChange={onChange}
          maxFigureBytes={maxFigureBytes}
          onError={onError}
        />
      );

    case 'pageBreak':
      return (
        <div className="flex items-center gap-2 py-1" data-testid={`flow-input-${index}`}>
          <span className="h-px flex-1 bg-border" />
          <span className="text-[0.625rem] uppercase tracking-wide text-muted-foreground">
            {t('flow.type.pageBreak', 'Page break')}
          </span>
          <span className="h-px flex-1 bg-border" />
        </div>
      );

    case 'spacer':
      return (
        <div className="flex items-center gap-2">
          <Input
            type="number"
            min={1}
            value={String(block.heightMm)}
            aria-label={t('flow.spacerHeight', 'Spacer height in millimetres')}
            data-testid={`flow-input-${index}`}
            onChange={(e) => onChange({ ...block, heightMm: Math.max(1, Number(e.target.value) || 1) })}
            className="w-24"
          />
          <span className="text-xs text-muted-foreground">mm</span>
        </div>
      );

    default: {
      const exhaustive: never = block;
      return <>{String(exhaustive)}</>;
    }
  }
}

/** A table's cells, edited in place. */
function TableBody({
  block,
  index,
  onChange,
}: {
  block: Extract<FlowBlock, { type: 'table' }>;
  index: number;
  onChange: (block: FlowBlock) => void;
}) {
  const t = useTranslation('documents');
  const columns = block.columns ?? [];

  const setCell = (row: number, col: number, value: string) => {
    onChange({
      ...block,
      rows: block.rows.map((r, ri) => (ri === row ? r.map((c, ci) => (ci === col ? value : c)) : r)),
    });
  };

  return (
    <div className="overflow-x-auto" data-testid={`flow-input-${index}`}>
      <table className="w-full border-collapse text-sm">
        {columns.length > 0 && (
          <thead>
            <tr>
              {columns.map((c, ci) => (
                <th key={ci} className="border border-border p-0">
                  <input
                    value={c}
                    aria-label={t('flow.tableHeader', 'Column {n} heading', { n: String(ci + 1) })}
                    className="w-full bg-transparent px-2 py-1 font-medium outline-none"
                    onChange={(e) =>
                      onChange({ ...block, columns: columns.map((x, i) => (i === ci ? e.target.value : x)) })
                    }
                  />
                </th>
              ))}
            </tr>
          </thead>
        )}
        <tbody>
          {block.rows.map((row, ri) => (
            <tr key={ri}>
              {row.map((cell, ci) => (
                <td key={ci} className="border border-border p-0">
                  <input
                    value={cell}
                    aria-label={t('flow.tableCell', 'Row {r}, column {c}', {
                      r: String(ri + 1),
                      c: String(ci + 1),
                    })}
                    className="w-full bg-transparent px-2 py-1 outline-none"
                    onChange={(e) => setCell(ri, ci, e.target.value)}
                  />
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
      <p className="pt-1 text-[0.625rem] text-muted-foreground">{flowBlockSummary(block)}</p>
    </div>
  );
}

/**
 * The figure block's body: choose an image, and caption it.
 *
 * BEFORE THIS, "Insert ▸ Image" WAS A DEAD COMMAND. `newFlowBlock('figure')`
 * starts as a 1×1 transparent PNG — the only honest empty state, because the
 * renderer refuses a remote source and an empty string would be a block that
 * exists and cannot print — and nothing anywhere let the author replace it. The
 * command appeared to work and produced an invisible dot on the page.
 *
 * The file becomes a `data:` URI because that is what the flowing renderer
 * accepts: it will not fetch, deliberately, since an http(s) source would make
 * every render an outbound request from inside the render tier.
 *
 * Both refusals are REPORTED. A picker that closes and changes nothing is
 * indistinguishable from the editor ignoring the click, which is the failure
 * this component exists to fix — fixing it by a different route would be a poor
 * trade.
 */
function FigureBody({
  block,
  index,
  onChange,
  maxFigureBytes,
  onError,
}: {
  block: Extract<FlowBlock, { type: 'figure' }>;
  index: number;
  onChange: (block: FlowBlock) => void;
  maxFigureBytes: number;
  onError?: (message: string) => void;
}) {
  const t = useTranslation('documents');
  const fileRef = useRef<HTMLInputElement>(null);

  const choose = (file: File | undefined) => {
    if (!file) return;

    // Judged BEFORE any bytes are read: reading a 40 MB file into memory to
    // then refuse it is work nobody asked for.
    const rejection = judgeFigureFile(file, maxFigureBytes);
    if (rejection === 'type') {
      onError?.(
        t(
          'flow.figureBadType',
          'That file type cannot be used. Choose a PNG, JPEG, GIF or WebP image.'
        )
      );
      return;
    }
    if (rejection === 'size') {
      onError?.(
        t('flow.figureTooBig', 'That image is larger than {mb} MB, so it cannot be embedded.', {
          mb: (maxFigureBytes / (1024 * 1024)).toFixed(1),
        })
      );
      return;
    }

    const reader = new FileReader();
    reader.onerror = () =>
      onError?.(t('flow.figureUnreadable', 'That image could not be read.'));
    reader.onload = () => {
      const result = reader.result;
      // A non-string result means the read produced something that is not a
      // data URI, and putting it in the block would create the exact
      // unprintable state the placeholder exists to avoid.
      if (typeof result !== 'string' || !result.startsWith('data:')) {
        onError?.(t('flow.figureUnreadable', 'That image could not be read.'));
        return;
      }
      onChange({ ...block, dataUri: result });
    };
    reader.readAsDataURL(file);
  };

  return (
    <div className="flex items-center gap-2">
      {/* eslint-disable-next-line @next/next/no-img-element -- a data: URI the author supplied; next/image cannot serve one. */}
      <img
        src={block.dataUri}
        alt=""
        data-testid={`flow-figure-preview-${index}`}
        className="h-12 w-12 rounded border border-border bg-muted/30 object-contain"
      />
      <input
        ref={fileRef}
        type="file"
        // Raster only. SVG is absent because it can carry script and this value
        // becomes a data: URI rendered both in the browser and in headless
        // Chromium — the same hazard element-content.tsx already refuses.
        accept={FIGURE_MIME_TYPES.join(',')}
        className="hidden"
        data-testid={`flow-figure-file-${index}`}
        onChange={(e) => {
          choose(e.target.files?.[0]);
          // Cleared so choosing the SAME file again still fires a change.
          e.target.value = '';
        }}
      />
      <Button
        type="button"
        variant="outline"
        size="sm"
        data-testid={`flow-figure-choose-${index}`}
        onClick={() => fileRef.current?.click()}
      >
        {t('flow.figureChoose', 'Choose image…')}
      </Button>
      <Input
        value={block.caption ?? ''}
        aria-label={t('flow.figureCaption', 'Image caption')}
        data-testid={`flow-input-${index}`}
        placeholder={t('flow.figureCaptionPlaceholder', 'Caption')}
        onChange={(e) => onChange({ ...block, caption: e.target.value })}
      />
    </div>
  );
}
