import { useState } from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FlowBlockSettings } from '@amroksaleh/features/document-designer';
import { newFlowBlock } from '@amroksaleh/ui/documents/flow';
import type { FlowBlock } from '@amroksaleh/ui/documents/flow';

/**
 * The block settings view — spacing, page behaviour and width for the selected
 * block (#1186).
 *
 * EVERY CONTROL HERE IS READ BY THE RENDERER, and that ordering is the point.
 * The renderer support landed first, because a control for something the
 * printer ignores is the defect this line of work keeps turning up: a figure
 * block with no picker, a table frozen at 2x1, four properties settable from
 * nowhere. The way not to add a fifth was to build the printed capability
 * first and the control second.
 *
 * So most of these tests are about the two ways a control can lie — storing a
 * value the renderer would reject, and staying live when it cannot do anything.
 */

/**
 * Render the panel the way a real consumer does: holding the block in state and
 * feeding it back.
 *
 * These are CONTROLLED inputs. Rendered once with a fixed block, every
 * keystroke is typed against a value that never advanced — so "900" arrives as
 * three separate first characters and the assertions measure the harness
 * rather than the component. Three tests failed that way before this wrapper
 * existed, and none of them was a defect in the panel.
 */
function setup(initial: FlowBlock, next?: FlowBlock | null) {
  const onChange = jest.fn();

  function Harness() {
    const [block, setBlock] = useState<FlowBlock>(initial);
    return (
      <FlowBlockSettings
        block={block}
        nextBlock={next ?? null}
        onChange={(b) => {
          onChange(b);
          setBlock(b);
        }}
      />
    );
  }

  render(<Harness />);
  return onChange;
}

/** The block as it was last handed back. */
function changed(onChange: jest.Mock): Record<string, unknown> {
  return onChange.mock.calls[onChange.mock.calls.length - 1][0] as Record<string, unknown>;
}

describe('with nothing selected', () => {
  it('says what the panel is for rather than showing empty controls', () => {
    render(<FlowBlockSettings block={null} onChange={jest.fn()} />);
    expect(screen.getByTestId('flow-settings-empty')).toBeInTheDocument();
    expect(screen.queryByTestId('flow-settings')).not.toBeInTheDocument();
  });
});

describe('spacing', () => {
  it('stores millimetres above and below', async () => {
    const user = userEvent.setup();
    const onChange = setup(newFlowBlock('paragraph'));

    await user.type(screen.getByTestId('flow-settings-space-before'), '6');
    expect(changed(onChange).spaceBeforeMm).toBe(6);

    await user.type(screen.getByTestId('flow-settings-space-after'), '4');
    expect(changed(onChange).spaceAfterMm).toBe(4);
  });

  /**
   * Cleared means ABSENT, not zero. The renderer treats a missing key and a
   * zero identically, so storing the zero only adds a key every later reader
   * has to learn is redundant.
   */
  it('clears to absent rather than to zero', async () => {
    const user = userEvent.setup();
    const onChange = setup({ ...newFlowBlock('paragraph'), spaceBeforeMm: 8 } as FlowBlock);

    await user.clear(screen.getByTestId('flow-settings-space-before'));

    expect(changed(onChange).spaceBeforeMm).toBeUndefined();
  });

  /**
   * The renderer refuses a space over half a page. Clamping here means the
   * author never composes a document the printer will then reject — the bound
   * is the same number on both sides.
   */
  it('clamps to what the renderer accepts instead of composing a refusal', async () => {
    const user = userEvent.setup();
    const onChange = setup(newFlowBlock('paragraph'));

    await user.type(screen.getByTestId('flow-settings-space-before'), '900');

    expect(changed(onChange).spaceBeforeMm).toBeLessThanOrEqual(148);
  });
});

