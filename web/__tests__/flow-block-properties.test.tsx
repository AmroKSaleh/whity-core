import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FlowEditor } from '@amroksaleh/features/document-designer';
import { newFlowBlock } from '@amroksaleh/ui/documents/flow';
import type { FlowBlock, FlowContent, FlowTable } from '@amroksaleh/ui/documents/flow';

/**
 * The block properties the RENDERER honours and the editor could not set.
 *
 * Four of them: `heading.unnumbered`, `heading.inContents`, `paragraph.align`
 * and `table.caption`. Each is read by `render-service/src/flow` — numbering
 * and the contents list in `document.js`, the alignment class and the table
 * caption in `html.js` — and each was declared in the model with no control
 * anywhere. The features existed and shipped; nobody could reach them.
 *
 * `table.caption` is the sharpest case: `flowToCanvas` READS it to represent a
 * table when a document is switched to canvas mode, so a converted table always
 * fell back to its "N × M" summary — because nothing could ever set one.
 */

function content(block: FlowBlock): FlowContent {
  return { blocks: [block] };
}

function firstBlock(onChange: jest.Mock): Record<string, unknown> {
  const next = onChange.mock.calls[onChange.mock.calls.length - 1][0] as FlowContent;
  return next.blocks[0] as unknown as Record<string, unknown>;
}

function heading(): FlowBlock {
  return newFlowBlock('heading');
}

describe('heading numbering and the contents list', () => {
  it('starts numbered and listed, which is what the renderer defaults to', () => {
    render(
      <FlowEditor content={content(heading())} onChange={jest.fn()} selected={0} onSelect={jest.fn()} />
    );
    expect(screen.getByTestId('flow-heading-numbered-0')).toBeChecked();
    expect(screen.getByTestId('flow-heading-contents-0')).toBeChecked();
  });

  it('marks a heading unnumbered for a front-matter-style title', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor content={content(heading())} onChange={onChange} selected={0} onSelect={jest.fn()} />
    );

    await user.click(screen.getByTestId('flow-heading-numbered-0'));

    expect(firstBlock(onChange).unnumbered).toBe(true);
  });

  it('stores the default as ABSENT rather than as a value meaning the same', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor
        content={content({ ...heading(), unnumbered: true } as FlowBlock)}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
      />
    );

    await user.click(screen.getByTestId('flow-heading-numbered-0'));

    // Not `unnumbered: false`. A key that means what its absence means is a key
    // every reader has to learn is redundant.
    expect(firstBlock(onChange).unnumbered).toBeUndefined();
  });

  it('takes a heading out of the contents while leaving it numbered', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor content={content(heading())} onChange={onChange} selected={0} onSelect={jest.fn()} />
    );

    await user.click(screen.getByTestId('flow-heading-contents-0'));

    expect(firstBlock(onChange).inContents).toBe(false);
    expect(firstBlock(onChange).unnumbered).toBeUndefined();
  });

  /**
   * THE DEPENDENCY, SHOWN RATHER THAN HIDDEN. The renderer lists a heading only
   * when `inContents !== false && !unnumbered`, so an unnumbered heading is
   * never listed whatever the other box says. Leaving it live would be a
   * control that silently does nothing — the failure this whole slice is about.
   */
  it('disables the contents box for an unnumbered heading, and shows it unchecked', () => {
    render(
      <FlowEditor
        content={content({ ...heading(), unnumbered: true } as FlowBlock)}
        onChange={jest.fn()}
        selected={0}
        onSelect={jest.fn()}
      />
    );

    const box = screen.getByTestId('flow-heading-contents-0');
    expect(box).toBeDisabled();
    // Unchecked, because that is what the renderer will actually do with it.
    expect(box).not.toBeChecked();
  });
});

describe('paragraph alignment', () => {
  it('offers the three the renderer understands', () => {
    render(
      <FlowEditor
        content={content(newFlowBlock('paragraph'))}
        onChange={jest.fn()}
        selected={0}
        onSelect={jest.fn()}
      />
    );
    const select = screen.getByTestId('flow-paragraph-align-0');
    expect(select).toHaveValue('start');
    expect(Array.from(select.querySelectorAll('option')).map((o) => o.value)).toEqual([
      'start',
      'center',
      'end',
    ]);
  });

  it('sets centre', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor
        content={content(newFlowBlock('paragraph'))}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
      />
    );

    await user.selectOptions(screen.getByTestId('flow-paragraph-align-0'), 'center');

    expect(firstBlock(onChange).align).toBe('center');
  });

  /**
   * `undefined`, not `'start'`. The renderer emits a class only for center and
   * end, so a third stored value would be a key in the document that means
   * exactly what its absence means.
   */
  it('stores the default as absent', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor
        content={content({ ...newFlowBlock('paragraph'), align: 'center' } as FlowBlock)}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
      />
    );

    await user.selectOptions(screen.getByTestId('flow-paragraph-align-0'), 'start');

    expect(firstBlock(onChange).align).toBeUndefined();
  });
});

describe('table caption', () => {
  it('can be set at all', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor
        content={content(newFlowBlock('table'))}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
      />
    );

    await user.type(screen.getByTestId('flow-table-caption-0'), 'R');

    expect((firstBlock(onChange) as unknown as FlowTable).caption).toBe('R');
  });

  it('clears to absent rather than to an empty string', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor
        content={content({ ...newFlowBlock('table'), caption: 'X' } as FlowBlock)}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
      />
    );

    await user.clear(screen.getByTestId('flow-table-caption-0'));

    // The renderer branches on `if (block.caption)`, so '' and absent behave
    // identically — storing '' would put a meaningless key in every document
    // whose caption was typed and then removed.
    expect(firstBlock(onChange).caption).toBeUndefined();
  });
});
