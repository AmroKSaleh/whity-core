import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ConflictResolver } from '@amroksaleh/features/sync';
import type { Conflict } from '@amroksaleh/features/sync';

const conflict: Conflict = {
  id: 42,
  title: 'Widget',
  fields: [
    { field: 'name', label: 'Name', mine: 'اسم عربي', theirs: 'English name' },
    { field: 'status', label: 'Status', mine: 'active', theirs: 'archived' },
  ],
};

describe('ConflictResolver', () => {
  it('defaults to "theirs" and resolves with a per-field choice map', async () => {
    const onResolve = jest.fn();
    render(<ConflictResolver conflict={conflict} onResolve={onResolve} />);

    await userEvent.click(screen.getByRole('button', { name: /resolve/i }));
    expect(onResolve).toHaveBeenCalledWith({
      conflictId: 42,
      fields: { name: { pick: 'theirs' }, status: { pick: 'theirs' } },
    });
  });

  it('lets a field pick mine and another a custom merged value', async () => {
    const onResolve = jest.fn();
    render(<ConflictResolver conflict={conflict} onResolve={onResolve} />);

    await userEvent.click(screen.getByRole('radio', { name: /Name.*mine/i }));
    await userEvent.click(screen.getByRole('radio', { name: /Status.*custom/i }));
    await userEvent.type(screen.getByRole('textbox', { name: /Status.*custom/i }), 'draft');

    await userEvent.click(screen.getByRole('button', { name: /resolve/i }));
    expect(onResolve).toHaveBeenCalledWith({
      conflictId: 42,
      fields: { name: { pick: 'mine' }, status: { pick: 'custom', value: 'draft' } },
    });
  });

  it('wraps user content in dir="auto" (bidi-safe for Arabic)', () => {
    render(<ConflictResolver conflict={conflict} onResolve={() => {}} />);
    expect(screen.getByText('اسم عربي').closest('[dir="auto"]')).not.toBeNull();
  });

  it('disables Resolve until a chosen custom field has a value', async () => {
    render(<ConflictResolver conflict={conflict} onResolve={() => {}} />);

    await userEvent.click(screen.getByRole('radio', { name: /Name.*custom/i }));
    expect(screen.getByRole('button', { name: /resolve/i })).toBeDisabled();

    await userEvent.type(screen.getByRole('textbox', { name: /Name.*custom/i }), 'merged');
    expect(screen.getByRole('button', { name: /resolve/i })).not.toBeDisabled();
  });
});
