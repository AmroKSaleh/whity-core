<?php

declare(strict_types=1);

namespace Documents\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * Shared {@see SyncableResource} descriptor for the Document Designer`s two
 * identical-shape tenant-owned tables -- `document_templates` (a saved
 * DocTemplate) and `document_blocks` (a reusable DocElement[] fragment). Core
 * migration 059 owns both; this is the offline-syncable adopt-and-augment port.
 *
 * The whole client object lives verbatim in the `data` column -- the JSON is the
 * contract, never shredded into columns. It is STORED json-encoded (mirroring
 * core`s {@see \Whity\Core\Document\DocumentRecordTrait::encodeData()}, same
 * JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE flags) so the value is valid for
 * BOTH the server`s JSONB column AND the offline TEXT column, and decoded back to
 * an array on read (mirroring `normalizeRow()`). `is_system` uses the same
 * portable boolean coercion as core`s `toBool()`.
 *
 * A subclass only names its {@see table()} + {@see sequenceKey()} and the empty
 * `data` placeholder a form-created record starts from (a document has no canvas
 * editor yet -- content editing is the follow-on slice): `{}` for a template,
 * `[]` for a block.
 */
abstract class AbstractDocumentDesignerResource implements SyncableResource
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_PERMISSION_LENGTH = 128;

    /** The visibility tiers core`s migration 059 documents for `scope`. */
    private const SCOPES = ['personal', 'tenant', 'global', 'system'];

    abstract public function table(): string;

    abstract public function sequenceKey(): string;

    /**
     * The empty `data` placeholder (already JSON) a form-created record starts
     * from: `{}` (object) for a template, `[]` (list) for a block.
     */
    abstract protected function emptyDataJson(): string;

    public function domainColumns(): array
    {
        return ['name', 'data', 'scope', 'required_permission', 'is_system'];
    }

    public function validate(array $body, bool $requireAll): array
    {
        $name = $body['name'] ?? null;
        if ($requireAll) {
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                return ['ok' => false, 'error' => 'name must be a non-empty string of at most '
                    . self::MAX_NAME_LENGTH . ' characters'];
            }
        }

        // scope: defaults to personal; a provided value must be one of the tiers.
        $scope = $body['scope'] ?? 'personal';
        if (!is_string($scope) || !in_array($scope, self::SCOPES, true)) {
            return ['ok' => false, 'error' => 'scope must be one of: ' . implode(', ', self::SCOPES)];
        }

        $requiredPermission = $body['requiredPermission'] ?? null;
        if ($requiredPermission !== null
            && (!is_string($requiredPermission) || mb_strlen($requiredPermission) > self::MAX_PERMISSION_LENGTH)) {
            return ['ok' => false, 'error' => 'requiredPermission must be a string of at most '
                . self::MAX_PERMISSION_LENGTH . ' characters'];
        }
        if (is_string($requiredPermission) && trim($requiredPermission) === '') {
            $requiredPermission = null;
        }

        // data: the verbatim client object (an array). Absent on a form-create ->
        // the empty placeholder; a non-array is rejected.
        $data = $body['data'] ?? null;
        if ($data !== null && !is_array($data)) {
            return ['ok' => false, 'error' => 'data must be an object or array'];
        }
        $encodedData = $data === null ? $this->emptyDataJson() : $this->encodeData($data);

        return ['ok' => true, 'values' => [
            'name'                => is_string($name) ? trim($name) : '',
            'data'                => $encodedData,
            'scope'               => $scope,
            'required_permission' => is_string($requiredPermission) ? trim($requiredPermission) : null,
            // Store as 0/1 so the bound value is accepted by BOTH a Postgres
            // BOOLEAN column ('0'/'1' are valid boolean input) and the offline
            // INTEGER/NUMERIC column.
            'is_system'           => self::toBool($body['isSystem'] ?? false) ? 1 : 0,
        ]];
    }

    public function toPublicFields(array $row): array
    {
        $decoded = json_decode((string) ($row['data'] ?? ''), true);

        return [
            'name'               => (string) ($row['name'] ?? ''),
            'data'               => is_array($decoded) ? $decoded : [],
            'scope'              => (string) ($row['scope'] ?? 'personal'),
            'requiredPermission' => isset($row['required_permission']) && $row['required_permission'] !== null
                ? (string) $row['required_permission'] : null,
            'isSystem'           => self::toBool($row['is_system'] ?? false),
        ];
    }

    /**
     * Encode a client object for the `data` column (mirrors
     * {@see \Whity\Core\Document\DocumentRecordTrait::encodeData()}).
     *
     * @param array<string, mixed>|list<mixed> $data
     */
    private function encodeData(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    /**
     * Portable DB-boolean coercion (PG t/f, SQLite 0/1, in-process bool) --
     * mirrors {@see \Whity\Core\Document\DocumentRecordTrait::toBool()}.
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
