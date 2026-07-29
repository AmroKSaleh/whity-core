import { act, renderHook } from '@testing-library/react';

import { createAlwaysSyncedController, useSyncStatus } from '@amroksaleh/features/sync';
import type { SyncController, SyncStatus } from '@amroksaleh/features/sync';

/**
 * Tests for the shared sync contract (@amroksaleh/features/sync): the
 * always-synced controller online-only clients inject, and the useSyncStatus
 * hook. The load-bearing invariant is the referential-stability contract on
 * SyncController.getStatus() that keeps useSyncExternalStore from looping.
 */

/** A minimal in-test SyncController whose status can be pushed to subscribers. */
function makeFakeController(initial: SyncStatus): {
  controller: SyncController;
  push: (next: SyncStatus) => void;
} {
  let status = initial;
  const listeners = new Set<(s: SyncStatus) => void>();
  return {
    controller: {
      getStatus: () => status,
      subscribe: (cb) => {
        listeners.add(cb);
        return () => listeners.delete(cb);
      },
      syncNow: async () => {},
      resolveConflict: async () => {},
    },
    push: (next) => {
      status = next;
      listeners.forEach((l) => l());
    },
  };
}

describe('createAlwaysSyncedController', () => {
  it('reports fully-synced and returns a STABLE snapshot reference', () => {
    const controller = createAlwaysSyncedController();
    const a = controller.getStatus();
    const b = controller.getStatus();

    // Identity stability is the useSyncExternalStore-safety contract.
    expect(a).toBe(b);
    expect(a).toMatchObject({
      online: true,
      syncing: false,
      unsyncedCount: 0,
      conflicts: [],
      locked: false,
    });
    expect(a.lastSyncedAt).toBeNull();
  });

  it('has a no-op subscribe (callable unsubscribe) and resolving actions', async () => {
    const controller = createAlwaysSyncedController();
    const unsubscribe = controller.subscribe(() => {
      throw new Error('always-synced controller must never notify');
    });

    expect(typeof unsubscribe).toBe('function');
    expect(() => unsubscribe()).not.toThrow();
    await expect(controller.syncNow()).resolves.toBeUndefined();
    await expect(
      controller.resolveConflict({ conflictId: 1, fields: {} }),
    ).resolves.toBeUndefined();
  });
});

describe('useSyncStatus', () => {
  it('returns null when no controller is injected (online-only client)', () => {
    const { result } = renderHook(() => useSyncStatus(null));
    expect(result.current).toBeNull();
  });

  it('returns the current snapshot and re-renders on notify', () => {
    const base: SyncStatus = {
      online: true,
      syncing: false,
      unsyncedCount: 0,
      lastSyncedAt: null,
      conflicts: [],
    };
    const fake = makeFakeController(base);

    const { result } = renderHook(() => useSyncStatus(fake.controller));
    expect(result.current).toBe(base);

    const next: SyncStatus = { ...base, syncing: true, unsyncedCount: 3 };
    act(() => fake.push(next));

    expect(result.current).toBe(next);
    expect(result.current?.unsyncedCount).toBe(3);
  });

  it('surfaces conflicts and the locked flag from the controller', () => {
    const withConflict: SyncStatus = {
      online: false,
      syncing: false,
      unsyncedCount: 1,
      lastSyncedAt: '2026-07-29T00:00:00.000Z',
      conflicts: [
        {
          id: 42,
          resource: 'demo-catalog',
          title: 'Widget',
          fields: [{ field: 'name', mine: 'Mine', theirs: 'Theirs' }],
        },
      ],
      locked: true,
    };
    const fake = makeFakeController(withConflict);

    const { result } = renderHook(() => useSyncStatus(fake.controller));
    expect(result.current?.locked).toBe(true);
    expect(result.current?.conflicts).toHaveLength(1);
    expect(result.current?.conflicts[0].fields[0]).toMatchObject({
      field: 'name',
      mine: 'Mine',
      theirs: 'Theirs',
    });
  });
});
