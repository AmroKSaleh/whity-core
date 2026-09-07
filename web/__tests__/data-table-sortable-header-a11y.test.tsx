import React, { useState } from 'react';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';

/**
 * The sortable column header, for people who are not holding a mouse (#1129).
 *
 * WHAT WAS WRONG. The header was a `<th onClick>`: no focusable control inside
 * it, and no `aria-sort` on it. A `th` takes no keyboard focus and has no
 * activation behaviour, so a keyboard user could not sort ANY admin table —
 * and a screen-reader user, mouse in hand, was never told which column the
 * rows were ordered by or in which direction.
 *
 * WHY IT STOPPED BEING COSMETIC. While sorting only reordered rows already in
 * the browser, the header was a shortcut. With `DataTableServerSorting` the
 * header is the only route to rows on OTHER pages: on a 400-row table, a
 * keyboard user who cannot sort cannot reach row 300 by any means the UI
 * offers.
 *
 * The two halves are tested against BOTH modes on purpose. `aria-sort` is
 * derived from `getIsSorted()`, which reads the resolved sorting state — the
 * caller's in server mode, this component's in client mode. Getting one right
 * and the other wrong is the failure this file is shaped to catch, and the
 * server one is the half that matters more.
 */
interface Row {
  id: number;
  name: string;
  role: string;
}

const columns: DataTableColumn<Row>[] = [
  { accessorKey: 'name', header: 'Name', enableSorting: true },
  { accessorKey: 'role', header: 'Role' },
];

/** Deliberately NOT alphabetical, so "unsorted" is distinguishable. */
const rows: Row[] = [
  { id: 1, name: 'Zoe', role: 'admin' },
  { id: 2, name: 'Adam', role: 'viewer' },
  { id: 3, name: 'Mona', role: 'editor' },
];

const getRowId = (row: Row) => String(row.id);

function nameHeader(): HTMLElement {
  return screen.getByRole('columnheader', { name: /Name/ });
}

function nameColumn(): string[] {
  return screen
    .getAllByRole('row')
    .map((row) => within(row).queryAllByRole('cell')[0]?.textContent ?? null)
    .filter((text): text is string => text !== null);
}

/** A caller that holds the sort in state, as a real server-sorted page does. */
function ServerHarness({
  onSortingChange,
}: {
  onSortingChange: (sortKey: string | null, direction: 'asc' | 'desc') => void;
}) {
  const [sort, setSort] = useState<{
    sortKey: string | null;
    direction: 'asc' | 'desc';
  }>({ sortKey: null, direction: 'asc' });

  return (
    <DataTable<Row>
      ariaLabel="Users"
      columns={columns}
      data={rows}
      getRowId={getRowId}
      sorting={{
        ...sort,
        onSortingChange: (sortKey, direction) => {
          onSortingChange(sortKey, direction);
          setSort({ sortKey, direction });
        },
      }}
    />
  );
}

describe('the sortable header is reachable from the keyboard', () => {
  it('puts a focusable button in the cell that Tab alone can reach', async () => {
    const user = userEvent.setup();
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    const sortButton = within(nameHeader()).getByRole('button');

    // THE REGRESSION. Before #1129 there was nothing here to focus, so this
    // Tab landed on whatever came after the table — or on nothing at all.
    await user.tab();
    expect(sortButton).toHaveFocus();
  });

  it('sorts on Enter, exactly as a click does', async () => {
    const user = userEvent.setup();
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);

    within(nameHeader()).getByRole('button').focus();
    await user.keyboard('{Enter}');

    expect(nameColumn()).toEqual(['Adam', 'Mona', 'Zoe']);
  });

  it('sorts on Space too, and walks the full asc -> desc -> unsorted cycle', async () => {
    const user = userEvent.setup();
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    within(nameHeader()).getByRole('button').focus();

    await user.keyboard('[Space]');
    expect(nameColumn()).toEqual(['Adam', 'Mona', 'Zoe']);

    await user.keyboard('[Space]');
    expect(nameColumn()).toEqual(['Zoe', 'Mona', 'Adam']);

    // The third activation must return to the order the rows arrived in. A
    // keyboard path that can enter a sort but not leave it is not the same
    // control the mouse has.
    await user.keyboard('[Space]');
    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);
  });

  it('reports keyboard activation to a server-sorting caller like a click does', async () => {
    const user = userEvent.setup();
    const onSortingChange = jest.fn();
    render(<ServerHarness onSortingChange={onSortingChange} />);

    within(nameHeader()).getByRole('button').focus();

    await user.keyboard('{Enter}');
    expect(onSortingChange).toHaveBeenNthCalledWith(1, 'name', 'asc');

    await user.keyboard('{Enter}');
    expect(onSortingChange).toHaveBeenNthCalledWith(2, 'name', 'desc');

    // `null` is "no column chosen" — the caller then sends no `sort` and the
    // endpoint's own default ordering applies. Reachable by keyboard or not
    // at all.
    await user.keyboard('{Enter}');
    expect(onSortingChange).toHaveBeenNthCalledWith(3, null, 'asc');

    // Exactly three. The button carries no handler of its own precisely so a
    // single activation cannot fire the cell's handler twice.
    expect(onSortingChange).toHaveBeenCalledTimes(3);
  });
});

