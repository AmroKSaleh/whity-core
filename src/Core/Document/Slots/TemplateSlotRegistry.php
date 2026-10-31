<?php

declare(strict_types=1);

namespace Whity\Core\Document\Slots;

use Whity\Core\Container\HostWiredService;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * The template slots a workspace has (DOCUMENT-AUTOMATION.md 3.2, WHIT-742):
 * those of its switched-on workspace plugins (`documents.slots` in
 * plugin.json) and those code plugins register. A plugin that is off
 * contributes none, so its slots can't be bound or issued from.
 *
 * Per worker: code slots change only when the loader (re)registers a plugin;
 * manifest slots are read per call from the workspace's live manifests.
 */
final class TemplateSlotRegistry implements HostWiredService
{
    /** @var array<string, list<TemplateSlot>> code plugin name => its slots */
    private array $code = [];

    /**
     * @param (callable(int): list<PluginManifest>)|null $manifests the
     *        workspace's live, switched-on manifests (TenantManifestHost)
     */
    public function __construct(private readonly mixed $manifests = null)
    {
    }

    /** @param list<TemplateSlot> $slots */
    public function register(string $plugin, array $slots): void
    {
        $this->code[$plugin] = array_values(array_filter(
            $slots,
            static fn (TemplateSlot $slot): bool => $slot->plugin === $plugin && str_starts_with($slot->key, $plugin . '.'),
        ));
    }

    public function unregister(string $plugin): void
    {
        unset($this->code[$plugin]);
    }

    /**
     * Every slot the workspace has, keyed by slot key, sorted by key. When two
     * declare the same key the first wins: code plugins, then manifests.
     *
     * @return array<string, TemplateSlot>
     */
    public function forTenant(int $tenantId): array
    {
        $slots = [];
        foreach ($this->code as $declared) {
            foreach ($declared as $slot) {
                $slots[$slot->key] ??= $slot;
            }
        }
        if ($this->manifests !== null && $tenantId !== 0) {
            foreach (($this->manifests)($tenantId) as $manifest) {
                foreach (TemplateSlot::fromManifest($manifest->name(), $manifest->documentSlots()) as $slot) {
                    $slots[$slot->key] ??= $slot;
                }
            }
        }
        ksort($slots);

        return $slots;
    }

    public function find(int $tenantId, string $key): ?TemplateSlot
    {
        return $this->forTenant($tenantId)[$key] ?? null;
    }
}
