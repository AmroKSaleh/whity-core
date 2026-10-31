<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant\Automation;

use PDO;
use Throwable;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\Document\Issuing\IssueInput;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Plugin\Tenant\RecordActor;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Sdk\Expression\Expression;
use Whity\Sdk\Expression\ExpressionContext;
use Whity\Sdk\Expression\ExpressionError;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Runs one automation run's steps (WHIT-763; docs/design/WHITY-BUILDER.md
 * 2.5), in the background job {@see RunPluginAutomationJob}.
 *
 * WHO ACTS. The plugin, for the person who caused the event: a step may do
 * only what the plugin's own permissions allow AND that person holds. Every
 * run today has such a person; a run without one is refused its writes until
 * "the plugin acting alone" has a policy of its own (scheduled triggers,
 * WHIT-795). Writes go through {@see RecordWriter},
 * so the record type's rules and lifecycle hold, the audit trail names the
 * automation, the run and the person, and the writes can start further runs
 * one level deeper.
 *
 * EXACTLY ONCE PER STEP. Each step and its trace entry commit together, and
 * the trace is re-read under a row lock before the step runs, so a retried run
 * skips the steps already done and two workers holding the same run (a lease
 * reclaimed while it was still running) take turns rather than both writing.
 * A run whose automation changed since it was queued stops rather than
 * matching its trace against different steps.
 *
 * ISSUING A DOCUMENT is the one step that doesn't run inside its trace's
 * transaction: it renders and stores a file, which a rollback can't take
 * back. Its origin key (`automation:<run>:<step>`) makes it safe instead: a
 * retry, or a second worker, gets the same document back (`replayed`), never
 * a second one. It issues through the workspace's slot binding with
 * {@see \Whity\Core\Document\Issuing\TemplateIssuing}, as the person the
 * run acts for, who must hold `documents:issue` (DOCUMENT-AUTOMATION.md 3.7).
 *
 * A step that is refused (a rule, a permission, a record that's gone, a slot
 * nothing is bound to) fails the run with its reason in the trace and stops;
 * it isn't retried. An unexpected error rolls the step back and is thrown, so
 * the queue retries it.
 */
final class AutomationRunner
{
    /** People one notify step reaches at most. */
    public const MAX_RECIPIENTS = 50;

    /** Members scanned for a role when notifying it. */
    private const MAX_MEMBERS_SCANNED = 2000;

    public const NOTIFICATION_TYPE = 'plugin.automation';

    /** @var (\Closure(ActingAs, IssueInput): array<string, mixed>)|null */
    private ?\Closure $issueDocument;

