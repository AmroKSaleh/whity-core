<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant\Automation;

use PDO;

/**
 * Automation runs of workspace plugins and their traces (migration 175).
 * Every query binds tenant_id.
 *
 * A run's trace is a list with one entry per step it reached:
 * `{step, kind, status: done|failed, input, outcome, at}`. It is written after
 * each step inside that step's transaction, which is what lets a retried run
 * skip the steps that already happened.
 */
final class AutomationRunRepository
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const STOPPED = 'stopped';

    public function __construct(private readonly PDO $db)
    {
    }

    public function create(
        int $tenantId,
        string $pluginKey,
        string $automation,
        string $event,
        string $recordType,
        int $recordId,
        int $depth,
        ?int $actorProfileId,
        ?int $rootRunId,
        string $automationHash,
        string $status = self::QUEUED,
        ?string $reason = null,
    ): int {
        $finished = $status === self::QUEUED ? null : gmdate('Y-m-d H:i:s');
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_automation_runs
                (tenant_id, plugin_key, automation_key, event, record_type, record_id, depth, actor_profile_id, root_run_id, automation_hash, status, reason, trace, finished_at)
             VALUES (:tenant_id, :plugin_key, :automation_key, :event, :record_type, :record_id, :depth, :actor, :root, :hash, :status, :reason, :trace, :finished_at)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plugin_key' => $pluginKey,
            ':automation_key' => $automation,
            ':event' => $event,
            ':record_type' => $recordType,
            ':record_id' => $recordId,
            ':depth' => $depth,
            ':actor' => $actorProfileId,
            ':root' => $rootRunId,
            ':hash' => $automationHash,
            ':status' => $status,
            ':reason' => $reason,
            ':trace' => '[]',
            ':finished_at' => $finished,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plugin_automation_runs WHERE id = :id AND tenant_id = :tenant_id');
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::row($row) : null;
    }

    /**
     * A run's trace, read for update inside the caller's transaction: a second
     * worker running the same run waits here, then sees the step done.
     *
     * @return list<array<string, mixed>>|null Null when the run isn't this workspace's.
     */
    public function lockTrace(int $tenantId, int $id): ?array
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' FOR UPDATE' : '';
        $stmt = $this->db->prepare('SELECT trace FROM plugin_automation_runs WHERE id = :id AND tenant_id = :tenant_id' . $lock);
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $trace = $stmt->fetchColumn();
        if ($trace === false) {
            return null;
        }
        $decoded = json_decode((string) $trace, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /** Runs in the chain a root run started, the root included. */
    public function countInChain(int $tenantId, int $rootRunId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM plugin_automation_runs
              WHERE tenant_id = :tenant_id AND (id = :root OR root_run_id = :root2)'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':root' => $rootRunId, ':root2' => $rootRunId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The automation, as stored on a run: a stable hash of its declaration.
     *
     * @param array<string, mixed> $automation
     */
    public static function hash(array $automation): string
    {
        // Key order isn't meaning: JSONB doesn't keep it.
        $canonical = static function (mixed $node) use (&$canonical): mixed {
            if (!is_array($node)) {
                return $node;
            }
            if (!array_is_list($node)) {
                ksort($node);
            }

            return array_map($canonical, $node);
        };

        return hash('sha256', (string) json_encode($canonical($automation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * A plugin's runs, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, string $pluginKey, ?int $before, int $limit): array
    {
        $sql = 'SELECT * FROM plugin_automation_runs WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key';
        $params = [':tenant_id' => $tenantId, ':plugin_key' => $pluginKey];
        if ($before !== null) {
            $sql .= ' AND id < :before';
            $params[':before'] = $before;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(100, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'row'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function markRunning(int $tenantId, int $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_automation_runs SET status = :status WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':status' => self::RUNNING, ':id' => $id, ':tenant_id' => $tenantId]);
    }

    /**
     * @param list<array<string, mixed>> $trace
     */
    public function saveTrace(int $tenantId, int $id, array $trace): void
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_automation_runs SET trace = :trace WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':trace' => json_encode($trace, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ':id' => $id,
            ':tenant_id' => $tenantId,
        ]);
    }

    public function finish(int $tenantId, int $id, string $status, ?string $reason = null): void
    {
        $stmt = $this->db->prepare(
            'UPDATE plugin_automation_runs SET status = :status, reason = :reason, finished_at = :finished_at
              WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([
            ':status' => $status,
            ':reason' => $reason,
            ':finished_at' => gmdate('Y-m-d H:i:s'),
            ':id' => $id,
            ':tenant_id' => $tenantId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $trace = json_decode((string) $row['trace'], true);

        return [
            'id' => (int) $row['id'],
            'plugin' => (string) $row['plugin_key'],
            'automation' => (string) $row['automation_key'],
            'event' => (string) $row['event'],
            'record_type' => (string) $row['record_type'],
            'record_id' => (int) $row['record_id'],
            'depth' => (int) $row['depth'],
            'actor_profile_id' => $row['actor_profile_id'] === null ? null : (int) $row['actor_profile_id'],
            'root_run_id' => $row['root_run_id'] === null ? null : (int) $row['root_run_id'],
            'automation_hash' => (string) $row['automation_hash'],
            'status' => (string) $row['status'],
            'reason' => $row['reason'] === null ? null : (string) $row['reason'],
            'trace' => is_array($trace) ? array_values($trace) : [],
            'created_at' => (string) $row['created_at'],
            'finished_at' => $row['finished_at'] === null ? null : (string) $row['finished_at'],
        ];
    }
}
