import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DemoCatalogList } from '@amroksaleh/features/demo-catalog';
import type { DemoCatalogAdapter, DemoCatalogItem } from '@amroksaleh/features/demo-catalog';

/**
 * Component tests for the multi-client extraction pilot's DemoCatalogList
 * (@amroksaleh/features/demo-catalog). Verifies the component's loading/
 * empty/error/populated states and that it never fetches directly — every
 * assertion here drives a hand-rolled fake `DemoCatalogAdapter`, proving the
 * component's data-source-agnostic contract from the consumer's side.
 */

function fakeAdapter(overrides: Partial<DemoCatalogAdapter> = {}): DemoCatalogAdapter {
  return {
    list: jest.fn().mockResolvedValue([]),
    get: jest.fn().mockResolvedValue(null),
    save: jest.fn(),
    ...overrides,
  };
}

const item = (over: Partial<DemoCatalogItem> = {}): DemoCatalogItem => ({
  id: 1,
  name: 'Sample item',
  description: 'A description',
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  ...over,
});

describe('DemoCatalogList', () => {
  it('renders a busy skeleton while the adapter list() call is pending', () => {
    const adapter = fakeAdapter({ list: jest.fn(() => new Promise(() => {})) });

    const { container } = render(
      <DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />
    );

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull();
  });

  it('renders an empty state when the adapter resolves an empty list', async () => {
    const adapter = fakeAdapter({ list: jest.fn().mockResolvedValue([]) });

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />);

    expect(await screen.findByText('demoCatalog.list.emptyTitle')).toBeInTheDocument();
  });

  it('renders an error state and retries via the adapter when list() rejects', async () => {
    const list = jest.fn().mockRejectedValueOnce(new Error('network down')).mockResolvedValueOnce([item()]);
    const adapter = fakeAdapter({ list });
    const user = userEvent.setup();

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} />);

    expect(await screen.findByText('demoCatalog.list.errorTitle')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'demoCatalog.list.retry' }));

    expect(await screen.findByText('Sample item')).toBeInTheDocument();
    expect(list).toHaveBeenCalledTimes(2);
  });

  it('renders items with name/description/status and calls onSelect on click', async () => {
    const onSelect = jest.fn();
    const adapter = fakeAdapter({
      list: jest.fn().mockResolvedValue([
        item({ id: 1, name: 'Active one', status: 'active' }),
        item({ id: 2, name: 'Archived one', status: 'archived', description: null }),
      ]),
    });
    const user = userEvent.setup();

    render(<DemoCatalogList adapter={adapter} onSelect={onSelect} onCreate={jest.fn()} />);

    expect(await screen.findByText('Active one')).toBeInTheDocument();
    expect(screen.getByText('Archived one')).toBeInTheDocument();
    expect(screen.getByText('demoCatalog.status.active')).toBeInTheDocument();
    expect(screen.getByText('demoCatalog.status.archived')).toBeInTheDocument();

    await user.click(screen.getByText('Active one'));
    expect(onSelect).toHaveBeenCalledWith(1);
  });

  it('calls onCreate when the create button is clicked', async () => {
    const onCreate = jest.fn();
    const adapter = fakeAdapter({ list: jest.fn().mockResolvedValue([]) });
    const user = userEvent.setup();

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={onCreate} />);

    await waitFor(() => expect(adapter.list).toHaveBeenCalled());
    await user.click(screen.getByRole('button', { name: /demoCatalog.list.create/ }));
    expect(onCreate).toHaveBeenCalledTimes(1);
  });

  it('translates labels through an injected t()', async () => {
    const adapter = fakeAdapter({ list: jest.fn().mockResolvedValue([]) });
    const t = (key: string) => `translated:${key}`;

    render(<DemoCatalogList adapter={adapter} onSelect={jest.fn()} onCreate={jest.fn()} t={t} />);

    expect(await screen.findByText('translated:demoCatalog.list.emptyTitle')).toBeInTheDocument();
  });
});