describe('page behaviour', () => {
  it('sets each hint, and stores false as absent', async () => {
    const user = userEvent.setup();
    const onChange = setup(newFlowBlock('paragraph'));

    await user.click(screen.getByTestId('flow-settings-break-before'));
    expect(changed(onChange).breakBefore).toBe(true);

    await user.click(screen.getByTestId('flow-settings-keep-together'));
    expect(changed(onChange).keepTogether).toBe(true);
  });

  it('turning a hint back off clears the key', async () => {
    const user = userEvent.setup();
    const onChange = setup({ ...newFlowBlock('paragraph'), keepTogether: true } as FlowBlock);

    await user.click(screen.getByTestId('flow-settings-keep-together'));

    expect(changed(onChange).keepTogether).toBeUndefined();
  });

  /**
   * THE INTERACTION WORTH SHOWING. "Keep with the next block" asks the
   * paginator to move this block rather than let a break fall after it — which
   * a successor that starts its own page makes impossible. Left live it would
   * be a setting somebody could turn on forever with no effect.
   */
  it('disables keep-with-next when the next block starts its own page', () => {
    setup(newFlowBlock('paragraph'), { ...newFlowBlock('heading'), breakBefore: true } as FlowBlock);

    const box = screen.getByTestId('flow-settings-keep-with-next');
    expect(box).toBeDisabled();
    expect(box).not.toBeChecked();
  });

  it('disables it for an explicit page break too', () => {
    setup(newFlowBlock('paragraph'), newFlowBlock('pageBreak'));
    expect(screen.getByTestId('flow-settings-keep-with-next')).toBeDisabled();
  });

  it('leaves it live when the next block is ordinary', () => {
    setup(newFlowBlock('paragraph'), newFlowBlock('paragraph'));
    expect(screen.getByTestId('flow-settings-keep-with-next')).toBeEnabled();
  });

  it('leaves it live at the end of the document', () => {
    setup(newFlowBlock('paragraph'), null);
    expect(screen.getByTestId('flow-settings-keep-with-next')).toBeEnabled();
  });
});

describe('width', () => {
  it('stores a percentage of the text column', async () => {
    const user = userEvent.setup();
    const onChange = setup(newFlowBlock('figure'));

    await user.type(screen.getByTestId('flow-settings-width'), '50');

    expect(changed(onChange).widthPercent).toBe(50);
  });

  /** Full width is the default, so it is stored as absent. */
  it('treats a hundred as no setting at all', async () => {
    const user = userEvent.setup();
    const onChange = setup({ ...newFlowBlock('figure'), widthPercent: 50 } as FlowBlock);

    await user.clear(screen.getByTestId('flow-settings-width'));
    await user.type(screen.getByTestId('flow-settings-width'), '100');

    expect(changed(onChange).widthPercent).toBeUndefined();
  });

  it('refuses a width below what stays readable', async () => {
    const user = userEvent.setup();
    const onChange = setup(newFlowBlock('figure'));

    await user.type(screen.getByTestId('flow-settings-width'), '5');

    expect(changed(onChange).widthPercent).toBeUndefined();
  });
});

describe('blocks with no box', () => {
  /**
   * The renderer REFUSES layout keys on these. Showing six controls that would
   * each be rejected would be worse than saying so.
   */
  it('says a page break takes no layout, and offers none', () => {
    render(<FlowBlockSettings block={newFlowBlock('pageBreak')} onChange={jest.fn()} />);

    expect(screen.getByTestId('flow-settings-boxless')).toBeInTheDocument();
    expect(screen.queryByTestId('flow-settings-space-before')).not.toBeInTheDocument();
    expect(screen.queryByTestId('flow-settings-width')).not.toBeInTheDocument();
  });

  /** A spacer has no layout either — but it does have the one thing it IS. */
  it('offers a spacer its height and nothing else', async () => {
    const user = userEvent.setup();
    const onChange = setup(newFlowBlock('spacer'));

    expect(screen.queryByTestId('flow-settings-space-before')).not.toBeInTheDocument();

    await user.clear(screen.getByTestId('flow-settings-spacer-height'));
    await user.type(screen.getByTestId('flow-settings-spacer-height'), '12');

    expect(changed(onChange).heightMm).toBe(12);
  });
});
