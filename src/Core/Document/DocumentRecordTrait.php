<?php

declare(strict_types=1);

namespace Whity\Core\Document;

/**
 * Shared row-mapping for the document-designer repositories (WC-docdesigner).
 *
 * `document_templates` and `document_blocks` have an identical shape — a tenant-
 * scoped row whose `data` column is the verbatim client object (DocTemplate JSON
 * / DocBlock DocElement[] fragment) stored as JSON. This trait maps a raw DB row
 * to a typed array and encodes the `data` payload for writes. The SQL itself
 * lives (literally, per table) in each concrete repository so the CI tenant-
 * predicate scanner can verify the `tenant_id` predicate on every statement.
 */
trait DocumentRecordTrait
{
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $decoded = json_decode((string) $row['data'], true);

        return [
            'id'                  => (int) $row['id'],
            'tenant_id'           => (int) $row['tenant_id'],
            'name'                => (string) $row['name'],
            'data'                => is_array($decoded) ? $decoded : [],
            'scope'               => (string) $row['scope'],
            'required_permission' => $row['required_permission'] !== null ? (string) $row['required_permission'] : null,
            'is_system'           => self::toBool($row['is_system']),
            'created_by'          => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'created_at'          => (string) $row['created_at'],
            'updated_at'          => (string) $row['updated_at'],
        ];
    }

    /**
     * Encode a client object for the `data` column.
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    private function encodeData(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Portable DB-boolean coercion (PG 't'/'f', SQLite 0/1, in-process bool).
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'f', 'false', 'no'], true);
    }
}
