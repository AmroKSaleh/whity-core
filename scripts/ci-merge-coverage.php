<?php

declare(strict_types=1);

/**
 * Merge per-shard PHPUnit coverage into a single Clover report (WC-ci-wallclock).
 *
 * The CI "Tests shard N/M (SQLite, coverage)" jobs each run a disjoint slice of
 * the suite and emit php-code-coverage's own serialised object with
 * `--coverage-php`. This script unions those slices back into one report so that
 * scripts/coverage-check.php still judges the PROJECT-WIDE line-coverage floor,
 * exactly as it did when the whole suite ran in one process.
 *
 * The union is done by php-code-coverage itself (CodeCoverage::merge()), not by
 * arithmetic over per-shard Clover XML: a line executed in two shards is counted
 * once, and the executable-line denominator is derived from the source the same
 * way a single unsharded run derives it. This is the same operation phpunit/phpcov's
 * `merge` command performs; it is reimplemented here so CI needs no extra tool.
 *
 * Usage:
 *   php scripts/ci-merge-coverage.php <output-clover.xml> <input.cov|dir> [...]
 *
 * Directory arguments are expanded to their *.cov entries. Merging holds every
 * shard's (line, test) attribution in memory at once, so run it with a generous
 * memory_limit (CI uses `php -d memory_limit=-1`).
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;

require __DIR__ . '/../vendor/autoload.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/ci-merge-coverage.php <output-clover.xml> <input.cov|dir> [...]\n");
    exit(2);
}

$target = $argv[1];
$inputs = array_slice($argv, 2);

/** @var list<string> $covFiles */
$covFiles = [];

foreach ($inputs as $input) {
    if (is_dir($input)) {
        $found = glob(rtrim($input, '/\\') . '/*.cov') ?: [];

        if ($found === []) {
            fwrite(STDERR, "ci-merge-coverage: no *.cov files in directory: {$input}\n");
            exit(2);
        }

        $covFiles = array_merge($covFiles, $found);

        continue;
    }

    if (!is_file($input)) {
        fwrite(STDERR, "ci-merge-coverage: input not found: {$input}\n");
        exit(2);
    }

    $covFiles[] = $input;
}

sort($covFiles);

if ($covFiles === []) {
    fwrite(STDERR, "ci-merge-coverage: no coverage inputs resolved\n");
    exit(2);
}

$merged = null;

foreach ($covFiles as $covFile) {
    // The --coverage-php report is `<?php return \unserialize(...);`, so
    // requiring it yields the CodeCoverage instance.
    $coverage = require $covFile;

    if (!$coverage instanceof CodeCoverage) {
        fwrite(
            STDERR,
            sprintf(
                "ci-merge-coverage: %s did not return a CodeCoverage instance"
                . " — was it written with --coverage-php?\n",
                $covFile,
            ),
        );
        exit(2);
    }

    if ($merged === null) {
        $merged = $coverage;
    } else {
        $merged->merge($coverage);
    }

    // Free the shard's copy before loading the next one.
    unset($coverage);

    printf("merged %s\n", $covFile);
}

assert($merged instanceof CodeCoverage);

(new Clover())->process($merged, $target);

printf("ci-merge-coverage: merged %d shard(s) into %s\n", count($covFiles), $target);

exit(0);
