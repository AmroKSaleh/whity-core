<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;
use PDOException;

/**
 * Data-access for `document_route_recipients` (#947 item 3) — THE INBOX.
 *
 * #947 says "recipients as the inbox", and this table is not something an inbox
 * is BUILT FROM; it IS the inbox. An open row is an item awaiting somebody, and
 * "awaiting me" — item 5's second derived folder — is one indexed predicate
 * over it.
 *
 * THE ROW HOLDS NO STATE OF ITS OWN
 * ---------------------------------
 * The obvious column, `status VARCHAR`, is exactly what migration 108 refused
 * one table over: a second copy of an answer the trail already gives, free to
 * disagree with it, and the copy is the one screens read.
 *
 * So the row carries two FOREIGN KEYS INTO THE TRAIL instead:
 *
 *   created_by_event_id — the event that put this in your inbox (NOT NULL)
 *   closed_by_event_id  — the event that took it out   (NULL = still open)
 *
 * Their values can only ever be trail row ids, so they cannot say anything the
 * trail does not also say. "Awaiting me" is `closed_by_event_id IS NULL`; the
 * item's status as an inbox renders it is the ACTION of the creating event —
 * read through the pointer, never stored again beside it.
 *
 * The rejected alternative was deriving open-ness with a correlated NOT EXISTS
 * against the trail on every read. Correct, and it is the hottest query in the
 * subsystem — it is the inbox — so it would be a trail scan per page view,
 * forever.
 *
 * WHY THIS ONE IS NOT APPEND-ONLY, AND WHY THAT IS NOT A HOLE
 * ----------------------------------------------------------
 * {@see close()} writes. That is the single mutation in the four routing
 * tables, it sets one column, and the value it sets is a trail row id — so the
 * mutation cannot introduce a claim the trail does not already make.
 *
 * This table is a PROJECTION and is not claimed otherwise. What it holds that
 * the trail cannot re-derive is WHICH PEOPLE a rule actually resolved to and
 * when: rules resolve against the organisation as it stood at that instant, and
 * replaying them later against today's org chart would answer a different
 * question. Everything else about the row is a pointer.
 *
 * FAN-OUT, AND THE PARTIAL UNIQUE INDEX
 * -------------------------------------
 * `parent_recipient_id` links each row to the recipient whose action produced
 * it, so the chains are independent and there is no step-level aggregate
 * anywhere that could hold the fast ones for the slow one.
 *
 * Two chains can legitimately reach the same person at the same step. They
 * should see ONE item, so {@see create()} tolerates the unique violation from
 * migration 112's partial index (`WHERE closed_by_event_id IS NULL`) and treats
 * it as "another chain got here first" — the trail still records both forwards
 * in full, because de-duplicating the inbox is not the same as editing history.
 *
 * The index is partial rather than a plain UNIQUE so that a `returned` document
 * can reappear in the predecessor's inbox as a NEW row: at most one OPEN item
 * per person per step, an unbounded history of closed ones.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file.
 */
