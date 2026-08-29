/**
 * A declared key must say what the code actually renders.
 *
 * `@i18n-keys` exists for keys a scanner cannot read — here, the SSO failure
 * map reached as `t(entry.key, entry.fallback)`. That makes the declaration the
 * one place a translator's English comes from, and the one place nothing checks
 * automatically: change a fallback in the map, forget the comment, and the
 * screen renders one sentence while every translator works from another. Both
 * look correct in isolation and the difference only ever surfaces in a language
 * nobody on the team reads.
 *
 * So the loop is closed here instead: the committed catalogue (which the
 * extractor built FROM the declaration) is compared against the map the code
 * actually uses. This is the pattern every future conversion copies, so it had
 * better be trustworthy.
 */

import { readFileSync } from 'node:fs';
import path from 'node:path';

import { SSO_ERROR_KEYS } from '@/app/login/sso-error-keys';

const CATALOGUE = path.join(__dirname, '..', '..', 'database', 'i18n', 'auth.json');

describe('declared SSO keys match the catalogue', () => {
  const catalogue = JSON.parse(readFileSync(CATALOGUE, 'utf8')) as {
    keys: Record<string, string>;
  };

  it.each(Object.entries(SSO_ERROR_KEYS))(
    'sso_error=%s renders the English the catalogue holds',
    (_reason, entry) => {
      expect(catalogue.keys[entry.key]).toBe(entry.fallback);
    }
  );

  it('declares every key the map can reach', () => {
    const declared = Object.keys(catalogue.keys).filter((key) => key.startsWith('sso.error.'));
    const used = Object.values(SSO_ERROR_KEYS).map((entry) => entry.key);

    expect(declared.sort()).toEqual([...used].sort());
  });
});
