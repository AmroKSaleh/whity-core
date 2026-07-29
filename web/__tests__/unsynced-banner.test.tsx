import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { UnsyncedBanner } from '@amroksaleh/features/sync';
import type { SyncStatus } from '@amroksaleh/features/sync';

const base: SyncStatus = {
  online: true,
  syncing: false,
  unsyncedCount: 0,
  lastSyncedAt: null,
  conflicts: [],
  locked: false,
};

describe('UnsyncedBanner', () => {
  it('renders nothing when fully synced', () => {
    const { container } = render(<UnsyncedBanner status={base} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('shows the unsynced count and a Sync now action', async () => {
    const onSyncNow = jest.fn();
    render(<UnsyncedBanner status={{ ...base, unsyncedCount: 3 }} onSyncNow={onSyncNow} />);

    expect(screen.getByText(/3/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /sync\.banner\.syncNow/i }));
    expect(onSyncNow).toHaveBeenCalledTimes(1);
  });

  it('reports offline when not online', () => {
    render(<UnsyncedBanner status={{ ...base, online: false, unsyncedCount: 2 }} />);
    expect(screen.getByText(/offline/i)).toBeInTheDocument();
  });

  it('surfaces conflicts with a review action', async () => {
    const onReview = jest.fn();
    const status: SyncStatus = {
      ...base,
      conflicts: [{ id: 1, fields: [{ field: 'name', mine: 'a', theirs: 'b' }] }],
    };
    render(<UnsyncedBanner status={status} onReviewConflicts={onReview} />);

    await userEvent.click(screen.getByRole('button', { name: /reviewConflicts/i }));
    expect(onReview).toHaveBeenCalledTimes(1);
  });

  it('routes copy through the injected translator', () => {
    render(<UnsyncedBanner status={{ ...base, unsyncedCount: 1 }} onSyncNow={() => {}} t={(k) => `T:${k}`} />);
    expect(screen.getByRole('button', { name: 'T:sync.banner.syncNow' })).toBeInTheDocument();
  });
});
