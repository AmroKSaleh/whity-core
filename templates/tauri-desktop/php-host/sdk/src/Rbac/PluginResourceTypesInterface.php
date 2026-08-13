<?php

declare(strict_types=1);

namespace Whity\Sdk\Rbac;

/**
 * Declares the RESOURCE TYPES a plugin owns (SDK 1.18, WC-712 §2).
 *
 * A resource type names a kind of record that can carry authority: "this
 * document", "this catalogue item". Declaring one lets a plugin address role
 * grants at a single record instead of keeping a private grant table — a
 * private table being a second source of truth for the same authorization
 * question that {@see PermissionResolver} exists to eliminate.
 *
 * OPTIONAL. Implement it only if the plugin needs per-record authority; the
 * host checks for this interface and skips plugins that do not implement it, so
 * adding it breaks nothing that already exists.
 *
 * Namespacing
 * -----------
 * Declare BARE slugs. The host stores them under the plugin's own namespace, so
 * a plugin declaring `record` is registered as `acme:record`. Two plugins can
 * therefore both declare `record` without colliding, and no plugin can shadow a
 * core type such as `ou` — the prefix is derived from the plugin NAME the
 * loader supplies, never from anything the plugin returns here.
 *
 * Ask the host's resource-type registry for the namespaced key when granting;
 * do not concatenate the prefix by hand, or a change to the namespacing rule
 * silently breaks every grant the plugin has written.
 *
 *     public function getResourceTypes(): array
 *     {
 *         return ['record', 'catalog_item'];
 *     }
 */
interface PluginResourceTypesInterface
{
    /**
     * The bare resource-type slugs this plugin owns.
     *
     * Each must be lowercase, start with a letter, and contain only letters,
     * digits and underscores — no colon, which is the namespace separator the
     * host applies. A malformed slug rejects the whole declaration with a logged
     * warning rather than crashing the host, matching how declared permissions
     * behave.
     *
     * @return array<int, string> Bare slugs, e.g. `['record', 'catalog_item']`.
     */
    public function getResourceTypes(): array;
}
