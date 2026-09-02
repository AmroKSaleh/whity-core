'use client';

import type { DocElement } from './types';
import type { DocBlock } from './blocks';
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
}: {
  block: DocBlock | undefined;
  data: Record<string, string>;
  preview: boolean;
  /** Shown in place of a block that did not resolve. Editing only. */
  label?: string;
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
  return <ElementLayer elements={block.elements} data={data} />;
}
