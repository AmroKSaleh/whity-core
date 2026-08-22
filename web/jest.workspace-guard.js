/**
 * Fail loudly when a workspace package resolves out of this checkout (#840).
 *
 * ## What goes wrong without this
 *
 * Worktrees in this repo live at `.claude/worktrees/<name>` — INSIDE the repo.
 * A worktree that has never had `npm install` run in it has no `node_modules`
 * of its own, so Node's resolution walks up from `<worktree>/web/` and finds
 * the MAIN checkout's `node_modules`, whose `@amroksaleh/ui` entry is an
 * npm-workspaces symlink to the MAIN checkout's `packages/ui`.
 *
 * The component under test is then loaded from a different physical path than
 * the one `jest.mock('@amroksaleh/ui/...')` is registered against. The mock
 * factory becomes dead code, every importer silently gets the real component,
 * and the assertions that depended on the mock fail.
 *
 * ## Why a guard rather than a resolver fix
 *
 * The cost of #840 was never the broken run — it was that the breakage
 * IMPERSONATED A PRODUCT BUG. Four suites (`user-modals-password-reset`,
 * `user-memberships-modal`, `edit-user-modal-ou`, `TwoFactorSettings`) failed
 * in a way that read as "develop is red", and a contributor shipped a
 * `moduleNameMapper` change inside an unrelated PR to "fix" it. A guard that
 * names the cause turns an hour of misdirected debugging into one line of
 * output.
 *
 * It also refuses to let the failure become routine. "Those four always fail,
 * ignore them" trains people to ignore a real regression in exactly the suites
 * covering password reset, membership management, OU assignment and 2FA — a
 * bad set to be blind in.
 *
 * There is deliberately **no escape hatch**. An environment variable to silence
 * this would be set once, in frustration, and would restore precisely the
 * blindness the guard exists to remove. `npm install` in the checkout is the
 * fix, it takes one command, and it leaves the suite genuinely trustworthy.
 */

import fs from 'node:fs';
import path from 'node:path';

/**
 * The package whose resolution is probed. Any `@amroksaleh/ui` subpath would
 * do; `button` is a leaf with no module-level side effects, so probing it
 * cannot execute component code as a side effect of loading the guard.
 */
export const PROBE_SPECIFIER = '@amroksaleh/ui/button';

/**
 * Resolve symlinks and casing so two spellings of one location compare equal.
 * Falls back to a plain absolute path for inputs that do not exist on disk —
 * which is what the unit tests pass.
 */
function canonical(target) {
  try {
    return fs.realpathSync.native(target);
  } catch {
    return path.resolve(target);
  }
}

/**
 * Whether `child` lives beneath `parent`. Equal paths are NOT inside: the
 * question this answers is containment, and a package that resolved to the
 * workspace root itself is as wrong as one outside it.
 */
export function isInside(child, parent) {
  const relative = path.relative(canonical(parent), canonical(child));

  return (
    relative !== '' &&
    !relative.startsWith(`..${path.sep}`) &&
    relative !== '..' &&
    !path.isAbsolute(relative)
  );
}

/**
 * The operator-facing explanation, or `null` when resolution is sound.
 *
 * Separated from the throw so it can be asserted on directly — a diagnostic
 * whose whole value is its wording deserves a test that reads the wording.
 *
 * @param {{ resolved: string | null, workspaceRoot: string }} input
 *   `resolved` is `null` when the probe could not be resolved at all, which is
 *   a different failure (a missing dependency) that this guard must not claim.
 * @returns {string | null}
 */
export function foreignResolutionMessage({ resolved, workspaceRoot }) {
  if (resolved === null || isInside(resolved, workspaceRoot)) {
    return null;
  }

  return [
    `${PROBE_SPECIFIER} resolved OUTSIDE this checkout.`,
    '',
    `  resolved to:    ${resolved}`,
    `  expected under: ${workspaceRoot}`,
    '',
    'Node walked past this checkout and found another node_modules — almost',
    'always because this is a git worktree with no node_modules of its own, so',
    'resolution reached the main checkout instead.',
    '',
    'Every jest.mock() of a workspace package is then registered against a',
    'different physical module than the code under test imports. The mock',
    'becomes dead code and the assertions depending on it fail, which reads as',
    'a broken component rather than a broken environment (#840).',
    '',
    'Fix: run `npm install` once in this checkout, then re-run.',
  ].join('\n');
}

/**
 * Throw unless workspace packages resolve inside this checkout.
 *
 * @param {{
 *   workspaceRoot?: string,
 *   resolveSpecifier?: (specifier: string) => string,
 * }} [options] Injection points for the unit tests; production callers pass
 *   nothing and get the real resolver rooted at this file's own location.
 */
export function assertWorkspaceResolution(options = {}) {
  const {
    // This file sits in `<checkout>/web`, so its parent is the workspace root
    // that owns the `node_modules` resolution should be finding. Deriving it
    // from __dirname rather than process.cwd() keeps the answer correct however
    // Jest was invoked.
    workspaceRoot = path.resolve(__dirname, '..'),
    resolveSpecifier = (specifier) => require.resolve(specifier),
  } = options;

  let resolved;
  try {
    resolved = resolveSpecifier(PROBE_SPECIFIER);
  } catch {
    // The probe is not resolvable at all. That is a missing or broken install,
    // which surfaces on its own with a clearer error than anything this guard
    // could add — and misreporting it as a worktree problem would be the exact
    // sin this guard was written to prevent.
    return;
  }

  const message = foreignResolutionMessage({ resolved, workspaceRoot });
  if (message !== null) {
    throw new Error(message);
  }
}
