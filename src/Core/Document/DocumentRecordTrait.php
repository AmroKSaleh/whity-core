<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Whity\Core\Db\DbBool;
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
            'owner_ou_id'         => ($row['owner_ou_id'] ?? null) !== null ? (int) $row['owner_ou_id'] : null,
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
     * Coerce a DB boolean column to a real bool.
     *
     * Delegates to the canonical coercion (#891). {@see DbBool} records which
     * spellings each driver actually returns — measured on the PHP this
     * platform ships, not assumed — and why a bare `(bool)` cast is not an
     * equivalent substitute for it.
     */
    private static function toBool(mixed $value): bool
    {
        return DbBool::of($value);
    }
}
