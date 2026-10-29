<?php

declare(strict_types=1);

namespace Whity\Core\Document\Embed;

/**
 * One kind of record a Record block can show (editor M3, E.16): an issued
 * document from core, an invoice from a billing plugin, and so on.
 *
 * resolve() answers for ONE reader. It returns null both when the record does
 * not exist and when the reader may not see it, and the caller cannot tell the
 * two apart: a Record block in a shared document must never reveal that a
 * hidden record exists.
 */
interface DocumentRecordProvider
{
    /** The `ref.type` this provider answers for, e.g. "document". */
    public function type(): string;

    /** The type's name in English, for the record picker. */
    public function label(): string;

    /**
     * The record as this reader may see it, or null.
     *
     * @param callable(string): bool $hasPermission The reader's permissions in this tenant.
     * @return array{type_label: string, title: string, subtitle?: ?string, amount?: ?string,
     *               status?: ?array{label: string, tone: string}, href?: ?string}|null
     */
    public function resolve(int $tenantId, int $callerId, callable $hasPermission, string $id): ?array;
}