    /**
     * @param (\Closure(ActingAs, IssueInput): array<string, mixed>)|null $issueDocument
     *        Issues a document ({@see \Whity\Core\Document\Issuing\TemplateIssuing::issue()});
     *        throws DocumentIssueRefused. Null where documents can't be issued.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly AutomationRunRepository $runs,
        private readonly TenantManifestHost $host,
        private readonly TenantPluginRepository $plugins,
        private readonly TenantRecordRepository $records,
        private readonly RecordWriter $writer,
        private readonly RoleChecker $roleChecker,
        private readonly ?NotificationDispatcher $notifications = null,
        ?\Closure $issueDocument = null,
    ) {
        $this->issueDocument = $issueDocument;
    }

    /**
     * @return array{status: string, reason?: string|null}
     */
    public function run(int $tenantId, int $runId): array
    {
        // A worker serves many tenants and runs: read the manifests and the
        // grants afresh.
        $this->host->resetRequest();
        RoleChecker::clearCache();

        $run = $this->runs->find($tenantId, $runId);
        if ($run === null) {
            return ['status' => 'missing'];
        }
        if (!in_array($run['status'], [AutomationRunRepository::QUEUED, AutomationRunRepository::RUNNING], true)) {
            return ['status' => (string) $run['status']];
        }

        $manifest = null;
        foreach ($this->host->manifestsFor($tenantId) as $candidate) {
            if ($candidate->name() === $run['plugin']) {
                $manifest = $candidate;
                break;
            }
        }
        $automation = $manifest?->automations()[$run['automation']] ?? null;
        if ($manifest === null) {
            return $this->stop($tenantId, $runId, 'plugin_off');
        }
        if (!$this->plugins->automationsEnabled($tenantId, $manifest->name())) {
            return $this->stop($tenantId, $runId, 'automations_off');
        }
        if (!is_array($automation)) {
            return $this->stop($tenantId, $runId, 'automation_removed');
        }
        if (AutomationRunRepository::hash($automation) !== $run['automation_hash']) {
            return $this->stop($tenantId, $runId, 'automation_changed');
        }

        $this->runs->markRunning($tenantId, $runId);
        $actor = RecordActor::automation(
            $run['actor_profile_id'],
            (int) $run['depth'],
            $runId,
            (string) $run['automation'],
            $run['root_run_id'] ?? $runId,
        );
        /** @var list<array<string, mixed>> $steps */
        $steps = $automation['steps'] ?? [];

        foreach ($steps as $i => $step) {
            $kind = (string) array_key_first($step);
            /** @var array<string, mixed> $args */
            $args = $step[$kind];

            $outcome = null;
            if ($kind === 'issue_document') {
                // Outside the trace's transaction (see the class comment).
                $this->db->beginTransaction();
                $done = (($this->runs->lockTrace($tenantId, $runId) ?? [])[$i]['status'] ?? null) === 'done';
                $this->db->commit();
                if ($done) {
                    continue;
                }
                $outcome = $this->issueDocument($tenantId, $manifest, $run, $i, $args, $actor);
            }

            $this->db->beginTransaction();
            try {
                $trace = $this->runs->lockTrace($tenantId, $runId) ?? [];
                if (($trace[$i]['status'] ?? null) === 'done') {
                    $this->db->commit();
                    continue;
                }
                $outcome ??= $this->step($tenantId, $manifest, $run, $kind, $args, $actor);
                $trace[$i] = ['step' => $i, 'kind' => $kind] + $outcome + ['at' => gmdate('Y-m-d\TH:i:s\Z')];
                $this->runs->saveTrace($tenantId, $runId, $trace);
                if ($outcome['status'] !== 'done') {
                    $this->runs->finish($tenantId, $runId, AutomationRunRepository::FAILED, (string) ($outcome['reason'] ?? 'step_failed'));
                }
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }

            if ($outcome['status'] !== 'done') {
                return ['status' => AutomationRunRepository::FAILED, 'reason' => $outcome['reason'] ?? null];
            }
        }

        $this->runs->finish($tenantId, $runId, AutomationRunRepository::DONE);

        return ['status' => AutomationRunRepository::DONE];
    }

    /**
     * Do one step. Returns its trace entry: status, reason when it failed,
     * its input and its outcome.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $args
     * @return array{status: 'done'|'failed', reason?: string, input?: mixed, outcome?: mixed}
     */
    private function step(int $tenantId, PluginManifest $manifest, array $run, string $kind, array $args, RecordActor $actor): array
    {
        $type = (string) $run['record_type'];
        $decl = $manifest->records()[$type] ?? null;
        $record = is_array($decl) ? $this->records->find($tenantId, $manifest->name(), $type, (int) $run['record_id']) : null;
        if (!is_array($decl) || $record === null) {
            return ['status' => 'failed', 'reason' => 'record_gone'];
        }
        $context = new ExpressionContext($record['data'], $record['state'], $this->writer->roles($tenantId, $actor), gmdate('Y-m-d'), gmdate('Y-m-d\TH:i:s\Z'));

        try {
            switch ($kind) {
                case 'set_fields':
                    $values = self::values($args['values'] ?? [], $context);
                    if (!$this->may($tenantId, $manifest, $actor, (string) $decl['permissions']['edit'])) {
                        return ['status' => 'failed', 'reason' => 'not_permitted', 'input' => $values];
                    }

                    return self::written($this->writer->update($tenantId, $manifest, $type, $record['id'], $values, $actor), $values, ['record_id' => $record['id']]);

                case 'create_record':
                    $target = (string) $args['type'];
                    $values = self::values($args['values'] ?? [], $context);
                    if (isset($args['link'])) {
                        $values[(string) $args['link']] = $record['id'];
                    }
                    if (!$this->may($tenantId, $manifest, $actor, (string) $manifest->records()[$target]['permissions']['create'])) {
                        return ['status' => 'failed', 'reason' => 'not_permitted', 'input' => $values];
                    }
                    $result = $this->writer->create($tenantId, $manifest, $target, $values, $actor);

                    return self::written($result, $values, $result['ok'] ? ['record_type' => $target, 'record_id' => $result['id']] : null);

                case 'move':
                    $move = (string) $args['move'];
                    $permission = (string) ($decl['lifecycle']['moves'][$move]['permission'] ?? '');
                    if ($permission === '' || !$this->may($tenantId, $manifest, $actor, $permission)) {
                        return ['status' => 'failed', 'reason' => 'not_permitted', 'input' => ['move' => $move]];
                    }

                    return self::written($this->writer->move($tenantId, $manifest, $type, $record['id'], $move, $actor), ['move' => $move], ['record_id' => $record['id']]);

                case 'notify':
                    return $this->notify($tenantId, $manifest, $decl, $record, $args, $run);
            }
        } catch (ExpressionError $e) {
            return ['status' => 'failed', 'reason' => 'expression_error', 'outcome' => ['message' => $e->getMessage()]];
        }

        return ['status' => 'failed', 'reason' => 'unknown_step'];
    }

