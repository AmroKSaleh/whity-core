import React, { useState } from 'react';
import { act, fireEvent, render, screen, within } from '@testing-library/react';
import {
  DataTable,
  DATA_TABLE_SEARCH_DEBOUNCE_MS,
  type DataTableColumn,
} from '@amroksaleh/ui/data-table';

/**
 * Server-driven sorting and searching for the shared DataTable.
 *
 * WHAT THESE ARE GUARDING. The table has supported server PAGINATION for a
 * while, but sorted and filtered rows itself regardless. In server-pagination
 * mode that means `getSortedRowModel` sorts the twenty five rows it was handed
 * and the result is presented as a sorted list — page 2 then re-sorts a
 * different twenty five. Nothing throws and nothing looks broken, which is why
 * the document library gave up on sortable headers entirely and moved sort
 * into its own toolbar rather than ship it.
 *
 * So the assertions that matter here are the NEGATIVE ones: that the component
 * does not reorder and does not filter. A test that only checked "the callback
 * fired" would pass just as happily against the broken version, because the
 * broken version fires the callback too — and then also sorts the page.
 *
 * The last block pins the CLIENT behaviour, which twenty-six existing callers
 * depend on and none of them opted into anything to get.
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

/** Deliberately NOT in alphabetical order, so "unsorted" is distinguishable. */
const rows: Row[] = [
  { id: 1, name: 'Zoe', role: 'admin' },
  { id: 2, name: 'Adam', role: 'viewer' },
  { id: 3, name: 'Mona', role: 'editor' },
];

const getRowId = (row: Row) => String(row.id);

/** The first cell of every BODY row, top to bottom. Header rows have no `cell`. */
function nameColumn(): string[] {
  return screen
    .getAllByRole('row')
    .map((row) => within(row).queryAllByRole('cell')[0]?.textContent ?? null)
    .filter((text): text is string => text !== null);
}

function nameHeader(): HTMLElement {
  return screen.getByRole('columnheader', { name: /Name/ });
}

describe('DataTable server-side sorting', () => {
  it('reports the click and leaves the row order exactly as it was given', () => {
    const onSortingChange = jest.fn();

    // A REAL caller: it records the sort and re-renders with it, exactly as a
    // page holding this in state would, but keeps handing back the SAME rows —
    // standing in for a request that has not come back yet. A jest.fn() that
    // swallowed the callback would not do: `sortKey` would stay null, no sort
    // would ever be applied, and the assertion below would hold even with the
    // client sorter switched on. It would test nothing and look like it did.
    function Harness() {
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

    render(<Harness />);

    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);

    fireEvent.click(nameHeader());

    expect(onSortingChange).toHaveBeenCalledTimes(1);
    expect(onSortingChange).toHaveBeenCalledWith('name', 'asc');

    // THE ASSERTION THIS FILE EXISTS FOR. The caller now says "sorted by name
    // ascending" and the component is re-rendered with that state, but the
    // rows it holds are still the ones it was given. It must show them in that
    // order and wait. Sorting them here is sorting one page and calling it a
    // sorted list.
    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);
  });

  it('renders the caller\'s sort in the header rather than one of its own', () => {
    const { rerender } = render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: 'name', direction: 'desc', onSortingChange: jest.fn() }}
      />
    );

    // Descending per the caller — so the next click continues the cycle from
    // there rather than restarting at ascending.
    const onSortingChange = jest.fn();
    rerender(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: 'name', direction: 'desc', onSortingChange }}
      />
    );

    fireEvent.click(nameHeader());

    // asc -> desc -> none. Coming from desc, the third state is "no column",
    // which the server contract reads as "use your own default order".
    expect(onSortingChange).toHaveBeenCalledWith(null, 'asc');
  });

  it('walks asc -> desc when clicked from the ascending state', () => {
    const onSortingChange = jest.fn();

    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: 'name', direction: 'asc', onSortingChange }}
      />
    );

    fireEvent.click(nameHeader());

    expect(onSortingChange).toHaveBeenCalledWith('name', 'desc');
  });

  it('does not reorder even once the caller has a sort applied', () => {
    // The caller says "sorted by name ascending" but hands over rows that are
    // not. That is a lie about the data, and the component must repeat it
    // rather than quietly correct it — because correcting it locally is
    // precisely the bug: it would sort THIS PAGE and hide that the server's
    // ordering was never applied.
    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        sorting={{ sortKey: 'name', direction: 'asc', onSortingChange: jest.fn() }}
      />
    );

    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);
  });
});

