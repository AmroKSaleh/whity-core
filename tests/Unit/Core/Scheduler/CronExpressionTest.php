<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Core\Scheduler\CronExpression;

/**
 * Unit tests for the hand-rolled 5-field cron parser (WC-scheduler): field
 * syntax (wildcards, steps, ranges, lists), matching, next-occurrence
 * computation, and the Vixie-cron day-of-month/day-of-week OR rule. All times UTC.
 *
 * Reference dates (UTC): 2026-01-01 = Thursday, 2026-01-04 = Sunday,
 * 2026-01-05 = Monday.
 */
final class CronExpressionTest extends TestCase
{
    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function testWildcardMatchesEveryMinute(): void
    {
        $cron = new CronExpression('* * * * *');
        self::assertTrue($cron->matches($this->at('2026-01-05 09:37:00')));
        self::assertTrue($cron->matches($this->at('2026-07-29 23:59:00')));
    }

    public function testStepMinutes(): void
    {
        $cron = new CronExpression('*/15 * * * *');
        self::assertTrue($cron->matches($this->at('2026-01-05 09:00:00')));
        self::assertTrue($cron->matches($this->at('2026-01-05 09:15:00')));
        self::assertTrue($cron->matches($this->at('2026-01-05 09:45:00')));
        self::assertFalse($cron->matches($this->at('2026-01-05 09:07:00')));
    }

    public function testHourAndExactMinute(): void
    {
        $cron = new CronExpression('0 9 * * *');
        self::assertTrue($cron->matches($this->at('2026-01-05 09:00:00')));
        self::assertFalse($cron->matches($this->at('2026-01-05 09:01:00')));
        self::assertFalse($cron->matches($this->at('2026-01-05 10:00:00')));
    }

    public function testHourRangeAndList(): void
    {
        $cron = new CronExpression('0 9-17 * * *');
        self::assertTrue($cron->matches($this->at('2026-01-05 09:00:00')));
        self::assertTrue($cron->matches($this->at('2026-01-05 17:00:00')));
        self::assertFalse($cron->matches($this->at('2026-01-05 18:00:00')));

        $list = new CronExpression('0,30 * * * *');
        self::assertTrue($list->matches($this->at('2026-01-05 09:00:00')));
        self::assertTrue($list->matches($this->at('2026-01-05 09:30:00')));
        self::assertFalse($list->matches($this->at('2026-01-05 09:15:00')));
    }

    public function testDayOfMonth(): void
    {
        $cron = new CronExpression('30 14 1 * *');
        self::assertTrue($cron->matches($this->at('2026-01-01 14:30:00')));
        self::assertFalse($cron->matches($this->at('2026-01-02 14:30:00')));
    }

    public function testDayOfWeekSundayAcceptsBoth0And7(): void
    {
        $sun0 = new CronExpression('0 0 * * 0');
        $sun7 = new CronExpression('0 0 * * 7');
        self::assertTrue($sun0->matches($this->at('2026-01-04 00:00:00')), '2026-01-04 is Sunday');
        self::assertTrue($sun7->matches($this->at('2026-01-04 00:00:00')));
        self::assertFalse($sun0->matches($this->at('2026-01-05 00:00:00')), '2026-01-05 is Monday');
    }

    public function testWeekdayRange(): void
    {
        $cron = new CronExpression('0 9 * * 1-5'); // 09:00 Mon–Fri
        self::assertTrue($cron->matches($this->at('2026-01-05 09:00:00')), 'Monday');
        self::assertFalse($cron->matches($this->at('2026-01-04 09:00:00')), 'Sunday excluded');
    }

    public function testDomAndDowBothRestrictedIsOr(): void
    {
        // Vixie rule: with BOTH dom and dow restricted, match if EITHER matches.
        $cron = new CronExpression('0 0 1 * 0'); // the 1st OR any Sunday
        self::assertTrue($cron->matches($this->at('2026-01-01 00:00:00')), 'the 1st (a Thursday) — dom matches');
        self::assertTrue($cron->matches($this->at('2026-01-04 00:00:00')), 'a Sunday (not the 1st) — dow matches');
        self::assertFalse($cron->matches($this->at('2026-01-05 00:00:00')), 'Monday, not the 1st — neither');
    }

    public function testNextRunAfterIsStrictlyLaterAndMinuteAligned(): void
    {
        $cron = new CronExpression('*/15 * * * *');
        $next = $cron->nextRunAfter($this->at('2026-01-05 09:00:30'));
        self::assertSame('2026-01-05 09:15:00', $next->format('Y-m-d H:i:s'));

        // Exactly on a match → returns the NEXT one (strictly after).
        $next2 = $cron->nextRunAfter($this->at('2026-01-05 09:15:00'));
        self::assertSame('2026-01-05 09:30:00', $next2->format('Y-m-d H:i:s'));
    }

    public function testNextRunAfterRollsToNextDay(): void
    {
        $cron = new CronExpression('0 0 * * *'); // daily midnight
        $next = $cron->nextRunAfter($this->at('2026-01-05 09:00:00'));
        self::assertSame('2026-01-06 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testNextRunAfterMonthly(): void
    {
        $cron = new CronExpression('0 3 1 * *'); // 03:00 on the 1st
        $next = $cron->nextRunAfter($this->at('2026-01-15 12:00:00'));
        self::assertSame('2026-02-01 03:00:00', $next->format('Y-m-d H:i:s'));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidExpressions(): array
    {
        return [
            ['* * * *'],        // 4 fields
            ['* * * * * *'],    // 6 fields
            ['60 * * * *'],     // minute out of range
            ['* 24 * * *'],     // hour out of range
            ['* * 0 * *'],      // day-of-month < 1
            ['* * * 13 *'],     // month out of range
            ['* * * * 8'],      // day-of-week > 7
            ['abc * * * *'],    // non-numeric
            ['*/0 * * * *'],    // zero step
            ['5-1 * * * *'],    // inverted range
            [''],               // empty
        ];
    }

    /**
     * @dataProvider invalidExpressions
     */
    public function testInvalidExpressionsAreRejected(string $expression): void
    {
        self::assertFalse(CronExpression::isValid($expression), "expected invalid: '{$expression}'");
        $this->expectException(InvalidArgumentException::class);
        new CronExpression($expression);
    }

    public function testValidExpressionsPassIsValid(): void
    {
        foreach (['* * * * *', '*/5 * * * *', '0 9-17 * * 1-5', '0,30 0 1,15 * *', '0 0 * * 7'] as $expr) {
            self::assertTrue(CronExpression::isValid($expr), "expected valid: '{$expr}'");
        }
    }
}
