<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * The single access path for `document_route_effect_attempts` (migration 139).
 *
 * APPEND-ONLY, exactly like {@see RouteEventRepository}. There is one write and
 * it is {@see append()}: no update, no delete, no status column to flip. A
 * second attempt at the same effect is a NEW ROW with a higher `attempt`, so
 * "it failed twice and then worked" stays readable — which is the whole reason
 * the issue asks for "how many attempts" rather than a success flag.
 *
 * The moment somebody most wants to tidy this table is exactly the moment it
 * must be immutable, which is the argument migration 112 makes for the route
 * trail and this inherits.
 */
final class RouteEffectAttemptRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Record one attempt.
     *
     * @param array{
     *     event_id?: int|null,
     *     effect_id?: int|null,
     *     effect_kind: string,
     *     status: string,
     *     attempt?: int,
     *     detail?: string|null
     * } $attempt
     *
     * @throws \InvalidArgumentException On a status outside the vocabulary —
     *         raised here rather than left to the database, so the message names
     *         the vocabulary instead of arriving as a driver error from inside a
     *         fail-soft handler.
     */
    public function append(int $tenantId, int $documentId, array $attempt): int
    {
        $status = (string) $attempt['status'];
        if (!RouteEffectStatus::isValid($status)) {
            throw new \InvalidArgumentException(
                "'{$status}' is not a route-effect status. Expected one of: "
                . implode(', ', RouteEffectStatus::all()) . '.'
            );
        }

        $statement = $this->db->prepare(
            'INSERT INTO document_route_effect_attempts
                (tenant_id, document_id, event_id, effect_id, effect_kind, status, attempt, detail, occurred_at)
             VALUES (:tenant_id, :document_id, :event_id, :effect_id, :effect_kind, :status, :attempt, :detail, NOW())'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'document_id' => $documentId,
            'event_id' => $attempt['event_id'] ?? null,
            'effect_id' => $attempt['effect_id'] ?? null,
            'effect_kind' => (string) $attempt['effect_kind'],
            'status' => $status,
            'attempt' => $attempt['attempt'] ?? 1,
            'detail' => $attempt['detail'] ?? null,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * What this document's effects have done, oldest first.
     *
     * Oldest first because it is read as a history beside the route trail, and
     * the trail reads that way — two logs about one document that ran in
     * opposite directions would be read wrong by somebody comparing them.
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $documentId, int $tenantId, int $limit = 200): array
    {
        $statement = $this->db->prepare(
            'SELECT id, tenant_id, document_id, event_id, effect_id, effect_kind, status, attempt, detail, occurred_at
               FROM document_route_effect_attempts
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY occurred_at ASC, id ASC
              LIMIT :limit'
        );
        $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue('document_id', $documentId, PDO::PARAM_INT);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): array => self::normalize($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
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
        $row['event_id'] = $row['event_id'] === null ? null : (int) $row['event_id'];
        $row['effect_id'] = $row['effect_id'] === null ? null : (int) $row['effect_id'];
        $row['attempt'] = (int) $row['attempt'];

        return $row;
    }
}
