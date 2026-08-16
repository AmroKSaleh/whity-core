<?php

declare(strict_types=1);

namespace Whity\Sdk\Sql;

/**
 * Hand me the next number, and never hand the same one twice.
 *
 * The bug this exists to prevent
 * ------------------------------
 * Numbering a document is the plainest requirement there is, and the obvious
 * implementation is wrong:
 *
 *     $current = SELECT value FROM acme_counters WHERE name = 'invoice';
 *     UPDATE acme_counters SET value = :next WHERE name = 'invoice';
 *     return $current + 1;
 *
 * Two clients read `3`. Two clients write `4`. Two documents are numbered `4`,
 * on records whose whole point is to be uniquely numbered — and nothing errors,
 * so it surfaces weeks later as two invoices with one number. An adopter shipped
 * exactly this, with a docblock that already claimed atomicity.
 *
 * A helper would not, by itself, have stopped anyone writing that. What it can
 * do is make the correct construction the one that is easier to reach for, and
 * put it somewhere it only has to be right once.
 *
 * Why this is a HOST service, not a snippet
 * -----------------------------------------
 * The obvious alternative is to publish the correct SQL and let each plugin
 * paste it over its own counter table. That leaves every adopter shipping a
 * migration for a table with no domain meaning, and leaves the correctness in N
 * places. So the host owns the storage: a plugin that needs numbering declares
 * nothing, migrates nothing, and writes no SQL — it asks for a number.
 *
 * Resolve it from the service container:
 *
 *     $sequences = \Whity\app(\Whity\Sdk\Sql\SequenceAllocator::class);
 *     $number = $sequences->next($tenantId, 'invoice');
 *
 * Counters are namespaced per tenant AND per name, so `invoice` in one tenant
 * is a different counter from `invoice` in another, and a plugin picking a name
 * another plugin already uses shares a counter rather than corrupting one —
 * prefix your names if that matters to you.
 *
 * What is guaranteed, and what is not
 * -----------------------------------
 * GUARANTEED: no two successful calls for the same `(tenant, name)` ever return
 * the same number, under any concurrency, on PostgreSQL and on SQLite. The
 * allocation is a single statement; there is no window between reading and
 * writing because there is no reading.
 *
 * NOT guaranteed: gaplessness. A caller that allocates `7` and then fails —
 * or rolls back — leaves `7` unused, and the next caller gets `8`. Numbers are
 * unique, monotonic, and may skip. If your requirement is a legally gapless
 * series, allocation is not where that is solved; it needs the number and the
 * record committed together, and a compensating record for a cancelled one.
 * That is a domain decision this interface deliberately does not make for you.
 *
 * Also not guaranteed: that a rolled-back transaction returns the number. The
 * allocation participates in the caller's transaction if one is open, so a
 * rollback DOES un-allocate — and then a concurrent caller that already took
 * the next number leaves a gap. Unique, monotonic, may skip. Say it three times.
 */
interface SequenceAllocator
{
    /**
     * Allocate and return the next number for a tenant's named counter.
     *
     * The first call for a counter that has never been used returns `$step`
     * (so the default first value is `1`); a counter is created on first use
     * and needs no set-up.
     *
     * @param int    $tenantId The tenant the counter belongs to.
     * @param string $name     The counter name, e.g. `invoice`. Lowercase letters,
     *        digits, underscore, colon and hyphen; at most 128 characters.
     * @param int    $step     How much to advance by. Must be positive — a counter
     *        that can go backwards is not a counter.
     * @return int The allocated value. Never returned to any other caller.
     * @throws \InvalidArgumentException On a malformed name or a non-positive step.
     */
    public function next(int $tenantId, string $name, int $step = 1): int;

    /**
     * Allocate a CONTIGUOUS block of numbers in one statement.
     *
     * For importing or batch-numbering N records without N round trips — and
     * without the loop that would otherwise interleave with another client and
     * hand you a non-contiguous set.
     *
     * @param int    $tenantId The tenant the counter belongs to.
     * @param int    $count    How many numbers to reserve. Must be positive.
     * @return array{first: int, last: int} The inclusive range reserved. `first`
     *         through `last` are yours and will not be issued again.
     * @throws \InvalidArgumentException On a malformed name or a non-positive count.
     */
    public function nextBlock(int $tenantId, string $name, int $count): array;

    /**
     * Allocate from a DEPLOYMENT-WIDE counter — one series shared by every
     * tenant on the instance.
     *
     * A change-feed cursor is the honest example: it orders writes across the
     * whole instance, so per-tenant numbering would be meaningless. Almost
     * nothing else is: if two tenants can see each other's numbers, that is
     * usually a leak rather than a feature, so reach for {@see next()} first.
     *
     * The distinct method name is the point. Where the host puts these is its
     * business (they are not a second, unpoliced global table), but WHICH kind
     * of counter you are asking for is yours, and it should be something you
     * said rather than something that happened by passing a particular id.
     *
     * @throws \InvalidArgumentException On a malformed name or a non-positive step.
     */
    public function nextPlatformWide(string $name, int $step = 1): int;

    /**
     * The counter's current value WITHOUT allocating.
     *
     * Returns 0 for a counter that has never been used. This is a read for
     * display or diagnostics; it is NOT a way to decide the next number, and a
     * caller that does `peek() + 1` has rebuilt the bug this interface exists
     * to remove.
     *
     * @throws \InvalidArgumentException On a malformed name.
     */
    public function peek(int $tenantId, string $name): int;
}
