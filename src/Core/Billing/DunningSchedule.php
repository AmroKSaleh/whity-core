<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;

/**
 * When to try again, and when to stop trying — the whole retry policy as one
 * value.
 *
 * ONE SETTING, NOT THREE. The obvious shape is `retry_count`, `retry_interval`
 * and maybe `retry_backoff`, and it cannot express what operators actually
 * want, which is "try tomorrow, then in three days, then a week later, then
 * give up". A list of offsets says both how many attempts there are and when
 * each falls, in a form somebody can read off a screen and check against what
 * they meant.
 *
 * "1,3,7" IS THREE ATTEMPTS, on days 1, 3 and 7 after the invoice fell due —
 * not on days 1, 4 and 11. Offsets are from the DUE DATE, not from each other,
 * because "the third attempt happens a week after it was due" is the sentence
 * an operator is trying to write, and cumulative intervals make them do
 * arithmetic to find out when their customer gets locked out.
 *
 * WHY LOCKING IS SEPARATE FROM THE LAST ATTEMPT. Running out of retries and
 * losing access are different events, and collapsing them removes the one
 * period that matters commercially: the stretch after the platform has stopped
 * asking automatically, while a human still has time to pay. An instance that
 * wants them together sets the lock day equal to the last retry, and that is a
 * decision it has made rather than one the code made for it.
 *
 * EMPTY IS LEGITIMATE. A deployment that never retries automatically — because
 * its rail is a bank push, and only the customer can act — sets an empty
 * schedule and still gets a lock day. That is not a degenerate case to guard
 * against; on the CliQ rail it is the ordinary configuration.
 */
final class DunningSchedule
{
    /**
     * Nobody's retry policy is a year long, and a schedule that reaches past
     * one is a typo in a settings field rather than an intention.
     */
    private const MAX_OFFSET_DAYS = 365;

    /** More than this many attempts is a misconfiguration, not a strategy. */
    private const MAX_ATTEMPTS = 20;

    /**
     * @param list<int> $retryOffsetDays Ascending, distinct, days after due.
     * @param int       $lockAfterDays   Day on which access is withdrawn.
     */
    private function __construct(
        private readonly array $retryOffsetDays,
        private readonly int $lockAfterDays,
    ) {
    }

    /**
     * Parse an operator's settings values.
     *
     * INVALID INPUT FALLS BACK RATHER THAN THROWING. This is read on the path
     * that decides whether somebody keeps access to their account, and a
     * settings typo must not become an exception there — the failure mode of
     * throwing is that dunning stops running entirely and nobody is ever
     * chased, which is silent and expensive. So a malformed schedule degrades
     * to the supplied fallback, and {@see self::parseProblem()} lets a settings
     * screen say so at the point where somebody can fix it.
     *
     * @param string $retrySchedule Comma-separated day offsets, e.g. "1,3,7".
     */
    public static function fromSettings(
        string $retrySchedule,
        int $lockAfterDays,
        ?self $fallback = null,
    ): self {
        $offsets = self::parseOffsets($retrySchedule);

        if ($offsets === null) {
            return $fallback ?? self::conservativeDefault();
        }

        return new self(
            $offsets,
            max(0, min($lockAfterDays, self::MAX_OFFSET_DAYS)),
        );
    }

    /**
     * What is wrong with a schedule string, or null when it is fine — for a
     * settings screen to show at the moment somebody types it.
     */
    public static function parseProblem(string $retrySchedule): ?string
    {
        if (trim($retrySchedule) === '') {
            return null; // no automatic retries is a valid choice
        }

        $seen = [];
        foreach (explode(',', $retrySchedule) as $piece) {
            $piece = trim($piece);

            if (preg_match('/^\d+$/', $piece) !== 1) {
                return "\"{$piece}\" is not a whole number of days";
            }

            $day = (int) $piece;

            if ($day < 1) {
                return 'a retry on day 0 would happen the moment the invoice fell due, '
                    . 'before the payment that failed could possibly have been retried';
            }

            if ($day > self::MAX_OFFSET_DAYS) {
                return "day {$day} is more than a year after the invoice was due";
            }

            if (isset($seen[$day])) {
                return "day {$day} appears twice, which would attempt payment twice at once";
            }

            $seen[$day] = true;
        }

        if (count($seen) > self::MAX_ATTEMPTS) {
            return sprintf('%d attempts is more than the %d this allows', count($seen), self::MAX_ATTEMPTS);
        }

        return null;
    }

    /**
     * Three attempts over a week, then locked a week after that.
     *
     * Conservative in the direction that matters: it chases promptly but leaves
     * a fortnight before anybody loses access, because the cost of locking out
     * a paying customer whose card expired is far higher than the cost of
     * carrying them a few extra days.
     */
    public static function conservativeDefault(): self
    {
        return new self([1, 3, 7], 14);
    }

    /** How many automatic attempts this policy makes. */
    public function attemptCount(): int
    {
        return count($this->retryOffsetDays);
    }

    /** @return list<int> */
    public function retryOffsetDays(): array
    {
        return $this->retryOffsetDays;
    }

    public function lockAfterDays(): int
    {
        return $this->lockAfterDays;
    }

    /**
     * When attempt number `$attempt` (1-based) falls, or null when the policy
     * has no such attempt.
     */
    public function attemptDueAt(DateTimeImmutable $invoiceDueAt, int $attempt): ?DateTimeImmutable
    {
        $offset = $this->retryOffsetDays[$attempt - 1] ?? null;

        return $offset === null ? null : $invoiceDueAt->modify("+{$offset} days");
    }

    /**
     * The next attempt owed at this moment, given how many have already been
     * made — or null when there is nothing to do yet or nothing left to do.
     *
     * Returning the ATTEMPT NUMBER rather than a boolean is deliberate: the
     * caller needs it for the idempotency key, so that a retry of attempt three
     * is recognisably the same request and attempt four is recognisably a
     * different one.
     */
    public function nextAttemptDue(
        DateTimeImmutable $invoiceDueAt,
        int $attemptsMade,
        DateTimeImmutable $now,
    ): ?int {
        $next = $attemptsMade + 1;
        $dueAt = $this->attemptDueAt($invoiceDueAt, $next);

        if ($dueAt === null) {
            return null; // the policy is exhausted
        }

        return $now >= $dueAt ? $next : null;
    }

    /**
     * Whether access should be withdrawn by now.
     *
     * Deliberately NOT "the retries are exhausted": see the class docblock.
     * Running out of attempts and losing access are different events, and the
     * gap between them is where a human still has time to pay.
     */
    public function shouldLock(DateTimeImmutable $invoiceDueAt, DateTimeImmutable $now): bool
    {
        return $now >= $invoiceDueAt->modify("+{$this->lockAfterDays} days");
    }

    /**
     * @return list<int>|null Null when the string is malformed.
     */
    private static function parseOffsets(string $retrySchedule): ?array
    {
        if (self::parseProblem($retrySchedule) !== null) {
            return null;
        }

        if (trim($retrySchedule) === '') {
            return [];
        }

        $offsets = [];
        foreach (explode(',', $retrySchedule) as $piece) {
            $offsets[] = (int) trim($piece);
        }

        // Sorted rather than trusted in order: "7,1,3" is an operator writing
        // the same policy carelessly, not a different policy, and running the
        // attempts out of order would make the third one fall before the first.
        sort($offsets);

        return $offsets;
    }
}
