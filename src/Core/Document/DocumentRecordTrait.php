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
            // WHICH shipped starter this row IS, or null for anything a user or
            // a plugin made (#1013).
            //
            // It used to be withheld here, and withholding it made the id of a
            // starter unreachable through this repository: `starterKeysForTenant()`
            // hands back keys with no ids, and a row carried no key, so "the
            // template for starter key X" had no supported answer and the one
            // consumer that needed it went round the repository with its own
            // SELECT — a second query surface for a table the tenant-predicate
            // guard polices, and the repository no longer the single place that
            // knows how these rows are addressed.
            //
            // It is also the only column that can answer "is this row starter X",
            // which `is_system` cannot: that says "a system row" and nothing more,
            // so a management surface driving a Starter badge off it can label the
            // row but can never offer to restore the starter it came from.
            'starter_key'         => ($row['starter_key'] ?? null) !== null ? (string) $row['starter_key'] : null,
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
