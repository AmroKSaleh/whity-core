/**
 * WC-221: per-plugin VERSION badge classifier.
 *
 * `classifyPluginVersion` is a PURE function deriving a badge tier from a
 * plugin's semver string. Precedence (highest first):
 *   1. a `-alpha` prerelease tag  -> alpha
 *   2. a `-beta`  prerelease tag  -> beta   (tag wins over a 0.x major)
 *   3. major >= 1                 -> stable
 *   4. major === 0 (0.x.x)        -> prerelease
 *   5. missing / invalid / unparseable -> null (no badge)
 */

import { classifyPluginVersion } from '@/lib/plugin-version-badge';

describe('classifyPluginVersion', () => {
  it('classifies a 1.0.0 release as stable', () => {
    expect(classifyPluginVersion('1.0.0')).toEqual({
      tier: 'stable',
      label: 'Stable',
    });
  });

  it('classifies a 2.4.1 release as stable', () => {
    expect(classifyPluginVersion('2.4.1')).toEqual({
      tier: 'stable',
      label: 'Stable',
    });
  });

  it('classifies a 0.1.3 release as prerelease', () => {
    expect(classifyPluginVersion('0.1.3')).toEqual({
      tier: 'prerelease',
      label: 'Pre-release',
    });
  });

  it('classifies a 1.0.0-alpha tag as alpha', () => {
    expect(classifyPluginVersion('1.0.0-alpha')).toEqual({
      tier: 'alpha',
      label: 'Alpha',
    });
  });

  it('classifies a 2.0.0-beta.2 tag as beta', () => {
    expect(classifyPluginVersion('2.0.0-beta.2')).toEqual({
      tier: 'beta',
      label: 'Beta',
    });
  });

  it('lets a -beta tag win over a 0.x major (0.2.0-beta -> beta)', () => {
    expect(classifyPluginVersion('0.2.0-beta')).toEqual({
      tier: 'beta',
      label: 'Beta',
    });
  });

  it('lets an -alpha tag win over a 0.x major (0.9.0-alpha.1 -> alpha)', () => {
    expect(classifyPluginVersion('0.9.0-alpha.1')).toEqual({
      tier: 'alpha',
      label: 'Alpha',
    });
  });

  it('returns null for an empty string', () => {
    expect(classifyPluginVersion('')).toBeNull();
  });

  it('returns null for null', () => {
    expect(classifyPluginVersion(null)).toBeNull();
  });

  it('returns null for undefined', () => {
    expect(classifyPluginVersion(undefined)).toBeNull();
  });

  it('returns null for an unparseable / garbage version', () => {
    expect(classifyPluginVersion('garbage')).toBeNull();
    expect(classifyPluginVersion('v')).toBeNull();
    expect(classifyPluginVersion('..')).toBeNull();
  });

  it('tolerates a leading v prefix (v1.2.3 -> stable)', () => {
    expect(classifyPluginVersion('v1.2.3')).toEqual({
      tier: 'stable',
      label: 'Stable',
    });
  });
});
