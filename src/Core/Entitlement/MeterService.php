<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

use DateTimeImmutable;
use DateTimeZone;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\SharedStoreInterface;

/**
 * Limits that are SPENT and come back: "5 documents a day, 50 a month".
 *
 * ── Why this is not the rate limiter ────────────────────────────────────────
 *
 * {@see \Whity\Core\RateLimit\RateLimitStoreInterface} counts the same shape and
 * was the obvious thing to reuse, but two of its decisions are right for
 * throttling and wrong for a quota somebody paid for:
 *
 *  1. IT ALWAYS COUNTS THE HIT, even when refusing. For a rate limiter that is
 *     correct — hammering should extend the penalty. For a quota it means a
 *     customer who is already at their limit burns budget by being told no,
 *     and tomorrow starts in the hole.
 *  2. ITS WINDOW OPENS ON THE FIRST HIT, so "per day" means twenty-four hours
 *     from whenever somebody first rendered something. A customer sold "5 a
 *     day" will read that as a calendar day, and on the rolling reading their
 *     reset time wanders a little later every day.
 *
 * So the window here is CALENDAR-ALIGNED and lives in the key: the counter for
 * the 13th is a different key from the counter for the 14th, and the reset is
 * therefore exact regardless of TTL precision or clock drift. The TTL only
 * stops old windows accumulating.
 *
 * ── Whose midnight ─────────────────────────────────────────────────────────
 *
 * The TENANT's. A workspace in Amman that is told its quota resets at midnight
 * means their midnight; resetting on UTC would cut their day at 3am and hand
 * them a fresh allowance in the middle of a working evening. The tenant's
 * `timezone` setting already exists for exactly this class of question.
 *
 * ── Spending several windows at once ───────────────────────────────────────
 *
 * One render spends the daily meter AND the monthly one, and it must spend
 * both or neither. {@see consume()} increments each in turn and GIVES BACK
 * everything it took if any of them refuses — the same claim/release shape
 * {@see \Whity\Core\Billing\External\EventLedger} uses. Without that, a customer
 * at their monthly limit would lose a day's allowance every time they were told
 * no, which is the bug that turns "you have 5 a day" into a lie.
 */
final class MeterService
{
    /** Namespace for counter keys, kept distinct from rate-limit keys. */
    private const PREFIX = 'meter:';

    /**
     * How long a window's counter outlives the window itself.
     *
     * A small grace rather than exactly zero: the counter must still be readable
     * for a request that began just before the boundary, and an expiry landing a
     * moment early would hand somebody a second full allowance.
     */
    private const TTL_GRACE_SECONDS = 300;

