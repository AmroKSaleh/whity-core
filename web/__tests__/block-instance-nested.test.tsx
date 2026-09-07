import { render, screen } from '@testing-library/react';
import { BlockInstanceContent } from '@amroksaleh/ui/documents/element-layer';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

/**
 * Rendering a block that holds another block (#1186 slice 3).
 *
 * The model tests cover the expansion. These cover the WIRING, which is where
 * this feature would otherwise fail silently: a nested instance that never
 * reached `flattenBlock` would land on `ElementContent`'s `case
 * 'blockInstance'`, return null, and draw nothing — no error, no marker, just
 * an absence nobody has any reason to look for.
 *
 * And they cover the rule the surrounding renderer already follows: an
 * authoring marker is never allowed to reach print.
 */

function block(id: string, elements: unknown[]): DocBlock {
  return { id, name: id, scope: 'personal', w: 100, h: 20, elements } as DocBlock;
}

const LOGO = block('logo', [
  { id: 'e1', type: 'text', x: 0, y: 0, w: 50, h: 10, rotation: 0, z: 1, text: 'ACME', style: {} },
]);

const LETTERHEAD = block('head', [
  { id: 'e2', type: 'text', x: 0, y: 0, w: 50, h: 10, rotation: 0, z: 1, text: 'Invoice', style: {} },
  { id: 'e3', type: 'blockInstance', blockId: 'logo', x: 0, y: 12, w: 50, h: 10, rotation: 0, z: 2 },
]);

const LIBRARY = { logo: LOGO, head: LETTERHEAD };

describe('a block containing another block', () => {
  it('draws the nested content', () => {
    render(<BlockInstanceContent block={LETTERHEAD} data={{}} preview blocks={LIBRARY} />);

    expect(screen.getByText('Invoice')).toBeInTheDocument();
    expect(screen.getByText('ACME')).toBeInTheDocument();
  });

  it('draws nothing nested when no library is passed, exactly as before', () => {
    // The prop is optional so the published package's existing consumers keep
    // working. Without it a nested instance resolves to nothing — no worse than
    // the behaviour that shipped before nesting existed.
    render(<BlockInstanceContent block={LETTERHEAD} data={{}} preview />);

    expect(screen.getByText('Invoice')).toBeInTheDocument();
    expect(screen.queryByText('ACME')).not.toBeInTheDocument();
  });

  it('says so while editing when a nested block cannot be resolved', () => {
    const orphan = block('head', [
      { id: 'e3', type: 'blockInstance', blockId: 'gone', x: 0, y: 0, w: 10, h: 10, rotation: 0, z: 1 },
    ]);

    render(<BlockInstanceContent block={orphan} data={{}} preview={false} blocks={{}} />);

    expect(screen.getByTestId('doc-block-nested-broken')).toBeInTheDocument();
  });

  /**
   * THE RULE THIS PACKAGE ALREADY FOLLOWS. A marker is an authoring affordance;
   * printing one puts a red box on a customer's document. The unresolved-block
   * marker and the unresolved-image placeholder are both gated on `preview` for
   * this reason, and one of them was not, once.
   */
  it('never prints the marker', () => {
    const orphan = block('head', [
      { id: 'e3', type: 'blockInstance', blockId: 'gone', x: 0, y: 0, w: 10, h: 10, rotation: 0, z: 1 },
    ]);

    render(<BlockInstanceContent block={orphan} data={{}} preview blocks={{}} />);

    expect(screen.queryByTestId('doc-block-nested-broken')).not.toBeInTheDocument();
  });

  it('stays quiet when everything resolved', () => {
    render(<BlockInstanceContent block={LETTERHEAD} data={{}} preview={false} blocks={LIBRARY} />);

    expect(screen.queryByTestId('doc-block-nested-broken')).not.toBeInTheDocument();
  });

  it('still draws the parts that resolved when one branch is broken', () => {
    const partial = block('head', [
      { id: 'e2', type: 'text', x: 0, y: 0, w: 50, h: 10, rotation: 0, z: 1, text: 'Invoice', style: {} },
      { id: 'e3', type: 'blockInstance', blockId: 'gone', x: 0, y: 12, w: 10, h: 10, rotation: 0, z: 2 },
    ]);

    render(<BlockInstanceContent block={partial} data={{}} preview blocks={{}} />);

    // One bad pointer is not a reason to blank the block.
    expect(screen.getByText('Invoice')).toBeInTheDocument();
  });

  it('renders a self-referencing block without recursing forever', () => {
    const self = block('self', [
      { id: 'e1', type: 'text', x: 0, y: 0, w: 50, h: 10, rotation: 0, z: 1, text: 'Loop', style: {} },
      { id: 'e2', type: 'blockInstance', blockId: 'self', x: 0, y: 12, w: 10, h: 10, rotation: 0, z: 2 },
    ]);

    render(<BlockInstanceContent block={self} data={{}} preview blocks={{ self }} />);

    expect(screen.getByText('Loop')).toBeInTheDocument();
  });
});
