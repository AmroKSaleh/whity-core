<?php

declare(strict_types=1);

/**
 * Duration-balanced split of the PHPUnit test files across CI shards (WHIT-715).
 *
 * Shared by scripts/ci-test-shard.php and its test. Pure functions only: no I/O
 * beyond what the caller passes in, so every shard computes the identical plan.
 */

/**
 * Every test file PHPUnit's `<directory>tests</directory>` would walk, as
 * repo-relative forward-slash paths in byte order (the old `LC_ALL=C sort`).
 *
 * @return list<string>
 */
function whity_list_test_files(string $repoRoot, string $testsDir = 'tests'): array
{
    $root = rtrim(str_replace('\\', '/', $repoRoot), '/');
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $testsDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile() && str_ends_with($file->getFilename(), 'Test.php')) {
            $files[] = substr(str_replace('\\', '/', $file->getPathname()), strlen($root) + 1);
        }
    }

    sort($files, SORT_STRING);

    return $files;
}

/**
 * Greedy longest-processing-time assignment: heaviest file first, each onto the
 * currently lightest shard. Files with no recorded timing are weighted at the
 * median of the known ones, so a new test file still lands somewhere sensible
 * and the plan stays valid with an empty or stale timings file.
 *
 * Ties break on path and on the lowest shard index, so the result depends only
 * on the inputs — every shard of a run derives the same partition.
 *
 * @param list<string>         $files
 * @param array<string, float> $timings seconds per repo-relative file
 *
 * @return list<array{files: list<string>, seconds: float}> index 0 is shard 1;
 *         each shard's files are heaviest first, so ParaTest starts the long
 *         ones early instead of leaving one to run alone at the end
 */
function whity_plan_shards(array $files, array $timings, int $shards): array
{
    if ($shards < 1) {
        throw new InvalidArgumentException("shard count must be >= 1, got {$shards}");
    }

    $known = [];
    foreach ($files as $file) {
        if (isset($timings[$file])) {
            $known[] = (float) $timings[$file];
        }
    }
    sort($known);
    $fallback = $known === [] ? 1.0 : $known[intdiv(count($known), 2)];

    $weighted = [];
    foreach ($files as $file) {
        $weighted[] = [$file, isset($timings[$file]) ? (float) $timings[$file] : $fallback];
    }
    usort($weighted, static fn(array $a, array $b): int => [$b[1], $a[0]] <=> [$a[1], $b[0]]);

    $plan = array_fill(0, $shards, ['files' => [], 'seconds' => 0.0]);
    foreach ($weighted as [$file, $seconds]) {
        $lightest = 0;
        for ($i = 1; $i < $shards; $i++) {
            if ($plan[$i]['seconds'] < $plan[$lightest]['seconds']) {
                $lightest = $i;
            }
        }
        $plan[$lightest]['files'][] = $file;
        $plan[$lightest]['seconds'] += $seconds;
    }

    return $plan;
}

/**
 * Sum JUnit `<testcase time>` per test file. Works for both PHPUnit's and
 * ParaTest's JUnit output: each testcase carries the `file` it came from.
 *
 * @param list<string> $junitXml documents to read
 *
 * @return array<string, float> seconds per repo-relative file, sorted by path
 */
function whity_timings_from_junit(array $junitXml, string $repoRoot): array
{
    $root = rtrim(str_replace('\\', '/', $repoRoot), '/') . '/';
    $timings = [];

    foreach ($junitXml as $xml) {
        $doc = new DOMDocument();
        if (!@$doc->loadXML($xml)) {
            throw new RuntimeException('not a JUnit XML document');
        }
        foreach ($doc->getElementsByTagName('testcase') as $case) {
            $file = str_replace('\\', '/', $case->getAttribute('file'));
            if ($file === '') {
                continue;
            }
            if (str_starts_with($file, $root)) {
                $file = substr($file, strlen($root));
            }
            $timings[$file] = ($timings[$file] ?? 0.0) + (float) $case->getAttribute('time');
        }
    }

    ksort($timings, SORT_STRING);

    return array_map(static fn(float $s): float => round($s, 3), $timings);
}
