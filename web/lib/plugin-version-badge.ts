/**
 * Plugin version-badge classifier (WC-221).
 *
 * A PURE, dependency-free function that maps a plugin's semver string to a
 * badge tier the plugins console renders next to each plugin. The classifier
 * is deliberately lenient about exact semver compliance (a plugin author may
 * ship `v1.2.3` or `1.0.0-alpha`) but returns `null` for anything it cannot
 * confidently parse so the console renders NO badge rather than a misleading
 * one.
 */

/** The badge tiers, in descending "maturity"/precedence-relevant order. */
export type PluginVersionTier = 'alpha' | 'beta' | 'prerelease' | 'stable';

/** The classification result: a tier plus the human-readable badge label. */
export interface PluginVersionBadge {
  tier: PluginVersionTier;
  label: string;
}

/**
 * Matches a leading `MAJOR.MINOR.PATCH` (optionally `v`-prefixed) followed by
 * an optional `-prerelease`/`+build` tail. We only need the major number and
 * the prerelease tail, so MINOR/PATCH are matched but not captured by name.
 */
const SEMVER_CORE = /^v?(\d+)\.(\d+)\.(\d+)(?:[-+](.+))?$/;

/**
 * Classify a plugin's semver into a badge tier.
 *
 * Precedence (a prerelease TAG always wins over the numeric major, so a
 * `0.2.0-beta` is "beta", not "prerelease"):
 *   1. prerelease tail contains `alpha` -> alpha
 *   2. prerelease tail contains `beta`  -> beta
 *   3. major >= 1                       -> stable
 *   4. major === 0 (0.x.x)              -> prerelease
 *
 * Returns `null` for a missing, empty, or unparseable version.
 */
export function classifyPluginVersion(
  version: string | null | undefined
): PluginVersionBadge | null {
  if (version === null || version === undefined) {
    return null;
  }

  const match = SEMVER_CORE.exec(version.trim());
  if (match === null) {
    return null;
  }

  const major = Number.parseInt(match[1], 10);
  const prerelease = (match[4] ?? '').toLowerCase();

  // A prerelease tag wins over the numeric major version.
  if (prerelease.includes('alpha')) {
    return { tier: 'alpha', label: 'Alpha' };
  }
  if (prerelease.includes('beta')) {
    return { tier: 'beta', label: 'Beta' };
  }

  if (major >= 1) {
    return { tier: 'stable', label: 'Stable' };
  }

  // 0.x.x with no alpha/beta tag is an early pre-release.
  return { tier: 'prerelease', label: 'Pre-release' };
}
