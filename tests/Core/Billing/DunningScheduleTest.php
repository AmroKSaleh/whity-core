<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Whity\Core\Billing\DunningSchedule;

/**
 * The retry policy.
 *
 * This decides when somebody loses access to their account, so the tests are
 * written around the two ways that goes wrong: locking out a customer who paid
 * on time, and never chasing one who did not.
 */
final class DunningScheduleTest extends TestCase
{
    private function due(string $when = '2026-09-01 00:00:00'): DateTimeImmutable
    {
        return new DateTimeImmutable($when);
    }

    // ── reading an operator's schedule ───────────────────────────────────────

    /**
     * "1,3,7" is three attempts on days 1, 3 and 7 after the due date — not on
     * days 1, 4 and 11. Offsets are from the due date because "the third
     * attempt happens a week after it was due" is the sentence an operator is
     * writing, and cumulative intervals make them do arithmetic to find out
     * when their customer gets locked out.
     */
    public function testOffsetsAreFromTheDueDateAndNotFromEachOther(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 14);
        $due = $this->due();

        self::assertSame(3, $schedule->attemptCount());
        self::assertSame('2026-09-02', $schedule->attemptDueAt($due, 1)?->format('Y-m-d'));
        self::assertSame('2026-09-04', $schedule->attemptDueAt($due, 2)?->format('Y-m-d'));
        self::assertSame('2026-09-08', $schedule->attemptDueAt($due, 3)?->format('Y-m-d'));
        self::assertNull($schedule->attemptDueAt($due, 4), 'the policy is exhausted');
    }

    /**
     * "7,1,3" is the same policy written carelessly, not a different one.
     * Running the attempts in the given order would put the third before the
     * first.
     */
    public function testAnOutOfOrderScheduleIsTheSamePolicy(): void
    {
        self::assertSame(
            DunningSchedule::fromSettings('1,3,7', 14)->retryOffsetDays(),
            DunningSchedule::fromSettings('7,1,3', 14)->retryOffsetDays()
        );
    }

    public function testWhitespaceIsForgiven(): void
    {
        self::assertSame([1, 3, 7], DunningSchedule::fromSettings(' 1 , 3 ,7 ', 14)->retryOffsetDays());
    }

    /**
     * NEVER RETRYING IS A REAL CONFIGURATION, not a degenerate case. On a push
     * rail only the customer can act, so there is nothing to retry — but there
     * is still a day on which access is withdrawn.
     */
    public function testAnEmptyScheduleIsValidAndStillLocks(): void
    {
        $schedule = DunningSchedule::fromSettings('', 14);

        self::assertSame(0, $schedule->attemptCount());
        self::assertNull(DunningSchedule::parseProblem(''));
        self::assertTrue($schedule->shouldLock($this->due(), new DateTimeImmutable('2026-09-16')));
    }

    // ── bad input must not stop dunning ──────────────────────────────────────

    /**
     * A SETTINGS TYPO MUST NOT THROW HERE. This is read on the path that
     * decides whether somebody keeps access, and an exception there stops
     * dunning running at all — so nobody is ever chased, silently. Falling back
     * is the loud-in-the-right-place choice: the schedule still works, and
     * parseProblem() tells the settings screen what is wrong where somebody can
     * fix it.
     */
    public function testAMalformedScheduleFallsBackRatherThanThrowing(): void
    {
        $schedule = DunningSchedule::fromSettings('tomorrow, next week', 14);

        self::assertSame(
            DunningSchedule::conservativeDefault()->retryOffsetDays(),
            $schedule->retryOffsetDays()
        );
    }

    public function testTheProblemIsReportableForASettingsScreen(): void
    {
        self::assertNull(DunningSchedule::parseProblem('1,3,7'));
        self::assertStringContainsString('whole number', (string) DunningSchedule::parseProblem('soon'));
        self::assertStringContainsString('twice', (string) DunningSchedule::parseProblem('1,3,3'));
        self::assertStringContainsString('year', (string) DunningSchedule::parseProblem('1,400'));
    }

    /**
     * A retry on day 0 would fire the instant the invoice fell due, before the
     * payment that failed could possibly have been retried.
     */
    public function testARetryOnTheDueDateItselfIsRefused(): void
    {
        self::assertNotNull(DunningSchedule::parseProblem('0,3,7'));
    }

    public function testAnAbsurdNumberOfAttemptsIsRefused(): void
    {
        self::assertNotNull(DunningSchedule::parseProblem(implode(',', range(1, 25))));
    }

    // ── what the caller actually asks ────────────────────────────────────────

    /**
     * The attempt NUMBER, not a boolean, because the caller needs it for the
     * idempotency key: a retry of attempt three must be recognisably the same
     * request, and attempt four recognisably a different one.
     */
    public function testTheNextAttemptIsIdentifiedByNumber(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 14);
        $due = $this->due();

        self::assertSame(1, $schedule->nextAttemptDue($due, 0, new DateTimeImmutable('2026-09-02 06:00')));
        self::assertSame(2, $schedule->nextAttemptDue($due, 1, new DateTimeImmutable('2026-09-04 06:00')));
        self::assertSame(3, $schedule->nextAttemptDue($due, 2, new DateTimeImmutable('2026-09-08 06:00')));
    }

    /** Nothing is owed before the offset has passed. */
    public function testNoAttemptIsOwedEarly(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 14);

        self::assertNull($schedule->nextAttemptDue($this->due(), 0, new DateTimeImmutable('2026-09-01 12:00')));
        self::assertNull($schedule->nextAttemptDue($this->due(), 1, new DateTimeImmutable('2026-09-03 12:00')));
    }

    /** And nothing once the policy is spent, however long it has been. */
    public function testNothingIsOwedOnceThePolicyIsExhausted(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 14);

        self::assertNull($schedule->nextAttemptDue($this->due(), 3, new DateTimeImmutable('2027-01-01')));
    }

    // ── locking ──────────────────────────────────────────────────────────────

    /**
     * RUNNING OUT OF RETRIES IS NOT LOSING ACCESS. Collapsing the two removes
     * the period that matters commercially: after the platform has stopped
     * asking automatically, while a human still has time to pay.
     */
    public function testAccessSurvivesTheLastRetryUntilTheLockDay(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 14);
        $due = $this->due();

        // Day 8: every attempt is spent, and the tenant still has access.
        self::assertNull($schedule->nextAttemptDue($due, 3, new DateTimeImmutable('2026-09-09')));
        self::assertFalse($schedule->shouldLock($due, new DateTimeImmutable('2026-09-09')));

        // Day 15: now it is withdrawn.
        self::assertTrue($schedule->shouldLock($due, new DateTimeImmutable('2026-09-16')));
    }

    public function testAnInstanceMayLockOnTheLastRetryIfItChoosesTo(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 7);

        self::assertTrue($schedule->shouldLock($this->due(), new DateTimeImmutable('2026-09-08')));
    }

    /** Nothing locks before its day, however many attempts have failed. */
    public function testNothingLocksEarly(): void
    {
        $schedule = DunningSchedule::fromSettings('1,3,7', 14);

        self::assertFalse($schedule->shouldLock($this->due(), new DateTimeImmutable('2026-09-14 23:59')));
    }

    /**
     * Zero means access goes the moment the invoice is overdue — harsh, and
     * somebody's deliberate choice rather than something to refuse.
     */
    public function testAnInstanceMayLockImmediately(): void
    {
        self::assertTrue(
            DunningSchedule::fromSettings('', 0)->shouldLock($this->due(), new DateTimeImmutable('2026-09-01 00:01'))
        );
    }

    /**
     * The default leaves a fortnight, because locking out a paying customer
     * whose card expired costs far more than carrying them a few extra days.
     */
    public function testTheDefaultIsGenerousInTheDirectionThatMatters(): void
    {
        $schedule = DunningSchedule::conservativeDefault();

        self::assertSame([1, 3, 7], $schedule->retryOffsetDays());
        self::assertSame(14, $schedule->lockAfterDays());
    }
}
