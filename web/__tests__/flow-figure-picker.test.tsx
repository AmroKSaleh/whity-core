import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FlowEditor } from '@amroksaleh/features/document-designer';
import {
  DEFAULT_MAX_FIGURE_BYTES,
  FIGURE_MIME_TYPES,
  judgeFigureFile,
} from '@amroksaleh/ui/documents/flow';
import type { FlowContent } from '@amroksaleh/ui/documents/flow';

/**
 * Choosing the image in a `figure` block.
 *
 * INSERT ▸ IMAGE WAS A DEAD COMMAND. `newFlowBlock('figure')` starts as a 1×1
 * transparent PNG — the only honest empty state, since the renderer refuses a
 * remote source and an empty string would be a block that exists and cannot
 * print — and nothing anywhere let the author replace it. The command appeared
 * to work and put an invisible dot on the page.
 *
 * Two rules matter as much as the picker itself, and both are about what must
 * NOT be accepted:
 *
 *   - SVG is refused. It can carry script, and this value becomes a `data:` URI
 *     rendered in the browser AND in headless Chromium.
 *   - Oversized files are refused, with the reason said out loud, because the
 *     server caps the whole template and a save refused later names bytes
 *     rather than the picture somebody just chose.
 */

const PNG_1x1 =
  'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

function figureContent(): FlowContent {
  return { blocks: [{ type: 'figure', dataUri: PNG_1x1 }] };
}

function file(name: string, type: string, size: number): File {
  const f = new File(['x'], name, { type });
  Object.defineProperty(f, 'size', { value: size });
  return f;
}

describe('judging a chosen file, before any bytes are read', () => {
  it('accepts the raster types the renderer can embed', () => {
    for (const type of FIGURE_MIME_TYPES) {
      expect(judgeFigureFile({ type, size: 1000 })).toBeNull();
    }
  });

  /**
   * NOT AN OVERSIGHT ABOUT VECTORS. An SVG can carry script and this value
   * becomes a data: URI that two renderers execute HTML for. `element-content`
   * already refuses script-carrying SVG data URIs on the canvas side.
   */
  it('refuses SVG', () => {
    expect(judgeFigureFile({ type: 'image/svg+xml', size: 100 })).toBe('type');
  });

  it('refuses a non-image outright', () => {
    expect(judgeFigureFile({ type: 'application/pdf', size: 100 })).toBe('type');
    expect(judgeFigureFile({ type: '', size: 100 })).toBe('type');
  });

  it('refuses a file over the budget, and accepts one exactly at it', () => {
    expect(judgeFigureFile({ type: 'image/png', size: DEFAULT_MAX_FIGURE_BYTES + 1 })).toBe('size');
    expect(judgeFigureFile({ type: 'image/png', size: DEFAULT_MAX_FIGURE_BYTES })).toBeNull();
  });

  it('honours a raised budget', () => {
    const big = DEFAULT_MAX_FIGURE_BYTES * 4;
    expect(judgeFigureFile({ type: 'image/png', size: big }, big)).toBeNull();
  });

  /**
   * The client cap must stay clear of the server's whole-template limit, since
   * base64 inflates by about a third. A guard set at or above it would pass
   * files the encoded template then fails on.
   */
  it('leaves room under the server default once base64 inflation is counted', () => {
    const SERVER_TEMPLATE_BYTES = 2_000_000;
    expect(DEFAULT_MAX_FIGURE_BYTES * (4 / 3)).toBeLessThan(SERVER_TEMPLATE_BYTES / 2);
  });
});

describe('the picker in the editor', () => {
  it('offers a control to choose an image at all', () => {
    render(
      <FlowEditor content={figureContent()} onChange={jest.fn()} selected={0} onSelect={jest.fn()} />
    );
    expect(screen.getByTestId('flow-figure-choose-0')).toBeInTheDocument();
  });

  it('puts the chosen image into the block as a data URI', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor content={figureContent()} onChange={onChange} selected={0} onSelect={jest.fn()} />
    );

    await user.upload(
      screen.getByTestId('flow-figure-file-0'),
      new File(['hello'], 'logo.png', { type: 'image/png' })
    );

    await waitFor(() => expect(onChange).toHaveBeenCalled());
    const next = onChange.mock.calls[0][0] as FlowContent;
    const block = next.blocks[0] as { type: string; dataUri: string };
    expect(block.type).toBe('figure');
    expect(block.dataUri.startsWith('data:')).toBe(true);
    // Replaced, not left on the invisible placeholder.
    expect(block.dataUri).not.toBe(PNG_1x1);
  });

  it('SAYS WHY it refused an SVG, rather than closing and doing nothing', async () => {
    // `applyAccept: false` on purpose. The `accept` attribute only filters the
    // FILE DIALOG, and every desktop browser lets somebody switch it to "All
    // files" and pick anything — so accept is a convenience and the JS check is
    // the actual guard. Letting userEvent honour accept here would test the
    // hint and leave the guard unexercised.
    const user = userEvent.setup({ applyAccept: false });
    const onChange = jest.fn();
    const onError = jest.fn();
    render(
      <FlowEditor
        content={figureContent()}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
        onError={onError}
      />
    );

    await user.upload(
      screen.getByTestId('flow-figure-file-0'),
      file('evil.svg', 'image/svg+xml', 100)
    );

    await waitFor(() => expect(onError).toHaveBeenCalled());
    expect(onChange).not.toHaveBeenCalled();
    expect(String(onError.mock.calls[0][0])).toMatch(/PNG|JPEG|type/i);
  });

  it('says why it refused an oversized image', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    const onError = jest.fn();
    render(
      <FlowEditor
        content={figureContent()}
        onChange={onChange}
        selected={0}
        onSelect={jest.fn()}
        onError={onError}
      />
    );

    await user.upload(
      screen.getByTestId('flow-figure-file-0'),
      file('huge.png', 'image/png', DEFAULT_MAX_FIGURE_BYTES + 1)
    );

    await waitFor(() => expect(onError).toHaveBeenCalled());
    expect(onChange).not.toHaveBeenCalled();
  });

  it('still lets the caption be edited', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(
      <FlowEditor content={figureContent()} onChange={onChange} selected={0} onSelect={jest.fn()} />
    );

    await user.type(screen.getByTestId('flow-input-0'), 'F');

    expect(onChange).toHaveBeenCalled();
    const next = onChange.mock.calls[0][0] as FlowContent;
    expect((next.blocks[0] as { caption?: string }).caption).toBe('F');
  });
});