describe('DataTable server-side search', () => {
  beforeEach(() => {
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.runOnlyPendingTimers();
    jest.useRealTimers();
  });

  function searchBox(): HTMLElement {
    return screen.getByPlaceholderText('Search…');
  }

  it('reports what was typed and filters nothing out locally', () => {
    const onSearchChange = jest.fn();

    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange, debounceMs: 0 }}
      />
    );

    fireEvent.change(searchBox(), { target: { value: 'Adam' } });

    expect(onSearchChange).toHaveBeenCalledWith('Adam');

    // Every row still on screen. A client-side global filter here would leave
    // one row and report "1 entry" — a search over the current page dressed up
    // as a search over the table.
    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);
  });

  it('renders the search box because `search` was passed, without enableGlobalFilter', () => {
    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange: jest.fn(), debounceMs: 0 }}
      />
    );

    expect(searchBox()).toBeInTheDocument();
  });

  it('debounces: many keystrokes become one call, carrying the final term', () => {
    const onSearchChange = jest.fn();

    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange }}
      />
    );

    fireEvent.change(searchBox(), { target: { value: 'A' } });
    fireEvent.change(searchBox(), { target: { value: 'Ad' } });
    fireEvent.change(searchBox(), { target: { value: 'Ada' } });

    // Nothing sent yet — this is the point of the debounce.
    expect(onSearchChange).not.toHaveBeenCalled();
    // ...but the box shows every character, immediately.
    expect(searchBox()).toHaveValue('Ada');

    act(() => {
      jest.advanceTimersByTime(DATA_TABLE_SEARCH_DEBOUNCE_MS);
    });

    expect(onSearchChange).toHaveBeenCalledTimes(1);
    expect(onSearchChange).toHaveBeenCalledWith('Ada');
  });

  it('honours a caller-supplied delay instead of the default', () => {
    const onSearchChange = jest.fn();

    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange, debounceMs: 1000 }}
      />
    );

    fireEvent.change(searchBox(), { target: { value: 'Mona' } });

    act(() => {
      jest.advanceTimersByTime(DATA_TABLE_SEARCH_DEBOUNCE_MS);
    });
    // The default would have fired by now; this table was told to wait longer.
    expect(onSearchChange).not.toHaveBeenCalled();

    act(() => {
      jest.advanceTimersByTime(1000 - DATA_TABLE_SEARCH_DEBOUNCE_MS);
    });
    expect(onSearchChange).toHaveBeenCalledWith('Mona');
  });

  it('keeps typing that happened while the request was in flight', () => {
    // The regression this guards: naively syncing the box to `value` on every
    // prop change means the echo of our own callback overwrites whatever was
    // typed in the meantime, and characters vanish as you type.
    function Harness() {
      const [applied, setApplied] = useState('');
      return (
        <DataTable<Row>
          ariaLabel="Users"
          columns={columns}
          data={rows}
          getRowId={getRowId}
          search={{ value: applied, onSearchChange: setApplied, debounceMs: 0 }}
        />
      );
    }

    render(<Harness />);

    fireEvent.change(searchBox(), { target: { value: 'Mo' } });
    expect(searchBox()).toHaveValue('Mo');

    fireEvent.change(searchBox(), { target: { value: 'Mon' } });
    expect(searchBox()).toHaveValue('Mon');
  });

  it('adopts an external change to the applied term', () => {
    // The other half of the same problem: a "clear search" control elsewhere on
    // the page sets `value` to '' and the box has to follow, or the reader is
    // left looking at a term that is no longer applied.
    const { rerender } = render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: 'Mona', onSearchChange: jest.fn(), debounceMs: 0 }}
      />
    );

    expect(searchBox()).toHaveValue('Mona');

    rerender(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange: jest.fn(), debounceMs: 0 }}
      />
    );

    expect(searchBox()).toHaveValue('');
  });

  it('does not fire a pending callback after the term was cleared externally', () => {
    const onSearchChange = jest.fn();

    const { rerender } = render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange }}
      />
    );

    fireEvent.change(searchBox(), { target: { value: 'Zo' } });

    rerender(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        search={{ value: 'something else', onSearchChange }}
      />
    );

    act(() => {
      jest.advanceTimersByTime(DATA_TABLE_SEARCH_DEBOUNCE_MS * 4);
    });

    // The in-flight 'Zo' was abandoned when the caller changed the term.
    expect(onSearchChange).not.toHaveBeenCalled();
    expect(searchBox()).toHaveValue('something else');
  });
});

describe('DataTable client behaviour is untouched when neither prop is passed', () => {
  it('still sorts its own rows when `sorting` is omitted', () => {
    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
      />
    );

    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);

    fireEvent.click(nameHeader());
    expect(nameColumn()).toEqual(['Adam', 'Mona', 'Zoe']);

    fireEvent.click(nameHeader());
    expect(nameColumn()).toEqual(['Zoe', 'Mona', 'Adam']);

    // Third click clears the sort and the original order comes back.
    fireEvent.click(nameHeader());
    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);
  });

  it('still filters its own rows when `search` is omitted', () => {
    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
        enableGlobalFilter
      />
    );

    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);

    fireEvent.change(screen.getByPlaceholderText('Search…'), {
      target: { value: 'Adam' },
    });

    expect(nameColumn()).toEqual(['Adam']);
  });

  it('keeps per-column filters working alongside SERVER search', () => {
    // `manualFiltering` would have been the obvious way to stop the component
    // filtering by the search term, and it would also have silently disabled
    // these — table-core has one flag for both. Roughly eighteen admin columns
    // rely on them, the users table included, which is the first screen due to
    // adopt server search.
    const filterable: DataTableColumn<Row>[] = [
      { accessorKey: 'name', header: 'Name', enableSorting: true },
      { accessorKey: 'role', header: 'Role', enableColumnFilter: true },
    ];

    render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={filterable}
        data={rows}
        getRowId={getRowId}
        search={{ value: '', onSearchChange: jest.fn(), debounceMs: 0 }}
      />
    );

    expect(nameColumn()).toEqual(['Zoe', 'Adam', 'Mona']);

    fireEvent.change(screen.getByLabelText('Filter Role'), {
      target: { value: 'editor' },
    });

    expect(nameColumn()).toEqual(['Mona']);
  });
});
