import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DemoCatalogDetail } from '@amroksaleh/features/demo-catalog';
import type { DemoCatalogAdapter, DemoCatalogItem } from '@amroksaleh/features/demo-catalog';

/**
 * Component tests for the multi-client extraction pilot's DemoCatalogDetail
 * (@amroksaleh/features/demo-catalog). Covers both modes (`itemId: null` =
 * create, `itemId` set = edit), the not-found/load-error paths, and that
 * save()/cancel() route through the injected adapter and callbacks — never a
 * direct fetch.
 *
 * The `t = (key) => key` default was the source of a real infinite-render-loop
 * bug caught while manually verifying the SPA harness (an inline default
 * allocates a new function every render); these tests exercise the exact
 * effect this bug lived in (loading an existing item on mount) so a
 * regression here would fail immediately instead of only surfacing in a
 * browser.
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
  name: 'Existing item',
  description: 'Existing description',
  status: 'active',
  createdAt: '2026-01-01T00:00:00Z',
  updatedAt: '2026-01-01T00:00:00Z',
  ...over,
});

describe('DemoCatalogDetail', () => {
  it('renders an empty create form when itemId is null, without calling adapter.get', async () => {
    const adapter = fakeAdapter();

    render(
      <DemoCatalogDetail adapter={adapter} itemId={null} onSaved={jest.fn()} onCancel={jest.fn()} />
    );

    expect(await screen.findByLabelText('demoCatalog.detail.nameLabel')).toHaveValue('');
    expect(adapter.get).not.toHaveBeenCalled();
  });

  it('loads and populates the form for an existing item', async () => {
    const adapter = fakeAdapter({ get: jest.fn().mockResolvedValue(item()) });

    render(
      <DemoCatalogDetail adapter={adapter} itemId={1} onSaved={jest.fn()} onCancel={jest.fn()} />
    );

    expect(await screen.findByDisplayValue('Existing item')).toBeInTheDocument();
    expect(screen.getByDisplayValue('Existing description')).toBeInTheDocument();
    expect(adapter.get).toHaveBeenCalledWith(1);

    // Renders exactly once per mount — the identityTranslate fix keeps the
    // load effect from looping.
    expect(adapter.get).toHaveBeenCalledTimes(1);
  });

  it('renders a not-found error state when adapter.get resolves null', async () => {
    const adapter = fakeAdapter({ get: jest.fn().mockResolvedValue(null) });

    render(
      <DemoCatalogDetail adapter={adapter} itemId={999} onSaved={jest.fn()} onCancel={jest.fn()} />
    );

    expect(await screen.findByText('demoCatalog.detail.notFound')).toBeInTheDocument();
  });

  it('calls onCancel when Cancel is clicked', async () => {
    const onCancel = jest.fn();
    const user = userEvent.setup();

    render(
      <DemoCatalogDetail adapter={fakeAdapter()} itemId={null} onSaved={jest.fn()} onCancel={onCancel} />
    );

    await screen.findByLabelText('demoCatalog.detail.nameLabel');
    await user.click(screen.getByRole('button', { name: 'demoCatalog.detail.cancel' }));
    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it('creates a new item: submits without an id and calls onSaved with the result', async () => {
    const saved = item({ id: 42, name: 'Brand new' });
    const save = jest.fn().mockResolvedValue(saved);
    const onSaved = jest.fn();
    const user = userEvent.setup();

    render(
      <DemoCatalogDetail adapter={fakeAdapter({ save })} itemId={null} onSaved={onSaved} onCancel={jest.fn()} />
    );

    await user.type(await screen.findByLabelText('demoCatalog.detail.nameLabel'), 'Brand new');
    await user.click(screen.getByRole('button', { name: 'demoCatalog.detail.save' }));

    expect(save).toHaveBeenCalledWith({ name: 'Brand new', description: null, status: 'active' });
    await screen.findByText('demoCatalog.detail.save'); // form still there until onSaved resolves in caller
    expect(onSaved).toHaveBeenCalledWith(saved);
  });

  it('updates an existing item: submits with its id', async () => {
    const save = jest.fn().mockResolvedValue(item({ name: 'Renamed' }));
    const user = userEvent.setup();

    render(
      <DemoCatalogDetail
        adapter={fakeAdapter({ get: jest.fn().mockResolvedValue(item()), save })}
        itemId={1}
        onSaved={jest.fn()}
        onCancel={jest.fn()}
      />
    );

    const nameInput = await screen.findByDisplayValue('Existing item');
    await user.clear(nameInput);
    await user.type(nameInput, 'Renamed');
    await user.click(screen.getByRole('button', { name: 'demoCatalog.detail.save' }));

    expect(save).toHaveBeenCalledWith({ id: 1, name: 'Renamed', description: 'Existing description', status: 'active' });
  });

  it('shows a save error and does not call onSaved when adapter.save rejects', async () => {
    const onSaved = jest.fn();
    const save = jest.fn().mockRejectedValue(new Error('boom'));
    const user = userEvent.setup();

    render(
      <DemoCatalogDetail adapter={fakeAdapter({ save })} itemId={null} onSaved={onSaved} onCancel={jest.fn()} />
    );

    await user.type(await screen.findByLabelText('demoCatalog.detail.nameLabel'), 'x');
    await user.click(screen.getByRole('button', { name: 'demoCatalog.detail.save' }));

    expect(await screen.findByText('demoCatalog.detail.saveError')).toBeInTheDocument();
    expect(onSaved).not.toHaveBeenCalled();
  });
});
