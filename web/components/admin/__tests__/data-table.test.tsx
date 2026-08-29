/**
 * The admin DataTable adapter — real tests, where a file named like tests used
 * to sit.
 *
 * `web/components/admin/data-table.test.tsx` existed since #353 and contained
 * three exported React examples and zero `it()` blocks. It was outside any
 * `__tests__` directory, so Jest's `testMatch` never collected it; had it been
 * collected it would have FAILED ("Your test suite must contain at least one
 * test"). Nothing imported it. Its effect was to occupy the name under which
 * this component's tests would be looked for, so the component read as tested
 * and had no coverage at all. The examples it held are the Storybook stories
 * in `packages/ui/src/data-table.stories.tsx`, which CI builds.
 *
 * WHAT IS WORTH TESTING HERE. The implementation lives in `@amroksaleh/ui`;
 * this file is an adapter, and adapters fail at their seams. It is also a
 * PUBLISHED registry item (`admin-data-table`, baked verbatim into
 * `web/public/r/data-table.json`), so its exported shape — `Column<T>`,
 * `DataTableProps<T>` — is a contract with downstream consumers who installed
 * this source into their own project. These assertions are about the mapping
 * it performs and the defaults it injects, not about TanStack Table.
 */
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DataTable, type Column } from '../data-table';

interface User {
  id: string;
  name: string;
  email: string;
}

const columns: Column<User>[] = [
  { key: 'name', label: 'Name', sortable: true },
  { key: 'email', label: 'Email' },
];

const users: User[] = [
  { id: '1', name: 'Carol White', email: 'carol@example.com' },
  { id: '2', name: 'Alice Johnson', email: 'alice@example.com' },
];

/** Row cells, header row excluded, in render order. */
function bodyRowText(): string[][] {
  const [, ...rows] = screen.getAllByRole('row');
  return rows.map((row) =>
    within(row)
      .getAllByRole('cell')
      .map((cell) => cell.textContent ?? '')
  );
}

describe('admin DataTable adapter', () => {
  it('maps each column label to a header and each key to that column cell', () => {
    render(<DataTable<User> columns={columns} data={users} />);

    expect(screen.getByRole('columnheader', { name: /Name/ })).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: /Email/ })).toBeInTheDocument();

    // `key` selects the value, `label` names the column — the whole job of the
    // Column<T> -> DataTableColumn<T> mapping.
    expect(bodyRowText()).toEqual([
      ['Carol White', 'carol@example.com'],
      ['Alice Johnson', 'alice@example.com'],
    ]);
  });

  it('renders rowActions once per row', () => {
    render(
      <DataTable<User>
        columns={columns}
        data={users}
        rowActions={(user) => <button type="button">Edit {user.name}</button>}
      />
    );

    expect(screen.getByRole('button', { name: 'Edit Carol White' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Edit Alice Johnson' })).toBeInTheDocument();
  });

  it('sorts on a sortable column and leaves an unsortable one inert', async () => {
    const user = userEvent.setup();
    render(<DataTable<User> columns={columns} data={users} />);

    // `sortable: true` -> enableSorting: true.
    await user.click(screen.getByRole('columnheader', { name: /Name/ }));
    expect(bodyRowText().map((cells) => cells[0])).toEqual(['Alice Johnson', 'Carol White']);

    // `sortable` omitted -> the shared table's `enableSorting: column.enableSorting ?? false`,
    // so this header is not a sort control and the order set above must hold.
    await user.click(screen.getByRole('columnheader', { name: /Email/ }));
    expect(bodyRowText().map((cells) => cells[0])).toEqual(['Alice Johnson', 'Carol White']);
  });

  describe('the empty state', () => {
    it('falls back to a generic title when the caller supplies none', () => {
      render(<DataTable<User> columns={columns} data={[]} />);

      expect(screen.getByText('No data available')).toBeInTheDocument();
      // No table at all when there are no rows — not an empty table with headers.
      expect(screen.queryByRole('table')).not.toBeInTheDocument();
    });

    it("lets the caller's title win, which is how this component gets translated", () => {
      // This adapter takes its copy through props on purpose: it is published
      // as registry source, so it cannot reach for the app's `t()`. A caller
      // passing a translated title MUST override the English default, or every
      // empty table in an Arabic tenant reads "No data available".
      render(
        <DataTable<User> columns={columns} data={[]} emptyState={{ title: 'لا توجد بيانات' }} />
      );

      expect(screen.getByText('لا توجد بيانات')).toBeInTheDocument();
      expect(screen.queryByText('No data available')).not.toBeInTheDocument();
    });

    it('keeps the default title when the caller customises only the description', () => {
      // The adapter spreads `{ title: 'No data available', ...emptyState }`, so
      // a partial emptyState must not blank out the title — an empty table
      // showing a description under no heading is the "table that lacks
      // information" failure.
      render(
        <DataTable<User>
          columns={columns}
          data={[]}
          emptyState={{ description: 'Invite someone to get started.' }}
        />
      );

      expect(screen.getByText('No data available')).toBeInTheDocument();
      expect(screen.getByText('Invite someone to get started.')).toBeInTheDocument();
    });
  });

  it('shows a busy placeholder while loading rather than claiming there is no data', () => {
    // The distinction that matters to a reader of the screen: "still loading"
    // and "genuinely empty" must not look alike. The shared table marks the
    // placeholder `aria-busy`; see the comment at its `isLoading` branch for
    // the incident (#1006) that established this.
    render(<DataTable<User> columns={columns} data={[]} isLoading />);

    expect(screen.getByRole('table')).toHaveAttribute('aria-busy', 'true');
    expect(screen.queryByText('No data available')).not.toBeInTheDocument();
  });
});