    public function __construct(
        private readonly SharedStoreInterface $store,
        private readonly EntitlementService $entitlements,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Spend one unit of every metered limit named, or none of them.
     *
     * @param list<string> $keys Metered entitlement keys that this one action
     *                           consumes — e.g. the per-day and per-month
     *                           counters for the same resource.
     *
     * @return MeterDecision Allowed, or refused naming the limit that refused.
     */
    public function consume(int $tenantId, array $keys, int $units = 1, ?DateTimeImmutable $now = null): MeterDecision
    {
        $now ??= new DateTimeImmutable();
        $effective = $this->entitlements->effective($tenantId);

        /** @var list<array{key: string, counter: string}> $taken */
        $taken = [];

        foreach ($keys as $key) {
            $limit = $this->limitFor($effective, $key);

            // UNLIMITED IS NOT COUNTED AT ALL. Counting it would cost a write
            // per action to produce a number nothing reads, and the only
            // consequence of not counting is that a tenant moved from unlimited
            // to a cap mid-window starts that window empty — which is the
            // generous direction, and the right one for somebody whose plan
            // just changed under them.
            if ($limit === EntitlementRegistry::UNLIMITED) {
                continue;
            }

            if ($limit === 0) {
                // Sold none of it. Refused without spending anything, and
                // without a counter key that would never be read.
                $this->giveBack($taken);

                return MeterDecision::refused($key, 0, 0, $this->resetsAt($tenantId, $key, $now));
            }

            $counter = $this->counterKey($tenantId, $key, $now);
            $count = $this->store->increment($counter, $this->ttlFor($tenantId, $key, $now));

            // `increment` returns the count AFTER this action, and several units
            // may be spent at once, so the remaining ones are added on top.
            for ($i = 1; $i < $units; $i++) {
                $count = $this->store->increment($counter, $this->ttlFor($tenantId, $key, $now));
            }

            $taken[] = ['key' => $key, 'counter' => $counter, 'units' => $units];

            if ($count > $limit) {
                $this->giveBack($taken);

                return MeterDecision::refused(
                    $key,
                    $limit,
                    max(0, $limit - ($count - $units)),
                    $this->resetsAt($tenantId, $key, $now)
                );
            }
        }

        return MeterDecision::allowed();
    }

    /**
     * What is left, without spending any of it.
     *
     * For showing somebody "3 of 5 used today" — and deliberately a separate
     * call from {@see consume()} rather than a flag on it, because a screen that
     * accidentally consumed a unit by rendering a progress bar is a bug nobody
     * would look for.
     *
     * @param list<string> $keys
     *
     * @return array<string, array{limit: int, used: int, remaining: int|null, resets_at: string|null, period: string|null}>
     */
    public function peek(int $tenantId, array $keys, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable();
        $effective = $this->entitlements->effective($tenantId);

        $out = [];
        foreach ($keys as $key) {
            $limit = $this->limitFor($effective, $key);
            $unlimited = $limit === EntitlementRegistry::UNLIMITED;
            $used = $unlimited ? 0 : max(0, $this->store->count($this->counterKey($tenantId, $key, $now)));

            $out[$key] = [
                'limit' => $limit,
                'used' => $used,
                // Null rather than a huge number: "unlimited" is not a quantity,
                // and a client that formatted one would show something absurd.
                'remaining' => $unlimited ? null : max(0, $limit - $used),
                'resets_at' => $unlimited ? null : $this->resetsAt($tenantId, $key, $now),
                'period' => EntitlementRegistry::periodFor($key),
            ];
        }

        return $out;
    }

    /**
     * Return units to a metered limit because the action did not happen.
     *
     * THE OTHER HALF OF SPENDING BEFORE THE WORK. A quota has to be taken
     * BEFORE the expensive thing starts — a render that has already spun up a
     * headless browser and then gets refused has cost the half-gigabyte anyway
     * — but that means a render which then FAILS would silently eat one of the
     * five documents somebody is allowed today. The render container being down
     * is not the customer spending their allowance.
     *
     * Safe when nothing was taken: an unlimited limit was never counted, and
     * decrementing a counter that does not exist is a no-op in the store.
     *
     * @param list<string> $keys The same keys the action consumed.
     */
    public function refund(int $tenantId, array $keys, int $units = 1, ?DateTimeImmutable $now = null): void
    {
        $now ??= new DateTimeImmutable();
        $effective = $this->entitlements->effective($tenantId);

        foreach ($keys as $key) {
            // Only limits that were actually counted are given back. Refunding
            // an unlimited one would drive its counter negative and, if the tier
            // later gained a cap, hand that tenant a free allowance.
            if ($this->limitFor($effective, $key) === EntitlementRegistry::UNLIMITED) {
                continue;
            }

            $counter = $this->counterKey($tenantId, $key, $now);
            for ($i = 0; $i < $units; $i++) {
                $this->store->decrement($counter);
            }
        }
    }

    /**
     * Hand back everything already taken for this action.
     *
     * @param list<array{key: string, counter: string, units?: int}> $taken
     */
    private function giveBack(array $taken): void
    {
        foreach ($taken as $entry) {
            $units = $entry['units'] ?? 1;
            for ($i = 0; $i < $units; $i++) {
                $this->store->decrement($entry['counter']);
            }
        }
    }

    /**
     * @param array<string, bool|int> $effective
     */
    private function limitFor(array $effective, string $key): int
    {
        $period = EntitlementRegistry::periodFor($key);
        if ($period === null) {
            throw new \InvalidArgumentException(
                "'{$key}' is not a metered entitlement; it holds a value rather than being spent."
            );
        }

        $value = $effective[$key] ?? EntitlementRegistry::UNLIMITED;

        return is_int($value) ? $value : EntitlementRegistry::UNLIMITED;
    }

    /**
     * The counter for one tenant, one limit, one calendar window.
     *
     * THE WINDOW IS IN THE KEY, which is what makes the reset exact: at the
     * boundary the key simply changes, and the new one starts at zero without
     * anything having to run on a schedule.
     */
    private function counterKey(int $tenantId, string $key, DateTimeImmutable $now): string
    {
        return self::PREFIX . $tenantId . ':' . $key . ':' . $this->windowId($tenantId, $key, $now);
    }

    private function windowId(int $tenantId, string $key, DateTimeImmutable $now): string
    {
        $local = $now->setTimezone($this->zoneFor($tenantId));

        return match (EntitlementRegistry::periodFor($key)) {
            EntitlementDefinition::PERIOD_DAY => $local->format('Y-m-d'),
            // ISO-8601 week, so the year belongs to the WEEK rather than to the
            // date — 'o' not 'Y'. With 'Y', the days either side of New Year
            // land in two different keys for one week, handing somebody a second
            // allowance on 1 January.
            EntitlementDefinition::PERIOD_WEEK => $local->format('o-\WW'),
            default => $local->format('Y-m'),
        };
    }

    /** Seconds from now until this window closes, plus a grace. */
    private function ttlFor(int $tenantId, string $key, DateTimeImmutable $now): int
    {
        $endsAt = $this->windowEnd($tenantId, $key, $now);

        return max(1, $endsAt->getTimestamp() - $now->getTimestamp()) + self::TTL_GRACE_SECONDS;
    }

    private function resetsAt(int $tenantId, string $key, DateTimeImmutable $now): string
    {
        return $this->windowEnd($tenantId, $key, $now)->format(DATE_ATOM);
    }

    /**
     * The instant this window closes, in the tenant's own zone.
     *
     * `modify()` on a zoned date does the calendar arithmetic that manual second
     * counting gets wrong: a day is not always 86400 seconds where daylight
     * saving applies, and months are not a fixed length at all.
     */
    private function windowEnd(int $tenantId, string $key, DateTimeImmutable $now): DateTimeImmutable
    {
        $local = $now->setTimezone($this->zoneFor($tenantId));

        return match (EntitlementRegistry::periodFor($key)) {
            EntitlementDefinition::PERIOD_DAY => $local->modify('tomorrow midnight'),
            EntitlementDefinition::PERIOD_WEEK => $local->modify('monday next week midnight'),
            default => $local->modify('first day of next month midnight'),
        };
    }

    /**
     * The tenant's zone, falling back to the deployment's and then to UTC.
     *
     * An unusable value falls back rather than throwing: a meter is on the path
     * of a customer doing their work, and a typo in a settings row must not turn
     * every render into a 500.
     */
    private function zoneFor(int $tenantId): DateTimeZone
    {
        $value = $this->settings->effective($tenantId)[SettingsRegistry::TIMEZONE] ?? null;
        if (!is_string($value) || $value === '') {
            $value = 'UTC';
        }

        try {
            return new DateTimeZone($value);
        } catch (\Exception) {
            return new DateTimeZone('UTC');
        }
    }
}