    /**
     * Issue a document from one of the plugin's slots for the triggering
     * record. Outside any transaction: see the class comment.
     *
     * @param array<string, mixed> $run
     * @param array<string, mixed> $args
     * @return array{status: 'done'|'failed', reason?: string, input?: mixed, outcome?: mixed}
     */
    private function issueDocument(int $tenantId, PluginManifest $manifest, array $run, int $step, array $args, RecordActor $actor): array
    {
        $type = (string) $run['record_type'];
        $record = isset($manifest->records()[$type])
            ? $this->records->find($tenantId, $manifest->name(), $type, (int) $run['record_id'])
            : null;
        if ($record === null) {
            return ['status' => 'failed', 'reason' => 'record_gone'];
        }
        $slot = $manifest->name() . '.' . (string) $args['slot'];
        $input = ['slot' => $slot];
        if ($this->issueDocument === null) {
            return ['status' => 'failed', 'reason' => 'documents_unavailable', 'input' => $input];
        }
        // No person, no document: issuing without one needs the workspace's
        // background allow-list (DOCUMENT-AUTOMATION.md 3.7).
        if ($actor->profileId === null
            || !$this->roleChecker->hasPermissionForProfile($actor->profileId, CorePermissions::DOCUMENTS_ISSUE, $tenantId)) {
            return ['status' => 'failed', 'reason' => 'not_permitted', 'input' => $input];
        }

        try {
            $context = new ExpressionContext($record['data'], $record['state'], $this->writer->roles($tenantId, $actor), gmdate('Y-m-d'), gmdate('Y-m-d\TH:i:s\Z'));
            $values = [];
            foreach (self::values($args['values'] ?? [], $context) as $path => $value) {
                $values[$path] = $value === null || is_array($value) ? null : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
            }
        } catch (ExpressionError $e) {
            return ['status' => 'failed', 'reason' => 'expression_error', 'input' => $input, 'outcome' => ['message' => $e->getMessage()]];
        }
        $input += ['values' => $values];

        try {
            $issued = ($this->issueDocument)(
                new ActingAs($tenantId, $actor->profileId, ScopedPermissionSet::forProfile($this->roleChecker, $actor->profileId, $tenantId)),
                new IssueInput(
                    slot: $slot,
                    record: $manifest->name() . '.' . $type . ':' . $record['id'],
                    values: $values,
                    originKey: 'automation:' . $run['id'] . ':' . $step,
                ),
            );
        } catch (DocumentIssueRefused $refused) {
            return [
                'status' => 'failed',
                'reason' => $refused->reason,
                'input' => $input,
                'outcome' => ['message' => $refused->clientMessage, 'details' => $refused->details],
            ];
        }

        /** @var array<string, mixed> $document */
        $document = $issued['document'] ?? [];
        /** @var array<string, mixed> $render */
        $render = $issued['render'] ?? [];

        return ['status' => 'done', 'input' => $input, 'outcome' => [
            'document_id' => isset($document['id']) ? (int) $document['id'] : null,
            'replayed' => ($issued['replayed'] ?? false) === true,
            'rendered' => ($render['stored'] ?? false) === true,
        ]];
    }

