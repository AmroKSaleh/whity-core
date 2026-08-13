<?php

declare(strict_types=1);

namespace Whity\Sdk\Health;

/**
 * Declares the HEALTH PROBES a plugin contributes to the status page (SDK 1.19).
 *
 * The host samples its own components — database, queue, scheduler, renderer —
 * on a fixed cadence and publishes them on the public status page. A plugin
 * that owns a dependency of its own (a directory server, a payment gateway, a
 * device fleet) can have it sampled and published the same way instead of
 * inventing a second, private status surface that nobody watches.
 *
 * OPTIONAL. Implement it only if the plugin owns something worth watching; the
 * host checks for this interface and skips plugins that do not implement it, so
 * adding it breaks nothing that already exists.
 *
 * Namespacing
 * -----------
 * Declare BARE keys. The host stores them under the plugin's own namespace, so
 * a plugin declaring `ldap` is registered as `acme:ldap`. Two plugins can
 * therefore both declare `ldap` without colliding, and no plugin can shadow a
 * core probe such as `database` — the prefix is derived from the plugin NAME
 * the loader supplies, never from anything the plugin returns here.
 *
 * Cost
 * ----
 * Every probe runs on every collection pass, in one process, one after another.
 * Keep them cheap and give any network call a short explicit timeout: the host
 * cannot interrupt a blocked probe, and a status page that stops updating
 * during an incident is worse than no status page.
 *
 *     public function getHealthProbes(): array
 *     {
 *         return [
 *             new HealthProbeDefinition(
 *                 'ldap',
 *                 'Directory service',
 *                 fn (): ProbeResult => $this->pingDirectory(),
 *             ),
 *         ];
 *     }
 */
interface PluginHealthProbesInterface
{
    /**
     * The probes this plugin contributes.
     *
     * A malformed entry (a bad key, or anything that is not a
     * {@see HealthProbeDefinition}) rejects the whole declaration with a logged
     * warning rather than crashing the host, matching how declared permissions
     * and resource types behave.
     *
     * @return array<int, HealthProbeDefinition>
     */
    public function getHealthProbes(): array;
}
