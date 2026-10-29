<?php

declare(strict_types=1);

namespace Whity\Core\Document\Embed;

/**
 * Something a Chart block can read from (editor M3, E.16): one series of
 * labelled values, counted within ONE reader's permissions.
 *
 * load() returns null when the reader may not use the source at all; an empty
 * `points` list means they may, and there is nothing to show.
 */
interface DocumentChartSource
{
    /** The `source.query` this answers for, e.g. "documents-by-month". */
    public function key(): string;

    /** Its name in English, for the source menu. */
    public function label(): string;

    /**
     * @param callable(string): bool $hasPermission The reader's permissions in this tenant.
     * @param array<string, string>  $params        The block's `source.params`.
     * @return array{points: list<array{label: string, value: int|float}>, value_label?: string}|null
     */
    public function load(int $tenantId, int $callerId, callable $hasPermission, array $params): ?array;
}
