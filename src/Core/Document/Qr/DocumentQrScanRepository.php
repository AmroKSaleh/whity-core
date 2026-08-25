<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

use PDO;

/**
 * Data-access for `document_qr_scans` (#1036) — THE SCAN TRAIL.
 *
 * APPEND-ONLY, BY OMISSION. There is {@see append()} and there are reads. No
 * `update()`, no `delete()`, no counter to increment — the same construction
 * {@see \Whity\Core\Document\Routing\RouteEventRepository} uses, and for the
 * same reason it gives: a store that offers an UPDATE is a store where somebody
 * eventually calls it, at which point the guarantee is a comment in a docblock.
 *
 * "How many times has this been scanned" is {@see countForDocument()} — a
 * COUNT over rows, not a column somebody keeps up to date. That distinction is
 * the whole point: the number cannot disagree with the trail, because it is
 * derived from it on every read.
 *
 * WHAT IS NOT HERE. No IP address, no user agent, no location, no device id —
 * there are no such columns to write. Migration 120's docblock carries the
 * argument; the short form is that an anonymous scanner is a member of the
 * public holding a piece of paper, not a user, and building a timestamped
 * interest record about people who have no account and no way to ask what is
 * held about them is not a side effect verification should have.
 *
 * TENANT-OWNED. Every statement binds a literal `tenant_id` predicate, spelled
 * out in SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file. Unlike the token repository there is no exception: by the time a
 * scan is recorded the token has already resolved, so its tenant is known.
 */
final class DocumentQrScanRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Append one scan and return its id. The only write this class has.
     *
     * The outcome is CHECK-constrained by the schema (migration 122), so a verb
     * outside {@see QrScanOutcome::all()} is refused by the database rather than
     * stored as a row nothing renders.
     *
     * `$scannerProfileId` is null for an anonymous scan and that null is
     * MEANINGFUL: it says a member of the public verified this document, which
     * is a fact the tenant wants. It does not say who, because nothing here
     * could.
     */
    public function append(
        int $tenantId,
        int $documentId,
        int $qrTokenId,
        ?int $scannerProfileId,
        string $outcome,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO document_qr_scans
                 (tenant_id, document_id, qr_token_id, scanner_profile_id, outcome, scanned_at)
             VALUES (:tenant_id, :document_id, :qr_token_id, :scanner_profile_id, :outcome, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':document_id' => $documentId,
            ':qr_token_id' => $qrTokenId,
            ':scanner_profile_id' => $scannerProfileId,
            ':outcome' => $outcome,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Whether this exact code has already been recorded for this exact scanner
     * within the last `$seconds`.
     *
     * WHY A COALESCING WINDOW EXISTS AT ALL. A phone that opens the verification
     * page, rotates, and reloads has scanned the paper ONCE. Without this the
     * trail becomes a page-view log — which is both useless to the person
     * reading it ("scanned 40 times" when it was scanned twice) and an
     * amplification surface, since the caller deciding how many rows to write is
     * an anonymous stranger holding a photograph.
     *
     * It reads rather than writes, so the trail stays append-only: the decision
     * not to append is taken before the insert, never by revising a row.
     *
     * The comparison is expressed as `scanned_at > :since` with the cutoff
     * computed in PHP rather than as a dialect-specific interval — PostgreSQL
     * and SQLite spell `NOW() - INTERVAL` differently, and the unit suite builds
     * its schema on SQLite.
     *
     * A race between two concurrent scans can produce two rows. That is fine and
     * is not worth a lock: the failure mode is one extra row in a trail, and the
     * alternative is serialising an anonymous public endpoint.
     */
    public function recentlyRecorded(
        int $tenantId,
        int $qrTokenId,
        ?int $scannerProfileId,
        string $since,
    ): bool {
        $sql = 'SELECT 1
                  FROM document_qr_scans
                 WHERE tenant_id = :tenant_id
                   AND qr_token_id = :qr_token_id
                   AND scanned_at > :since';
        $params = [
            ':tenant_id' => $tenantId,
            ':qr_token_id' => $qrTokenId,
            ':since' => $since,
        ];

        // Appended through the builder rather than interpolated into the literal
        // above, because `IS NULL` and `= :id` cannot share one placeholder and
        // the static scanner reads a `WHERE` whose first clause was built
        // conditionally as `WHERE AND …`. The tenant predicate stays in the
        // literal for exactly that reason.
        if ($scannerProfileId === null) {
            $sql .= ' AND scanner_profile_id IS NULL';
        } else {
            $sql .= ' AND scanner_profile_id = :scanner_profile_id';
            $params[':scanner_profile_id'] = $scannerProfileId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * A document's scans, NEWEST FIRST, for the record panel.
     *
     * Newest first, unlike the routing trail's oldest-first: a routing trail is
     * read as a history from the beginning, and a scan list is read as "has
     * anybody looked at this lately".
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $tenantId, int $documentId, int $limit, int $offset): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, qr_token_id, scanner_profile_id, outcome, scanned_at
               FROM document_qr_scans
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id DESC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':document_id', $documentId, PDO::PARAM_INT);
        // Bound as INT explicitly. PDO's default is PARAM_STR, which emulated
        // prepares quote — `LIMIT '25'` is a syntax error on PostgreSQL and
        // silently accepted on SQLite, so the SQLite unit run would pass and the
        // real engine would not.
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * How many scans a document has, so the panel reports a total the caller can
     * actually page to — and so "verified 11 times" is a COUNT rather than a
     * column somebody has to keep correct.
     */
    public function countForDocument(int $tenantId, int $documentId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM document_qr_scans
              WHERE tenant_id = :tenant_id AND document_id = :document_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['document_id'] = (int) $row['document_id'];
        $row['qr_token_id'] = (int) $row['qr_token_id'];
        $row['scanner_profile_id'] = isset($row['scanner_profile_id'])
            ? (int) $row['scanner_profile_id']
            : null;

        return $row;
    }
}
