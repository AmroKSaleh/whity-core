<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\Container\HostWiredService;

/**
 * The record types templates can be written for, and their merge fields
 * (editor M4, E.26). Core registers Company, Today and issued documents;
 * plugins add theirs at boot. A record type registered twice is a boot error,
 * not a silent replacement, so a plugin cannot take over another plugin's fields.
 *
 * Host-wired: the populated instance is registered in public/index.php and
 * BaseCommand::setupKernel(), so a plugin resolving it never gets an empty one.
 */
final class DocumentFieldRegistry implements HostWiredService
{
    /** @var array<string, DocumentFieldProvider> */
    private array $typed = [];

    /** @var list<DocumentFieldProvider> */
    private array $shared = [];

    public function add(DocumentFieldProvider $provider): void
    {
        $type = $provider->recordType();
        if ($type === '*') {
            $this->shared[] = $provider;

            return;
        }
        if (isset($this->typed[$type])) {
            throw new \LogicException("Field record type '{$type}' is already registered");
        }
        $this->typed[$type] = $provider;
    }

    public function has(string $recordType): bool
    {
        return isset($this->typed[$recordType]);
    }

    /** @return list<array{key: string, label: string}> */
    public function recordTypes(): array
    {
        $out = [];
        foreach ($this->typed as $key => $provider) {
            $out[] = ['key' => $key, 'label' => $provider->recordLabel()];
        }

        return $out;
    }

    /**
     * The picker's groups for a record type: the record's own first, then the
     * ones every template has. A null or unknown type gets only the shared ones.
     *
     * @return list<array{group: string, items: list<array{path: string, label: string, sample?: string, required?: bool}>}>
     */
    public function groups(int $tenantId, ?string $recordType): array
    {
        $groups = [];
        if ($recordType !== null && isset($this->typed[$recordType])) {
            $groups = $this->typed[$recordType]->groups($tenantId);
        }
        foreach ($this->shared as $provider) {
            array_push($groups, ...$provider->groups($tenantId));
        }

        return $groups;
    }

    /**
     * Every path a record type offers, for the publish check (E.37).
     *
     * @return array<string, true>
     */
    public function paths(int $tenantId, ?string $recordType): array
    {
        $paths = [];
        foreach ($this->groups($tenantId, $recordType) as $group) {
            foreach ($group['items'] as $item) {
                $paths[$item['path']] = true;
            }
        }

        return $paths;
    }

    /**
     * Paths whose value must be there to issue a document (E.27).
     *
     * @return array<string, true>
     */
    public function requiredPaths(int $tenantId, ?string $recordType): array
    {
        $paths = [];
        foreach ($this->groups($tenantId, $recordType) as $group) {
            foreach ($group['items'] as $item) {
                if (($item['required'] ?? false) === true) {
                    $paths[$item['path']] = true;
                }
            }
        }

        return $paths;
    }

    /**
     * The values for one record, merged with the shared fields', or null when
     * the record type is unknown or the reader may not see the record. A
     * template with no record type gets the shared values only.
     *
     * @param callable(string): bool $hasPermission
     * @return array{label: string, values: array<string, string|null>}|null
     */
    public function resolve(int $tenantId, int $callerId, callable $hasPermission, ?string $recordType, ?string $recordId): ?array
    {
        $label = '';
        $values = [];
        if ($recordType !== null) {
            $provider = $this->typed[$recordType] ?? null;
            if ($provider === null || $recordId === null) {
                return null;
            }
            $own = $provider->values($tenantId, $callerId, $hasPermission, $recordId);
            if ($own === null) {
                return null;
            }
            $label = $own['label'];
            $values = $own['values'];
        }
        foreach ($this->shared as $provider) {
            $shared = $provider->values($tenantId, $callerId, $hasPermission, null);
            if ($shared !== null) {
                $values += $shared['values'];
            }
        }

        return ['label' => $label, 'values' => $values];
    }

    /**
     * @param callable(string): bool $hasPermission
     * @return list<array{id: string, label: string}>
     */
    public function records(int $tenantId, int $callerId, callable $hasPermission, string $recordType, string $query, int $limit): array
    {
        $provider = $this->typed[$recordType] ?? null;

        return $provider === null ? [] : $provider->records($tenantId, $callerId, $hasPermission, $query, $limit);
    }
}
