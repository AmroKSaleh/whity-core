<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

/**
 * Where a template's merge fields come from (editor M4, E.26; owner's answer
 * Q6): one provider per record type, registered by core and by plugins.
 * A provider whose recordType() is "*" adds fields every template has
 * (Company, Today) and has no records of its own.
 *
 * Every method that reads data answers for ONE reader. values() returns null
 * both when the record does not exist and when the reader may not see it, so a
 * preview never reveals that a hidden record exists.
 */
interface DocumentFieldProvider
{
    /** The record type these fields belong to, e.g. "document", or "*" for every type. */
    public function recordType(): string;

    /** The record type's name in the reader's language, for the template's record picker. */
    public function recordLabel(): string;

    /**
     * The picker's groups (contract `DocEditorProps.fields`).
     *
     * @return list<array{group: string, items: list<array{path: string, label: string, sample?: string, required?: bool}>}>
     */
    public function groups(int $tenantId): array;

    /**
     * Each field's value for one record as this reader may see it, or null.
     * A field with no value for this record maps to null (E.27). Shared
     * providers ignore $recordId.
     *
     * @param callable(string): bool $hasPermission The reader's permissions in this tenant.
     * @return array{label: string, values: array<string, string|null>}|null
     */
    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array;

    /**
     * Records the reader may preview a template with, newest first.
     *
     * @param callable(string): bool $hasPermission
     * @return list<array{id: string, label: string}>
     */
    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array;
}
