<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * Data-access for `document_collections` and `document_collection_items`
 * (#978) — a person's own filing of documents, and the only part of the
 * document organizer that is written down.
 *
 * TENANT-OWNED AND PROFILE-OWNED
 * ------------------------------
 * Every statement binds an explicit `tenant_id` predicate, spelled out in
 * literal SQL so scripts/ci-tenant-predicate-guard.php can verify it by reading
 * this file. Every statement ALSO binds `profile_id`, which the guard does not
 * check and which matters just as much here: a collection is private to one
 * person inside one tenant, so an id from someone else's rail must not resolve.
 * Both predicates are on the collection lookup rather than one being inferred —
 * which is why {@see findOwned()} is the only way in and every mutation goes
 * through it.
 *
 * NOT FOUND, NEVER FORBIDDEN
 * --------------------------
 * A collection belonging to another profile is reported as absent, exactly as
 * {@see DocumentVisibilityPolicy} reports a document the caller may not see. A
 * 403 on somebody else's collection id would confirm it exists, and collection
 * ids are enumerable integers, so the shape of a colleague's filing would be
 * readable by walking them.
 *
 * MEMBERSHIP IS A POINTER, NOT A GRANT
 * ------------------------------------
 * Nothing here checks or confers document visibility. {@see contains()} and the
 * membership fetch answer "is this document filed here", and the LIST path
 * re-applies the visibility policy to every row it returns — see
 * {@see DocumentRepository::listForCriteria()}. Visibility can narrow after a
 * document is filed, and a collection that kept serving what its owner may no
 * longer read would be a permanent bypass of the policy, created by an action
 * (starring) that looks like a bookmark.
 */
final class DocumentCollectionRepository
{
    /**
     * The well-known key of the starred collection.
     *
     * Starring is a collection, not a second concept — migration 114's docblock
     * argues why, including what a dedicated `document_stars` table would have
     * cost. The KEY rather than the name is the identity, so the row's display
     * name can be renamed or translated without the star control losing what it
     * points at.
     */
    public const STARRED_KEY = 'starred';

    /** The name a lazily-created starred collection is born with. */
    public const STARRED_DEFAULT_NAME = 'Starred';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The caller's collections in this tenant, each with how many documents it
     * holds.
     *
     * The count is a correlated sub-select rather than a `LEFT JOIN … GROUP BY`:
     * a person holds a handful of collections, the index
     * `(tenant_id, collection_id, document_id)` covers each sub-select outright,
     * and the join form would need the same grouping to be re-derived by every
     * future column added to the row.
     *
     * The count is of ROWS FILED, not of rows the owner may still read. Those
     * can differ — see the class docblock — and reporting the filtered number
     * here would mean running the visibility policy once per collection on every
     * rail render. The rail says how much you filed; opening it says how much
     * you may still see.
     *
     * @return list<array<string, mixed>>
     */
    public function listOwned(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            'SELECT c.id, c.tenant_id, c.profile_id, c.name, c.system_key, c.created_at,
                    (SELECT COUNT(*) FROM document_collection_items i
                      WHERE i.tenant_id = c.tenant_id AND i.collection_id = c.id) AS item_count
               FROM document_collections c
              WHERE c.tenant_id = :tenant_id AND c.profile_id = :profile_id
              ORDER BY c.system_key IS NULL, c.name ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->normalizeCollection(...), $rows);
    }

    /**
     * One of the caller's OWN collections, or null.
     *
     * Null covers "no such id", "another tenant's" and "another person's"
     * identically, and every caller turns it into a 404.
     *
     * @return array<string, mixed>|null
     */
    public function findOwned(int $id, int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, profile_id, name, system_key, created_at
               FROM document_collections
              WHERE id = :id AND tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeCollection($row) : null;
    }

    /**
     * The caller's collection carrying a well-known key, or null when they have
     * none yet.
     *
     * @return array<string, mixed>|null
     */
    public function findBySystemKey(string $systemKey, int $tenantId, int $profileId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, profile_id, name, system_key, created_at
               FROM document_collections
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND system_key = :system_key'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':system_key' => $systemKey,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizeCollection($row) : null;
    }

    /**
     * Create a collection and return its id.
     *
     * Throws whatever the driver throws on the unique constraint; the handler
     * turns that into a 409 rather than pre-checking, because a check-then-write
     * is a race two clicks apart and the constraint is the only answer that
     * cannot be wrong.
     */
    public function create(int $tenantId, int $profileId, string $name, ?string $systemKey = null): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_collections (tenant_id, profile_id, name, system_key, created_at)
             VALUES (:tenant_id, :profile_id, :name, :system_key, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
            ':name' => $name,
            ':system_key' => $systemKey,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** Rename one of the caller's own collections. */
    public function rename(int $id, int $tenantId, int $profileId, string $name): void
    {
        $stmt = $this->db->prepare(
            'UPDATE document_collections SET name = :name
              WHERE id = :id AND tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([
            ':name' => $name,
            ':id' => $id,
            ':tenant_id' => $tenantId,
            ':profile_id' => $profileId,
        ]);
    }

    /**
     * Delete one of the caller's own collections. Its items go with it through
     * the `ON DELETE CASCADE` on `collection_id` — the documents themselves are
     * untouched, which is the whole difference between a collection and a folder.
     */
    public function delete(int $id, int $tenantId, int $profileId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM document_collections
              WHERE id = :id AND tenant_id = :tenant_id AND profile_id = :profile_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':profile_id' => $profileId]);
    }

    /**
     * File a document into a collection. Idempotent: filing a document that is
     * already there is a no-op, not an error, because the operation the user
     * performed ("this belongs in my Q3 pile") is already true afterwards and
     * two clicks on a star must not differ from one.
     *
     * The caller has already established that the collection is theirs and the
     * document is visible to them.
     */
    public function addItem(int $tenantId, int $collectionId, int $documentId): void
    {
        // ON CONFLICT DO NOTHING against the (collection_id, document_id) unique
        // constraint — the same shape the permission-grant migrations use, and
        // the only form that is safe against two concurrent clicks.
        $stmt = $this->db->prepare(
            'INSERT INTO document_collection_items (tenant_id, collection_id, document_id, added_at)
             VALUES (:tenant_id, :collection_id, :document_id, NOW())
             ON CONFLICT (collection_id, document_id) DO NOTHING'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':collection_id' => $collectionId,
            ':document_id' => $documentId,
        ]);
    }

    /** Remove a document from a collection. Idempotent, for the same reason. */
    public function removeItem(int $tenantId, int $collectionId, int $documentId): void
    {
        $stmt = $this->db->prepare(
            'DELETE FROM document_collection_items
              WHERE tenant_id = :tenant_id AND collection_id = :collection_id AND document_id = :document_id'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':collection_id' => $collectionId,
            ':document_id' => $documentId,
        ]);
    }

    public function contains(int $tenantId, int $collectionId, int $documentId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM document_collection_items
              WHERE tenant_id = :tenant_id AND collection_id = :collection_id AND document_id = :document_id
              LIMIT 1'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':collection_id' => $collectionId,
            ':document_id' => $documentId,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Which of the caller's collections hold each of these documents.
     *
     * ONE query for a whole page, not one per row: the organizer renders a star
     * and a "filed in" badge on every row, and the per-row form is 25 round
     * trips per page — the classic N+1 that only shows up once a tenant has
     * real volume.
     *
     * Returns documentId => list of collection ids, containing an entry only for
     * documents that are filed somewhere. A document absent from the map is
     * filed nowhere, which the caller renders as no badge — not as an unknown.
     *
     * @param list<int> $documentIds
     * @return array<int, list<int>>
     */
    public function collectionIdsForDocuments(int $tenantId, int $profileId, array $documentIds): array
    {
        if ($documentIds === []) {
            return [];
        }

        $placeholders = [];
        $bindings = [':tenant_id' => $tenantId, ':profile_id' => $profileId];
        foreach (array_values($documentIds) as $i => $documentId) {
            $name = ':doc_' . $i;
            $placeholders[] = $name;
            $bindings[$name] = $documentId;
        }

        // The join to `document_collections` is what scopes this to the CALLER's
        // collections: `document_collection_items` alone would return every
        // colleague's filing of the same document. Both sides bind tenant_id.
        $sql = 'SELECT i.document_id, i.collection_id
                  FROM document_collection_items i
                  JOIN document_collections c
                    ON c.id = i.collection_id AND c.tenant_id = i.tenant_id
                 WHERE i.tenant_id = :tenant_id
                   AND c.profile_id = :profile_id
                   AND i.document_id IN (' . implode(', ', $placeholders) . ')';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($bindings);

        $byDocument = [];
        /** @var array<string, mixed> $row */
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $byDocument[(int) $row['document_id']][] = (int) $row['collection_id'];
        }

        return $byDocument;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeCollection(array $row): array
    {
        $normalized = [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'profile_id' => (int) $row['profile_id'],
            'name' => (string) $row['name'],
            'system_key' => $row['system_key'] !== null ? (string) $row['system_key'] : null,
            'created_at' => (string) $row['created_at'],
        ];

        if (array_key_exists('item_count', $row)) {
            $normalized['item_count'] = (int) $row['item_count'];
        }

        return $normalized;
    }
}