describe('aria-sort tracks the real sort state', () => {
  it('follows the cycle in CLIENT mode', async () => {
    const user = userEvent.setup();
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    expect(nameHeader()).toHaveAttribute('aria-sort', 'none');

    await user.click(nameHeader());
    expect(nameHeader()).toHaveAttribute('aria-sort', 'ascending');

    await user.click(nameHeader());
    expect(nameHeader()).toHaveAttribute('aria-sort', 'descending');

    await user.click(nameHeader());
    expect(nameHeader()).toHaveAttribute('aria-sort', 'none');
  });

  it("follows the CALLER's sort in SERVER mode, including on first render", () => {
    // Not a click cycle: the sort arrives as a prop, which is the case a
    // client-only implementation gets wrong. The screen may well have been
    // loaded from a URL that already carried `?sort=name&direction=desc`.
    const { rerender } = render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: 'name', direction: 'desc', onSortingChange: jest.fn() }}
      />
    );

    expect(nameHeader()).toHaveAttribute('aria-sort', 'descending');

    rerender(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: 'name', direction: 'asc', onSortingChange: jest.fn() }}
      />
    );
    expect(nameHeader()).toHaveAttribute('aria-sort', 'ascending');

    rerender(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: null, direction: 'asc', onSortingChange: jest.fn() }}
      />
    );
    expect(nameHeader()).toHaveAttribute('aria-sort', 'none');
  });

  it('follows a click through in SERVER mode, where the header is the only route to other pages', async () => {
    const user = userEvent.setup();
    render(<ServerHarness onSortingChange={jest.fn()} />);

    expect(nameHeader()).toHaveAttribute('aria-sort', 'none');

    await user.click(nameHeader());
    expect(nameHeader()).toHaveAttribute('aria-sort', 'ascending');

    await user.click(nameHeader());
    expect(nameHeader()).toHaveAttribute('aria-sort', 'descending');
  });

  it('says nothing at all on a column that cannot be sorted', () => {
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    const roleHeader = screen.getByRole('columnheader', { name: /Role/ });

    // `aria-sort="none"` here would advertise a control that is not there.
    expect(roleHeader).not.toHaveAttribute('aria-sort');
    expect(within(roleHeader).queryByRole('button')).toBeNull();
  });
});

describe('the markup change stays invisible to everything that selects headers', () => {
  it("leaves the columnheader's accessible name exactly the label", () => {
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    // EXACT strings, not the regexes used elsewhere in the suite. Fourteen
    // tables' worth of Playwright and Jest selectors are `getByRole(
    // 'columnheader', { name })`, one of them `exact: true`. An `aria-label`
    // on the button inside — "Sort by Name" — would read better in isolation
    // and would rename the cell for every one of them, because a
    // columnheader's name is computed from its contents.
    expect(screen.getByRole('columnheader', { name: 'Name' })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Role' })).toBeInTheDocument();
    expect(within(nameHeader()).getByRole('button')).toHaveAccessibleName('Name');
  });

  it('still sorts when the cell itself is clicked, not the button', async () => {
    const user = userEvent.setup();
    render(
      <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
    );

    // The whole cell stays the click target — the behaviour 26 callers have
    // today, and what every existing test clicking `getByRole('columnheader')`
    // depends on.
    await user.click(nameHeader());
    expect(nameColumn()).toEqual(['Adam', 'Mona', 'Zoe']);
  });
});

describe('the sort affordance reads correctly in RTL', () => {
  it('keeps the label leading and the indicator trailing, by logical properties only', () => {
    render(
      <div dir="rtl">
        <DataTable<Row> ariaLabel="Users" columns={columns} data={rows} getRowId={getRowId} />
      </div>
    );

    const sortButton = within(nameHeader()).getByRole('button');

    // Document order is label then indicator; `flex` + `gap` are
    // direction-relative, so RTL flips which side "trailing" is without any
    // second code path. What would NOT flip is a physical margin or offset, so
    // the class list must not contain one — that is the bug this catches,
    // since jsdom applies no layout and an `ml-2` here would look fine in
    // every other assertion.
    expect(sortButton.className).not.toMatch(/(^|\s|:)-?m[lr]-/);
    expect(sortButton.className).not.toMatch(/(^|\s|:)(left|right)-/);
    expect(sortButton.className).toMatch(/text-start/);

    // The indicator is the last child, after the label text.
    expect(sortButton.textContent).toBe('Name');
    expect(sortButton.lastElementChild?.tagName.toLowerCase()).toBe('svg');
  });
});
