import { render, screen } from '@testing-library/react';
import { BlockInstanceContent } from '@amroksaleh/ui/documents/element-layer';

/**
 * WHAT THE UNRESOLVED-BLOCK MARKER IS ALLOWED TO CLAIM.
 *
 * It used to say "missing block", which is usually not what happened.
 *
 * The library this resolves against comes from `GET /document-blocks`, and that
 * response is filtered per caller (`DocumentAccessPolicy::filterVisible`, with
 * the caller's permissions and organisational reach). A tenant block gated
 * behind a permission tag the viewer does not hold is simply absent from their
 * library, and every instance of it lands in this branch.
 *
 * That document is fine. `DocumentRenderer::resolveBlocks()` resolves by
 * `findById($id, $tenantId)` — by tenant, not by reader — so the block prints
 * correctly for everyone. And a referenced block cannot ordinarily be deleted
 * at all: `DocumentBlocksApiHandler::delete()` returns 409 while any template
 * in the tenant still points at it.
 *
 * So the common case is "you cannot see this block" and the rare one is "it is
 * gone", and the marker asserted the rare one — telling an author their
 * document was broken when it was not.
 */

const BLOCK = {
  id: '5',
  name: 'Header',
  scope: 'tenant' as const,
  w: 100,
  h: 20,
  elements: [
    { id: 'e1', type: 'text', x: 0, y: 0, w: 50, h: 10, rotation: 0, z: 1, text: 'Acme', style: {} },
  ],
} as never;

describe('BlockInstanceContent — a block that did not resolve', () => {
  it('does not claim the block is missing', () => {
    render(<BlockInstanceContent block={undefined} data={{}} preview={false} />);

    const marker = screen.getByTestId('doc-block-unresolved');
    expect(marker.textContent?.toLowerCase()).not.toContain('missing');
  });

  it('says what is actually known — that it is not in your library', () => {
    render(
      <BlockInstanceContent block={undefined} data={{}} preview={false} label="Block not in your library" />
    );

    expect(screen.getByTestId('doc-block-unresolved')).toHaveTextContent('Block not in your library');
  });

  it('takes its copy from the caller, so it can be translated', () => {
    // The kit is published standalone and must not depend on the i18n feature
    // (#758), so the sentence arrives as a prop. Hardcoded English here was the
    // only untranslated string left on the canvas.
    render(<BlockInstanceContent block={undefined} data={{}} preview={false} label="كتلة غير متاحة" />);

    expect(screen.getByTestId('doc-block-unresolved')).toHaveTextContent('كتلة غير متاحة');
  });

  it('never prints — the marker is an editing affordance', () => {
    const { container } = render(<BlockInstanceContent block={undefined} data={{}} preview />);

    expect(container.textContent).toBe('');
    expect(screen.queryByTestId('doc-block-unresolved')).toBeNull();
  });

  it('renders the block itself when it did resolve, marker or no marker', () => {
    // The control: "never claims missing" must not have been achieved by
    // showing nothing at all.
    render(<BlockInstanceContent block={BLOCK} data={{}} preview={false} />);

    expect(screen.queryByTestId('doc-block-unresolved')).toBeNull();
    expect(screen.getByTestId('doc-textbox')).toHaveTextContent('Acme');
  });
});
