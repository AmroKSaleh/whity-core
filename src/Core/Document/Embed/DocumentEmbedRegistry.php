<?php

declare(strict_types=1);

namespace Whity\Core\Document\Embed;

/**
 * The record types and chart sources the editor's live blocks can use (M3).
 * Core registers issued documents; plugins add their own (invoices, orders) by
 * registering a provider at boot. A key registered twice is a boot error, not a
 * silent replacement, so a plugin cannot take over another's records.
 */
final class DocumentEmbedRegistry
{
    /** @var array<string, DocumentRecordProvider> */
    private array $records = [];

    /** @var array<string, DocumentChartSource> */
    private array $charts = [];

    public function addRecordProvider(DocumentRecordProvider $provider): void
    {
        $type = $provider->type();
        if (isset($this->records[$type])) {
            throw new \LogicException("Record type '{$type}' is already registered");
        }
        $this->records[$type] = $provider;
    }

    public function addChartSource(DocumentChartSource $source): void
    {
        $key = $source->key();
        if (isset($this->charts[$key])) {
            throw new \LogicException("Chart source '{$key}' is already registered");
        }
        $this->charts[$key] = $source;
    }

    public function recordProvider(string $type): ?DocumentRecordProvider
    {
        return $this->records[$type] ?? null;
    }

    public function chartSource(string $key): ?DocumentChartSource
    {
        return $this->charts[$key] ?? null;
    }

    /** @return list<DocumentRecordProvider> */
    public function recordProviders(): array
    {
        return array_values($this->records);
    }

    /** @return list<DocumentChartSource> */
    public function chartSources(): array
    {
        return array_values($this->charts);
    }
}
