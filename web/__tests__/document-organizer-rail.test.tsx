/**
 * #978: the document organizer's rail.
 *
 * The rail is where the whole design either holds or fails, so what is pinned
 * here is the THREE-WAY DISTINCTION between the ways a folder can show no
 * documents:
 *
 *  1. ABSENT — the server did not send it. Either this installation does not
 *     record the facts it reads, or the folder is simply not built yet: #947
 *     item 5's "awaiting me", "acted on by me" and "passed through my unit" are
 *     the second case, since item 3 supplied their facts but each folder still
 *     needs a server-side predicate and registration. Either way nothing is
 *     rendered for them, because an empty "Awaiting me" would state "nothing
 *     awaits you" — false, unfalsifiable from outside, and indistinguishable
 *     from having nothing to do.
 *  2. UNAVAILABLE TO THIS VIEWER — `available: false`. Rendered DISABLED with
 *     the reason visible, never hidden (#951: a hidden control makes "I have no
 *     unit", "the feature was removed" and "it is broken" identical).
 *  3. AN ORDINARY, ENABLED FOLDER.
 *
 * Plus the two things that make the rail server-driven rather than hardcoded:
 * a folder this build has never heard of still renders (with the server's
 * label), and a folder with a REQUIRED parameter is instantiated per collection
 * rather than offered bare.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { userEvent } from '@testing-library/user-event';
import { ViewRail } from '@/app/(protected)/admin/document-library/view-rail';
import type { DocumentCollection, DocumentSubstrate, DocumentView } from '@/app/(protected)/admin/document-library/types';

function view(overrides: Partial<DocumentView> & Pick<DocumentView, 'key'>): DocumentView {
  return {
    label: overrides.key,
    description: `The ${overrides.key} folder.`,
    group: 'derived',
    parameters: [],
    requires: [],
    available: true,
    unavailable_reason: null,
    ...overrides,
  };
}

const CORE_VIEWS: DocumentView[] = [
  view({ key: 'all', label: 'All documents' }),
  view({ key: 'created-by-me', label: 'Created by me' }),
  view({
    key: 'raised-by-my-unit',
    label: 'Raised by my unit',
    parameters: [{ name: 'ou_id', required: false }],
  }),
  view({ key: 'starred', label: 'Starred', group: 'personal' }),
  view({
    key: 'collection',
    label: 'Collection',
    group: 'personal',
    parameters: [{ name: 'collection_id', required: true }],
  }),
];

const COLLECTIONS: DocumentCollection[] = [
  {
    id: 5,
    tenant_id: 1,
    profile_id: 10,
    name: 'Q3 audit',
    system_key: null,
    created_at: '2026-08-01 10:00:00',
    item_count: 3,
  },
  {
    id: 6,
    tenant_id: 1,
    profile_id: 10,
    name: 'Starred',
    system_key: 'starred',
    created_at: '2026-08-01 10:00:00',
    item_count: 1,
  },
];

function renderRail(props: Partial<React.ComponentProps<typeof ViewRail>> = {}) {
  const onSelectView = jest.fn();
  const onSelectCollection = jest.fn();
  const onCreateCollection = jest.fn();

  render(
    <ViewRail
      views={CORE_VIEWS}
      collections={COLLECTIONS}
      unavailableSubstrates={[]}
      selectedViewKey="all"
      selectedCollectionId={null}
      onSelectView={onSelectView}
      onSelectCollection={onSelectCollection}
      onCreateCollection={onCreateCollection}
      {...props}
    />
  );

  return { onSelectView, onSelectCollection, onCreateCollection };
}

describe('the document organizer rail', () => {
  // ── 1. absent stays absent ────────────────────────────────────────────────

  it('renders nothing at all for a folder the server did not send', () => {
    renderRail();

    for (const absent of ['Awaiting me', 'Acted on by me', 'Passed through my unit']) {
      expect(screen.queryByText(absent)).not.toBeInTheDocument();
    }
    // Not even a disabled placeholder: a disabled control asserts the folder
    // exists, which is the claim being refused.
    expect(screen.getAllByRole('button').every((b) => !b.textContent?.includes('waiting'))).toBe(true);
  });

  // ── 2. unavailable-to-me is disabled WITH the reason ──────────────────────

  it('disables a folder this viewer cannot anchor and shows the reason', async () => {
    const reason = 'You do not belong to an organizational unit. Select one to use this folder.';
    const { onSelectView } = renderRail({
      views: CORE_VIEWS.map((v) =>
        v.key === 'raised-by-my-unit' ? { ...v, available: false, unavailable_reason: reason } : v
      ),
    });

    const button = screen.getByRole('button', { name: /Raised by my unit/ });
    expect(button).toBeDisabled();
    // Visible text, not only a title attribute: hover is touch-inaccessible and
    // would hide the very explanation the control exists to give.
    expect(screen.getByText(reason)).toBeInTheDocument();
    expect(button).toHaveAttribute('title', reason);

    await userEvent.click(button);
    expect(onSelectView).not.toHaveBeenCalled();
  });

  it('leaves every other folder enabled when one cannot be anchored', () => {
    renderRail({
      views: CORE_VIEWS.map((v) =>
        v.key === 'raised-by-my-unit' ? { ...v, available: false, unavailable_reason: 'no unit' } : v
      ),
    });

    expect(screen.getByRole('button', { name: /Created by me/ })).toBeEnabled();
    expect(screen.getByRole('button', { name: /All documents/ })).toBeEnabled();
  });

  // ── 3. ordinary folders ──────────────────────────────────────────────────

  it('selects a folder by key', async () => {
    const { onSelectView } = renderRail();

    await userEvent.click(screen.getByRole('button', { name: /Created by me/ }));

    expect(onSelectView).toHaveBeenCalledWith('created-by-me');
  });

  it('marks the selected folder as the current page for assistive technology', () => {
    renderRail({ selectedViewKey: 'created-by-me' });

    expect(screen.getByRole('button', { name: /Created by me/ })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: /All documents/ })).not.toHaveAttribute('aria-current');
  });

  // ── the rail is server-driven ────────────────────────────────────────────

  /**
   * The seam, from the client side. When #947 item 3 registers its folders they
   * appear here with no change to this build — rendered with the server's own
   * label, because a client cannot hold a translation for a key it has never
   * seen. A client-side label map would render a blank chip instead.
   */
  it('renders a folder this build has never heard of, using the server label', async () => {
    const { onSelectView } = renderRail({
      views: [...CORE_VIEWS, view({ key: 'awaiting-me', label: 'Awaiting me' })],
    });

    const button = screen.getByRole('button', { name: /Awaiting me/ });
    expect(button).toBeEnabled();

    await userEvent.click(button);
    expect(onSelectView).toHaveBeenCalledWith('awaiting-me');
  });

  /**
   * A folder with a REQUIRED parameter is a template, not an entry: it is
   * instantiated once per collection. Offering it bare would produce a folder
   * that 400s the moment it is clicked.
   */
  it('instantiates the parameterised collection folder per collection instead of offering it bare', async () => {
    const { onSelectCollection } = renderRail();

    // The template itself is not an entry...
    expect(screen.queryByRole('button', { name: /^Collection$/ })).not.toBeInTheDocument();
    // ...but the user's own pile is, with its filed count.
    const pile = screen.getByRole('button', { name: /Q3 audit/ });
    expect(pile).toBeInTheDocument();
    expect(pile).toHaveTextContent('3');

    await userEvent.click(pile);
    expect(onSelectCollection).toHaveBeenCalledWith(5);
  });

  /**
   * The starred collection is reachable through its own folder, so it is not
   * repeated as a pile — one affordance per thing, or a user has two entries
   * that look different and do the same.
   */
  it('does not repeat the starred collection as a pile', () => {
    renderRail();

    expect(screen.getAllByRole('button', { name: /Starred/ })).toHaveLength(1);
  });

  // ── the footnote explains absence without becoming a folder ──────────────

  it('states what this installation does not record, as prose rather than a folder', () => {
    const substrates: DocumentSubstrate[] = [
      {
        key: 'routing.engine',
        description:
          'Documents are routed: recipient rows say who a document is awaiting, and an append-only trail says who acted on it.',
        provenance: '#947 item 3 — not built yet, so the folders derived from it are absent rather than empty',
      },
    ];

    renderRail({ unavailableSubstrates: substrates });

    expect(screen.getByText(/Not recorded in this installation/)).toBeInTheDocument();
    expect(screen.getByText(substrates[0].description)).toBeInTheDocument();
    expect(screen.getByText(substrates[0].provenance!)).toBeInTheDocument();
    // Nothing in it is clickable, so it cannot be read as a folder that is
    // merely unavailable.
    expect(screen.queryByRole('button', { name: /routing/i })).not.toBeInTheDocument();
  });

  it('says nothing at all when this installation records everything the rail knows about', () => {
    renderRail({ unavailableSubstrates: [] });

    expect(screen.queryByText(/Not recorded in this installation/)).not.toBeInTheDocument();
  });

  it('opens the create-collection affordance', async () => {
    const { onCreateCollection } = renderRail();

    await userEvent.click(screen.getByRole('button', { name: /New collection/ }));

    expect(onCreateCollection).toHaveBeenCalled();
  });
});
