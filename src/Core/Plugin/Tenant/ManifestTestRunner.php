<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use PDO;
use Throwable;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Runs a manifest's `tests` against a version that isn't live yet (Whity
 * Builder, WHIT-764; docs/design/WHITY-BUILDER.md 2.7): the publish check.
 *
 * Each test writes through the same {@see RecordWriter} as the record API, so
 * it exercises the version's real rules, defaults, computed fields, locks and
 * lifecycle, on the real store, inside a transaction that is always rolled
 * back. Nothing a test writes is ever seen, and no automation starts: the
 * writer here has no listener and no audit.
 *
 * What a test does NOT exercise: permissions (it acts as nobody, with the
 * roles it names) and automations. `given` is the record as stored, so its
 * computed fields are whatever the test wrote.
 */
final class ManifestTestRunner
{
    public function __construct(
        private readonly PDO $db,
        private readonly TenantRecordRepository $records,
        private readonly RecordWriter $writer,
    ) {
    }

    /**
     * @return array{passed: int, failed: int, results: list<array<string, mixed>>}
     */
    public function run(int $tenantId, PluginManifest $manifest): array
    {
        $results = [];
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            foreach ($manifest->tests() as $i => $test) {
                $this->db->exec('SAVEPOINT manifest_test');
                try {
                    $results[] = ['test' => $i, 'label' => $test['label']] + $this->one($tenantId, $manifest, $test);
                } catch (Throwable $e) {
                    error_log('[ManifestTestRunner] ' . $e->getMessage());
                    $results[] = ['test' => $i, 'label' => $test['label'], 'passed' => false, 'reason' => 'error'];
                } finally {
                    $this->db->exec('ROLLBACK TO SAVEPOINT manifest_test');
                }
            }
        } finally {
            if ($own) {
                $this->db->rollBack();
            }
        }

        $passed = count(array_filter($results, static fn (array $r): bool => $r['passed'] === true));

        return ['passed' => $passed, 'failed' => count($results) - $passed, 'results' => $results];
    }

    /**
     * @param array<string, mixed> $test
     * @return array<string, mixed>
     */
    private function one(int $tenantId, PluginManifest $manifest, array $test): array
    {
        $type = (string) $test['record'];
        /** @var list<string> $roles */
        $roles = $test['as'] ?? [];
        $actor = RecordActor::tester($roles);
        $decl = $manifest->records()[$type];

        if (isset($test['create'])) {
            $result = $this->writer->create($tenantId, $manifest, $type, (array) $test['create'], $actor);
            $id = $result['ok'] ? $result['id'] : null;
        } else {
            $state = $test['state'] ?? $decl['lifecycle']['initial'] ?? null;
            $id = $this->records->create($tenantId, $manifest->name(), $type, is_string($state) ? $state : null, (array) ($test['given'] ?? []), null);
            $result = isset($test['update'])
                ? $this->writer->update($tenantId, $manifest, $type, $id, (array) $test['update'], $actor)
                : $this->writer->move($tenantId, $manifest, $type, $id, (string) $test['move'], $actor);
        }

        $got = ['refused' => $result['ok'] !== true];
        if ($got['refused']) {
            $got['issues'] = array_values(array_unique(array_map(
                static fn (array $issue): string => (string) ($issue['column'] ?? ''),
                $result['issues'] ?? [],
            )));
        } elseif ($id !== null) {
            $row = $this->records->find($tenantId, $manifest->name(), $type, $id);
            $got['fields'] = $row['data'] ?? [];
            $got['state'] = $row['state'] ?? null;
        }

        /** @var array<string, mixed> $expect */
        $expect = $test['expect'];
        $reason = null;
        if ($expect['refused'] !== $got['refused']) {
            $reason = $expect['refused'] ? 'not_refused' : 'refused';
        } elseif (isset($expect['issues'])) {
            $want = array_values($expect['issues']);
            $have = $got['issues'] ?? [];
            sort($want);
            sort($have);
            if ($want !== $have) {
                $reason = 'issues_differ';
            }
        } else {
            foreach ((array) ($expect['fields'] ?? []) as $name => $value) {
                if (!self::same($value, $got['fields'][$name] ?? null)) {
                    $reason = 'field_differs';
                    $got['field'] = $name;
                    break;
                }
            }
            if ($reason === null && isset($expect['state']) && $expect['state'] !== ($got['state'] ?? null)) {
                $reason = 'state_differs';
            }
        }

        return ['passed' => $reason === null] + ($reason === null ? [] : ['reason' => $reason]) + ['got' => $got];
    }

    /** Numbers compare by value (JSON has one number type); everything else exactly. */
    private static function same(mixed $want, mixed $have): bool
    {
        if ((is_int($want) || is_float($want)) && (is_int($have) || is_float($have))) {
            return $want == $have;
        }
        if (is_array($want) && is_array($have)) {
            if (count($want) !== count($have)) {
                return false;
            }
            foreach ($want as $key => $value) {
                if (!array_key_exists($key, $have) || !self::same($value, $have[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $want === $have;
    }
}
