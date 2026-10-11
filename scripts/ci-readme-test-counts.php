<?php

declare(strict_types=1);

/**
 * CI README test-count guard (WHIT-616): fail the build when the suite sizes
 * the README advertises stop matching the suite.
 *
 * ── Why this exists ───────────────────────────────────────────────────────
 *
 * The "Tested" bullet has been corrected by hand three times — PR #685, PR
 * #690, and again in #1266, which found it claiming `3695+ PHPUnit tests` and
 * `162 Playwright E2E tests` against a real 7,875 and 187. It had understated
 * the suite by more than half for months.
 *
 * Nothing was wrong with any of those fixes. The problem is the shape: a
 * number maintained by hand, in a file nobody re-reads while working, drifts
 * by construction. A fourth manual correction would buy the same few months.
 *
 * ── What it checks, and why a floor rather than equality ──────────────────
 *
 * Every figure is read as a FLOOR, whether or not it carries a `+`:
 *
 *   too LOW  (claimed > actual)            -> the README overstates. A lie,
 *                                             however small, so no tolerance.
 *   too HIGH (actual > claimed * 1.10)     -> reality outgrew the claim and
 *                                             the README now undersells the
 *                                             project. This is the failure
 *                                             that actually happened.
 *
 * Equality is deliberately NOT required. A gate that fires on every added test
 * would be edited out within a week, and it would fail PRs for the crime of
 * adding coverage. The band lets the suite grow normally while still catching
 * BOTH drifts that occurred: 3695 claimed against 7875 actual (113% over) and
 * 162 against 187 (15% over). See DRIFT_TOLERANCE — the second one is what
 * set the number, because the first draft was loose enough to miss it.
 *
 * ── Why it takes counts as arguments ──────────────────────────────────────
 *
 * The three counts are not obtainable in one place. `phpunit --list-tests`
 * needs `vendor/`, which the static-analysis job has and the frontend job does
 * not; the Jest and Playwright totals need `node_modules`, which is the other
 * way round. So each job passes what it already knows and this script checks
 * that subset. Passing no counts at all is an error, not a silent pass.
 *
 * ── What is wired today, and what is not ──────────────────────────────────
 *
 * CI currently supplies only `--phpunit`, from the static-analysis job. That
 * is the figure that broke worst (3695 against 7875) and it is now gated.
 *
 * `--jest` and `--playwright` are implemented and tested but NOT yet wired,
 * because the frontend job has no PHP and this script does. Wiring them means
 * either adding a PHP setup step to that job or porting this file to Node —
 * a real choice, not an oversight, and one worth making deliberately rather
 * than by assuming which interpreters a runner image happens to ship.
 *
 * Until then those two figures are unguarded. Saying so here is the point: a
 * guard that silently covers one of three claims, while the header implies
 * three, is how the next person concludes the README is fully checked.
 *
 * To wire them, in the `web` job after `npx jest`:
 *   jest  -> the "Tests: N passed" line of the run it already does
 *   pw    -> `npx playwright test --list` ("Total: N tests in M files")
 *
 * Usage:
 *   php scripts/ci-readme-test-counts.php --phpunit=7875
 *   php scripts/ci-readme-test-counts.php --jest=2369 --playwright=187
 *   php scripts/ci-readme-test-counts.php --phpunit=7875 --readme=README.md
 */

/**
 * How far above its claimed floor a suite may grow before the claim is stale.
 *
 * 10%, chosen against the two real drifts rather than picked round. A 25% band
 * was the first draft and it let the Playwright case through: 162 claimed
 * against 187 actual is only 15% over, so the gate would have passed the very
 * understatement it exists to catch, while still catching the PHPUnit one at
 * 113%. A guard that fires on the dramatic half of a known failure and not the
 * quiet half is worse than it looks, because the quiet half is the one nobody
 * spots by eye.
 *
 * 10% leaves real headroom: every figure in the README today sits within 4% of
 * its floor. Growing past the band is a one-line edit with the error message
 * telling you the number to write.
 */
const DRIFT_TOLERANCE = 1.10;

