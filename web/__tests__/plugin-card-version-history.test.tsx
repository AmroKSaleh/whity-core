/**
 * Plugin cards must not invent provenance (#756).
 *
 * `PluginItem.versions` and `PluginItem.permissions` are both OPTIONAL, and
 * both used to fall back to a hardcoded sample array rather than to nothing.
 * That is not placeholder text: it renders as a specific, plausible claim about
 * third-party software on the screen where someone decides whether to trust it
 * — version numbers the plugin never released, dates that are not its dates, a
 * changelog asserting a fix ("Fixed memory leak in background worker queue")
 * for work that was never done, and a permission list the plugin never
 * requested.
 *
 * The permission list is the sharper half. A changelog misinforms; a permission
 * list is the security decision itself, and inventing one both understates a
 * plugin that asks for more and slanders one that asks for nothing.
 *
 * These tests pin the honest behaviour in both directions: absent data renders
 * an explicit empty state and NONE of the old sample values, and supplied data
 * still renders in full — the fallback was the bug, not the feature.
 *
 * Lives in `web/__tests__` rather than beside the component because
 * `packages/ui` has no Jest project of its own; `web/jest.config.mjs` is the
 * only runner in the repo, and a test file it does not scan is worse than no
 * test file.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
  InstalledPluginCard,
  PluginStoreCard,
  type PluginItem,
} from '@amroksaleh/ui/plugin-card';

beforeAll(() => {
  // Radix dialogs/dropdowns need these; jsdom implements none of them.
  if (!Element.prototype.hasPointerCapture) Element.prototype.hasPointerCapture = () => false;
  if (!Element.prototype.setPointerCapture) Element.prototype.setPointerCapture = () => {};
  if (!Element.prototype.releasePointerCapture) Element.prototype.releasePointerCapture = () => {};
  if (!Element.prototype.scrollIntoView) Element.prototype.scrollIntoView = () => {};
});

/**
 * The exact strings the component used to invent. Asserting on these rather
 * than on "no version rows" is deliberate: a future refactor could reintroduce
 * a different fabricated set and still satisfy a shape-only assertion, but
 * these are the specific claims that were being made about real software.
 */
const FABRICATED_VERSIONS = ['2.3.1', '2.2.0'];
const FABRICATED_CHANGELOG = /memory leak in background worker queue/i;
const FABRICATED_PERMISSIONS = [
  'storage:read-write',
  'network:outbound-http',
  'events:subscribe',
];

/** A plugin carrying no history and no declared permissions — the default shape. */
function barePlugin(overrides: Partial<PluginItem> = {}): PluginItem {
  return {
    id: 'acme-widgets',
    name: 'Acme Widgets',
    author: 'Acme',
    version: '1.0.0',
    description: 'Does widget things.',
    category: 'Utilities',
    rating: 4,
    reviewCount: 3,
    ...overrides,
  };
}

/** Open the details modal from a store card and switch to a tab. */
async function openDetailsTab(tabName: RegExp) {
  await userEvent.click(screen.getByRole('button', { name: /more info/i }));
  await userEvent.click(await screen.findByRole('tab', { name: tabName }));
}

describe('PluginDetailsModal version history', () => {
  it('shows an empty state instead of inventing releases', async () => {
    render(<PluginStoreCard plugin={barePlugin()} />);
    await openDetailsTab(/versions/i);

    expect(await screen.findByText(/no version history available/i)).toBeInTheDocument();

    for (const version of FABRICATED_VERSIONS) {
      expect(screen.queryByText(new RegExp(`Version ${version}`, 'i'))).not.toBeInTheDocument();
    }
    expect(screen.queryByText(FABRICATED_CHANGELOG)).not.toBeInTheDocument();
  });

  it('still renders a real version history when one is supplied', async () => {
    render(
      <PluginStoreCard
        plugin={barePlugin({
          versions: [
            { version: '1.0.0', releasedAt: '2026-08-01', changelog: 'First release.' },
          ],
        })}
      />
    );
    await openDetailsTab(/versions/i);

    expect(await screen.findByText(/version 1\.0\.0/i)).toBeInTheDocument();
    expect(screen.getByText('First release.')).toBeInTheDocument();
    expect(screen.queryByText(/no version history available/i)).not.toBeInTheDocument();
  });
});

describe('PluginDetailsModal permissions', () => {
  it('does not invent permissions the plugin never requested', async () => {
    render(<PluginStoreCard plugin={barePlugin()} />);
    await openDetailsTab(/permissions/i);

    expect(
      await screen.findByText(/does not request any special permissions/i)
    ).toBeInTheDocument();

    for (const permission of FABRICATED_PERMISSIONS) {
      expect(screen.queryByText(permission)).not.toBeInTheDocument();
    }
    // The lead-in promises a list; with nothing to list it must not appear.
    expect(
      screen.queryByText(/requests the following system permissions/i)
    ).not.toBeInTheDocument();
  });

  it('still renders declared permissions', async () => {
    render(<PluginStoreCard plugin={barePlugin({ permissions: ['tags:manage'] })} />);
    await openDetailsTab(/permissions/i);

    expect(await screen.findByText('tags:manage')).toBeInTheDocument();
    expect(screen.getByText(/requests the following system permissions/i)).toBeInTheDocument();
    expect(
      screen.queryByText(/does not request any special permissions/i)
    ).not.toBeInTheDocument();
  });
});

describe('InstalledPluginCard rollback affordance', () => {
  it('is absent when the plugin has no earlier releases', () => {
    render(<InstalledPluginCard plugin={barePlugin({ state: 'active' })} />);

    // Offering rollback to a version that was never published is an action the
    // host cannot honour — the control must not exist, not merely fail on use.
    expect(screen.queryByRole('button', { name: /rollback/i })).not.toBeInTheDocument();
  });

  it('offers rollback to genuinely earlier releases', () => {
    render(
      <InstalledPluginCard
        plugin={barePlugin({
          state: 'active',
          version: '2.0.0',
          versions: [
            { version: '2.0.0', releasedAt: 'Current', isCurrent: true },
            { version: '1.9.0', releasedAt: '2026-07-01' },
          ],
        })}
      />
    );

    expect(screen.getByRole('button', { name: /rollback/i })).toBeInTheDocument();
  });
});
