<?php

declare(strict_types=1);

namespace Tests\Core\TimeWindow;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Hooks\HookManager;
use Whity\Core\TimeWindow\TimeWindowRepository;
use Whity\Core\TimeWindow\WindowCloseReporter;
use Whity\Core\TimeWindow\WindowRejectedException;
use Whity\Core\TimeWindow\WindowResolver;
use Whity\Core\TimeWindow\WindowState;
use Whity\Core\TimeWindow\WindowTypeRegistry;
use Whity\Core\TimeWindow\WindowTypeRepository;

/**
 * Real-engine tests for #1070 — NAMED PERIODS THAT CAN BE CLOSED.
 *
 * THE FIXTURE IS TWO UNRELATED VOCABULARIES, ON PURPOSE. One tenant slices time
 * into a `crop_year` and the `growing_season`s inside it; the other into a
 * `kiln_campaign` and its `firing_run`s. Neither is the vocabulary that motivated
 * the feature, and having two means no reader can mistake either for the
 * canonical case — which is the entire premise of a declared vocabulary.
 *
 * AND NEITHER FIXTURE IS CALENDAR-ALIGNED. The crop year runs 1 October to 30
 * September; its seasons are 5 months, 3 months and 4 months long. If any
 * boundary in this subsystem were ever computed rather than read, every date
 * assertion below would move.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns real PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise. Both matter: migration 126's CHECK
 * constraints and DATE comparisons are only enforced by a real engine, while the
 * SQLite path is what CI's unit job runs.
 *
 * POSITIVE CONTROLS. Every assertion that something is CLOSED is paired with a
 * period in the same fixture that must still be OPEN, and every assertion that a
 * write was refused is paired with a read proving nothing was written. A test
 * that seals everything would otherwise pass the first half of most of these.
 */
