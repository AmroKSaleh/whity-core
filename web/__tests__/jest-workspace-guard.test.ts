/**
 * The worktree-resolution guard (#840).
 *
 * The property worth pinning, in the issue's words: *the web suite gives the
 * same verdict from a worktree as from the main checkout*. This guard cannot
 * deliver that by itself — `npm install` does — but it makes the difference
 * impossible to mistake for a product bug, which is what the failure actually
 * cost.
 *
 * So these tests check two things the guard would be worthless without: that it
 * fires on the real geometry (a worktree nested inside the repo whose packages
 * resolve to the parent checkout), and that its message names the cause and the
 * fix rather than merely reporting a mismatch.
 */

import path from 'node:path';

import {
  PROBE_SPECIFIER,
  assertWorkspaceResolution,
  foreignResolutionMessage,
  isInside,
} from '../jest.workspace-guard';

/** The main checkout. */
const REPO = path.resolve(path.sep, 'Projects', 'Whity', 'whity-core');
/** A worktree, nested inside it — the layout this repo actually uses. */
const WORKTREE = path.join(REPO, '.claude', 'worktrees', 'wt-example');

/** Where `@amroksaleh/ui/button` lands in each checkout. */
const uiIn = (root: string) => path.join(root, 'packages', 'ui', 'src', 'button.tsx');

describe('isInside', () => {
  it('accepts a package inside its own checkout', () => {
    expect(isInside(uiIn(REPO), REPO)).toBe(true);
    expect(isInside(uiIn(WORKTREE), WORKTREE)).toBe(true);
  });

  it('rejects the escape that #840 is about', () => {
    // The worktree is nested INSIDE the repo, so the containment test has to
    // run in the right direction: the repo's copy is not inside the worktree,
    // even though the worktree is inside the repo.
    expect(isInside(uiIn(REPO), WORKTREE)).toBe(false);
  });

  it('treats a nested worktree as inside the repo, which is why direction matters', () => {
    expect(isInside(WORKTREE, REPO)).toBe(true);
  });

  it('does not count a path as inside itself', () => {
    expect(isInside(REPO, REPO)).toBe(false);
  });
});

describe('foreignResolutionMessage', () => {
  it('says nothing when resolution stays in the checkout', () => {
    expect(
      foreignResolutionMessage({ resolved: uiIn(WORKTREE), workspaceRoot: WORKTREE })
    ).toBeNull();
  });

  it('says nothing when the probe could not be resolved at all', () => {
    // A missing install reports itself more clearly than this guard could, and
    // blaming it on a worktree would be the same misdirection the guard exists
    // to prevent.
    expect(foreignResolutionMessage({ resolved: null, workspaceRoot: WORKTREE })).toBeNull();
  });

  it('names both paths, the mechanism and the fix', () => {
    const message = foreignResolutionMessage({
      resolved: uiIn(REPO),
      workspaceRoot: WORKTREE,
    });

    expect(message).not.toBeNull();
    // Both sides of the mismatch: knowing only that it is wrong does not tell
    // you which checkout you are accidentally testing.
    expect(message).toContain(uiIn(REPO));
    expect(message).toContain(WORKTREE);
    // The mechanism, so the reader does not conclude the component is broken.
    expect(message).toMatch(/jest\.mock/i);
    expect(message).toMatch(/dead code/i);
    // The remedy, which is the whole point of failing loudly.
    expect(message).toMatch(/npm install/i);
    expect(message).toContain('#840');
  });
});

describe('assertWorkspaceResolution', () => {
  it('throws when a workspace package resolves to another checkout', () => {
    expect(() =>
      assertWorkspaceResolution({
        workspaceRoot: WORKTREE,
        resolveSpecifier: () => uiIn(REPO),
      })
    ).toThrow(/resolved OUTSIDE this checkout/);
  });

  it('passes when the package resolves inside the checkout', () => {
    expect(() =>
      assertWorkspaceResolution({
        workspaceRoot: WORKTREE,
        resolveSpecifier: () => uiIn(WORKTREE),
      })
    ).not.toThrow();
  });

  it('stays quiet when the probe cannot be resolved', () => {
    expect(() =>
      assertWorkspaceResolution({
        workspaceRoot: WORKTREE,
        resolveSpecifier: () => {
          throw new Error('Cannot find module');
        },
      })
    ).not.toThrow();
  });

  it('probes a side-effect-free leaf module', () => {
    // Loading the guard must not be able to execute component code, so the
    // probe stays a leaf rather than the package root.
    expect(PROBE_SPECIFIER).toBe('@amroksaleh/ui/button');
  });

  it('passes for real, in whatever checkout this suite is running from', () => {
    // The end-to-end case: the defaults, the real resolver, this checkout. If
    // this fails, the suite it is guarding is the thing that cannot be trusted.
    expect(() => assertWorkspaceResolution()).not.toThrow();
  });
});
