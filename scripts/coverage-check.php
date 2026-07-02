<?php

declare(strict_types=1);

/**
 * CI coverage floor enforcement (WC-6342bfc4).
 *
 * Parses the Clover XML report produced by PHPUnit and exits non-zero if the
 * overall line-coverage percentage falls below COVERAGE_FLOOR_PERCENT.
 *
 * Usage:
 *   php scripts/coverage-check.php coverage.xml
 *
 * Updating the floor:
 *   1. Run the suite locally with coverage:
 *        php -d memory_limit=512M vendor/bin/phpunit --coverage-text
 *   2. Read the "Lines:" figure from the summary.
 *   3. Set COVERAGE_FLOOR_PERCENT to (measured_pct - 2), rounded down, so the
 *      gate is meaningful but doesn't flake on minor test-mix variance.
 *   4. Commit the change with a note of the new measured value in the message.
 */

// ---------------------------------------------------------------------------
// Floor value — update this when coverage meaningfully improves (see above).
// Measured baseline: 83.73 % (9874 / 11793 lines) on 2026-07-02.
// ---------------------------------------------------------------------------
const COVERAGE_FLOOR_PERCENT = 81;

// ---------------------------------------------------------------------------

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/coverage-check.php <coverage.xml>\n");
    exit(2);
}

$cloverFile = $argv[1];

if (!is_file($cloverFile)) {
    fwrite(STDERR, "coverage-check: file not found: {$cloverFile}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverFile);

if ($xml === false) {
    fwrite(STDERR, "coverage-check: failed to parse {$cloverFile}\n");
    exit(2);
}

// Clover stores the project-level aggregate in //coverage/project/metrics.
$metrics = $xml->project->metrics;

if ($metrics === null) {
    fwrite(STDERR, "coverage-check: <project><metrics> element missing in {$cloverFile}\n");
    exit(2);
}

$total   = (int) $metrics['statements'];
$covered = (int) $metrics['coveredstatements'];

if ($total === 0) {
    fwrite(STDERR, "coverage-check: zero statements found — is pcov enabled and <source> configured?\n");
    exit(2);
}

$actual = round(($covered / $total) * 100, 2);

printf(
    "Coverage: %.2f%% (%d / %d lines)  |  floor: %d%%\n",
    $actual,
    $covered,
    $total,
    COVERAGE_FLOOR_PERCENT,
);

if ($actual < COVERAGE_FLOOR_PERCENT) {
    fwrite(
        STDERR,
        sprintf(
            "FAIL: line coverage %.2f%% is below the required floor of %d%%.\n"
            . "      Add tests or lower the floor deliberately (see scripts/coverage-check.php).\n",
            $actual,
            COVERAGE_FLOOR_PERCENT,
        ),
    );
    exit(1);
}

echo "OK: coverage is above the floor.\n";
exit(0);
