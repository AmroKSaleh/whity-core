<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use PDO;
use Throwable;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * The one write path for workspace plugins' records (WHIT-761, 762, 763).
 *
 * The record API and automation steps both write through here, so a record
 * gets the same rules ({@see RecordRules}), lifecycle gate, audit and
 * automation triggers whichever door the write came in by. Permission checks
 * stay with the caller: the API checks the person, an automation checks the
 * plugin's grants and the person it acts for.
 *
 * Each write and the automation runs it starts are committed together, so a
 * write never lands without its runs or the other way round. An update or a
 * move reads the record locked in that transaction, so a write made at the
 * same moment (an automation and a person) can't be overwritten unseen. If
 * starting the runs fails, the write fails with it. Inside a
 * transaction the caller already holds (an automation step), it joins that
 * one.
 *
 * Today is the UTC date for now; the workspace time zone comes with the
 * temporal spine (WHIT-586).
 */
final class RecordWriter
{
    public const AUDIT_CREATED = 'tenant_record.created';
    public const AUDIT_UPDATED = 'tenant_record.updated';
    public const AUDIT_MOVED = 'tenant_record.moved';
    public const AUDIT_DELETED = 'tenant_record.deleted';

    public function __construct(
        private readonly PDO $db,
        private readonly TenantRecordRepository $records,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuditLogger $audit = null,
        private readonly ?RecordEventListener $listener = null,
    ) {
    }

    /**
     * @param array<mixed> $values
     * @return array{ok: true, id: int}|array{ok: false, reason: 'issues', issues: list<array<string, string>>}
     */
    public function create(int $tenantId, PluginManifest $manifest, string $type, array $values, RecordActor $actor): array
    {
        $decl = $manifest->records()[$type];
        $state = $decl['lifecycle']['initial'] ?? null;
        $state = is_string($state) ? $state : null;
        $roles = $this->roles($tenantId, $actor);
        $checked = $this->prepare($tenantId, $manifest->name(), $decl, $values, null, $state, $roles);
        if ($checked['issues'] !== []) {
            return ['ok' => false, 'reason' => 'issues', 'issues' => $checked['issues']];
        }

        $id = $this->atomically(function () use ($tenantId, $manifest, $type, $state, $checked, $actor, $roles): int {
            $id = $this->records->create($tenantId, $manifest->name(), $type, $state, $checked['data'], $actor->profileId);
            $this->record(self::AUDIT_CREATED, $tenantId, $manifest->name(), $type, $id, $actor);
            $this->listener?->recordWritten($tenantId, $manifest, new RecordEvent(
                RecordEvent::CREATED, $type, $id, $state, $checked['data'], array_keys($checked['data']), $actor, $roles,
            ));

            return $id;
        });

        return ['ok' => true, 'id' => $id];
    }

    /**
     * @param array<mixed> $values The fields to change.
     * @return array{ok: true}|array{ok: false, reason: 'not_found'}|array{ok: false, reason: 'issues', issues: list<array<string, string>>}
     */
    public function update(int $tenantId, PluginManifest $manifest, string $type, int $id, array $values, RecordActor $actor): array
    {
        $decl = $manifest->records()[$type];
        $roles = $this->roles($tenantId, $actor);

        /** @var array{ok: true}|array{ok: false, reason: 'not_found'}|array{ok: false, reason: 'issues', issues: list<array<string, string>>} $result */
        $result = $this->atomically(function () use ($tenantId, $manifest, $type, $id, $values, $actor, $roles, $decl): array {
            $row = $this->records->find($tenantId, $manifest->name(), $type, $id, true);
            if ($row === null) {
                return ['ok' => false, 'reason' => 'not_found'];
            }
            $checked = $this->prepare($tenantId, $manifest->name(), $decl, $values, ['state' => $row['state'], 'data' => $row['data']], $row['state'], $roles);
            if ($checked['issues'] !== []) {
                return ['ok' => false, 'reason' => 'issues', 'issues' => $checked['issues']];
            }
            $changed = [];
            foreach ($checked['data'] as $name => $value) {
                if (($row['data'][$name] ?? null) !== $value) {
                    $changed[] = (string) $name;
                }
            }

            $this->records->update($tenantId, $manifest->name(), $type, $id, $checked['data'], null, $actor->profileId);
            $this->record(self::AUDIT_UPDATED, $tenantId, $manifest->name(), $type, $id, $actor, ['fields' => array_map('strval', array_keys($values))]);
            if ($changed !== []) {
                $this->listener?->recordWritten($tenantId, $manifest, new RecordEvent(
                    RecordEvent::CHANGED, $type, $id, $row['state'], $checked['data'], $changed, $actor, $roles,
                ));
            }

            return ['ok' => true];
        });

        return $result;
    }

