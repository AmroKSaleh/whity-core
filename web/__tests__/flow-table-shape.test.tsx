import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FlowEditor } from '@amroksaleh/features/document-designer';
import {
  addTableColumn,
  addTableRow,
  newFlowBlock,
  removeTableColumn,
  removeTableRow,
  tableColumnCount,
} from '@amroksaleh/ui/documents/flow';
import type { FlowContent, FlowTable } from '@amroksaleh/ui/documents/flow';

/**
 * Reshaping a table block.
 *
 * A table was FROZEN at the shape `newFlowBlock` created — two columns, one row,
 * forever. Every cell was editable, which is exactly what made the limit
 * costly: the block looked like a working table and only revealed it could not
 * grow when somebody had a third row to enter.
 *
 * THE INVARIANT UNDER TEST IS RECTANGULARITY. Adding a column means one heading
 * AND one cell in every row. Push a heading and forget the body and the result
 * validates — the renderer only checks that rows are arrays — then prints with
 * the last column missing from every row, which reads as a styling bug rather
 * than as lost data.
 */

function table(): FlowTable {
  return newFlowBlock('table') as FlowTable;
}

/** Rows all the same width as the header: the property everything must preserve. */
function isRectangular(t: FlowTable): boolean {
  const width = tableColumnCount(t);
  return t.rows.every((r) => r.length === width);
}

describe('the shape a table starts with', () => {
  it('is rectangular', () => {
    expect(isRectangular(table())).toBe(true);
  });
});

describe('adding', () => {
  it('adds a row of the right width', () => {
    const t = addTableRow(table());
    expect(t.rows).toHaveLength(2);
    expect(isRectangular(t)).toBe(true);
  });

  /** The one that catches the ragged-table bug. */
  it('adds a column to the heading AND to every row', () => {
    const start = addTableRow(addTableRow(table()));
    const t = addTableColumn(start);

    expect(t.columns).toHaveLength(3);
    expect(t.rows.every((r) => r.length === 3)).toBe(true);
    expect(isRectangular(t)).toBe(true);
  });

  it('stays rectangular through a long mixed sequence', () => {
    let t = table();
    for (let i = 0; i < 6; i += 1) {
      t = addTableRow(t);
      t = addTableColumn(t);
      if (i % 2 === 0) t = removeTableRow(t, 0);
      if (i % 3 === 0) t = removeTableColumn(t, 0);
      expect(isRectangular(t)).toBe(true);
    }
  });
});

describe('removing', () => {
  it('removes the named row', () => {
    let t = addTableRow(addTableRow(table()));
    t.rows[1][0] = 'middle';
    t = removeTableRow(t, 1);
    expect(t.rows).toHaveLength(2);
    expect(t.rows.some((r) => r[0] === 'middle')).toBe(false);
  });

  it('removes a column from the heading and from every row together', () => {
    let t = addTableColumn(addTableRow(table()));
    t = removeTableColumn(t, 0);
    expect(t.columns).toHaveLength(2);
    expect(isRectangular(t)).toBe(true);
  });

  /**
   * A table with no rows is valid to the renderer and prints as a header with
   * nothing under it — or, with no header, as nothing at all. Refusing the last
   * removal keeps the block visible and re-fillable instead of leaving an
   * invisible entry in the document's reading order.
   */
  it('keeps the last row', () => {
    const t = table();
    expect(removeTableRow(t, 0).rows).toHaveLength(1);
  });

  it('keeps the last column', () => {
    const one = removeTableColumn(table(), 0);
    expect(tableColumnCount(one)).toBe(1);
    expect(tableColumnCount(removeTableColumn(one, 0))).toBe(1);
  });
});

describe('the controls in the editor', () => {
  function content(): FlowContent {
    return { blocks: [table()] };
  }

  it('offers add row and add column at all', () => {
    render(<FlowEditor content={content()} onChange={jest.fn()} selected={0} onSelect={jest.fn()} />);
    expect(screen.getByTestId('flow-table-add-row-0')).toBeInTheDocument();
    expect(screen.getByTestId('flow-table-add-column-0')).toBeInTheDocument();
  });

  it('grows the table when add row is used', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(<FlowEditor content={content()} onChange={onChange} selected={0} onSelect={jest.fn()} />);

    await user.click(screen.getByTestId('flow-table-add-row-0'));

    const next = onChange.mock.calls[0][0] as FlowContent;
    expect((next.blocks[0] as FlowTable).rows).toHaveLength(2);
  });

  it('grows every row when add column is used', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(<FlowEditor content={content()} onChange={onChange} selected={0} onSelect={jest.fn()} />);

    await user.click(screen.getByTestId('flow-table-add-column-0'));

    const next = onChange.mock.calls[0][0] as FlowContent;
    expect(isRectangular(next.blocks[0] as FlowTable)).toBe(true);
    expect(tableColumnCount(next.blocks[0] as FlowTable)).toBe(3);
  });

  /**
   * Disabled rather than hidden. A control that vanishes reads as a bug, and
   * the reason it is unavailable is worth showing.
   */
  it('disables remove row at the last row instead of hiding it', () => {
    render(<FlowEditor content={content()} onChange={jest.fn()} selected={0} onSelect={jest.fn()} />);
    expect(screen.getByTestId('flow-table-remove-row-0')).toBeDisabled();
  });

  it('enables remove row once there is more than one', () => {
    const grown: FlowContent = { blocks: [addTableRow(table())] };
    render(<FlowEditor content={grown} onChange={jest.fn()} selected={0} onSelect={jest.fn()} />);
    expect(screen.getByTestId('flow-table-remove-row-0')).toBeEnabled();
  });

  it('still lets a cell be typed into', async () => {
    const user = userEvent.setup();
    const onChange = jest.fn();
    render(<FlowEditor content={content()} onChange={onChange} selected={0} onSelect={jest.fn()} />);

    await user.type(screen.getByLabelText('Row 1, column 1'), 'x');

    const next = onChange.mock.calls[0][0] as FlowContent;
    expect((next.blocks[0] as FlowTable).rows[0][0]).toBe('x');
  });
});
