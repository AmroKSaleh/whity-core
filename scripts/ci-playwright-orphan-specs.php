<?php

declare(strict_types=1);

/**
 * CI Playwright orphan-spec guard (WHIT-638): fail the build on an E2E spec
 * file that no project will ever run.
 *
 * ── The defect this exists for ────────────────────────────────────────────
 *
 * `web/playwright.config.ts` gives every project an explicit `testMatch`
 * ALLOWLIST naming the spec files it runs. A spec whose name appears in none
 * of them is not skipped, not reported, and not an error to Playwright — it is
 * simply never collected.
 *
 * Four had accumulated: `register.spec.ts` (added 2026-07-06),
 * `verify-email.spec.ts` (07-08), `role-record-sections.spec.ts` (08-23) and
 * `document-create.spec.ts` (08-24). Eleven tests. `git log -S` over the
 * config shows not one commit has ever mentioned any of them. They had
 * executed zero times.
 *
 * Nothing caught it because there was nothing to catch: the suite is green,
 * the files sit in `e2e/` looking exactly like coverage, and the only visible
 * symptom is that `playwright test --list` says 32 files while the directory
 * holds 35. That is the repository's own "check that cannot fail" shape — a
 * file that reads as coverage the project does not have.
 *
 * ── Why a static check rather than asking Playwright ──────────────────────
 *
 * `playwright test --list` would answer this directly, and it needs
 * `node_modules` plus the browsers' own config resolution. This guard parses
 * the config's regexes and compares them to the directory listing, so it runs
 * in the PHP static-analysis job with no toolchain at all — which means it
 * runs on every backend pull request rather than only where the frontend job
 * is triggered.
 *
 * The trade is precision about WHY a file is unmatched. This answers only
 * "does any project's testMatch accept this filename", which is the question
 * that had the wrong answer. A spec that matches but is skipped at runtime is
 * out of scope here and visible in the run output anyway.
 *
 * Usage:  php scripts/ci-playwright-orphan-specs.php
 *         php scripts/ci-playwright-orphan-specs.php --config=PATH --dir=PATH
 */

/**
 * Specs known to match no project, each with the reason it is tolerated.
 *
 * This list is DEBT, not permission. Every entry is a test somebody wrote that
 * does not run, and the guard names them on every green build so the fact
 * cannot go quiet again. WHIT-638 exists to empty it — by wiring each file
 * into a project or deleting it, per file.
 *
 * Adding an entry here should feel worse than fixing the spec.
 *
 * None of the four is believed stale. The pages they drive all exist and the
 * test names read as current behaviour, so the likely resolution is wiring
 * rather than deleting — which is the more expensive answer, since a test that
 * has never run may simply fail.
 */
const TOLERATED = [
    // Each entry says what the spec covers, because "never wired" alone invites
    // the assumption that it is dead. It is not: all four target pages exist and
    // every test reads as live coverage of a current surface. The first draft of
    // this list guessed that the two July files predated the identity cutover and
    // were probably stale — /register and /verify-email both still exist, so that
    // guess was wrong and is recorded here rather than left to mislead.
    'register.spec.ts' => 'WHIT-638: unwired since 2026-07-06. Covers /register — client-side validation and workspace creation. The page exists.',
    'verify-email.spec.ts' => 'WHIT-638: unwired since 2026-07-08. Covers /verify-email — invalid-token handling and an enumeration-safe resend. The page exists, and the enumeration property is security-relevant.',
    'role-record-sections.spec.ts' => 'WHIT-638: unwired since 2026-08-23. Covers the tenant-owned vs global base role editability asymmetry the instruction set documents (WC-110/WC-222).',
    'document-create.spec.ts' => 'WHIT-638: unwired since 2026-08-24. Covers the organizer New-document picker being filled from real templates.',
];

$root = dirname(__DIR__);
$configPath = $root . '/web/playwright.config.ts';
$specDir = $root . '/web/e2e';

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--config=(.+)$/', $arg, $m) === 1) {
        $configPath = $m[1];
    } elseif (preg_match('/^--dir=(.+)$/', $arg, $m) === 1) {
        $specDir = $m[1];
    } else {
        fwrite(STDERR, "Unrecognised argument: {$arg}\n");
        exit(2);
    }
}