    /**
     * A declared move. The caller has checked the move's permission.
     *
     * @return array{ok: true}|array{ok: false, reason: 'not_found'}|array{ok: false, reason: 'move_not_available', state: ?string, from: list<string>}|array{ok: false, reason: 'issues', issues: list<array<string, string>>}
     */
    public function move(int $tenantId, PluginManifest $manifest, string $type, int $id, string $move, RecordActor $actor): array
    {
        $decl = $manifest->records()[$type];
        $declared = $decl['lifecycle']['moves'][$move] ?? null;
        if (!is_array($declared)) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        $roles = $this->roles($tenantId, $actor);

        /** @var array{ok: true}|array{ok: false, reason: 'not_found'}|array{ok: false, reason: 'move_not_available', state: ?string, from: list<string>}|array{ok: false, reason: 'issues', issues: list<array<string, string>>} $result */
        $result = $this->atomically(function () use ($tenantId, $manifest, $type, $id, $move, $declared, $decl, $actor, $roles): array {
            $row = $this->records->find($tenantId, $manifest->name(), $type, $id, true);
            if ($row === null) {
                return ['ok' => false, 'reason' => 'not_found'];
            }
            /** @var list<string> $from */
            $from = is_string($declared['from']) ? [$declared['from']] : array_values((array) $declared['from']);
            if (!in_array($row['state'], $from, true)) {
                return ['ok' => false, 'reason' => 'move_not_available', 'state' => $row['state'], 'from' => $from];
            }
            $to = (string) $declared['to'];
            $issues = RecordRules::checkMove($decl, $row['data'], $to, $roles, gmdate('Y-m-d'), gmdate('Y-m-d\TH:i:s\Z'));
            if ($issues !== []) {
                return ['ok' => false, 'reason' => 'issues', 'issues' => $issues];
            }

            $this->records->update($tenantId, $manifest->name(), $type, $id, $row['data'], $to, $actor->profileId);
            $this->record(self::AUDIT_MOVED, $tenantId, $manifest->name(), $type, $id, $actor, ['move' => $move, 'from' => $row['state'], 'to' => $to]);
            $this->listener?->recordWritten($tenantId, $manifest, new RecordEvent(
                RecordEvent::MOVED, $type, $id, $to, $row['data'], [], $actor, $roles,
            ));

            return ['ok' => true];
        });

        return $result;
    }

    public function delete(int $tenantId, PluginManifest $manifest, string $type, int $id, RecordActor $actor): bool
    {
        if (!$this->records->delete($tenantId, $manifest->name(), $type, $id)) {
            return false;
        }
        $this->record(self::AUDIT_DELETED, $tenantId, $manifest->name(), $type, $id, $actor);

        return true;
    }

    /**
     * The actor's role names, which rules can ask about (`has_role`). The
     * plugin acting alone has none.
     *
     * @return list<string>
     */
    public function roles(int $tenantId, RecordActor $actor): array
    {
        if ($actor->roles !== null) {
            return $actor->roles;
        }

        return $actor->profileId === null
            ? []
            : array_values($this->roleChecker->getEffectiveRolesForProfile($actor->profileId, $tenantId));
    }

    /**
     * The record type's rules ({@see RecordRules}), plus: links to records must
     * point at records of the linked type in this workspace.
     *
     * @param array<string, mixed> $decl
     * @param array<mixed> $body
     * @param array{state: ?string, data: array<string, mixed>}|null $existing
     * @param list<string> $roles
     * @return array{data: array<string, mixed>, issues: list<array<string, string>>}
     */
    private function prepare(int $tenantId, string $plugin, array $decl, array $body, ?array $existing, ?string $state, array $roles): array
    {
        $checked = RecordRules::prepare($decl, $body, $existing, $state, $roles, gmdate('Y-m-d'), gmdate('Y-m-d\TH:i:s\Z'));
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $decl['fields'] ?? [];
        foreach ($body as $name => $value) {
            if (($fields[$name]['type'] ?? null) === 'record' && is_int($value)
                && !$this->records->allExist($tenantId, $plugin, (string) $fields[$name]['record'], [$value])) {
                $checked['issues'][] = ['severity' => 'error', 'message' => 'No such record', 'messageAr' => 'لا يوجد سجل كهذا', 'column' => (string) $name];
            }
        }

        return $checked;
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function atomically(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            return $work();
        }
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();

            return $result;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Audit a write. An automation's write is the plugin acting for a person:
     * both are recorded, with the run that did it.
     *
     * @param array<string, mixed> $metadata
     */
    private function record(string $action, int $tenantId, string $plugin, string $type, int $id, RecordActor $actor, array $metadata = []): void
    {
        $metadata = ['plugin' => $plugin, 'record_type' => $type] + $metadata;
        if ($actor->isAutomation()) {
            $metadata += [
                'actor_kind' => 'automation',
                'automation' => $actor->automation,
                'automation_run_id' => $actor->runId,
                'on_behalf_of' => $actor->profileId,
            ];
        }
        $this->audit?->record($action, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actor->profileId,
            'target_type' => 'tenant_record',
            'target_id' => $id,
            'metadata' => $metadata,
        ]);
    }
}
