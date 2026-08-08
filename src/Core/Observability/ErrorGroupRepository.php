<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use PDO;

/**
 * Storage for the built-in error inbox (WC-error-tracking).
 *
 * One row per DISTINCT error, keyed by fingerprint. {@see record()} is an UPSERT
 * that increments a counter, which is what keeps this affordable inside the
 * app's own database: a repeating failure costs one row regardless of how many
 * times it fires.
 *
 * NOT tenant-scoped — see the 086 migration. Error tracking is operator-only and
 * deployment-wide, so these queries carry no tenant predicate and the table sits
 * outside {@see \Whity\Core\Tenant\TenantOwnedTables}.
 */
final class ErrorGroupRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Record an occurrence, returning how the group changed — which is what
     * decides whether anyone gets emailed.
     *
     * `new`       — first time this fingerprint has ever been seen.
     * `regressed` — it was marked resolved and has come back.
     * `repeat`    — already known and still open; no one needs another email.
     *
     * @param array<string, mixed> $context Already scrubbed by the caller.
     * @return array{outcome: 'new'|'regressed'|'repeat', id: int, occurrences: int}
     */
    public function record(
        string $fingerprint,
        string $type,
        string $message,
        ?string $file,
        ?int $line,
        ?string $stack,
        array $context,
        ?string $environment,
        string $level = 'error',
    ): array {
        $existing = $this->findByFingerprint($fingerprint);

        if ($existing === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO error_groups
                    (fingerprint, level, type, message, file, line, stack, context, environment)
                 VALUES (:fingerprint, :level, :type, :message, :file, :line, :stack, :context, :environment)'
            );
            $stmt->execute([
                ':fingerprint' => $fingerprint,
                ':level' => $level,
                ':type' => $type,
                ':message' => $message,
                ':file' => $file,
                ':line' => $line,
                ':stack' => $stack,
                ':context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':environment' => $environment,
            ]);

            $created = $this->findByFingerprint($fingerprint);

            return [
                'outcome' => 'new',
                'id' => (int) ($created['id'] ?? 0),
                'occurrences' => 1,
            ];
        }

        $wasResolved = ($existing['status'] ?? 'unresolved') === 'resolved';

        // A resolved error coming back reopens it — otherwise a regression would
        // sit silently in the "resolved" bucket where nobody looks.
        $stmt = $this->pdo->prepare(
            'UPDATE error_groups
                SET occurrences  = occurrences + 1,
                    last_seen_at = NOW(),
                    message      = :message,
                    file         = :file,
                    line         = :line,
                    stack        = :stack,
                    context      = :context,
                    environment  = :environment,
                    status       = CASE WHEN status = :resolved THEN :unresolved ELSE status END
              WHERE fingerprint = :fingerprint'
        );
        $stmt->execute([
            ':message' => $message,
            ':file' => $file,
            ':line' => $line,
            ':stack' => $stack,
            ':context' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':environment' => $environment,
            ':resolved' => 'resolved',
            ':unresolved' => 'unresolved',
            ':fingerprint' => $fingerprint,
        ]);

        return [
            'outcome' => $wasResolved ? 'regressed' : 'repeat',
            'id' => (int) $existing['id'],
            'occurrences' => ((int) ($existing['occurrences'] ?? 0)) + 1,
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByFingerprint(string $fingerprint): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM error_groups WHERE fingerprint = :fingerprint');
        $stmt->execute([':fingerprint' => $fingerprint]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM error_groups WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The inbox listing.
     *
     * @param 'unresolved'|'resolved'|'ignored'|null $status
     * @return list<array<string, mixed>>
     */
    public function list(?string $status, int $limit = 50, int $offset = 0): array
    {
        $sql = 'SELECT id, fingerprint, level, type, message, file, line, environment,
                       occurrences, status, first_seen_at, last_seen_at
                  FROM error_groups';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY last_seen_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function countByStatus(?string $status): int
    {
        $sql = 'SELECT COUNT(*) FROM error_groups';
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = :status';
            $params[':status'] = $status;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /** @param 'unresolved'|'resolved'|'ignored' $status */
    public function setStatus(int $id, string $status): bool
    {
        $stmt = $this->pdo->prepare('UPDATE error_groups SET status = :status WHERE id = :id');
        $stmt->execute([':status' => $status, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /** Stamped when an alert is sent, so a repeat never re-notifies. */
    public function markNotified(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE error_groups SET notified_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Retention GC: drop groups untouched for longer than the window. */
    public function pruneOlderThan(string $cutoff): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM error_groups WHERE last_seen_at < :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $stmt->rowCount();
    }
}