if (!is_file($configPath)) {
    fwrite(STDERR, "Playwright config not found at {$configPath}\n");
    exit(2);
}
if (!is_dir($specDir)) {
    fwrite(STDERR, "Spec directory not found at {$specDir}\n");
    exit(2);
}

$config = (string) file_get_contents($configPath);

/**
 * Every project's `testMatch` regex, as written in the config.
 *
 * Captured up to the closing delimiter + optional flags. A config that stops
 * using `testMatch` literals — switching to a variable, say — yields zero
 * patterns, and that is treated as a FAILURE below rather than as "everything
 * matches" or "nothing does. A guard that silently stops guarding when the
 * thing it reads is refactored is the defect it was written to prevent.
 */
preg_match_all('~testMatch:\s*/(.+?)/[gimsuy]*\s*,~', $config, $matches);
$patterns = $matches[1];

if ($patterns === []) {
    fwrite(STDERR, "No testMatch patterns found in {$configPath}.\n");
    fwrite(
        STDERR,
        "Either the config no longer uses inline /regex/ literals, or this guard's parser broke.\n"
        . "Fix the parser — an empty pattern list would otherwise mark every spec an orphan,\n"
        . "or (worse, depending on the bug) none of them.\n"
    );
    exit(2);
}

$specs = glob($specDir . '/*.spec.ts') ?: [];
sort($specs);

$orphans = [];
foreach ($specs as $path) {
    $name = basename($path);
    $matched = false;

    foreach ($patterns as $pattern) {
        // The config is TypeScript, so its regexes are JS-flavoured. The only
        // construct used here that PHP spells differently is the escaped path
        // separator class, and it means the same thing in both.
        if (@preg_match('~' . str_replace('~', '\~', $pattern) . '~', $name) === 1) {
            $matched = true;
            break;
        }
        // A pattern may be anchored on the path rather than the bare filename
        // (the setup project matches `support[\/]auth.setup.ts`).
        if (@preg_match('~' . str_replace('~', '\~', $pattern) . '~', 'e2e/' . $name) === 1) {
            $matched = true;
            break;
        }
    }

    if (!$matched) {
        $orphans[] = $name;
    }
}

$unexpected = array_values(array_diff($orphans, array_keys(TOLERATED)));
$fixed = array_values(array_diff(array_keys(TOLERATED), $orphans));

$exit = 0;

if ($unexpected !== []) {
    fwrite(STDERR, "\nPlaywright specs that no project will ever run:\n\n");
    foreach ($unexpected as $name) {
        $count = preg_match_all('/^\s*test\(/m', (string) file_get_contents($specDir . '/' . $name));
        fwrite(STDERR, sprintf("  - %s (%d test(s))\n", $name, $count));
    }
    fwrite(
        STDERR,
        "\nEvery project in playwright.config.ts names the specs it runs in an explicit\n"
        . "testMatch allowlist, so a new spec file runs only once it is added to one.\n"
        . "Add it to the project it belongs to, or delete it — a spec that never runs\n"
        . "reads as coverage this project does not have.\n\n"
    );
    $exit = 1;
}

// A tolerated entry that now MATCHES has been fixed. Say so and require the
// list to shrink: a stale allowlist is how the next orphan hides in plain
// sight, tolerated by a line nobody re-reads.
if ($fixed !== []) {
    fwrite(STDERR, "\nThese specs are wired now and no longer need tolerating:\n\n");
    foreach ($fixed as $name) {
        fwrite(STDERR, "  - {$name}\n");
    }
    fwrite(STDERR, "\nRemove them from TOLERATED in " . basename(__FILE__) . ".\n\n");
    $exit = 1;
}

if ($exit !== 0) {
    exit($exit);
}

$tolerated = array_values(array_intersect(array_keys(TOLERATED), $orphans));
printf(
    "OK: %d spec file(s) checked, every one claimed by a project%s.\n",
    count($specs),
    $tolerated === []
        ? ''
        : sprintf(' except %d tolerated orphan(s) tracked by WHIT-638: %s', count($tolerated), implode(', ', $tolerated))
);
exit(0);
