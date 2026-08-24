import { collectBlockIds } from '@amroksaleh/ui/documents/blocks';
import {
  describeAudience,
  needsPublish,
  placementLabel,
  scopeLabel,
} from '@/app/(protected)/admin/document-templates/audience';
import type { GovernedRow } from '@/app/(protected)/admin/document-templates/types';

/**
 * The pure half of the templates-and-blocks governance screen.
 *
 * These assert on OUTCOMES a broken feature could not produce. Two habits are
 * deliberately avoided:
 *
 *  - asserting that a label renders. A label renders over a failed fetch just as
 *    happily as over a successful one, so "the word Visibility is on screen"
 *    proves nothing about visibility.
 *  - asserting the intent rather than the result. Every case below fixes the
 *    three governance columns and checks the SENTENCE the screen shows for them,
 *    which is the only thing a reader acts on.
 *
 * `t` is the real fallback contract — `(key, fallback, vars) => interpolated
 * fallback` — so the strings under test are the strings that ship untranslated,
 * and a placeholder the code forgets to pass shows up as a literal `{unit}` in
 * the expectation rather than passing silently.
 */
const t = ((_key: string, fallback?: string, vars?: Record<string, string | number>): string => {
  let out = fallback ?? _key;
  for (const [name, value] of Object.entries(vars ?? {})) {
    out = out.split(`{${name}}`).join(String(value));
  }
  return out;
}) as Parameters<typeof describeAudience>[2];

const row = (over: Partial<GovernedRow> = {}): GovernedRow => ({
  id: 1,
  name: 'Row',
  scope: 'tenant',
  required_permission: null,
  owner_ou_id: null,
  is_system: false,
  created_by: 10,
  created_at: '2026-01-01T00:00:00Z',
  updated_at: '2026-01-01T00:00:00Z',
  ...over,
});

describe('describeAudience — the four branches DocumentAccessPolicy takes', () => {
  it('says "only you" for a personal row you created, and never mentions its placement', () => {
    const sentence = describeAudience(
      row({ scope: 'personal', created_by: 10, owner_ou_id: 77 }),
      { ouName: 'Civil Engineering', viewerProfileId: 10 },
      t
    );

    expect(sentence).toContain('Only you');
    // Placement is not consulted for a personal row. Naming the unit would be a
    // statement the dialog then contradicts.
    expect(sentence).not.toContain('Civil Engineering');
  });

  it('does not claim "only you" for a personal row somebody else created', () => {
    const sentence = describeAudience(
      row({ scope: 'personal', created_by: 99 }),
      { ouName: null, viewerProfileId: 10 },
      t
    );

    expect(sentence).not.toContain('Only you');
    expect(sentence).toContain('whoever created it');
  });

  it('names the unit for a placed tenant row, and says reach runs downward', () => {
    const sentence = describeAudience(
      row({ scope: 'tenant', owner_ou_id: 2 }),
      { ouName: 'Faculty of Engineering', viewerProfileId: 10 },
      t
    );

    expect(sentence).toContain('Faculty of Engineering');
    expect(sentence).toContain('below it');
    expect(sentence).toContain('No permission is required');
  });

  it('states BOTH predicates for a placed, permission-tagged row', () => {
    const sentence = describeAudience(
      row({ scope: 'tenant', owner_ou_id: 2, required_permission: 'documents:publish' }),
      { ouName: 'Faculty of Engineering', viewerProfileId: 10 },
      t
    );

    // A sentence naming only one of the two predicates is the misleading half:
    // both must pass for the row to be visible.
    expect(sentence).toContain('Faculty of Engineering');
    expect(sentence).toContain('documents:publish');
  });

  it('says an unplaced shared row reaches the whole tenant, not "unknown"', () => {
    const sentence = describeAudience(
      row({ scope: 'tenant', owner_ou_id: null }),
      { ouName: null, viewerProfileId: 10 },
      t
    );

    expect(sentence).toContain('everyone in the tenant');
  });

  it('says a system row carries no permission gate even when a tag is set', () => {
    // The policy's system branch skips passesRequiredPermission entirely, so a
    // sentence promising the tag still gates would be false.
    const sentence = describeAudience(
      row({ scope: 'system', required_permission: 'documents:publish' }),
      { ouName: null, viewerProfileId: 10 },
      t
    );

    expect(sentence).toContain('no permission gate');
    expect(sentence).not.toContain('documents:publish');
  });

  it('says NOBODY for a scope the server does not recognise', () => {
    // The policy's default branch is `false` — the row is hidden from everyone.
    // Rendering a friendly label for that would describe an audience that
    // cannot exist.
    const sentence = describeAudience(
      row({ scope: 'weird' as GovernedRow['scope'] }),
      { ouName: null, viewerProfileId: 10 },
      t
    );

    expect(sentence).toContain('Nobody');
  });
});

