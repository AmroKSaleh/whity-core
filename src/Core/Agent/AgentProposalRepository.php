<?php

declare(strict_types=1);

namespace Whity\Core\Agent;

use PDO;

/**
 * Storage for agent proposals (WHIT-784, migration 176): an agent's Ask-first
 * call, waiting for the person who granted it.
 *
 * Every read and write is scoped to the tenant, and every decision to the
 * granting person. A decision is a single conditional UPDATE from `waiting`, so
 * two clicks, two tabs or two workers can't both approve one proposal: exactly
 * one of them wins, and only the winner runs it.
 */
final class AgentProposalRepository
{
    public const WAITING  = 'waiting';
    public const APPROVED = 'approved';
    public const DECLINED = 'declined';
    public const EXPIRED  = 'expired';
    public const UNDONE   = 'undone';

    /** A waiting proposal expires after 7 days (owner decision 10). */
    public const LIFETIME_SECONDS = 7 * 24 * 3600;

    private const COLUMNS = 'id, tenant_id, agent_principal_id, on_behalf_of_profile_id, tool, arguments, diff, scope,
                             permissions, summary, undo_window_seconds, status, result, decided_by_profile_id,
                             decided_at, expires_at, created_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $scope
     * @param list<string>         $permissions
     */
    public function create(
        int $tenantId,
        string $agentPrincipalId,
        int $onBehalfOfProfileId,
        string $tool,
        array $arguments,
        array $scope,
        array $permissions,
        string $summary,
        ?int $undoWindowSeconds = null,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO agent_proposals
                 (tenant_id, agent_principal_id, on_behalf_of_profile_id, tool, arguments, scope, permissions,
                  summary, undo_window_seconds, status, expires_at, created_at)
             VALUES (:tenant_id, :agent, :profile, :tool, :arguments, :scope, :permissions,
                  :summary, :undo, :status, :expires_at, NOW())'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':agent'       => $agentPrincipalId,
            ':profile'     => $onBehalfOfProfileId,
            ':tool'        => $tool,
            ':arguments'   => self::encode($arguments === [] ? new \stdClass() : $arguments),
            ':scope'       => self::encode($scope),
            ':permissions' => self::encode($permissions),
            ':summary'     => $summary,
            ':undo'        => $undoWindowSeconds,
            ':status'      => self::WAITING,
            ':expires_at'  => gmdate('Y-m-d H:i:s', time() + self::LIFETIME_SECONDS),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM agent_proposals WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * A person's proposals, newest first, with waiting ones past their expiry
     * marked expired first so the list never offers what can't be approved.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(int $tenantId, int $profileId, ?string $status = null, int $limit = 50): array
    {
        $this->expireDue($tenantId);

        $sql    = 'SELECT ' . self::COLUMNS . ' FROM agent_proposals WHERE tenant_id = :tenant_id AND on_behalf_of_profile_id = :profile';
        $params = [':tenant_id' => $tenantId, ':profile' => $profileId];
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        $stmt = $this->db->prepare($sql . ' ORDER BY id DESC LIMIT :limit');
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(self::normalize(...), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The names people gave the agent credentials that asked, by principal id
     * (the credential's jti): what the approvals card calls the agent ("Billing
     * assistant"). A revoked or renamed credential keeps whatever name its row
     * holds; one that no longer exists is simply absent.
     *
     * @param list<string> $principalIds
     * @return array<string, string>
     */
    public function agentNames(int $tenantId, array $principalIds): array
    {
        $principalIds = array_values(array_unique(array_filter($principalIds, static fn ($id): bool => $id !== '')));
        if ($principalIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_map(static fn (int $i): string => ':jti' . $i, array_keys($principalIds)));
        $stmt = $this->db->prepare(
            'SELECT jti, name FROM mcp_tokens WHERE tenant_id = :tenant_id AND jti IN (' . $placeholders . ')'
        );
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        foreach ($principalIds as $i => $id) {
            $stmt->bindValue(':jti' . $i, $id, PDO::PARAM_STR);
        }
        $stmt->execute();

        $names = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $names[(string) $row['jti']] = (string) $row['name'];
        }
        return $names;
    }

    /**
     * Move a waiting, unexpired proposal of $profileId's to $status, recording
     * who decided and, for an edit, the new arguments. True only for the one
     * caller that made the move.
     *
     * @param array<string, mixed>|null $arguments
     */
    public function decide(int $id, int $tenantId, int $profileId, string $status, ?array $arguments = null): bool
    {
        $sql = 'UPDATE agent_proposals
                   SET status = :status, decided_by_profile_id = :profile, decided_at = NOW()'
            . ($arguments !== null ? ', arguments = :arguments' : '') . '
                 WHERE id = :id AND tenant_id = :tenant_id AND on_behalf_of_profile_id = :profile
                   AND status = :waiting AND expires_at > :now';
        $params = [
            ':status'    => $status,
            ':profile'   => $profileId,
            ':id'        => $id,
            ':tenant_id' => $tenantId,
            ':waiting'   => self::WAITING,
            ':now'       => gmdate('Y-m-d H:i:s'),
        ];
        if ($arguments !== null) {
            $params[':arguments'] = self::encode($arguments === [] ? new \stdClass() : $arguments);
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() === 1;
    }

    /** Record what running an approved proposal answered. */
    public function recordResult(int $id, int $tenantId, mixed $result): void
    {
        $stmt = $this->db->prepare('UPDATE agent_proposals SET result = :result WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':result' => self::encode($result), ':id' => $id, ':tenant_id' => $tenantId]);
    }

    /** Mark the tenant's waiting proposals past their expiry as expired ("Ask again"). */
    public function expireDue(int $tenantId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE agent_proposals SET status = :expired
              WHERE tenant_id = :tenant_id AND status = :waiting AND expires_at <= :now'
        );
        $stmt->execute([
            ':expired'   => self::EXPIRED,
            ':tenant_id' => $tenantId,
            ':waiting'   => self::WAITING,
            ':now'       => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $json = static function (mixed $value, mixed $default): mixed {
            if (!is_string($value) || $value === '') {
                return $default;
            }
            $decoded = json_decode($value, true);
            return $decoded ?? $default;
        };

        return [
            'id'                      => (int) $row['id'],
            'tenant_id'               => (int) $row['tenant_id'],
            'agent_principal_id'      => (string) $row['agent_principal_id'],
            'on_behalf_of_profile_id' => (int) $row['on_behalf_of_profile_id'],
            'tool'                    => (string) $row['tool'],
            'arguments'               => (array) $json($row['arguments'], []),
            'diff'                    => $json($row['diff'], null),
            'scope'                   => array_values(array_filter((array) $json($row['scope'], []), 'is_string')),
            'permissions'             => array_values(array_filter((array) $json($row['permissions'], []), 'is_string')),
            'summary'                 => (string) $row['summary'],
            'undo_window_seconds'     => $row['undo_window_seconds'] === null ? null : (int) $row['undo_window_seconds'],
            'status'                  => (string) $row['status'],
            'result'                  => $json($row['result'], null),
            'decided_by_profile_id'   => $row['decided_by_profile_id'] === null ? null : (int) $row['decided_by_profile_id'],
            'decided_at'              => $row['decided_at'] === null ? null : (string) $row['decided_at'],
            'expires_at'              => (string) $row['expires_at'],
            'created_at'              => (string) $row['created_at'],
        ];
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
