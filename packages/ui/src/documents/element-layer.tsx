'use client';

import type { DocElement } from './types';
import { flattenBlock, type DocBlock } from './blocks';
import { ElementContent } from './element-content';

/**
 * Renders a list of elements as absolutely-positioned children (z-ordered,
 * hidden ones omitted), resolved for display. Used inside a block instance and
 * by the print renderer. Non-interactive — no selection/drag handles.
 */
export function ElementLayer({ elements, data }: { elements: DocElement[]; data: Record<string, string> }) {
  return (
    <>
      {[...elements]
        .sort((a, b) => a.z - b.z)
        .filter((el) => !el.hidden)
        .map((el) => (
          <div
            key={el.id}
            style={{
              position: 'absolute',
              left: `${el.x}mm`,
              top: `${el.y}mm`,
              width: `${el.w}mm`,
              height: `${el.h}mm`,
              transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined,
              opacity: el.opacity ?? undefined,
              zIndex: el.z,
            }}
          >
            <ElementContent el={el} data={data} preview />
          </div>
        ))}
    </>
  );
}

/**
 * The visual content of a block instance: the referenced block's elements
 * (positioned relative to the instance box). Shows a placeholder when the block
 * did not resolve — omitted entirely in print/preview to avoid printing it.
 *
 * IT USED TO SAY "missing block", WHICH IS USUALLY NOT WHAT HAPPENED.
 *
 * The block list this resolves against is `GET /document-blocks`, and that
 * response is filtered per caller — `DocumentAccessPolicy::filterVisible` with
 * the caller's permissions and organisational reach. So a tenant block gated
 * behind a permission tag the viewer does not hold is simply absent from their
 * library, and every instance of it lands here.
 *
 * That document is FINE. `DocumentRenderer::resolveBlocks()` looks a block up
 * by `findById($id, $tenantId)` — by tenant, not by reader — so the block
 * renders correctly in the PDF for everyone. And a block that IS referenced
 * cannot ordinarily be deleted at all: `DocumentBlocksApiHandler::delete()`
 * refuses with 409 while any template in the tenant still points at it.
 *
 * So the common case behind this placeholder is "you cannot see this block",
 * and the rare one is "it is genuinely gone" — and the marker was asserting the
 * rare one. An author who could not open a colleague's block was told their
 * document was broken when it was not.
 *
 * This cannot tell the two apart, so it no longer claims to. `label` says what
 * is actually known.
 *
 * The copy arrives as a PROP with an English default rather than through a
 * translator, for the reason `BLOCK_SCOPES` carries English labels: this
 * package is published standalone and must not depend on the i18n feature
 * (#758). Both callers live in the designer package and pass a translated
 * string; a consumer that passes nothing still gets a sentence rather than a
 * blank box.
 */
export function BlockInstanceContent({
  block,
  data,
  preview,
  label = 'Block not in your library',
  blocks,
  brokenLabel = 'Part of this block could not be resolved',
}: {
  block: DocBlock | undefined;
  data: Record<string, string>;
  preview: boolean;
  /** Shown in place of a block that did not resolve. Editing only. */
  label?: string;
  /**
   * The library, for expanding blocks nested INSIDE this one (#1186 slice 3).
   *
   * Optional so the existing callers and the published package's consumers keep
   * working unchanged. Omitted, a nested instance resolves to nothing — which is
   * exactly what happened before nesting existed, so leaving it out is no worse
   * than the old behaviour and passing it is strictly better.
   */
  blocks?: Record<string, DocBlock>;
  /** Shown when nesting inside this block broke. Editing only. */
  brokenLabel?: string;
}) {
  if (!block) {
    if (preview) return null;
    return (
      <div
        className="flex h-full w-full items-center justify-center rounded-sm border border-dashed border-destructive/60 bg-destructive/5 p-0.5 text-center text-[8px] leading-tight text-destructive"
        data-testid="doc-block-unresolved"
        title={label}
      >
        {label}
      </div>
    );
  }

  const { elements, diagnostics } = flattenBlock(block, blocks ?? {});
  const broken =
    diagnostics.cycles.length > 0 || diagnostics.unresolved.length > 0 || diagnostics.tooDeep;

  return (
    <>
      <ElementLayer elements={elements} data={data} />
      {/* The parts that DID resolve are drawn either way — a bad pointer in one
          nested branch is not a reason to blank the rest of the block.
          The marker is an authoring affordance and never reaches print, the
          same rule the unresolved-block marker above follows and for the same
          reason: a hole a customer can see is worse than a hole an author is
          told about. */}
      {broken && !preview ? (
        <div
          className="pointer-events-none absolute inset-x-0 bottom-0 truncate bg-destructive/10 px-0.5 text-[7px] leading-tight text-destructive"
          data-testid="doc-block-nested-broken"
          title={brokenLabel}
        >
          {brokenLabel}
        </div>
      ) : null}
    </>
  );
}
