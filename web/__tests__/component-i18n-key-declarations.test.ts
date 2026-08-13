/**
 * A declared key must say what the code actually renders.
 *
 * `@i18n-keys` exists for keys a scanner cannot read — a lookup table reached
 * as `t(entry.key, entry.fallback)`. That makes the declaration the one place a
 * translator's English comes from, and the one place nothing checks
 * automatically: change a fallback in the table, forget the comment, and the
 * screen renders one sentence while every translator works from another. Both
 * look correct in isolation, and the difference only ever surfaces in a
 * language nobody on the team reads.
 *
 * So the loop is closed here, exactly as `login-sso-key-declaration.test.ts`
 * closes it for the sign-in screen's SSO failure map: the committed catalogue
 * (which the extractor built FROM the declaration) is compared against the
 * table the code actually uses. Every shared component that declares keys is
 * listed below — a new declaration with no row here is a new blind spot.
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';

import { LEVEL_META } from '@/components/PasswordStrengthIndicator';
import { TABS as APPROVAL_GATING_TABS } from '@/components/admin/approval-gating-tabs';
import { ASSET_META } from '@/components/branding-settings';

const CATALOGUE_DIR = path.join(__dirname, '..', '..', 'database', 'i18n');

function catalogueKeys(domain: string): Record<string, string> {
  const file = path.join(CATALOGUE_DIR, `${domain}.json`);
  return (JSON.parse(readFileSync(file, 'utf8')) as { keys: Record<string, string> }).keys;
}

/**
 * Every `key`/`label`-style pair the components reach through a lookup, with
 * the domain its declaration names and the prefix that declaration owns. The
 * prefix is what makes "declares every key the table can reach" meaningful:
 * without it, a key deleted from the table would simply vanish from both sides.
 */
const DECLARATIONS: {
  name: string;
  domain: string;
  prefix: string;
  entries: { key: string; english: string }[];
}[] = [
  {
    name: 'PasswordStrengthIndicator strength levels',
    domain: 'auth',
    prefix: 'password.strength.level.',
    entries: Object.values(LEVEL_META).map((meta) => ({ key: meta.key, english: meta.label })),
  },
  {
    name: 'ApprovalGatingTabs tab labels',
    domain: 'admin',
    prefix: 'approvalGating.tab.',
    entries: APPROVAL_GATING_TABS.map((tab) => ({ key: tab.key, english: tab.label })),
  },
  {
    name: 'BrandingSettings asset labels',
    domain: 'admin',
    prefix: 'branding.asset.',
    entries: ASSET_META.flatMap((meta) => [
      { key: meta.labelKey, english: meta.label },
      { key: meta.descriptionKey, english: meta.description },
    ]),
  },
];

describe.each(DECLARATIONS)('$name', ({ domain, prefix, entries }) => {
  const keys = catalogueKeys(domain);

  it.each(entries)('$key renders the English the catalogue holds', ({ key, english }) => {
    expect(keys[key]).toBe(english);
  });

  it('declares every key the table can reach, and no more', () => {
    const declared = Object.keys(keys).filter((key) => key.startsWith(prefix));
    const used = entries.map((entry) => entry.key);

    expect(declared.sort()).toEqual([...used].sort());
  });
});

/**
 * `password.strength.announcement` is a normal literal call site, not part of
 * the lookup — which is exactly why the levels live one segment deeper. If a
 * future edit flattened them back to `password.strength.*`, the announcement
 * would be swept into the prefix check above and that check would stop meaning
 * what it says. This asserts the separation directly.
 */
it('keeps the strength announcement outside the declared-lookup prefix', () => {
  const keys = catalogueKeys('auth');

  expect(keys['password.strength.announcement']).toBe('Password strength: {level}');
  expect('password.strength.announcement'.startsWith('password.strength.level.')).toBe(false);
});
