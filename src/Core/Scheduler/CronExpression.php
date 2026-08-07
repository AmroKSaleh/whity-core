<?php

declare(strict_types=1);

namespace Whity\Core\Scheduler;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Minimal, dependency-free standard 5-field cron parser (WC-scheduler):
 *
 *     minute  hour  day-of-month  month  day-of-week
 *      0-59   0-23      1-31       1-12      0-6   (0 = Sunday; 7 also accepted)
 *
 * Each field supports a wildcard, step values, ranges (`a-b`), range-steps, and
 * comma lists of those (standard Vixie-cron syntax). All times are in **UTC** (the scheduler
 * stores next_run_at in UTC and ticks in UTC) — document per-tenant timezones as
 * a future refinement. Hand-rolled rather than adding a composer dependency (the
 * project's third-party-dependency policy), mirroring the ULID generator.
 *
 * Day-of-month / day-of-week follow the Vixie-cron rule: when BOTH are
 * restricted (neither is `*`), a time matches if EITHER matches; otherwise both
 * must match.
 */
final class CronExpression
{
    /** @var list<int> */
    private array $minutes;
    /** @var list<int> */
    private array $hours;
    /** @var list<int> */
    private array $daysOfMonth;
    /** @var list<int> */
    private array $months;
    /** @var list<int> */
    private array $daysOfWeek;

    private bool $domRestricted;
    private bool $dowRestricted;

    /** Upper bound on the forward scan in nextRunAfter (~2.85 years of minutes). */
    private const SCAN_LIMIT_MINUTES = 1_500_000;

    public function __construct(string $expression)
    {
        $fields = preg_split('/\s+/', trim($expression)) ?: [];
        if (count($fields) !== 5) {
            throw new InvalidArgumentException('Cron expression must have exactly 5 fields (minute hour day-of-month month day-of-week)');
        }

        [$min, $hour, $dom, $mon, $dow] = $fields;

        $this->minutes = self::parseField($min, 0, 59);
        $this->hours = self::parseField($hour, 0, 23);
        $this->daysOfMonth = self::parseField($dom, 1, 31);
        $this->months = self::parseField($mon, 1, 12);
        $this->daysOfWeek = self::normalizeDaysOfWeek(self::parseField($dow, 0, 7));

        $this->domRestricted = trim($dom) !== '*';
        $this->dowRestricted = trim($dow) !== '*';
    }

    /**
     * Whether the expression is syntactically valid (for input validation).
     */
    public static function isValid(string $expression): bool
    {
        try {
            new self($expression);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /**
     * Does the given instant (to the minute) match this schedule?
     */
    public function matches(DateTimeImmutable $time): bool
    {
        $time = $time->setTimezone(new DateTimeZone('UTC'));
        $minute = (int) $time->format('i');
        $hour = (int) $time->format('G');
        $dom = (int) $time->format('j');
        $month = (int) $time->format('n');
        $dow = (int) $time->format('w'); // 0 (Sun) .. 6 (Sat)

        if (!in_array($minute, $this->minutes, true)
            || !in_array($hour, $this->hours, true)
            || !in_array($month, $this->months, true)) {
            return false;
        }

        $domOk = in_array($dom, $this->daysOfMonth, true);
        $dowOk = in_array($dow, $this->daysOfWeek, true);

        // Vixie-cron day rule: both restricted → OR; otherwise → AND.
        if ($this->domRestricted && $this->dowRestricted) {
            return $domOk || $dowOk;
        }

        return $domOk && $dowOk;
    }

    /**
     * The next matching instant STRICTLY AFTER $after (minute-aligned, UTC), so
     * a job that just fired advances to its next occurrence rather than
     * re-firing on the same minute.
     */
    public function nextRunAfter(DateTimeImmutable $after): DateTimeImmutable
    {
        $t = $after->setTimezone(new DateTimeZone('UTC'))
            ->setTime((int) $after->format('H'), (int) $after->format('i'), 0)
            ->modify('+1 minute');

        for ($i = 0; $i < self::SCAN_LIMIT_MINUTES; $i++) {
            if ($this->matches($t)) {
                return $t;
            }
            $t = $t->modify('+1 minute');
        }

        throw new InvalidArgumentException('Cron expression has no matching time within the scan window (impossible schedule?)');
    }

    /**
     * Parse a single cron field into the explicit sorted list of allowed values.
     *
     * @return list<int>
     */
    private static function parseField(string $field, int $min, int $max): array
    {
        $field = trim($field);
        if ($field === '') {
            throw new InvalidArgumentException('Empty cron field');
        }

        $values = [];
        foreach (explode(',', $field) as $part) {
            $part = trim($part);

            $step = 1;
            if (str_contains($part, '/')) {
                [$rangePart, $stepStr] = explode('/', $part, 2);
                if (!ctype_digit($stepStr) || (int) $stepStr < 1) {
                    throw new InvalidArgumentException("Invalid step in cron field: {$part}");
                }
                $step = (int) $stepStr;
                $part = trim($rangePart);
            }

            if ($part === '*') {
                $lo = $min;
                $hi = $max;
            } elseif (str_contains($part, '-')) {
                [$loStr, $hiStr] = explode('-', $part, 2);
                if (!ctype_digit(trim($loStr)) || !ctype_digit(trim($hiStr))) {
                    throw new InvalidArgumentException("Invalid range in cron field: {$part}");
                }
                $lo = (int) trim($loStr);
                $hi = (int) trim($hiStr);
            } else {
                if (!ctype_digit($part)) {
                    throw new InvalidArgumentException("Invalid value in cron field: {$part}");
                }
                $lo = $hi = (int) $part;
            }

            if ($lo < $min || $hi > $max || $lo > $hi) {
                throw new InvalidArgumentException("Cron field value out of range [{$min}-{$max}]: {$part}");
            }

            for ($v = $lo; $v <= $hi; $v += $step) {
                $values[$v] = true;
            }
        }

        $out = array_keys($values);
        sort($out);

        return $out;
    }

    /**
     * Normalise day-of-week 7 → 0 (both mean Sunday) and dedupe.
     *
     * @param list<int> $days
     * @return list<int>
     */
    private static function normalizeDaysOfWeek(array $days): array
    {
        $set = [];
        foreach ($days as $d) {
            $set[$d === 7 ? 0 : $d] = true;
        }
        $out = array_keys($set);
        sort($out);

        return $out;
    }
}
