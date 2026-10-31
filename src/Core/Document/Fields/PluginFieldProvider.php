<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Sdk\Documents\DocumentFieldProvider as SdkFieldProvider;

/**
 * A code plugin's record type (SDK 1.56, WHIT-742) seen as one of core's: the
 * SDK interface mirrors core's, so each call passes straight through.
 */
final class PluginFieldProvider implements DocumentFieldProvider
{
    public function __construct(private readonly SdkFieldProvider $provider)
    {
    }

    public function recordType(): string
    {
        return $this->provider->recordType();
    }

    public function recordLabel(): string
    {
        return $this->provider->recordLabel();
    }

    public function groups(int $tenantId): array
    {
        return $this->provider->groups($tenantId);
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        return $this->provider->values($tenantId, $callerId, $hasPermission, $recordId);
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        return $this->provider->records($tenantId, $callerId, $hasPermission, $query, $limit);
    }
}
