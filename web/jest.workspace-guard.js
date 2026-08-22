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
 * Which half of the mock that strands is worth stating precisely, because it
 * decides where the fix belongs. The component under test keeps loading the
 * LOCAL copy: `web/tsconfig.json` maps `@amroksaleh/ui/*` to
 * `../packages/ui/src/*`, `next/jest` hands those `paths` to SWC, and SWC emits
 * every ESM import as a RELATIVE require — `require("../../../packages/ui/
 * src/dialog")` — which cannot leave the checkout. It is the string in
 * `jest.mock('@amroksaleh/ui/dialog')` that escapes: a call argument rather
 * than an import, so SWC leaves it alone and Jest resolves it as a bare
 * specifier, walking node_modules up and out.
 *
 * So the mock is registered against a file nothing imports. The factory becomes
 * dead code, every importer silently gets the real component, and the
 * assertions that depended on the mock fail.
 *
 * ## Why this guard remains now that the resolver is also fixed
 *
 * `jest.config.mjs` now mirrors those tsconfig `paths` in its
 * `moduleNameMapper`, so both halves of a mock land on one file and this
 * divergence cannot arise from a missing `node_modules` alone. The guard is
 * what keeps that honest: remove or misdirect those mapper entries and every
 * suite aborts here, naming the cause, instead of four suites failing as
 * product bugs.
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
 * blindness the guard exists to remove. Restoring the mapper entries, or
 * `npm install` in the checkout, is the fix — either one leaves the suite
 * genuinely trustworthy.
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
    "Fix: restore the '@amroksaleh/*' moduleNameMapper entries in",
    'jest.config.mjs, which pin these packages to this checkout — or run',
    '`npm install` once here, giving the checkout its own node_modules.',
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
