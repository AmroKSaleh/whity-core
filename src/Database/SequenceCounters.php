<?php

declare(strict_types=1);

namespace Whity\Database;

use PDO;
use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * The host's implementation of {@see SequenceAllocator}: one core-owned table,
 * one statement per allocation, no read-then-write window.
 *
 * The whole mechanism
 * -------------------
 *     INSERT INTO sequence_counters (tenant_id, name, value)
 *     VALUES (:tenant, :name, :seed)
 *     ON CONFLICT (tenant_id, name)
 *     DO UPDATE SET value = sequence_counters.value + :step
 *     RETURNING value
 *
 * There is no SELECT. The value is computed by the engine, inside the write, on
 * the row the write locks — so the interval during which two clients could both
 * observe `3` does not exist, rather than being made small.
 *
 * Why that is atomic on BOTH engines
 * ----------------------------------
 * PostgreSQL: `INSERT … ON CONFLICT DO UPDATE` takes a row-level lock on the
 * conflicting row before evaluating the SET expression. A second session
 * running the same statement BLOCKS until the first commits, then re-reads the
 * committed row and adds to it. Never a lost update, and never at READ
 * COMMITTED's usual mercy — the special "speculative insertion" path exists
 * precisely so upserts do not suffer the read-modify-write race.
 *
 * SQLite: a writing statement holds the database write lock for its duration
 * and readers never see a half-applied write. Two connections cannot be inside
 * this statement at the same time; the second waits (`busy_timeout`) or is told
 * `SQLITE_BUSY`. Either way it does not proceed on a stale value.
 *
 * Neither claim is taken on faith here: `SequenceAllocatorConcurrencyTest`
 * drives two real connections into the interleaving that breaks the naive
 * allocator, shows the naive one hand out a duplicate, and shows this one
 * refuse to.
 *
 * Why the counter is TENANT-SCOPED storage even for platform-wide counters
 * -----------------------------------------------------------------------
 * `sequence_counters` carries a real `tenant_id` column with a real cascade.
 * That is what lets the tenant-predicate scanner police it, lets dropping a
 * tenant take its counters with it, and stops one tenant's `invoice` counter
 * from being reachable by supplying another tenant's name. A genuinely
 * platform-wide counter is the SYSTEM tenant's counter — see
 * {@see self::nextPlatformWide()} — which keeps one storage shape and one
 * predicate rather than a second, unpoliceable global table.
 *
 * Where the tenant predicate is, in a statement with no WHERE
 * -----------------------------------------------------------
 * The allocation carries no `WHERE tenant_id = ?`, and it does not need one:
 * the conflict target IS `(tenant_id, name)`, the table's primary key, so the
 * row the `DO UPDATE` branch touches is the one identified by the tenant id
 * bound in the VALUES list. There is no row it could reach across tenants.
 * {@see self::peek()}, which is an ordinary SELECT, binds the predicate the
 * ordinary way and is what the tenant-predicate guard reads.
 *
 * Gaps
 * ----
 * Allocation participates in whatever transaction the caller has open, so a
 * rollback un-allocates and a concurrent caller that already took the next
 * number leaves a hole. Unique and monotonic; not gapless. The interface says
 * so at length because "unique" and "gapless" get conflated, and only the first
 * one is a database problem.
 */
final class SequenceCounters implements SequenceAllocator, HostWiredService
{
    /**
     * The core-owned table. A literal in every statement below — never
     * interpolated — which is what lets the tenant-predicate scanner read them.
     */
    public const TABLE = 'sequence_counters';

    /**
     * The system tenant (id 0), under which platform-wide counters live.
     */
    private const SYSTEM_TENANT_ID = 0;

    /** Longest counter name the column holds. */
    private const MAX_NAME_LENGTH = 128;

    private PDO $pdo;

    /**
     * @param PDO $pdo Live database connection.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Allocate and return the next number for a tenant's named counter.
     *
     * @throws \InvalidArgumentException On a malformed name or a non-positive step.
     */
    public function next(int $tenantId, string $name, int $step = 1): int
    {
        return $this->allocate($tenantId, $name, $step);
    }

