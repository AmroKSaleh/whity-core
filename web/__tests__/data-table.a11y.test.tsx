import React from 'react';
import { render, screen } from '@testing-library/react';
import { DataTable, type DataTableColumn } from '@amroksaleh/ui/data-table';

/**
 * The DataTable's accessible name, in every state that renders a `table`.
 *
 * This is a REGRESSION test for #967. `ariaLabel` exists so that a page with
 * two tables (the users list and its pending-invitations panel, both carrying
 * an Email column) exposes two tables a reader can tell apart. It was wired
 * into the loading-skeleton branch only, so the name disappeared the instant
 * the rows arrived — the state a reader actually reads was the one left
 * anonymous, and `getByRole('table', { name })` quietly became an assertion
 * about the skeleton that held only while the request was in flight. It passed
 * against a slow backend and failed against a fast one, which is why it
 * surfaced as an intermittent E2E failure on unrelated PRs.
 *
 * The LOADED assertions below are the ones that were failing; the loading ones
 * pin the original behaviour so a future refactor cannot trade one for the
 * other.
 */
interface Row {
  id: number;
  email: string;
}

const columns: DataTableColumn<Row>[] = [
  { accessorKey: 'email', header: 'Email' },
];

const rows: Row[] = [
  { id: 1, email: 'admin@example.com' },
  { id: 2, email: 'user@example.com' },
];

const getRowId = (row: Row) => String(row.id);

describe('DataTable accessible name (#967)', () => {
  it('names the table while loading AND once the rows have arrived', () => {
    const { rerender } = render(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={[]}
        getRowId={getRowId}
        isLoading
      />
    );

    expect(screen.getByRole('table', { name: 'Users' })).toBeInTheDocument();

    rerender(
      <DataTable<Row>
        ariaLabel="Users"
        columns={columns}
        data={rows}
        getRowId={getRowId}
      />
    );

    // The regression: this used to be an unnamed table.
    expect(screen.getByRole('table', { name: 'Users' })).toBeInTheDocument();
    expect(
      screen.getByRole('cell', { name: 'admin@example.com' })
    ).toBeInTheDocument();
  });

  it('lets a reader tell two LOADED tables on one page apart by name', () => {
    render(
      <>
        <DataTable<Row>
          ariaLabel="Users"
          columns={columns}
          data={rows}
          getRowId={getRowId}
        />
        <DataTable<Row>
          ariaLabel="Pending invitations"
          columns={columns}
          data={[{ id: 3, email: 'invited@example.com' }]}
          getRowId={getRowId}
        />
      </>
    );

    expect(screen.getAllByRole('table')).toHaveLength(2);
    expect(screen.getByRole('table', { name: 'Users' })).toBeInTheDocument();
    expect(
      screen.getByRole('table', { name: 'Pending invitations' })
    ).toBeInTheDocument();
  });

  it('omits the attribute entirely when no name is given', () => {
    const { container } = render(
      <DataTable<Row> columns={columns} data={rows} getRowId={getRowId} />
    );

    expect(container.querySelector('table')).not.toHaveAttribute('aria-label');
  });
});
