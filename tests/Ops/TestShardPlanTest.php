<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/scripts/lib/test-shards.php';

/**
 * The CI shard planner (WHIT-715) must partition the suite exactly.
 *
 * A file dropped by the planner would simply never run, and CI would stay
 * green; a file dealt twice would only waste time. The workflow checks the
 * partition on every run, but that check only sees today's file set — these
 * tests pin the properties for the shapes that file set will grow into: new
 * files with no timing, an empty timings file, more shards than files.
 */
final class TestShardPlanTest extends TestCase
{
    /**
     * @param list<array{files: list<string>, seconds: float}> $plan
     *
     * @return list<string>
     */
    private static function union(array $plan): array
    {
        $all = array_merge(...array_column($plan, 'files'));
        sort($all, SORT_STRING);

        return $all;
    }

    public function testEveryFileLandsInExactlyOneShard(): void
    {
        $files = array_map(static fn(int $i): string => sprintf('tests/F%03dTest.php', $i), range(1, 37));
        $timings = ['tests/F001Test.php' => 90.0, 'tests/F002Test.php' => 3.5, 'tests/F010Test.php' => 40.0];

        foreach ([1, 2, 3, 4, 7] as $shards) {
            $plan = whity_plan_shards($files, $timings, $shards);
            self::assertCount($shards, $plan);
            self::assertSame($files, self::union($plan), "{$shards} shards");
        }
    }

    public function testBalancesSecondsNotFileCount(): void
    {
        $timings = [
            'tests/ATest.php' => 60.0,
            'tests/BTest.php' => 30.0,
            'tests/CTest.php' => 20.0,
            'tests/DTest.php' => 10.0,
        ];

        $plan = whity_plan_shards(array_keys($timings), $timings, 2);

        // Round-robin over the sorted list would give {A, C} = 80 s and {B, D} = 40 s.
        self::assertSame(['tests/ATest.php'], $plan[0]['files']);
        self::assertSame(['tests/BTest.php', 'tests/CTest.php', 'tests/DTest.php'], $plan[1]['files']);
        self::assertSame(60.0, $plan[0]['seconds']);
        self::assertSame(60.0, $plan[1]['seconds']);
    }

    public function testUntimedFilesTakeTheMedianWeight(): void
    {
        $timings = ['tests/ATest.php' => 1.0, 'tests/BTest.php' => 5.0, 'tests/CTest.php' => 9.0];
        $files = [...array_keys($timings), 'tests/NewTest.php'];

        $plan = whity_plan_shards($files, $timings, 1);

        self::assertSame(20.0, $plan[0]['seconds']);
        self::assertSame(['tests/CTest.php', 'tests/BTest.php', 'tests/NewTest.php', 'tests/ATest.php'], $plan[0]['files']);
    }

    public function testPlanIsDeterministicWithoutTimings(): void
    {
        $files = ['tests/ATest.php', 'tests/BTest.php', 'tests/CTest.php'];

        self::assertSame(whity_plan_shards($files, [], 2), whity_plan_shards(array_reverse($files), [], 2));
        self::assertSame(['tests/ATest.php', 'tests/CTest.php'], whity_plan_shards($files, [], 2)[0]['files']);
    }

    public function testMoreShardsThanFilesLeavesShardsEmpty(): void
    {
        // The planner reports it; ci-test-shard.php is what refuses to run one.
        $plan = whity_plan_shards(['tests/ATest.php'], [], 2);

        self::assertSame([], $plan[1]['files']);
    }

    public function testTimingsAreSummedPerFileFromJunit(): void
    {
        $xml = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <testsuites>
              <testsuite name="shard">
                <testsuite name="A" file="/repo/tests/ATest.php">
                  <testcase name="one" file="/repo/tests/ATest.php" time="1.25"/>
                  <testcase name="two" file="/repo/tests/ATest.php" time="0.75"/>
                </testsuite>
                <testsuite name="B" file="/repo/tests/Sub/BTest.php">
                  <testcase name="one" file="/repo/tests/Sub/BTest.php" time="0.5"/>
                </testsuite>
              </testsuite>
            </testsuites>
            XML;

        self::assertSame(
            ['tests/ATest.php' => 2.0, 'tests/Sub/BTest.php' => 0.5],
            whity_timings_from_junit([$xml], '/repo')
        );
    }

    public function testCommittedTimingsCoverTheCurrentSuite(): void
    {
        $root = dirname(__DIR__, 2);
        $timings = json_decode((string) file_get_contents($root . '/scripts/ci-test-timings.json'), true);
        self::assertIsArray($timings);

        $missing = array_diff(whity_list_test_files($root), array_keys($timings));

        // Not zero: a new test file may land before anyone refreshes the file,
        // and it still runs. A large gap means the balance has gone stale.
        self::assertLessThan(
            50,
            count($missing),
            "scripts/ci-test-timings.json is missing timings for many test files; refresh it from the latest\n"
            . "`test-timings` CI artifact. Missing: " . implode(', ', array_slice($missing, 0, 10))
        );
    }
}