/**
 * Each checkable figure, and the pattern that finds it in the README.
 *
 * The patterns are deliberately narrow — they anchor on the tool's own name
 * rather than on position in the sentence — so rewording the bullet does not
 * silently disable the check. A pattern that stops matching is a FAILURE
 * below, never a pass: an unfindable claim is the same problem as a wrong one.
 */
const CLAIMS = [
    'phpunit' => [
        'label' => 'PHPUnit',
        'pattern' => '/([\d,]+)\+?\s+PHPUnit tests/i',
    ],
    'jest' => [
        'label' => 'Jest',
        'pattern' => '/([\d,]+)\+?\s+Jest component tests/i',
    ],
    'playwright' => [
        'label' => 'Playwright',
        'pattern' => '/([\d,]+)\+?\s+Playwright E2E tests/i',
    ],
];

$readmePath = dirname(__DIR__) . '/README.md';
$actual = [];

foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--readme=(.+)$/', $arg, $m) === 1) {
        $readmePath = $m[1];
        continue;
    }

    if (preg_match('/^--([a-z]+)=(\d+)$/', $arg, $m) !== 1 || !isset(CLAIMS[$m[1]])) {
        fwrite(STDERR, "Unrecognised argument: {$arg}\n");
        fwrite(STDERR, "Expected --phpunit=N, --jest=N, --playwright=N or --readme=PATH\n");
        exit(2);
    }

    $actual[$m[1]] = (int) $m[2];
}

if ($actual === []) {
    // Called with nothing to check. This is a wiring mistake in the workflow,
    // and exiting 0 would make it look like a passing guard forever.
    fwrite(STDERR, "No counts supplied — nothing was checked.\n");
    fwrite(STDERR, "Pass at least one of --phpunit=N, --jest=N, --playwright=N.\n");
    exit(2);
}

if (!is_file($readmePath)) {
    fwrite(STDERR, "README not found at {$readmePath}\n");
    exit(2);
}

$readme = (string) file_get_contents($readmePath);
$failures = [];
$checked = [];

foreach ($actual as $key => $count) {
    $claim = CLAIMS[$key];

    if (preg_match($claim['pattern'], $readme, $m) !== 1) {
        $failures[] = sprintf(
            "%s: no claim found in %s.\n"
            . "      The guard looks for a figure followed by \"%s\". If the bullet was\n"
            . "      reworded, update CLAIMS in this script so the check keeps running —\n"
            . "      a claim the guard cannot find is not a claim it has verified.",
            $claim['label'],
            basename($readmePath),
            trim(str_replace(['/([\\d,]+)\\+?\\s+', '/i'], '', $claim['pattern']))
        );
        continue;
    }

    $claimed = (int) str_replace(',', '', $m[1]);
    $ceiling = (int) floor($claimed * DRIFT_TOLERANCE);
    $checked[] = sprintf('%s %d (claims %d)', $claim['label'], $count, $claimed);

    if ($count < $claimed) {
        $failures[] = sprintf(
            "%s: the README claims %s but there are %s.\n"
            . "      It OVERSTATES the suite. Correct the figure in %s.",
            $claim['label'],
            number_format($claimed),
            number_format($count),
            basename($readmePath)
        );
        continue;
    }

    if ($count > $ceiling) {
        $failures[] = sprintf(
            "%s: the README claims %s but there are %s — %d%% above the claim.\n"
            . "      The suite has outgrown its own description. Round %s down to a\n"
            . "      sensible floor and write it into %s.",
            $claim['label'],
            number_format($claimed),
            number_format($count),
            (int) round((($count / max($claimed, 1)) - 1) * 100),
            number_format($count),
            basename($readmePath)
        );
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nREADME test counts are out of date:\n\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, '  - ' . $failure . "\n\n");
    }
    fwrite(
        STDERR,
        "This bullet has drifted three times before (#685, #690, #1266), which is why\n"
        . "it is gated. Fixing the number is the whole job.\n\n"
    );
    exit(1);
}

fwrite(STDOUT, "OK: README test counts are current — " . implode(', ', $checked) . ".\n");
exit(0);