    /**
     * Allocate a contiguous block, returning its inclusive bounds.
     *
     * One statement, so the block cannot be split by another client mid-loop —
     * which is the failure a `for` loop around {@see next()} would have.
     *
     * @return array{first: int, last: int}
     * @throws \InvalidArgumentException On a malformed name or a non-positive count.
     */
    public function nextBlock(int $tenantId, string $name, int $count): array
    {
        if ($count < 1) {
            throw new \InvalidArgumentException(
                "Cannot allocate a block of {$count} from counter '{$name}': count must be positive."
            );
        }

        $last = $this->allocate($tenantId, $name, $count);

        return ['first' => $last - $count + 1, 'last' => $last];
    }

    /**
     * The counter's current value without allocating; 0 when never used.
     *
     * @throws \InvalidArgumentException On a malformed name.
     */
    public function peek(int $tenantId, string $name): int
    {
        $this->assertName($name);

        $statement = $this->pdo->prepare(
            'SELECT value FROM sequence_counters WHERE tenant_id = :tenant AND name = :name'
        );
        $statement->execute([':tenant' => $tenantId, ':name' => $name]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? 0 : (int) $value;
    }

    /**
     * Allocate from the PLATFORM-WIDE counter of this name.
     *
     * A deployment-level sequence — a change-feed cursor, a global document
     * number — stored under the system tenant so it shares one table, one
     * cascade and one tenant predicate with every other counter, instead of
     * being a second global table nothing polices.
     *
     * The method name is the declaration: reaching a platform-wide counter is
     * something a caller says, not something that happens by passing 0.
     *
     * @throws \InvalidArgumentException On a malformed name or a non-positive step.
     */
    public function nextPlatformWide(string $name, int $step = 1): int
    {
        return $this->allocate(self::SYSTEM_TENANT_ID, $name, $step);
    }

    /**
     * The single-statement allocation both public paths use.
     *
     * The insert seed and the update step are bound under DIFFERENT names even
     * though they carry the same number: PDO only permits a named placeholder
     * to be reused when emulated prepares are on, and this must not depend on
     * that setting.
     *
     * `sequence_counters.value` on the right-hand side names the EXISTING row
     * explicitly. Both engines also accept the bare column there, but the bare
     * form reads like the proposed value and is the sort of thing a later edit
     * turns into `excluded.value` — which would set the counter to the step
     * instead of advancing it, and would still pass a single-threaded test.
     *
     * @throws \InvalidArgumentException On a malformed name or a non-positive step.
     */
    private function allocate(int $tenantId, string $name, int $step): int
    {
        $this->assertName($name);

        if ($step < 1) {
            throw new \InvalidArgumentException(
                "Cannot advance counter '{$name}' by {$step}: a counter that can go "
                . 'backwards would re-issue numbers it has already handed out.'
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO sequence_counters (tenant_id, name, value)
             VALUES (:tenant, :name, :seed)
             ON CONFLICT (tenant_id, name)
             DO UPDATE SET value = sequence_counters.value + :step,
                           updated_at = CURRENT_TIMESTAMP
             RETURNING value'
        );
        $statement->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':name', $name, PDO::PARAM_STR);
        $statement->bindValue(':seed', $step, PDO::PARAM_INT);
        $statement->bindValue(':step', $step, PDO::PARAM_INT);
        $statement->execute();

        $value = $statement->fetchColumn();

        if ($value === false || $value === null) {
            // RETURNING on an upsert that took either branch always yields a
            // row; nothing yielded means the statement did not do what it says.
            throw new \RuntimeException(
                "Counter '{$name}' allocated no value. Refusing to guess one — a guessed "
                . 'sequence number is a duplicate waiting to happen.'
            );
        }

        return (int) $value;
    }

    /**
     * Counter names are stored, compared and used as half a primary key, so
     * their shape is constrained rather than trusted.
     *
     * @throws \InvalidArgumentException When the name is malformed.
     */
    private function assertName(string $name): void
    {
        if (preg_match('/^[a-z][a-z0-9_:-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid counter name "%s": expected lowercase letters, digits, '
                . 'underscore, colon or hyphen, starting with a letter.',
                $name
            ));
        }

        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid counter name "%s": %d characters exceeds the %d-character column.',
                $name,
                strlen($name),
                self::MAX_NAME_LENGTH
            ));
        }
    }
}
