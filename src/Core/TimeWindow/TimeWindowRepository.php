<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use DateTimeImmutable;
use PDO;
use PDOException;

/**
 * Data-access layer for `time_windows` and `time_window_state_events` (#1070,
 * migration 126) — the periods themselves, and the append-only record of each
 * being sealed or unsealed. All SQL touching either table lives here so API
 * handlers never issue raw queries (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every
 * SELECT/UPDATE/DELETE binds an explicit `tenant_id` predicate, and a period id
 * belonging to another tenant is indistinguishable from one that does not exist.
 *
 * THE FOUR INVARIANTS THIS CLASS OWNS
 * -----------------------------------
 * 1. BOUNDARIES ARE AUTHORED, NEVER DERIVED. Nothing here computes a start or an
 *    end from a month, a calendar year, or a parent's length. A period of a
 *    given kind may begin on any day, run for any span, and be a different
 *    length from its siblings, because that is what real periods do — the
 *    assumption that they are calendar-aligned fractions is the specific mistake
 *    this subsystem exists to avoid.
 *
 * 2. NO TWO PERIODS OF THE SAME KIND OVERLAP, within one tenant. This is what
 *    makes resolution by date a FUNCTION rather than a guess: without it, "which
 *    period contains this date" has several answers and every consumer picks a
 *    different one silently. Enforced here rather than by a database constraint —
 *    see the migration's docblock for why one enforcement point that behaves the
 *    same on both engines beats two that disagree — and enforced under a lock on
 *    the type row, so two concurrent creations of overlapping periods cannot both
 *    pass the check.
 *
 * 3. A NESTED PERIOD IS CONTAINED BY ITS PARENT, in dates and in kind. A child's
 *    range must sit inside its parent's, and its parent must be a period of the
 *    kind the child's kind nests inside. Uncontained nesting makes "roll this up"
 *    ambiguous in exactly the way overlapping siblings do.
 *
 * 4. NO OPEN PERIOD INSIDE A CLOSED ONE. This single invariant is the whole
 *    cascade decision, and every question about cascading falls out of it:
 *
 *      - a child MAY close while its parent is open — sealing a sub-period
 *        before the period containing it is over is the ordinary case;
 *      - a parent may NOT close while a child is open, because a sealed period
 *        containing an accruing one is a seal that is not a seal;
 *      - reopening a parent does NOT reopen its children — they stay closed,
 *        which the invariant permits, and auto-unsealing periods nobody named
 *        would be the trap this design is trying to avoid;
 *      - a child may NOT reopen while its parent is closed. Reopening it would
 *        put an accruing period inside a sealed one; the parent must be reopened
 *        first, explicitly, and that reopen is recorded like any other.
 *
 * WHAT CLOSING DELIBERATELY DOES NOT DO
 * -------------------------------------
 * It does not touch any record scoped to the period. Core owns no such records,
 * and — more importantly — what a closed period should FORBID is a question this
 * class refuses to answer on the domain's behalf. A record circulating for
 * approval across a period boundary is normal, and none of the available answers
 * is clean; picking one here would encode it for every consumer at once. So
 * closing sets a state and appends to a trail, {@see isOpen()} lets a domain ask,
 * and where that question is asked of a record mid-flight is the domain's
 * decision until the platform makes one.
 */
final class TimeWindowRepository
{
    private const COLUMNS = 'id, tenant_id, window_type_id, parent_window_id, window_key, label,'
        . ' starts_on, ends_on, state, created_at, updated_at';

    private readonly WindowTypeRepository $types;

    /**
     * @param WindowTypeRepository|null $types The vocabulary, consulted to check
     *        that a period's parent is a period of the kind its own kind nests
     *        inside. Defaulted rather than required so a caller holding only a
     *        PDO handle can construct one, and injectable so a test can hand in
     *        a repository over the same connection without a second one being
     *        conjured mid-check.
     */
    public function __construct(private readonly PDO $db, ?WindowTypeRepository $types = null)
    {
        $this->types = $types ?? new WindowTypeRepository($db);
    }

