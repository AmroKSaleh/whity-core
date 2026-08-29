/**
 * CI gate: every test file in the checkout must be owned by some runner.
 *
 * WHY THIS EXISTS. #1050 is the third instance of one bug — a tree containing
 * tests that nothing runs — and each instance was found by noticing it, not by
 * a check. `packages/**` shipped unverified until someone spotted the filter
 * gap; `templates/tauri-desktop/src/**` had nine suites reachable by the
 * runner but by no path filter, so a desktop-only PR ran none of them; and
 * `web/components/admin/data-table.test.tsx` sat outside any `__tests__`
 * directory, so Jest's `testMatch` never collected it at all.
 *
 * That last one is the shape this file is aimed at, because it is the one that
 * gives no signal whatsoever. A test that is filtered out still runs sometimes.
 * A test the runner never globs is inert forever, and reads — in a file
 * listing, in a coverage discussion, in a review — as coverage that exists.
 *
 * So the assertion is not "the filter is right"; it is the weaker, checkable
 * claim that every file NAMED like a test is somewhere a runner will actually
 * find it. Searching for "what does the filter miss" finds an instance;
 * asserting "no test file is orphaned" closes the class.
 *
 * ADDING A RUNNER, or a new tested tree? Add it to RUNNERS below. That is the
 * point: the map has to be updated deliberately, and an unowned tree fails
 * loudly instead of going quiet.
 */
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import path from 'node:path';

const WEB_DIR = path.resolve(__dirname, '..');
const CHECKOUT_ROOT = path.resolve(WEB_DIR, '..');

const TEST_FILE = /\.(test|spec)\.(ts|tsx|js|jsx|mjs|cjs)$/;

/**
 * TRACKED files only, via git rather than a directory walk.
 *
 * A walk has to be told what to ignore, and gets it wrong in both directions
 * here: this repo keeps git worktrees at `.claude/worktrees/*` INSIDE itself
 * (so a walk finds every test file several times over, once per worktree), and
 * `plugins/` is a gitignored runtime mount point whose local contents are not
 * this repo's tests at all. Both are invisible to `git ls-files`, along with
 * node_modules, dist and every other ignored path, without a skip list that
 * would need maintaining.
 *
 * Paths come back forward-slashed on every platform, which is what the
 * matchers below expect.
 */
function testFiles(): string[] {
  const tracked = execFileSync('git', ['ls-files', '-z'], {
    cwd: CHECKOUT_ROOT,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });

  return (
    tracked
      .split('\0')
      .filter((file) => file !== '' && TEST_FILE.test(file))
      // `git ls-files` reads the INDEX. On CI that is the checkout, but locally
      // a file deleted and not yet staged is still listed — which would fail
      // this gate for someone in the middle of removing a test file, naming a
      // path that is already gone. Check the working tree too.
      .filter((file) => existsSync(path.join(CHECKOUT_ROOT, file)))
  );
}

/**
 * Who runs what. Each entry states a runner and the exact condition under
 * which that runner collects a file — mirroring the real configuration, which
 * is named in each comment so the two can be checked against each other.
 */
const RUNNERS: { name: string; owns: (file: string) => boolean }[] = [
  {
    // web/jest.config.mjs: `roots` are web/, packages/features/src and
    // templates/tauri-desktop/src; `testMatch` is
    // `**/__tests__/**/*.test.ts(x)`. BOTH halves matter — a file under a root
    // but outside a `__tests__` directory is not collected, which is exactly
    // how data-table.test.tsx hid.
    name: 'web Jest project',
    owns: (file) =>
      /\.test\.(ts|tsx)$/.test(file) &&
      file.includes('/__tests__/') &&
      (file.startsWith('web/') ||
        file.startsWith('packages/features/src/') ||
        file.startsWith('templates/tauri-desktop/src/')),
  },
  {
    // render-service/jest.config.js, run by the `render-service` CI job.
    name: 'render-service Jest project',
    owns: (file) => file.startsWith('render-service/test/'),
  },
  {
    // web/playwright.config.ts, run by the `E2E gate`. Playwright, not Jest —
    // a different runner with a different glob, so it gets its own entry
    // rather than being folded into the project above.
    name: 'Playwright E2E',
    owns: (file) => file.startsWith('web/e2e/'),
  },
];

describe('test-file reachability', () => {
  const files = testFiles();

  it('finds test files at all', () => {
    // Guards the scanner. A listing that silently returned nothing — a changed
    // extension, a `git` that is not on PATH, a cwd that is not a checkout —
    // would make the orphan assertion vacuously true, which is the failure
    // mode this whole file exists to prevent.
    expect(files.length).toBeGreaterThan(50);
  });

  it('has every runner owning at least one file', () => {
    // Guards each matcher individually: a stale prefix (a tree that moved, a
    // renamed directory) would quietly stop claiming files, and its tests
    // would then read as orphans — or, worse, a typo'd matcher that claims
    // nothing would never be noticed while some OTHER runner covers the gap.
    // Reported as name -> count so a failure says WHICH runner went blind.
    const owned = Object.fromEntries(
      RUNNERS.map((runner) => [runner.name, files.filter(runner.owns).length])
    );

    expect(Object.entries(owned).filter(([, count]) => count === 0)).toEqual([]);
  });

  it('leaves no test file unowned by any runner', () => {
    const orphans = files.filter((file) => !RUNNERS.some((runner) => runner.owns(file)));

    // Listed by path so the failure names the file rather than a count. If you
    // are reading this in a CI log: the file below is named like a test and is
    // run by nothing. Move it under a `__tests__` directory, or add the tree
    // to RUNNERS if a real runner does own it.
    expect(orphans).toEqual([]);
  });
});
