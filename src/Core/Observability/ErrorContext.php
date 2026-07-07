<?php

declare(strict_types=1);

namespace Whity\Core\Observability;

use Whity\Core\CoreVersion;
use Whity\Core\Tenant\TenantContext;

/**
 * Builds the secret-free context attached to a captured error (WC-d).
 *
 * Assembles the tags that make an error attributable and reproducible:
 *   - release     — the core version the error occurred on;
 *   - tenant_id   — the acting tenant (null pre-auth / unresolved);
 *   - request_id  — a per-error correlation id (also printed with the log line);
 *   - plugins     — every loaded plugin id → version, so an error involving a
 *                   plugin (or a specific plugin version) is immediately visible
 *                   without reproducing the exact deployment's plugin set.
 *
 * Takes the plugin metadata as a plain array (from
 * PluginLoader::getPluginMetadata()) rather than the loader itself, so it stays
 * dependency-light and unit-testable. Contains no I/O and never throws on
 * well-formed input; the caller still guards the whole capture so telemetry can
 * never mask the original error.
 */
final class ErrorContext
{
    /**
     * @param array<int, mixed> $pluginMetadata From PluginLoader::getPluginMetadata(); elements are guarded at runtime.
     * @param string                     $requestId      Per-error correlation id.
     * @return array{release: string, tenant_id: int|null, request_id: string, plugins: array<string, string>}
     */
    public static function gather(array $pluginMetadata, string $requestId): array
    {
        $plugins = [];
        foreach ($pluginMetadata as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id = (string) ($p['id'] ?? $p['name'] ?? '');
            if ($id === '') {
                continue;
            }
            $plugins[$id] = (string) ($p['version'] ?? '');
        }
        ksort($plugins);

        return [
            'release'    => CoreVersion::VERSION,
            'tenant_id'  => TenantContext::getTenantId(),
            'request_id' => $requestId,
            'plugins'    => $plugins,
        ];
    }

    /** A short, log-correlatable per-error id. */
    public static function newRequestId(): string
    {
        return bin2hex(random_bytes(8));
    }
}