final class RouteRecipientRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Open one inbox item, or return null when this person already has an open
     * item at this step.
     *
     * The null is not an error and is not silence either: {@see DocumentRouter}
     * counts what was actually opened and the trail event records the
     * distribution, so a de-duplicated recipient is visible as a difference
     * between "resolved" and "delivered" rather than as a missing row nobody
     * can account for.
     *
     * @param array{document_id: int, route_id: int, step_id: int, profile_id: int,
     *              ou_id?: ?int, parent_recipient_id?: ?int, created_by_event_id: int} $rec
     *
     * @return int|null The new row's id, or null when an open row already existed.
     */
    public function create(int $tenantId, array $rec): ?int
    {
        // Checked first, then let the index be the backstop. The check answers
        // the ordinary case (two chains reaching the same person in two
        // separate requests) without an exception; the catch answers the
        // genuinely concurrent one, where two transactions both pass the check.
        // Doing only the check would be a race; doing only the catch would make
        // an ordinary, expected outcome arrive as an exception on the hot path.
        if ($this->hasOpen($tenantId, $rec['route_id'], $rec['step_id'], $rec['profile_id'])) {
            return null;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO document_route_recipients
                 (tenant_id, document_id, route_id, step_id, profile_id, ou_id,
                  parent_recipient_id, created_by_event_id, created_at)
             VALUES (:tenant_id, :document_id, :route_id, :step_id, :profile_id, :ou_id,
                     :parent_recipient_id, :created_by_event_id, NOW())'
        );

        try {
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':document_id' => $rec['document_id'],
                ':route_id' => $rec['route_id'],
                ':step_id' => $rec['step_id'],
                ':profile_id' => $rec['profile_id'],
                ':ou_id' => $rec['ou_id'] ?? null,
                ':parent_recipient_id' => $rec['parent_recipient_id'] ?? null,
                ':created_by_event_id' => $rec['created_by_event_id'],
            ]);
        } catch (PDOException $e) {
            // SQLSTATE 23505 on PostgreSQL, 23000 on SQLite: unique violation.
            // Only the open-item index can produce one on this statement — every
            // other column is either nullable or a foreign key, whose violation
            // is 23503/23000-with-different-text and would be a real bug worth
            // propagating.
            if (!self::isUniqueViolation($e)) {
                throw $e;
            }

            return null;
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Close an inbox item by naming the trail event that closed it.
     *
     * The ONLY mutation in the routing tables, and it sets one column to a trail
     * row id — see the class docblock. Idempotent by predicate: closing an
     * already-closed row matches nothing and reports false, so a retried request
     * cannot rewrite which event closed it.
     *
     * @return bool Whether a row was actually closed.
     */
    public function close(int $tenantId, int $recipientId, int $eventId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE document_route_recipients
                SET closed_by_event_id = :event_id
              WHERE id = :id AND tenant_id = :tenant_id AND closed_by_event_id IS NULL'
        );
        $stmt->execute([':event_id' => $eventId, ':id' => $recipientId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * One recipient row, tenant-scoped.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, profile_id, ou_id,
                    parent_recipient_id, created_by_event_id, closed_by_event_id, created_at
               FROM document_route_recipients
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * A person's OPEN item on a route, or null when they have none.
     *
     * This is the authorization check for acting: being a recipient IS the
     * authorization (see migration 113), so "may this person forward?" is
     * exactly "do they hold an open row on this route?". Bound to the tenant and
     * the profile, so a guessed route id resolves to null rather than to
     * somebody else's assignment.
     *
     * @return array<string, mixed>|null
     */
    public function findOpenForProfile(int $tenantId, int $routeId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, profile_id, ou_id,
                    parent_recipient_id, created_by_event_id, closed_by_event_id, created_at
               FROM document_route_recipients
              WHERE tenant_id = :tenant_id
                AND route_id = :route_id
                AND profile_id = :profile_id
                AND closed_by_event_id IS NULL
              ORDER BY id ASC
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':route_id' => $routeId, ':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? self::normalize($row) : null;
    }

    /**
     * Every recipient row on a document, oldest first — who a route reached and
     * what became of it.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, route_id, step_id, profile_id, ou_id,
                    parent_recipient_id, created_by_event_id, closed_by_event_id, created_at
               FROM document_route_recipients
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * THE INBOX QUERY: a person's items, newest first.
     *
     * Joined to `documents` and to the creating trail event, because an inbox
     * row on its own says nothing a person can read — it is ids. The join is
     * what turns it into "Purchase order 4471, forwarded to you on Tuesday",
     * which is exactly the item shape the #868 `inbox` block declares and #881
     * aggregates. Doing it here rather than in the handler means the inbox and
     * item 5's "awaiting me" folder cannot drift into showing different columns
     * for the same rows.
     *
     * `$openOnly` defaults to the open set because that is what an inbox IS; the
     * closed set is history and is asked for explicitly.
     *
     * @return list<array<string, mixed>>
     */
    public function listForProfile(
        int $tenantId,
        int $profileId,
        bool $openOnly,
        int $limit,
        int $offset,
    ): array {
        // Two literal statements rather than one assembled from fragments: the
        // tenant predicate guard reads this source, and a WHERE clause built by
        // concatenation is one it cannot verify. The duplication is the join
        // list twice and buys a check that actually runs — the same trade
        // {@see \Whity\Core\Document\DocumentRepository::listForTenant()} makes.
        if ($openOnly) {
            $stmt = $this->db->prepare(
                'SELECT r.id, r.tenant_id, r.document_id, r.route_id, r.step_id, r.profile_id, r.ou_id,
                        r.parent_recipient_id, r.created_by_event_id, r.closed_by_event_id, r.created_at,
                        d.title AS document_title, d.template_name AS document_template_name,
                        e.action AS arrived_by, e.actor_profile_id AS arrived_from
                   FROM document_route_recipients r
                   JOIN documents d
                     ON d.id = r.document_id AND d.tenant_id = :tenant_id
                   JOIN document_route_events e
                     ON e.id = r.created_by_event_id AND e.tenant_id = :tenant_id
                  WHERE r.tenant_id = :tenant_id
                    AND r.profile_id = :profile_id
                    AND r.closed_by_event_id IS NULL
                  ORDER BY r.id DESC
                  LIMIT :limit OFFSET :offset'
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT r.id, r.tenant_id, r.document_id, r.route_id, r.step_id, r.profile_id, r.ou_id,
                        r.parent_recipient_id, r.created_by_event_id, r.closed_by_event_id, r.created_at,
                        d.title AS document_title, d.template_name AS document_template_name,
                        e.action AS arrived_by, e.actor_profile_id AS arrived_from
                   FROM document_route_recipients r
                   JOIN documents d
                     ON d.id = r.document_id AND d.tenant_id = :tenant_id
                   JOIN document_route_events e
                     ON e.id = r.created_by_event_id AND e.tenant_id = :tenant_id
                  WHERE r.tenant_id = :tenant_id
                    AND r.profile_id = :profile_id
                  ORDER BY r.id DESC
                  LIMIT :limit OFFSET :offset'
            );
        }

        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':profile_id', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalizeInboxRow(...), $rows);
    }

    /**
     * How many items the same predicate as {@see listForProfile()} matches.
     *
     * The badge count, and the pagination total. Not derived from a fetched page
     * for the reason every other list in this codebase records: a post-filtered
     * total is one the caller cannot reach.
     */
    public function countForProfile(int $tenantId, int $profileId, bool $openOnly): int
    {
        if ($openOnly) {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM document_route_recipients
                  WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND closed_by_event_id IS NULL'
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM document_route_recipients
                  WHERE tenant_id = :tenant_id AND profile_id = :profile_id'
            );
        }
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether a profile has ever been a recipient of a document.
     *
     * The disjunct {@see \Whity\Core\Document\DocumentVisibilityPolicy} adds:
     * a document that reached you is a document you may read, and it stays
     * readable after you have acted — "I no longer have it in my inbox" is not
     * "I was never sent it", and a person who forwarded something last week must
     * still be able to open what they forwarded.
     */
    public function hasAnyForProfile(int $tenantId, int $documentId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM document_route_recipients
              WHERE tenant_id = :tenant_id AND document_id = :document_id AND profile_id = :profile_id
              LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':document_id' => $documentId,
            ':profile_id' => $profileId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Whether a person already has an OPEN item at a step. See {@see create()}.
     */
    private function hasOpen(int $tenantId, int $routeId, int $stepId, int $profileId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM document_route_recipients
              WHERE tenant_id = :tenant_id
                AND route_id = :route_id
                AND step_id = :step_id
                AND profile_id = :profile_id
                AND closed_by_event_id IS NULL
              LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':route_id' => $routeId,
            ':step_id' => $stepId,
            ':profile_id' => $profileId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * A unique-constraint violation, on either engine.
     *
     * PostgreSQL reports SQLSTATE 23505. SQLite reports the generic 23000 with a
     * driver message naming the constraint, so the text is the only
     * discriminator available there — which is why the check is anchored to the
     * word SQLite itself uses rather than to our index name (an `IF NOT EXISTS`
     * index created by an older migration run may carry a different one).
     */
    private static function isUniqueViolation(PDOException $e): bool
    {
        if ($e->getCode() === '23505') {
            return true;
        }

        return $e->getCode() === '23000' && stripos($e->getMessage(), 'UNIQUE constraint failed') !== false;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'document_id' => (int) $row['document_id'],
            'route_id' => (int) $row['route_id'],
            'step_id' => (int) $row['step_id'],
            'profile_id' => (int) $row['profile_id'],
            'ou_id' => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            'parent_recipient_id' => $row['parent_recipient_id'] !== null ? (int) $row['parent_recipient_id'] : null,
            'created_by_event_id' => (int) $row['created_by_event_id'],
            'closed_by_event_id' => $row['closed_by_event_id'] !== null ? (int) $row['closed_by_event_id'] : null,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * The inbox shape: a recipient row plus the two facts that make it legible.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeInboxRow(array $row): array
    {
        return self::normalize($row) + [
            'document_title' => (string) $row['document_title'],
            'document_template_name' => (string) $row['document_template_name'],
            // The ACTION of the creating trail event — how this reached you.
            // Read through the pointer rather than stored beside it, so it can
            // never disagree with the trail.
            'arrived_by' => (string) $row['arrived_by'],
            'arrived_from' => $row['arrived_from'] !== null ? (int) $row['arrived_from'] : null,
        ];
    }
}