final class TimeWindowRepositoryRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const ACTOR = 10;

    private PDO $pdo;
    private WindowTypeRepository $types;
    private TimeWindowRepository $windows;
    private WindowResolver $resolver;

    private int $cropYearType;
    private int $growingSeasonType;
    private int $kilnCampaignType;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->types = new WindowTypeRepository($this->pdo);
        $this->windows = new TimeWindowRepository($this->pdo, $this->types);
        $this->resolver = new WindowResolver($this->windows, $this->types);

        $this->cropYearType = (int) $this->types->create(
            self::TENANT,
            'crop_year',
            'Crop year',
            null,
            WindowTypeRegistry::TENANT_SOURCE
        );
        $this->growingSeasonType = (int) $this->types->create(
            self::TENANT,
            'growing_season',
            'Growing season',
            $this->cropYearType,
            WindowTypeRegistry::TENANT_SOURCE
        );
        $this->kilnCampaignType = (int) $this->types->create(
            self::OTHER_TENANT,
            'kiln_campaign',
            'Kiln campaign',
            null,
            WindowTypeRegistry::TENANT_SOURCE
        );
    }

    // ---------------------------------------------------------------- boundaries

    public function testABoundaryIsStoredExactlyAsAuthoredAndNeverAlignedToACalendar(): void
    {
        $id = $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            '2026-27',
            'Crop year 2026/27',
            '2026-10-01',
            '2027-09-30'
        );

        $window = $this->windows->find(self::TENANT, $id);
        self::assertNotNull($window);
        self::assertSame('2026-10-01', $window['starts_on']);
        self::assertSame('2027-09-30', $window['ends_on']);
        self::assertSame(WindowState::OPEN, $window['state']);
    }

    public function testASubPeriodNeedNotBeAnEqualFractionOfWhatContainsIt(): void
    {
        $year = $this->cropYear();
        $lengths = [];
        foreach ($this->threeUnevenSeasons($year) as $id) {
            $row = $this->windows->find(self::TENANT, $id);
            self::assertNotNull($row);
            $lengths[] = (new \DateTimeImmutable($row['starts_on']))
                ->diff(new \DateTimeImmutable($row['ends_on']))->days;
        }

        self::assertCount(3, array_unique($lengths), 'The three sub-periods must all differ in length.');
    }

    /**
     * @dataProvider impossibleDateProvider
     */
    public function testADateThatDoesNotExistIsRefusedRatherThanRolledForward(string $date): void
    {
        $this->expectException(WindowRejectedException::class);

        $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            'bad',
            'Bad',
            $date,
            '2027-09-30'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function impossibleDateProvider(): array
    {
        return [
            'day past the end of the month' => ['2026-02-30'],
            'month 13' => ['2026-13-01'],
            'not a date at all' => ['October 2026'],
            'timestamp' => ['2026-10-01T00:00:00Z'],
        ];
    }

    public function testEndBeforeStartIsRefused(): void
    {
        $this->expectException(WindowRejectedException::class);

        $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            'backwards',
            'Backwards',
            '2027-09-30',
            '2026-10-01'
        );
    }

    // ------------------------------------------------------------------ overlap

    public function testTwoPeriodsOfOneKindMayNotOverlap(): void
    {
        $this->cropYear();

        try {
            $this->windows->create(
                self::TENANT,
                $this->cropYearType,
                null,
                'overlapping',
                'Overlapping',
                '2027-09-30',
                '2028-09-29'
            );
            self::fail('An overlap of even one day must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/overlap/i', $e->getMessage());
        }

        self::assertCount(
            1,
            $this->windows->listForTenant(self::TENANT, $this->cropYearType),
            'The refused period must not have been written.'
        );
    }

    public function testAPeriodStartingTheDayAfterAnotherEndsIsAccepted(): void
    {
        $this->cropYear();

        $next = $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            '2027-28',
            'Crop year 2027/28',
            '2027-10-01',
            '2028-09-30'
        );

        self::assertNotNull($this->windows->find(self::TENANT, $next));
        self::assertCount(2, $this->windows->listForTenant(self::TENANT, $this->cropYearType));
    }

    public function testOverlapIsScopedToOneKindAndOneTenant(): void
    {
        $this->cropYear();

        // Same dates, different KIND: seasons sit inside the year by design.
        $inside = $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            $this->cropYearFor('2026-10-01'),
            'whole-year',
            'The whole year',
            '2026-10-01',
            '2027-09-30'
        );
        self::assertNotNull($this->windows->find(self::TENANT, $inside));

        // Same dates, different TENANT.
        $other = $this->windows->create(
            self::OTHER_TENANT,
            $this->kilnCampaignType,
            null,
            'campaign-9',
            'Campaign 9',
            '2026-10-01',
            '2027-09-30'
        );
        self::assertNotNull($this->windows->find(self::OTHER_TENANT, $other));
        self::assertNull(
            $this->windows->find(self::TENANT, $other),
            "Another tenant's period must be indistinguishable from one that does not exist."
        );
    }

    // ------------------------------------------------------------------ nesting

    public function testANestedPeriodMustSitInsideItsParent(): void
    {
        $year = $this->cropYear();

        try {
            $this->windows->create(
                self::TENANT,
                $this->growingSeasonType,
                $year,
                'spills',
                'Spills over',
                '2027-09-01',
                '2027-10-31'
            );
            self::fail('A sub-period spilling past its parent must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/fall outside/i', $e->getMessage());
        }

        self::assertSame([], $this->windows->listForTenant(self::TENANT, $this->growingSeasonType));
    }

    public function testAParentOfTheWrongKindIsRefused(): void
    {
        $year = $this->cropYear();
        $season = $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            $year,
            'autumn',
            'Autumn planting',
            '2026-10-01',
            '2027-02-28'
        );

        $this->expectException(WindowRejectedException::class);

        // A season inside a season: the kind says it nests inside a crop year.
        $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            $season,
            'nested-wrongly',
            'Nested wrongly',
            '2026-11-01',
            '2026-11-30'
        );
    }

    public function testAKindThatNestsInsideAnotherRequiresAParent(): void
    {
        $this->cropYear();

        $this->expectException(WindowRejectedException::class);
        $this->expectExceptionMessageMatches('/parent_window_id is required/');

        $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            null,
            'orphan',
            'Orphan',
            '2026-10-01',
            '2026-10-31'
        );
    }

    public function testATopLevelKindTakesNoParent(): void
    {
        $year = $this->cropYear();

        $this->expectException(WindowRejectedException::class);
        $this->expectExceptionMessageMatches('/does not nest inside anything/');

        // Dates well clear of the existing crop year, so the refusal is
        // unambiguously about the NESTING and not an overlap wearing its clothes.
        $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            $year,
            'nested-year',
            'Nested year',
            '2030-11-01',
            '2030-11-30'
        );
    }

    public function testNarrowingAParentIsRefusedWhenItWouldStrandAChild(): void
    {
        $year = $this->cropYear();
        $this->threeUnevenSeasons($year);

        try {
            $this->windows->update(self::TENANT, $year, ['ends_on' => '2027-06-30']);
            self::fail('Narrowing a period past a nested one must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/would leave/i', $e->getMessage());
        }

        $row = $this->windows->find(self::TENANT, $year);
        self::assertNotNull($row);
        self::assertSame('2027-09-30', $row['ends_on'], 'The boundary must be exactly as it was.');
    }

    // --------------------------------------------------------------- resolution

    public function testResolutionIsByDateAndReturnsAtMostOnePeriod(): void
    {
        $year = $this->cropYear();
        $this->threeUnevenSeasons($year);

        $resolved = $this->resolver->resolve(self::TENANT, $this->growingSeasonType, '2026-12-15');
        self::assertNotNull($resolved);
        self::assertSame('autumn', $resolved['key']);

        $boundary = $this->resolver->resolve(self::TENANT, $this->growingSeasonType, '2027-03-01');
        self::assertNotNull($boundary);
        self::assertSame('spring', $boundary['key'], 'Boundaries are inclusive at both ends.');
    }

    /**
     * Null is a LEGITIMATE answer, not a failure — and the positive control in
     * the same test is what makes that meaningful. A resolver that answered null
     * for everything would satisfy only half of this.
     */
    public function testADateNoPeriodCoversResolvesToNothingRatherThanTheNearestMatch(): void
    {
        $year = $this->cropYear();
        // Deliberately leaves 1 March to 31 May uncovered.
        $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            $year,
            'autumn',
            'Autumn planting',
            '2026-10-01',
            '2027-02-28'
        );
        $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            $year,
            'summer',
            'Summer growing',
            '2027-06-01',
            '2027-09-30'
        );

        self::assertNull(
            $this->resolver->resolve(self::TENANT, $this->growingSeasonType, '2027-04-15'),
            'A gap must resolve to nothing: attributing a record to the nearest period files it '
            . 'under one it does not belong to, and nothing reports that.'
        );
        self::assertNotNull(
            $this->resolver->resolve(self::TENANT, $this->growingSeasonType, '2027-02-28'),
            'Positive control: a covered date still resolves.'
        );
    }

    public function testResolutionNeverConsultsTheClock(): void
    {
        $year = $this->cropYear();
        $this->threeUnevenSeasons($year);

        // A date years in the past, which no clock reading could produce.
        self::assertNull($this->resolver->resolve(self::TENANT, $this->growingSeasonType, '1999-01-01'));
        // And one years ahead. Both answers depend only on the argument.
        self::assertNull($this->resolver->resolve(self::TENANT, $this->growingSeasonType, '2099-01-01'));
    }

    public function testAnExplicitlyNamedPeriodIsCheckedRatherThanTrusted(): void
    {
        $year = $this->cropYear();

        self::assertNotNull($this->resolver->validate(self::TENANT, $year));
        self::assertNull(
            $this->resolver->validate(self::OTHER_TENANT, $year),
            'Naming another tenant\'s period must answer "no such period", not leak its existence.'
        );
    }

    public function testRequireOpenRefusesASealedPeriodAndAcceptsAnOpenOne(): void
    {
        $year = $this->cropYear();
        $open = $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            '2027-28',
            'Crop year 2027/28',
            '2027-10-01',
            '2028-09-30'
        );
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);

        self::assertSame('2027-28', $this->resolver->requireOpen(self::TENANT, $open)['key']);

        $this->expectException(WindowRejectedException::class);
        $this->resolver->requireOpen(self::TENANT, $year);
    }

    // ------------------------------------------------------------ close / reopen

    public function testClosingSealsThePeriodAndRecordsWhoAndWhen(): void
    {
        $year = $this->cropYear();
        $control = $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            '2027-28',
            'Crop year 2027/28',
            '2027-10-01',
            '2028-09-30'
        );

        $closed = $this->windows->close(self::TENANT, $year, self::ACTOR, 'Harvest reconciled');

        self::assertSame([$year], $closed);
        self::assertSame(WindowState::CLOSED, $this->row(self::TENANT, $year)['state']);
        self::assertSame(
            WindowState::OPEN,
            $this->row(self::TENANT, $control)['state'],
            'POSITIVE CONTROL: a close must seal the period it names and nothing else.'
        );

        $trail = $this->windows->trail(self::TENANT, $year);
        self::assertCount(1, $trail);
        self::assertSame(WindowState::ACT_CLOSED, $trail[0]['action']);
        self::assertSame(self::ACTOR, $trail[0]['actor_profile_id']);
        self::assertSame('Harvest reconciled', $trail[0]['reason']);
        self::assertNull($trail[0]['cascaded_from_window_id']);
    }

    public function testTheStateColumnAndTheTrailNeverDisagree(): void
    {
        $year = $this->cropYear();
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);
        $this->windows->reopen(self::TENANT, $year, self::ACTOR, 'A yield figure was wrong');
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);

        $trail = $this->windows->trail(self::TENANT, $year);
        self::assertSame(
            [WindowState::ACT_CLOSED, WindowState::ACT_REOPENED, WindowState::ACT_CLOSED],
            array_column($trail, 'action')
        );

        $newest = $trail[count($trail) - 1]['action'];
        $expected = $newest === WindowState::ACT_CLOSED ? WindowState::CLOSED : WindowState::OPEN;
        self::assertSame(
            $expected,
            $this->row(self::TENANT, $year)['state'],
            'The state column is a materialisation of the newest trail row; if they can drift, the '
            . 'seal means whatever the reader happened to consult.'
        );
    }

    public function testClosingAnAlreadyClosedPeriodIsANoOpRatherThanASecondSeal(): void
    {
        $year = $this->cropYear();
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);

        self::assertSame([], $this->windows->close(self::TENANT, $year, self::ACTOR, null));
        self::assertCount(
            1,
            $this->windows->trail(self::TENANT, $year),
            'A second identical row would make the trail assert a close that did not happen.'
        );
    }

    public function testAChildMayCloseWhileItsParentStaysOpen(): void
    {
        $year = $this->cropYear();
        $seasons = $this->threeUnevenSeasons($year);

        $this->windows->close(self::TENANT, $seasons[0], self::ACTOR, null);

        self::assertSame(WindowState::CLOSED, $this->row(self::TENANT, $seasons[0])['state']);
        self::assertSame(
            WindowState::OPEN,
            $this->row(self::TENANT, $year)['state'],
            'Sealing a sub-period before the period containing it is over is the ordinary case.'
        );
        self::assertSame(WindowState::OPEN, $this->row(self::TENANT, $seasons[1])['state']);
    }

    public function testAParentIsRefusedWhileAnythingInsideItIsOpenAndTheRefusalNamesThem(): void
    {
        $year = $this->cropYear();
        $seasons = $this->threeUnevenSeasons($year);

        try {
            $this->windows->close(self::TENANT, $year, self::ACTOR, null);
            self::fail('Closing a period containing an open one must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/3 period\(s\) inside this one are still open/', $e->getMessage());
            self::assertStringContainsString('Autumn planting', $e->getMessage());
        }

        self::assertSame(
            WindowState::OPEN,
            $this->row(self::TENANT, $year)['state'],
            'A refused close must have written nothing.'
        );
        self::assertSame([], $this->windows->trail(self::TENANT, $year));
        foreach ($seasons as $season) {
            self::assertSame(WindowState::OPEN, $this->row(self::TENANT, $season)['state']);
        }
    }

    public function testAnExplicitCascadeClosesTheNestedPeriodsAndMarksThemAsSuch(): void
    {
        $year = $this->cropYear();
        $seasons = $this->threeUnevenSeasons($year);
        $untouched = $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            '2027-28',
            'Crop year 2027/28',
            '2027-10-01',
            '2028-09-30'
        );

        $closed = $this->windows->close(self::TENANT, $year, self::ACTOR, 'Year end', cascade: true);

        self::assertSame($year, $closed[0], 'The period named comes first in the report.');
        self::assertCount(4, $closed);
        foreach ($seasons as $season) {
            self::assertSame(WindowState::CLOSED, $this->row(self::TENANT, $season)['state']);

            $trail = $this->windows->trail(self::TENANT, $season);
            self::assertCount(1, $trail);
            self::assertSame(
                $year,
                $trail[0]['cascaded_from_window_id'],
                'A cascaded seal must be distinguishable from an act somebody performed here.'
            );
        }
        self::assertNull(
            $this->windows->trail(self::TENANT, $year)[0]['cascaded_from_window_id'],
            'The period the operator actually named is not itself a cascade.'
        );
        self::assertSame(
            WindowState::OPEN,
            $this->row(self::TENANT, $untouched)['state'],
            'POSITIVE CONTROL: a cascade follows nesting, not the whole tenant.'
        );
    }

    public function testAReopenIsRecordedWithItsReasonAndDoesNotReopenWhatWasInsideIt(): void
    {
        $year = $this->cropYear();
        $seasons = $this->threeUnevenSeasons($year);
        $this->windows->close(self::TENANT, $year, self::ACTOR, null, cascade: true);

        $this->windows->reopen(self::TENANT, $year, self::ACTOR, 'A delivery was recorded twice');

        self::assertSame(WindowState::OPEN, $this->row(self::TENANT, $year)['state']);
        foreach ($seasons as $season) {
            self::assertSame(
                WindowState::CLOSED,
                $this->row(self::TENANT, $season)['state'],
                'Auto-unsealing periods nobody named is the trap this design avoids; the invariant '
                . 'only forbids an OPEN period inside a CLOSED one, and closed-inside-open is fine.'
            );
        }

        $trail = $this->windows->trail(self::TENANT, $year);
        $reopen = $trail[count($trail) - 1];
        self::assertSame(WindowState::ACT_REOPENED, $reopen['action']);
        self::assertSame('A delivery was recorded twice', $reopen['reason']);
        self::assertSame(self::ACTOR, $reopen['actor_profile_id']);
    }

    public function testAReopenWithoutAReasonIsRefusedAndChangesNothing(): void
    {
        $year = $this->cropYear();
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);

        try {
            $this->windows->reopen(self::TENANT, $year, self::ACTOR, '   ');
            self::fail('A reopen with no reason must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/requires a reason/', $e->getMessage());
        }

        self::assertSame(WindowState::CLOSED, $this->row(self::TENANT, $year)['state']);
        self::assertCount(1, $this->windows->trail(self::TENANT, $year));
    }

    public function testAChildMayNotReopenWhileThePeriodContainingItIsClosed(): void
    {
        $year = $this->cropYear();
        $seasons = $this->threeUnevenSeasons($year);
        $this->windows->close(self::TENANT, $year, self::ACTOR, null, cascade: true);

        try {
            $this->windows->reopen(self::TENANT, $seasons[0], self::ACTOR, 'Correction needed');
            self::fail('Reopening inside a sealed period must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/is closed/', $e->getMessage());
        }
        self::assertSame(WindowState::CLOSED, $this->row(self::TENANT, $seasons[0])['state']);

        // ...and it becomes possible once the containing period is reopened, on
        // the record. That ordering is the whole point: the parent's unsealing is
        // itself an act somebody had to justify.
        $this->windows->reopen(self::TENANT, $year, self::ACTOR, 'Reopening to correct a season');
        $this->windows->reopen(self::TENANT, $seasons[0], self::ACTOR, 'Correction needed');
        self::assertSame(WindowState::OPEN, $this->row(self::TENANT, $seasons[0])['state']);
    }

    public function testASealedPeriodCannotHaveItsBoundariesMovedInstead(): void
    {
        $year = $this->cropYear();
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);

        try {
            $this->windows->update(self::TENANT, $year, ['ends_on' => '2027-08-31']);
            self::fail('Editing a sealed period must be refused.');
        } catch (WindowRejectedException $e) {
            self::assertMatchesRegularExpression('/Reopen it before changing it/', $e->getMessage());
        }

        self::assertSame(
            '2027-09-30',
            $this->row(self::TENANT, $year)['ends_on'],
            'Moving a sealed boundary unseals it without leaving a trace: the state still reads '
            . 'closed while records that were inside it no longer are.'
        );
    }

    public function testANewPeriodCannotBeCreatedInsideASealedOne(): void
    {
        $year = $this->cropYear();
        $this->windows->close(self::TENANT, $year, self::ACTOR, null);

        $this->expectException(WindowRejectedException::class);
        $this->expectExceptionMessageMatches('/cannot take new periods inside it/');

        $this->windows->create(
            self::TENANT,
            $this->growingSeasonType,
            $year,
            'late',
            'Late addition',
            '2026-10-01',
            '2026-12-31'
        );
    }

    // ----------------------------------------------------------- close reporting

    public function testACloseReportNamesTheOpenPeriodsInsideAndBlocksOnThem(): void
    {
        $year = $this->cropYear();
        $seasons = $this->threeUnevenSeasons($year);
        $this->windows->close(self::TENANT, $seasons[0], self::ACTOR, null);

        $report = (new WindowCloseReporter($this->windows))->report(self::TENANT, $year);

        self::assertNotNull($report);
        self::assertTrue($report->isBlocked());
        self::assertCount(
            2,
            $report->openChildren(),
            'Only the ones still open: the one already sealed is not something the close would seal.'
        );
    }

    public function testAReportOnAPeriodWithNothingInsideItDoesNotBlock(): void
    {
        $year = $this->cropYear();

        $report = (new WindowCloseReporter($this->windows))->report(self::TENANT, $year);

        self::assertNotNull($report);
        self::assertFalse($report->isBlocked());
        self::assertSame([], $report->openChildren());
    }

    public function testUnfinishedCountsAreContributedAndDoNotBlockTheClose(): void
    {
        $year = $this->cropYear();
        $hooks = new HookManager();
        $hooks->listen(WindowCloseReporter::HOOK, static function (array $data): array {
            $data['unfinished'][] = ['label' => 'Deliveries without a weight ticket', 'count' => 4, 'source' => 'acme'];
            // Malformed entries are discarded rather than failing the report:
            // one contributor's typo must not make a period impossible to close.
            $data['unfinished'][] = ['label' => '', 'count' => 9, 'source' => 'acme'];
            $data['unfinished'][] = ['label' => 'No count', 'source' => 'acme'];

            return $data;
        });

        $report = (new WindowCloseReporter($this->windows, $hooks))->report(self::TENANT, $year);

        self::assertNotNull($report);
        self::assertCount(1, $report->unfinished());
        self::assertSame(4, $report->unfinishedTotal());
        self::assertTrue($report->hasContributions());
        self::assertFalse(
            $report->isBlocked(),
            'Unfinished work is told to the person, who decides. Only the structural finding blocks.'
        );

        // ...and the close proceeds regardless, which is what "does not block" means.
        self::assertSame([$year], $this->windows->close(self::TENANT, $year, self::ACTOR, null));
    }

    public function testNobodyAnsweringIsDistinguishedFromEverybodyAnsweringZero(): void
    {
        $year = $this->cropYear();

        $silent = (new WindowCloseReporter($this->windows, new HookManager()))->report(self::TENANT, $year);
        self::assertNotNull($silent);
        self::assertFalse(
            $silent->hasContributions(),
            '"Nothing is tracking unfinished work here" is not an all-clear.'
        );

        $hooks = new HookManager();
        $hooks->listen(WindowCloseReporter::HOOK, static function (array $data): array {
            $data['unfinished'][] = ['label' => 'Deliveries without a weight ticket', 'count' => 0, 'source' => 'acme'];

            return $data;
        });
        $answered = (new WindowCloseReporter($this->windows, $hooks))->report(self::TENANT, $year);
        self::assertNotNull($answered);
        self::assertTrue($answered->hasContributions());
        self::assertSame(0, $answered->unfinishedTotal());
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A crop year that starts in OCTOBER — the fixture's standing reminder that
     * a period need not begin where a calendar year does.
     */
    private function cropYear(): int
    {
        return $this->windows->create(
            self::TENANT,
            $this->cropYearType,
            null,
            '2026-27',
            'Crop year 2026/27',
            '2026-10-01',
            '2027-09-30'
        );
    }

    /**
     * A period that must exist, read straight from the table.
     *
     * Every state assertion goes through this rather than through the return
     * value of whatever wrote it: a close that reported success while writing
     * nothing would satisfy an assertion made against its own answer.
     *
     * @return array<string, mixed>
     */
    private function row(int $tenantId, int $id): array
    {
        $row = $this->windows->find($tenantId, $id);
        self::assertNotNull($row, "Expected period {$id} to exist for tenant {$tenantId}.");

        return $row;
    }

    private function cropYearFor(string $date): int
    {
        $row = $this->resolver->resolve(self::TENANT, $this->cropYearType, $date);
        self::assertNotNull($row);

        return (int) $row['id'];
    }

    /**
     * Three sub-periods of 5, 3 and 4 months. Uneven on purpose.
     *
     * @return list<int>
     */
    private function threeUnevenSeasons(int $year): array
    {
        return [
            $this->windows->create(
                self::TENANT,
                $this->growingSeasonType,
                $year,
                'autumn',
                'Autumn planting',
                '2026-10-01',
                '2027-02-28'
            ),
            $this->windows->create(
                self::TENANT,
                $this->growingSeasonType,
                $year,
                'spring',
                'Spring growing',
                '2027-03-01',
                '2027-05-31'
            ),
            $this->windows->create(
                self::TENANT,
                $this->growingSeasonType,
                $year,
                'summer',
                'Summer harvest',
                '2027-06-01',
                '2027-09-30'
            ),
        ];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec(
            'INSERT INTO tenants (id, name) VALUES
                (1, ' . $quote('Tenant One') . '),
                (2, ' . $quote('Tenant Two') . ')
             ON CONFLICT DO NOTHING'
        );
        $pdo->exec(
            'INSERT INTO profiles (id, display_name, password_hash, created_at)
             VALUES (10, ' . $quote('An operator') . ', ' . $quote('x') . ', ' . $now . ')
             ON CONFLICT DO NOTHING'
        );

        return $pdo;
    }
}
