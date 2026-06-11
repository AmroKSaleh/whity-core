<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional frontend feature declaration for plugins (SDK v1.2).
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to describe the admin-UI screens it contributes.
 * Like {@see PluginRequirementsInterface}, it is a sibling capability
 * interface: PluginInterface itself stays backend-only and plugins that do
 * not implement this load exactly as before.
 *
 * Descriptors are UI METADATA ONLY — they grant NOTHING. Data access is
 * always enforced by the route-level RBAC of the underlying API routes
 * (`requiredRole` / `requiredPermission` on the plugin's route declarations);
 * the host additionally filters the descriptor listing per caller server-side
 * (`GET /api/frontend/features` only returns descriptors whose
 * `requiredPermission` the caller actually holds).
 *
 * Descriptor shape
 * ----------------
 * Each entry of the returned list is an associative array:
 *
 * - `id` (string, REQUIRED): unique kebab-case slug matching
 *   `/^[a-z][a-z0-9-]*$/`. Ids are unique across ALL plugins; when two
 *   plugins claim the same id the host keeps the first (discovery order),
 *   drops the duplicate, and logs a warning.
 * - `label` (string, REQUIRED, non-empty): human-readable menu/screen title.
 * - `screen` (string, REQUIRED): either `'crud'` — the host renders a
 *   schema-driven list/create/edit/delete screen over the declared resource —
 *   or `'custom'` — the host application must register a bespoke component
 *   for this id in its UI registry, otherwise a placeholder renders.
 * - `requiredPermission` (string, REQUIRED — fail-closed, there are no
 *   permissionless screens): must match
 *   `/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/` and be a permission this plugin
 *   genuinely OWNS. Ownership is not self-asserted: the permission must be
 *   declared via {@see PluginInterface::getPermissions()}, must NOT be a core
 *   permission name (self-declaring e.g. `users:read` does not make it
 *   ownable), and across plugins the FIRST declarant (discovery order) owns
 *   a name — a later plugin re-declaring it cannot gate descriptors on it.
 *   Anything else is REJECTED: a plugin cannot expose a screen over someone
 *   else's resource. The host enforces the permission server-side when
 *   exposing the descriptor to a caller.
 * - `resource` (array, REQUIRED when `screen` is `'crud'`; optional metadata
 *   for `'custom'` screens, validated identically when present):
 *     - `basePath` (string): the plugin's OWN REST collection path, starting
 *       with `'/api/'`, e.g. `'/api/hello/greetings'`. The host validates
 *       that the plugin ACTUALLY REGISTERED a GET route at exactly this path
 *       — a declared route the host refused (for example a path collision
 *       with a core route; first registration wins) does not count — and
 *       that this GET route's own `requiredPermission` EQUALS the
 *       descriptor's. A path the plugin does not serve, or a menu gate that
 *       differs from the data route's gate, is REJECTED.
 *     - `titleField` (string, optional): the item field used as the display
 *       name in confirmations.
 * - `icon` (string, optional): kebab-case tabler icon name. Default: none.
 * - `group` (string, optional non-empty): navigation group. Default `'plugins'`.
 * - `order` (int, optional): sort order within the group. Default `100`.
 *
 * An invalid descriptor is DROPPED by the host with a logged warning naming
 * the plugin, the descriptor id (when present) and the exact reason; the
 * plugin itself still loads — descriptors are UI metadata, never a load gate.
 */
interface PluginFrontendInterface
{
    /**
     * @return list<array<string, mixed>> Frontend feature descriptors.
     */
    public function getFrontendFeatures(): array;
}