    /**
     * Periods, narrowed by any combination of kind, state, containing period and
     * a date they must contain.
     *
     * `$onDate` is the RESOLUTION filter: with a `$typeId` it returns at most one
     * row, and that row is the answer to "which period of this kind contains this
     * date". Zero rows is a real answer — see {@see WindowResolver}.
     *
     * @param int|null    $typeId   Restrict to one kind.
     * @param string|null $state    Restrict to {@see WindowState::OPEN} or {@see WindowState::CLOSED}.
     * @param string|null $onDate   `YYYY-MM-DD`; keep only periods whose range contains it.
     * @param int|null    $parentId Restrict to periods nesting directly inside this one.
     * @return list<array<string, mixed>>
     */
    public function listForTenant(
        int $tenantId,
        ?int $typeId = null,
        ?string $state = null,
        ?string $onDate = null,
        ?int $parentId = null
    ): array {
        // The tenant predicate stays a LITERAL in the base statement and the
        // optional narrowings are appended, which is what the static scanner
        // requires: a conditionally-built `tenant_id` fragment reads to it as an
        // unscoped query, and rightly so.
        $sql = 'SELECT ' . self::COLUMNS . ' FROM time_windows WHERE tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($typeId !== null) {
            $sql .= ' AND window_type_id = :window_type_id';
            $params[':window_type_id'] = $typeId;
        }
        if ($state !== null) {
            $sql .= ' AND state = :state';
            $params[':state'] = $state;
        }
        if ($onDate !== null) {
            $sql .= ' AND starts_on <= :on_date AND ends_on >= :on_date';
            $params[':on_date'] = $onDate;
        }
        if ($parentId !== null) {
            $sql .= ' AND parent_window_id = :parent_window_id';
            $params[':parent_window_id'] = $parentId;
        }

        // Ordered by where the period sits in time, not by insertion. A period
        // entered out of order, or a short one added after the fact, gets a
        // higher id than periods that precede it, so `id` orders nothing anybody
        // means. `id` only breaks ties so the sequence is total.
        $sql .= ' ORDER BY starts_on ASC, id ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One period, or null when absent or another tenant's.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM time_windows
             WHERE tenant_id = :tenant_id AND id = :id
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Whether a period is accruing.
     *
     * The one question a domain asks of this subsystem on an ordinary write, so
     * it is one indexed lookup by primary key and nothing more. A period that
     * does not exist answers FALSE: "may I write into a period I cannot see" has
     * one safe answer, and it is not yes.
     */
    public function isOpen(int $tenantId, int $id): bool
    {
        $row = $this->find($tenantId, $id);

        return $row !== null && $row['state'] === WindowState::OPEN;
    }