describe('placementLabel', () => {
  it('reports an unfiled row as unfiled rather than unknown', () => {
    expect(placementLabel({ owner_ou_id: null }, { ouName: null, viewerProfileId: 1 }, t)).toBe(
      'Not filed at a unit'
    );
  });

  it('falls back to the bare id when the unit list could not be read', () => {
    // `ous:read` is not implied by this page's own gate, so this is an ordinary
    // state. `#4` is honest; a guessed name would not be.
    expect(placementLabel({ owner_ou_id: 4 }, { ouName: null, viewerProfileId: 1 }, t)).toBe('#4');
  });
});

describe('scopeLabel', () => {
  it('does not dress up an unrecognised scope as a normal tier', () => {
    expect(scopeLabel('personal', t)).toBe('Personal');
    expect(scopeLabel('nonsense' as never, t)).toBe('Unrecognised');
  });
});

describe('needsPublish — mirrors DocumentAccessPolicy::needsPublish', () => {
  it('is false only for a plain, unplaced, untagged personal row', () => {
    expect(needsPublish('personal', null, null)).toBe(false);
  });

  it('is true for any shared scope', () => {
    expect(needsPublish('tenant', null, null)).toBe(true);
    expect(needsPublish('global', null, null)).toBe(true);
    expect(needsPublish('system', null, null)).toBe(true);
  });

  it('is true for a permission tag on any scope', () => {
    expect(needsPublish('personal', 'documents:publish', null)).toBe(true);
  });

  it('is true for a PLACEMENT even on a personal row — the non-obvious rule', () => {
    // The server counts filing a row at a unit as publishing even though a
    // personal row is creator-only and the placement changes nothing anybody can
    // observe. A client that missed this would offer a field whose submit 403s.
    expect(needsPublish('personal', null, 2)).toBe(true);
  });

  it('treats an empty-string tag as no tag, like the server does', () => {
    expect(needsPublish('personal', '', null)).toBe(false);
  });
});

describe('collectBlockIds — parity with PHP BlockReferenceScanner', () => {
  it('finds pointers at any depth, under any key, and de-duplicates them', () => {
    const ids = collectBlockIds({
      version: 2,
      pages: [
        {
          id: 'p1',
          elements: [
            { id: 'e1', type: 'text', text: 'hi' },
            { id: 'e2', type: 'blockInstance', blockId: '4' },
            // Nested somewhere the pages/elements shape does not predict. The PHP
            // scanner deliberately does not assume that shape, and neither does
            // this, so both stay correct across template-schema changes.
            { id: 'e3', type: 'group', children: [{ id: 'e4', type: 'blockInstance', blockId: '9' }] },
          ],
        },
        { id: 'p2', elements: [{ id: 'e5', type: 'blockInstance', blockId: '4' }] },
      ],
    });

    expect(ids).toEqual(['4', '9']);
  });

  it('stringifies a numeric blockId, because the two sides disagree about the type', () => {
    // The client's `blockId` is a string; the backend id is an integer. The
    // scanner is where they meet, and a mismatch here would make every count
    // zero while looking like it worked.
    expect(collectBlockIds({ type: 'blockInstance', blockId: 7 })).toEqual(['7']);
  });

  it('ignores a blockInstance-shaped node with no blockId, and non-trees', () => {
    expect(collectBlockIds({ type: 'blockInstance' })).toEqual([]);
    expect(collectBlockIds(null)).toEqual([]);
    expect(collectBlockIds('not a tree')).toEqual([]);
    expect(collectBlockIds([])).toEqual([]);
  });

  it('does not treat a type it does not know as a reference', () => {
    expect(collectBlockIds({ type: 'image', blockId: '4' })).toEqual([]);
  });
});
