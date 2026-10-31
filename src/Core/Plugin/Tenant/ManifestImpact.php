<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Core\Plugin\Tenant\Automation\AutomationRunRepository;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * What publishing a manifest would change, against the version that is live
 * (Whity Builder, WHIT-764; docs/design/WHITY-BUILDER.md 2.7).
 *
 * Each change is classified by what it does to data the workspace already
 * has, counted from the record store:
 *
 *  - `safe`: nothing existing changes (a new record type, field, state,
 *    screen or automation; anything removed that held no records);
 *  - `data`: existing records stay but need attention (a required field they
 *    don't have; a permission roles will lose);
 *  - `destructive`: existing values stop being served (a record type, field
 *    or state removed while records use it; a field's type changed under its
 *    values).
 *
 * The plan's `level` is its worst change. Publishing a destructive plan has
 * to be confirmed. Nothing is deleted from the store either way: a rollback
 * serves the values again.
 */
final class ManifestImpact
{
    public const SAFE = 'safe';
    public const DATA = 'data';
    public const DESTRUCTIVE = 'destructive';

    private const RANK = [self::SAFE => 0, self::DATA => 1, self::DESTRUCTIVE => 2];

    public function __construct(private readonly TenantRecordRepository $records)
    {
    }

    /**
     * @return array{
     *   plugin: string,
     *   from: ?string,
     *   to: string,
     *   level: string,
     *   changes: list<array{effect: string, change: string, path: string, records?: int, message: string, messageAr: string}>
     * }
     */
    public function plan(int $tenantId, ?PluginManifest $live, PluginManifest $draft): array
    {
        $changes = [];
        $plugin = $draft->name();

        if ($live === null) {
            $changes[] = self::change(self::SAFE, 'new_plugin', 'plugin', 'A new plugin', 'إضافة جديدة');
        } else {
            $counts = $this->records->countByType($tenantId, $plugin);
            $this->records($tenantId, $plugin, $live->records(), $draft->records(), $counts, $changes);
            $this->named('permission', array_values($live->permissions()), array_values($draft->permissions()), self::DATA, $changes);
            $this->named('automation', array_keys($live->automations()), array_keys($draft->automations()), self::SAFE, $changes);
            foreach (array_intersect_key($draft->automations(), $live->automations()) as $key => $automation) {
                if (AutomationRunRepository::hash($automation) !== AutomationRunRepository::hash($live->automations()[$key])) {
                    $changes[] = self::change(self::SAFE, 'automation_changed', "automations.{$key}", "Automation \"{$key}\" changes", "تتغير الأتمتة \"{$key}\"");
                }
            }
            $this->named('screen', self::screenIds($live), self::screenIds($draft), self::SAFE, $changes);
        }

        $level = self::SAFE;
        foreach ($changes as $change) {
            if (self::RANK[$change['effect']] > self::RANK[$level]) {
                $level = $change['effect'];
            }
        }

        return [
            'plugin' => $plugin,
            'from' => $live?->version(),
            'to' => $draft->version(),
            'level' => $level,
            'changes' => $changes,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $before
     * @param array<string, array<string, mixed>> $after
     * @param array<string, int> $counts
     * @param list<array<string, mixed>> $changes
     */
    private function records(int $tenantId, string $plugin, array $before, array $after, array $counts, array &$changes): void
    {
        foreach (array_diff_key($after, $before) as $type => $_) {
            $changes[] = self::change(self::SAFE, 'record_type_added', "records.{$type}", "New record type \"{$type}\"", "نوع سجل جديد \"{$type}\"");
        }
        foreach (array_diff_key($before, $after) as $type => $_) {
            $n = $counts[$type] ?? 0;
            $changes[] = self::change(
                $n > 0 ? self::DESTRUCTIVE : self::SAFE,
                'record_type_removed',
                "records.{$type}",
                "Record type \"{$type}\" is removed; its {$n} records are no longer served",
                "يُزال نوع السجل \"{$type}\"؛ لن تُعرض سجلاته ({$n})",
                $n,
            );
        }

        foreach (array_intersect_key($after, $before) as $type => $decl) {
            $n = $counts[$type] ?? 0;
            /** @var array<string, array<string, mixed>> $old */
            $old = $before[$type]['fields'] ?? [];
            /** @var array<string, array<string, mixed>> $new */
            $new = $decl['fields'] ?? [];

            foreach (array_diff_key($new, $old) as $field => $spec) {
                $required = ($spec['required'] ?? false) === true && !isset($spec['default']);
                $changes[] = $required && $n > 0
                    ? self::change(self::DATA, 'field_added', "records.{$type}.fields.{$field}", "Required field \"{$field}\" is added; {$n} existing records don't have it yet", "يُضاف الحقل الإلزامي \"{$field}\"؛ لا يحمله {$n} من السجلات الحالية بعد", $n)
                    : self::change(self::SAFE, 'field_added', "records.{$type}.fields.{$field}", "New field \"{$field}\"", "حقل جديد \"{$field}\"");
            }
            foreach (array_diff_key($old, $new) as $field => $_) {
                $values = $n > 0 ? $this->records->countWithValue($tenantId, $plugin, $type, (string) $field) : 0;
                $changes[] = self::change(
                    $values > 0 ? self::DESTRUCTIVE : self::SAFE,
                    'field_removed',
                    "records.{$type}.fields.{$field}",
                    "Field \"{$field}\" is removed; {$values} records have a value in it",
                    "يُزال الحقل \"{$field}\"؛ {$values} من السجلات فيها قيمة له",
                    $values,
                );
            }
            foreach (array_intersect_key($new, $old) as $field => $spec) {
                if (($spec['type'] ?? null) !== ($old[$field]['type'] ?? null)) {
                    $values = $n > 0 ? $this->records->countWithValue($tenantId, $plugin, $type, (string) $field) : 0;
                    $changes[] = self::change(
                        $values > 0 ? self::DESTRUCTIVE : self::SAFE,
                        'field_type_changed',
                        "records.{$type}.fields.{$field}",
                        "Field \"{$field}\" changes type; {$values} existing values may no longer fit",
                        "يتغير نوع الحقل \"{$field}\"؛ قد لا تناسبه {$values} من القيم الحالية",
                        $values,
                    );
                }
            }

            $oldStates = self::states($before[$type]);
            $newStates = self::states($decl);
            $inState = $n > 0 && $oldStates !== [] ? $this->records->countByState($tenantId, $plugin, $type) : [];
            foreach (array_diff($oldStates, $newStates) as $state) {
                $m = $inState[$state] ?? 0;
                $changes[] = self::change(
                    $m > 0 ? self::DESTRUCTIVE : self::SAFE,
                    'state_removed',
                    "records.{$type}.lifecycle.{$state}",
                    "State \"{$state}\" is removed; {$m} records are in it",
                    "تُزال الحالة \"{$state}\"؛ {$m} من السجلات فيها",
                    $m,
                );
            }
            foreach (array_diff($newStates, $oldStates) as $state) {
                $changes[] = self::change(self::SAFE, 'state_added', "records.{$type}.lifecycle.{$state}", "New state \"{$state}\"", "حالة جديدة \"{$state}\"");
            }
        }
    }

    /**
     * Added and removed names of one kind.
     *
     * @param list<string> $before
     * @param list<string> $after
     * @param list<array<string, mixed>> $changes
     */
    private function named(string $kind, array $before, array $after, string $removedEffect, array &$changes): void
    {
        $ar = ['permission' => 'الصلاحية', 'automation' => 'الأتمتة', 'screen' => 'الشاشة'][$kind];
        foreach (array_diff($after, $before) as $name) {
            $changes[] = self::change(self::SAFE, "{$kind}_added", "{$kind}s.{$name}", ucfirst($kind) . " \"{$name}\" is added", "تُضاف {$ar} \"{$name}\"");
        }
        foreach (array_diff($before, $after) as $name) {
            $changes[] = self::change($removedEffect, "{$kind}_removed", "{$kind}s.{$name}", ucfirst($kind) . " \"{$name}\" is removed", "تُزال {$ar} \"{$name}\"");
        }
    }

    /**
     * @param array<string, mixed> $decl
     * @return list<string>
     */
    private static function states(array $decl): array
    {
        $states = [];
        foreach ($decl['lifecycle']['states'] ?? [] as $state) {
            if (is_array($state) && is_string($state['key'] ?? null)) {
                $states[] = $state['key'];
            }
        }

        return $states;
    }

    /** @return list<string> */
    private static function screenIds(PluginManifest $manifest): array
    {
        return array_values(array_map(static fn (array $f): string => (string) $f['id'], $manifest->frontendFeatures()));
    }

    /**
     * @return array{effect: string, change: string, path: string, records?: int, message: string, messageAr: string}
     */
    private static function change(string $effect, string $change, string $path, string $message, string $messageAr, ?int $records = null): array
    {
        $entry = ['effect' => $effect, 'change' => $change, 'path' => $path];
        if ($records !== null) {
            $entry['records'] = $records;
        }

        return $entry + ['message' => $message, 'messageAr' => $messageAr];
    }
}