    /**
     * Create a period.
     *
     * Every invariant in the class docblock is checked here, inside a
     * transaction, after taking a lock on the KIND — so two requests creating
     * overlapping periods of the same kind serialise rather than both passing an
     * overlap check against a snapshot that did not include the other.
     *
     * @throws WindowRejectedException With a message written for the caller.
     */
    public function create(
        int $tenantId,
        int $windowTypeId,
        ?int $parentWindowId,
        string $windowKey,
        string $label,
        string $startsOn,
        string $endsOn
    ): int {
        $startsOn = self::normalizeDate($startsOn, 'starts_on');
        $endsOn = self::normalizeDate($endsOn, 'ends_on');
        if ($startsOn > $endsOn) {
            throw WindowRejectedException::because('starts_on must not fall after ends_on');
        }

        $owned = $this->beginTransaction();
        try {
            $this->lockType($tenantId, $windowTypeId);
            $this->assertNoOverlap($tenantId, $windowTypeId, $startsOn, $endsOn, null);
            $this->assertParentAdmissible($tenantId, $windowTypeId, $parentWindowId, $startsOn, $endsOn);

            $stmt = $this->db->prepare(
                'INSERT INTO time_windows
                    (tenant_id, window_type_id, parent_window_id, window_key, label,
                     starts_on, ends_on, state, created_at, updated_at)
                 VALUES (:tenant_id, :window_type_id, :parent_window_id, :window_key, :label,
                     :starts_on, :ends_on, :state, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id'        => $tenantId,
                ':window_type_id'   => $windowTypeId,
                ':parent_window_id' => $parentWindowId,
                ':window_key'       => $windowKey,
                ':label'            => $label,
                ':starts_on'        => $startsOn,
                ':ends_on'          => $endsOn,
                ':state'            => WindowState::OPEN,
            ]);
            $id = (int) $this->db->lastInsertId();

            if ($owned) {
                $this->db->commit();
            }

            return $id;
        } catch (PDOException $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if (self::isUniqueViolation($e)) {
                throw WindowRejectedException::because(
                    "A period of this kind already uses the key '{$windowKey}'"
                );
            }
            throw $e;
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Adjust a period's label, boundaries or parent. Only supplied fields change.
     *
     * A CLOSED period is refused outright. Moving the boundaries of a sealed
     * period is the most effective way there is to un-seal it without leaving a
     * trace: the state column still says `closed`, the trail still shows no
     * reopen, and yet records that were inside it no longer are. Whoever needs to
     * do that reopens the period first, on the record, and closes it again.
     *
     * `window_key` is not updatable, for the reason
     * {@see WindowTypeRepository::update()} gives about keys generally.
     *
     * @param array{label?: string, starts_on?: string, ends_on?: string, parent_window_id?: int|null} $fields
     * @throws WindowRejectedException
     */
    public function update(int $tenantId, int $id, array $fields): bool
    {
        $owned = $this->beginTransaction();
        try {
            $current = $this->find($tenantId, $id);
            if ($current === null) {
                if ($owned && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return false;
            }
            if ($current['state'] === WindowState::CLOSED) {
                throw WindowRejectedException::because(
                    'This period is closed. Reopen it before changing it, so the change is on the record.'
                );
            }

            $typeId = (int) $current['window_type_id'];
            $this->lockType($tenantId, $typeId);

            $startsOn = array_key_exists('starts_on', $fields)
                ? self::normalizeDate($fields['starts_on'], 'starts_on')
                : (string) $current['starts_on'];
            $endsOn = array_key_exists('ends_on', $fields)
                ? self::normalizeDate($fields['ends_on'], 'ends_on')
                : (string) $current['ends_on'];
            if ($startsOn > $endsOn) {
                throw WindowRejectedException::because('starts_on must not fall after ends_on');
            }

            $parentWindowId = array_key_exists('parent_window_id', $fields)
                ? $fields['parent_window_id']
                : ($current['parent_window_id'] === null ? null : (int) $current['parent_window_id']);

            $this->assertNoOverlap($tenantId, $typeId, $startsOn, $endsOn, $id);
            $this->assertParentAdmissible($tenantId, $typeId, $parentWindowId, $startsOn, $endsOn, $id);
            // Narrowing a period must not orphan what nests inside it: a child
            // whose range no longer fits is exactly as ambiguous as one that
            // never fitted, and the narrowing is the moment to say so.
            $this->assertChildrenStillContained($tenantId, $id, $startsOn, $endsOn);

            $sets = [];
            $params = [':tenant_id' => $tenantId, ':id' => $id];
            if (array_key_exists('label', $fields)) {
                $sets[] = 'label = :label';
                $params[':label'] = $fields['label'];
            }
            if (array_key_exists('starts_on', $fields)) {
                $sets[] = 'starts_on = :starts_on';
                $params[':starts_on'] = $startsOn;
            }
            if (array_key_exists('ends_on', $fields)) {
                $sets[] = 'ends_on = :ends_on';
                $params[':ends_on'] = $endsOn;
            }
            if (array_key_exists('parent_window_id', $fields)) {
                $sets[] = 'parent_window_id = :parent_window_id';
                $params[':parent_window_id'] = $parentWindowId;
            }
            if ($sets === []) {
                if ($owned && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return false;
            }
            $sets[] = 'updated_at = NOW()';

            $stmt = $this->db->prepare(
                'UPDATE time_windows SET ' . implode(', ', $sets)
                . ' WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->execute($params);
            $changed = $stmt->rowCount() > 0;

            if ($owned) {
                $this->db->commit();
            }

            return $changed;
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Seal a period, and optionally the periods nesting inside it.
     *
     * WHY CASCADE IS OPT-IN RATHER THAN AUTOMATIC. Both extremes are wrong. An
     * automatic cascade seals periods the operator did not name and cannot see
     * the list of, which is the trap this subsystem is built to avoid. A flat
     * refusal, with no cascade available, makes closing a period with twelve
     * sub-periods twelve separate acts and pushes people toward the one thing
     * that is worse than either — editing boundary dates so the sub-periods stop
     * being inside it. So: refuse by default, name what is in the way, and close
     * the children in one act when explicitly asked. Each child gets its OWN
     * trail row, marked as having come from this parent, so the trail
     * distinguishes an act somebody performed from a consequence of one they
     * performed elsewhere.
     *
     * A period that is ALREADY closed is a no-op rather than an error: the caller
     * asked for a state that already holds, and a second identical seal row would
     * make the trail assert a close that did not happen.
     *
     * @return list<int> The ids actually closed by this call, parent first.
     * @throws WindowRejectedException When children are open and `$cascade` is false.
     */
    public function close(
        int $tenantId,
        int $id,
        ?int $actorProfileId,
        ?string $reason,
        bool $cascade = false
    ): array {
        $owned = $this->beginTransaction();
        try {
            $window = $this->find($tenantId, $id);
            if ($window === null) {
                if ($owned && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [];
            }
            if ($window['state'] === WindowState::CLOSED) {
                if ($owned && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return [];
            }

            $openDescendants = $this->openDescendants($tenantId, $id);
            if ($openDescendants !== [] && !$cascade) {
                $count = count($openDescendants);
                $names = implode(', ', array_map(
                    static fn (array $row): string => (string) $row['label'],
                    array_slice($openDescendants, 0, 3)
                ));
                $more = $count > 3 ? ', and ' . ($count - 3) . ' more' : '';
                throw WindowRejectedException::because(
                    "{$count} period(s) inside this one are still open ({$names}{$more}). "
                    . 'Close them first, or repeat this request with cascade to close them together.'
                );
            }

            // Deepest first, so the invariant holds after every single write
            // rather than only at the end of the transaction. A reader that
            // observes an intermediate state observes a legal one.
            $closed = [];
            foreach (array_reverse($openDescendants) as $descendant) {
                $this->seal($tenantId, (int) $descendant['id'], $actorProfileId, $reason, $id);
                $closed[] = (int) $descendant['id'];
            }
            $this->seal($tenantId, $id, $actorProfileId, $reason, null);

            if ($owned) {
                $this->db->commit();
            }

            return array_merge([$id], array_reverse($closed));
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Unseal a period, loudly.
     *
     * A REASON IS REQUIRED and there is no path that omits it. That is the entire
     * difference between this and a silent state flip: six months later, the
     * question is never "was this period reopened" — the state column answers
     * that — but "why", and a reason nobody was made to give is a reason nobody
     * gave.
     *
     * Does NOT reopen the children, and refuses when the PARENT is closed. Both
     * follow from the one nesting invariant; see the class docblock.
     *
     * @throws WindowRejectedException
     */
    public function reopen(int $tenantId, int $id, ?int $actorProfileId, string $reason): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw WindowRejectedException::because(
                'Reopening a closed period requires a reason, and it is recorded permanently.'
            );
        }

        $owned = $this->beginTransaction();
        try {
            $window = $this->find($tenantId, $id);
            if ($window === null) {
                if ($owned && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return false;
            }
            if ($window['state'] === WindowState::OPEN) {
                if ($owned && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }

                return false;
            }

            $parentId = $window['parent_window_id'];
            if ($parentId !== null) {
                $parent = $this->find($tenantId, (int) $parentId);
                if ($parent !== null && $parent['state'] === WindowState::CLOSED) {
                    throw WindowRejectedException::because(
                        "The period containing this one ('{$parent['label']}') is closed. "
                        . 'Reopen that first — an open period inside a closed one is not a seal.'
                    );
                }
            }

            $stmt = $this->db->prepare(
                'UPDATE time_windows SET state = :state, updated_at = NOW()
                 WHERE tenant_id = :tenant_id AND id = :id'
            );
            $stmt->execute([':state' => WindowState::OPEN, ':tenant_id' => $tenantId, ':id' => $id]);

            $this->appendEvent($tenantId, $id, WindowState::ACT_REOPENED, $actorProfileId, $reason, null);

            if ($owned) {
                $this->db->commit();
            }

            return true;
        } catch (\Throwable $e) {
            if ($owned && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Periods nesting directly inside this one that are still open.
     *
     * @return list<array<string, mixed>>
     */
    public function openChildren(int $tenantId, int $id): array
    {
        return $this->listForTenant($tenantId, null, WindowState::OPEN, null, $id);
    }

    /**
     * The seal trail for one period, oldest first.
     *
     * Ordered by `id`, not `occurred_at`: a cascaded close writes several rows in
     * one transaction and they share a timestamp to the microsecond, so the clock
     * cannot order them and the monotonic key can.
     *
     * @return list<array<string, mixed>>
     */
    public function trail(int $tenantId, int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, time_window_id, action, actor_profile_id, reason,
                    cascaded_from_window_id, occurred_at
             FROM time_window_state_events
             WHERE tenant_id = :tenant_id AND time_window_id = :id
             ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'window_id' => (int) $row['time_window_id'],
            'action' => (string) $row['action'],
            'actor_profile_id' => $row['actor_profile_id'] === null ? null : (int) $row['actor_profile_id'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'cascaded_from_window_id' => $row['cascaded_from_window_id'] === null
                ? null
                : (int) $row['cascaded_from_window_id'],
            'occurred_at' => (string) $row['occurred_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Validate and normalise a `YYYY-MM-DD` boundary.
     *
     * Strict, and rejects a date that merely looks like one: `2026-02-30` parses
     * in a lenient reader and rolls forward into March, which would silently move
     * a boundary a person typed. A boundary nobody typed is worse than a refusal.
     *
     * @throws WindowRejectedException
     */
    public static function normalizeDate(mixed $raw, string $field): string
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1) {
            throw WindowRejectedException::because("{$field} must be a date in YYYY-MM-DD form");
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($parsed === false || $parsed->format('Y-m-d') !== $raw) {
            throw WindowRejectedException::because("{$field} is not a date that exists");
        }

        return $raw;
    }

    /**
     * Write the state column and the trail row for one seal, in that order.
     */
    private function seal(
        int $tenantId,
        int $id,
        ?int $actorProfileId,
        ?string $reason,
        ?int $cascadedFrom
    ): void {
        $stmt = $this->db->prepare(
            'UPDATE time_windows SET state = :state, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([':state' => WindowState::CLOSED, ':tenant_id' => $tenantId, ':id' => $id]);

        $reason = $reason === null ? null : (trim($reason) === '' ? null : trim($reason));
        $this->appendEvent($tenantId, $id, WindowState::ACT_CLOSED, $actorProfileId, $reason, $cascadedFrom);
    }

    private function appendEvent(
        int $tenantId,
        int $windowId,
        string $action,
        ?int $actorProfileId,
        ?string $reason,
        ?int $cascadedFrom
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO time_window_state_events
                (tenant_id, time_window_id, action, actor_profile_id, reason,
                 cascaded_from_window_id, occurred_at)
             VALUES (:tenant_id, :time_window_id, :action, :actor_profile_id, :reason,
                 :cascaded_from_window_id, NOW())'
        );
        $stmt->execute([
            ':tenant_id'               => $tenantId,
            ':time_window_id'          => $windowId,
            ':action'                  => $action,
            ':actor_profile_id'        => $actorProfileId,
            ':reason'                  => $reason,
            ':cascaded_from_window_id' => $cascadedFrom,
        ]);
    }

    /**
     * Every open period beneath this one, breadth-first, shallowest first.
     *
     * @return list<array<string, mixed>>
     */
    private function openDescendants(int $tenantId, int $id): array
    {
        $found = [];
        $frontier = [$id];
        $guard = 0;

        while ($frontier !== [] && $guard++ < WindowTypeRepository::MAX_NESTING_DEPTH) {
            $next = [];
            foreach ($frontier as $parentId) {
                foreach ($this->listForTenant($tenantId, null, WindowState::OPEN, null, $parentId) as $child) {
                    $found[] = $child;
                    $next[] = (int) $child['id'];
                }
            }
            $frontier = $next;
        }

        return $found;
    }

    /**
     * Refuse a range that overlaps another period of the same kind.
     *
     * Inclusive boundaries on both ends: two periods that share a single day
     * overlap on that day, and "which period does that day belong to" must have
     * one answer. A domain that wants back-to-back periods gives the second one a
     * start of the day after the first one's end.
     *
     * @throws WindowRejectedException
     */
    private function assertNoOverlap(
        int $tenantId,
        int $windowTypeId,
        string $startsOn,
        string $endsOn,
        ?int $excludeId
    ): void {
        $sql = 'SELECT id, label, starts_on, ends_on FROM time_windows
                WHERE tenant_id = :tenant_id
                  AND window_type_id = :window_type_id
                  AND starts_on <= :ends_on
                  AND ends_on >= :starts_on';
        $params = [
            ':tenant_id'      => $tenantId,
            ':window_type_id' => $windowTypeId,
            ':starts_on'      => $startsOn,
            ':ends_on'        => $endsOn,
        ];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude_id';
            $params[':exclude_id'] = $excludeId;
        }
        $sql .= ' ORDER BY starts_on ASC LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $clash = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($clash !== false) {
            throw WindowRejectedException::because(sprintf(
                "These dates overlap the period '%s' (%s to %s). Periods of one kind may not overlap, "
                . 'because a date has to belong to exactly one of them.',
                (string) $clash['label'],
                (string) $clash['starts_on'],
                (string) $clash['ends_on']
            ));
        }
    }

    /**
     * Refuse a parent that is the wrong kind, not there, or does not contain the
     * child's range — and refuse a child under a closed parent, which would put
     * an accruing period inside a sealed one.
     *
     * @throws WindowRejectedException
     */
    private function assertParentAdmissible(
        int $tenantId,
        int $windowTypeId,
        ?int $parentWindowId,
        string $startsOn,
        string $endsOn,
        ?int $selfId = null
    ): void {
        $ownType = $this->types->find($tenantId, $windowTypeId);
        if ($ownType === null) {
            throw WindowRejectedException::because('window_type_id does not name a period kind in this tenant');
        }

        if ($parentWindowId === null) {
            if ($ownType['parent_type_id'] !== null) {
                throw WindowRejectedException::because(sprintf(
                    "A period of kind '%s' nests inside another period, so parent_window_id is required.",
                    (string) $ownType['label']
                ));
            }

            return;
        }

        if ($selfId !== null && $parentWindowId === $selfId) {
            throw WindowRejectedException::because('A period cannot nest inside itself');
        }
        if ($ownType['parent_type_id'] === null) {
            throw WindowRejectedException::because(sprintf(
                "A period of kind '%s' does not nest inside anything, so it takes no parent_window_id.",
                (string) $ownType['label']
            ));
        }

        $parent = $this->find($tenantId, $parentWindowId);
        if ($parent === null) {
            throw WindowRejectedException::because('parent_window_id does not name a period in this tenant');
        }
        if ((int) $parent['window_type_id'] !== (int) $ownType['parent_type_id']) {
            throw WindowRejectedException::because(sprintf(
                "A period of kind '%s' nests inside a period of the kind its own kind names as parent, "
                . "and '%s' is not one.",
                (string) $ownType['label'],
                (string) $parent['label']
            ));
        }
        if ($parent['state'] === WindowState::CLOSED) {
            throw WindowRejectedException::because(sprintf(
                "The period '%s' is closed and cannot take new periods inside it.",
                (string) $parent['label']
            ));
        }
        if ((string) $parent['starts_on'] > $startsOn || (string) $parent['ends_on'] < $endsOn) {
            throw WindowRejectedException::because(sprintf(
                "These dates fall outside '%s' (%s to %s), which they must sit within.",
                (string) $parent['label'],
                (string) $parent['starts_on'],
                (string) $parent['ends_on']
            ));
        }
    }

    /**
     * Refuse a narrowing that would push a nested period outside its parent.
     *
     * @throws WindowRejectedException
     */
    private function assertChildrenStillContained(
        int $tenantId,
        int $id,
        string $startsOn,
        string $endsOn
    ): void {
        foreach ($this->listForTenant($tenantId, null, null, null, $id) as $child) {
            if ((string) $child['starts_on'] < $startsOn || (string) $child['ends_on'] > $endsOn) {
                throw WindowRejectedException::because(sprintf(
                    "These dates would leave '%s' (%s to %s) outside this period, which contains it.",
                    (string) $child['label'],
                    (string) $child['starts_on'],
                    (string) $child['ends_on']
                ));
            }
        }
    }

    /**
     * Serialise concurrent writes for one period kind.
     *
     * The overlap check reads and then writes, so without this two requests can
     * each find no clash against a snapshot taken before the other's insert, and
     * both succeed. Locking the KIND rather than the periods is what makes it
     * work: the rows that would clash do not exist yet, so there is nothing else
     * to lock, and the kind is the smallest thing every clashing write has in
     * common.
     *
     * `FOR UPDATE` is PostgreSQL-only here because SQLite would reject the
     * clause outright — and needs no equivalent, being a single-writer engine
     * whose write transactions already serialise.
     */
    private function lockType(int $tenantId, int $windowTypeId): void
    {
        if ($this->driver() !== 'pgsql') {
            return;
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM time_window_types
             WHERE tenant_id = :tenant_id AND id = :id
             FOR UPDATE'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $windowTypeId]);
        $stmt->fetchAll();
    }

    /**
     * Open a transaction unless the caller already has one.
     *
     * @return bool Whether this call owns the transaction and must end it.
     */
    private function beginTransaction(): bool
    {
        if ($this->db->inTransaction()) {
            return false;
        }
        $this->db->beginTransaction();

        return true;
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private static function isUniqueViolation(PDOException $e): bool
    {
        // Postgres 23505 / SQLite "UNIQUE constraint failed".
        return $e->getCode() === '23505' || str_contains($e->getMessage(), 'UNIQUE constraint failed');
    }

    /**
     * Shape a row for the API.
     *
     * `key` rather than `window_key` for the same reason the type repository
     * renames `type_key`: the column name dodges a reserved word, and the wire
     * contract has no such constraint.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'tenant_id'        => (int) $row['tenant_id'],
            'window_type_id'   => (int) $row['window_type_id'],
            'parent_window_id' => $row['parent_window_id'] === null ? null : (int) $row['parent_window_id'],
            'key'              => (string) $row['window_key'],
            'label'            => (string) $row['label'],
            // DATE comes back as `YYYY-MM-DD` from both engines, and is passed
            // through as a string rather than a timestamp: a period boundary is a
            // DAY, and rendering it as an instant invites a timezone to move it.
            'starts_on'        => substr((string) $row['starts_on'], 0, 10),
            'ends_on'          => substr((string) $row['ends_on'], 0, 10),
            'state'            => (string) $row['state'],
            'created_at'       => (string) $row['created_at'],
            'updated_at'       => (string) $row['updated_at'],
        ];
    }
}
