<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * A plugin's record type as merge fields for templates (SDK 1.56, WHIT-742;
 * editor M4, E.26). Mirrors the host's own field providers.
 *
 * `recordType()` is `<plugin name>.<type>`, e.g. `Invoicing.invoice`; one
 * outside the plugin's own name is refused at load. Every method answers for
 * ONE reader: `values()` returns null both when the record doesn't exist and
 * when the reader may not see it, so a preview never reveals a hidden record.
 * A field with no value for this record maps to null.
 */
interface DocumentFieldProvider
{
    public function recordType(): string;

    /** The record type's name in the reader's language, for the template's record picker. */
    public function recordLabel(): string;

    /**
     * The picker's groups. Paths are unique to this record type.
     *
     * @return list<array{group: string, items: list<array{path: string, label: string, sample?: string, required?: bool}>}>
     */
    public function groups(int $tenantId): array;

    /**
     * @param callable(string): bool $hasPermission The reader's permissions in this workspace.
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