    /**
     * Notify a role the plugin declares, or the person in a person field. Only
     * people who can read the record type are told: the message carries its
     * fields.
     *
     * @param array<string, mixed> $decl
     * @param array<string, mixed> $record
     * @param array<string, mixed> $args
     * @param array<string, mixed> $run
     * @return array{status: 'done'|'failed', reason?: string, input?: mixed, outcome?: mixed}
     */
    private function notify(int $tenantId, PluginManifest $manifest, array $decl, array $record, array $args, array $run): array
    {
        if ($this->notifications === null) {
            return ['status' => 'failed', 'reason' => 'notifications_unavailable'];
        }
        $read = (string) $decl['permissions']['read'];
        if (isset($args['role'])) {
            $candidates = $this->holdersOf($tenantId, (string) $args['role']);
            $input = ['role' => $args['role']];
        } else {
            $person = $record['data'][(string) $args['person']] ?? null;
            $candidates = is_int($person) ? [$person] : [];
            $input = ['person' => $args['person']];
        }
        $recipients = [];
        foreach ($candidates as $profileId) {
            if (count($recipients) >= self::MAX_RECIPIENTS) {
                break;
            }
            if ($this->roleChecker->hasPermissionForProfile($profileId, $read, $tenantId)) {
                $recipients[] = $profileId;
            }
        }

        /** @var array{en: string, ar: string} $message */
        $message = $args['message'];
        $body = self::fill((string) $message['en'], $record['data']);
        $bodyAr = self::fill((string) $message['ar'], $record['data']);
        foreach ($recipients as $profileId) {
            $this->notifications->dispatch($tenantId, $profileId, self::NOTIFICATION_TYPE, [
                'subject' => $body,
                'body' => $body,
                'data' => [
                    'subjectAr' => $bodyAr,
                    'bodyAr' => $bodyAr,
                    'plugin' => $manifest->name(),
                    'automation' => $run['automation'],
                    'automation_run_id' => $run['id'],
                    'record_type' => $run['record_type'],
                    'record_id' => $record['id'],
                ],
            ]);
        }

        return ['status' => 'done', 'input' => $input + ['message' => $body], 'outcome' => ['notified' => $recipients]];
    }

    /**
     * The plugin may use a permission only if it declares it and the person the
     * run acts for holds it. No person, no writes (see the class comment).
     */
    private function may(int $tenantId, PluginManifest $manifest, RecordActor $actor, string $permission): bool
    {
        if (!in_array($permission, $manifest->permissions(), true)) {
            return false;
        }

        return $actor->profileId !== null
            && $this->roleChecker->hasPermissionForProfile($actor->profileId, $permission, $tenantId);
    }

    /**
     * Active members of the workspace who hold `$role`.
     *
     * @return list<int>
     */
    private function holdersOf(int $tenantId, string $role): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT profile_id FROM memberships
              WHERE tenant_id = :tenant_id AND status = 'active'
              ORDER BY profile_id LIMIT " . self::MAX_MEMBERS_SCANNED
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $holders = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $profileId) {
            if ($this->roleChecker->hasRoleForProfile((int) $profileId, $role, $tenantId)) {
                $holders[] = (int) $profileId;
            }
        }

        return $holders;
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function stop(int $tenantId, int $runId, string $reason): array
    {
        $this->runs->finish($tenantId, $runId, AutomationRunRepository::STOPPED, $reason);

        return ['status' => AutomationRunRepository::STOPPED, 'reason' => $reason];
    }

    /**
     * @param array<mixed> $expressions
     * @return array<string, mixed>
     */
    private static function values(array $expressions, ExpressionContext $context): array
    {
        $values = [];
        foreach ($expressions as $name => $expression) {
            $values[(string) $name] = Expression::evaluate($expression, $context);
        }

        return $values;
    }

    /**
     * A write's result as a trace entry.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $outcome
     * @return array{status: 'done'|'failed', reason?: string, input?: mixed, outcome?: mixed}
     */
    private static function written(array $result, array $input, ?array $outcome): array
    {
        if ($result['ok'] === true) {
            return ['status' => 'done', 'input' => $input, 'outcome' => $outcome];
        }
        $failed = ['status' => 'failed', 'reason' => (string) $result['reason'], 'input' => $input];
        if (isset($result['issues'])) {
            $failed['outcome'] = ['issues' => $result['issues']];
        }

        return $failed;
    }

    /**
     * `{{field}}` in a message becomes the field's value. Text only: nothing
     * is evaluated, and lists and objects are left out.
     *
     * @param array<string, mixed> $data
     */
    private static function fill(string $template, array $data): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', static function (array $m) use ($data): string {
            $value = $data[$m[1]] ?? null;

            return is_scalar($value) ? (is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value) : '';
        }, $template);
    }
}
