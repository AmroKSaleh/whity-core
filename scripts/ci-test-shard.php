<?php

declare(strict_types=1);

/**
 * Plan the CI SQLite test shards, and keep the timings that plan from (WHIT-715).
 *
 * The `Tests shard N/M (SQLite, coverage)` jobs used to deal test files out
 * round-robin (`i % shards`). That balances file COUNT, not time: in run
 * 40881065695 the four shards took 9, 16, 13 and 13 minutes, and the 16-minute
 * one held up the whole workflow. This script balances recorded per-file
 * seconds instead (see whity_plan_shards()).
 *
 *   php scripts/ci-test-shard.php plan <shard> <total> <config-out.xml>
 *
 *     Writes a copy of phpunit.xml whose only test suite is this shard's files,
 *     heaviest first, for `paratest --configuration`. ParaTest takes one path
 *     argument, not a file list, so the list travels in the config. Fails when
 *     no test file exists, when the shards do not partition the file set
 *     exactly, or when this shard is empty.
 *
 *   php scripts/ci-test-shard.php timings <out.json> <junit.xml|dir> [...]
 *
 *     Rebuilds the timings file from JUnit reports (directories expand to their
 *     *.xml). Every CI run publishes a fresh one as the `test-timings` artifact;
 *     commit it over scripts/ci-test-timings.json when the plan drifts. Stale
 *     timings only cost balance, never correctness: a file missing from them
 *     still runs, at the median weight.
 */

require_once __DIR__ . '/lib/test-shards.php';

const TIMINGS_FILE = __DIR__ . '/ci-test-timings.json';

$repoRoot = dirname(__DIR__);
$command = $argv[1] ?? '';

if ($command === 'plan' && $argc === 5) {
    $shard = (int) $argv[2];
    $total = (int) $argv[3];
    $out = $argv[4];

    if ($total < 1 || $shard < 1 || $shard > $total) {
        fwrite(STDERR, "::error::invalid shard {$argv[2]}/{$argv[3]}\n");
        exit(2);
    }

    $files = whity_list_test_files($repoRoot);
    if ($files === []) {
        fwrite(STDERR, "::error::no test files matched tests/**/*Test.php — refusing to run an empty shard\n");
        exit(1);
    }

    $timings = is_file(TIMINGS_FILE)
        ? json_decode((string) file_get_contents(TIMINGS_FILE), true, 512, JSON_THROW_ON_ERROR)
        : [];
    $plan = whity_plan_shards($files, $timings, $total);

    // Every shard runs this same check, so a planner bug fails the run loudly
    // rather than silently dropping (or doubling) a test file.
    $assigned = array_merge(...array_column($plan, 'files'));
    sort($assigned, SORT_STRING);
    if ($assigned !== $files) {
        fwrite(STDERR, "::error::shard plan does not cover every test file exactly once\n");
        exit(1);
    }

    $mine = $plan[$shard - 1];
    if ($mine['files'] === []) {
        fwrite(STDERR, "::error::shard {$shard}/{$total} selected 0 of " . count($files) . " test files\n");
        exit(1);
    }

    $unknown = count(array_diff($files, array_keys($timings)));
    printf(
        "Shard %d/%d: %d of %d test files, %.0f s recorded (shards: %s)%s\n",
        $shard,
        $total,
        count($mine['files']),
        count($files),
        $mine['seconds'],
        implode(' / ', array_map(static fn(array $s): string => sprintf('%.0f', $s['seconds']), $plan)),
        $unknown > 0 ? ", {$unknown} file(s) without a timing weighted at the median" : ''
    );

    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = false;
    $doc->formatOutput = true;
    $phpunit = $doc->load($repoRoot . '/phpunit.xml') ? $doc->documentElement : null;
    if ($phpunit === null) {
        fwrite(STDERR, "::error::cannot read phpunit.xml\n");
        exit(2);
    }
    foreach (iterator_to_array($phpunit->getElementsByTagName('testsuites')) as $old) {
        $phpunit->removeChild($old);
    }
    $suites = $doc->createElement('testsuites');
    $suite = $doc->createElement('testsuite');
    $suite->setAttribute('name', 'shard');
    foreach ($mine['files'] as $file) {
        $suite->appendChild($doc->createElement('file', $file));
    }
    $suites->appendChild($suite);
    $phpunit->insertBefore($suites, $phpunit->firstChild);
    $phpunit->setAttribute('defaultTestSuite', 'shard');

    // Relative paths (bootstrap, <source>, the files) resolve against the
    // config's own directory, so the generated config must sit at the root.
    if (realpath(dirname($out)) !== realpath($repoRoot)) {
        fwrite(STDERR, "::error::{$out} must be written to the repository root\n");
        exit(2);
    }
    $doc->save($out);
    exit(0);
}

if ($command === 'timings' && $argc >= 4) {
    $xml = [];
    foreach (array_slice($argv, 3) as $input) {
        foreach (is_dir($input) ? (glob(rtrim($input, '/\\') . '/*.xml') ?: []) : [$input] as $path) {
            $xml[] = (string) file_get_contents($path);
        }
    }
    if ($xml === []) {
        fwrite(STDERR, "ci-test-shard: no JUnit reports found\n");
        exit(2);
    }

    $timings = whity_timings_from_junit($xml, whity_list_test_files($repoRoot));
    file_put_contents($argv[2], json_encode($timings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    printf("ci-test-shard: %d files, %.0f s total -> %s\n", count($timings), array_sum($timings), $argv[2]);
    exit(0);
}

fwrite(STDERR, "Usage:\n"
    . "  php scripts/ci-test-shard.php plan <shard> <total> <config-out.xml>\n"
    . "  php scripts/ci-test-shard.php timings <out.json> <junit.xml|dir> [...]\n");
exit(2);
