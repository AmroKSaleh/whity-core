<?php

declare(strict_types=1);

namespace Whity\Sdk\Hooks;

use Whity\Sdk\PluginNamespace;

/**
 * Catalogue of hook event names dispatched by the Whity platform (SDK v1.0).
 *
 * Plugins subscribe to these via {@see \Whity\Sdk\PluginInterface::getHooks()}.
 * Two flavours exist:
 *
 * - Synchronous FILTER hooks (`*.creating` / `*.updating` / `*.deleting`):
 *   listeners receive the mutable payload plus a context array and MUST return
 *   the (possibly modified) payload.
 * - Notification hooks (`*.created` / `*.updated` / `*.deleted` and the
 *   `*.async` variants): listeners observe; async variants are queued.
 *
 * Deletion ordering + transactionality (SDK 1.15, WC-713)
 * -------------------------------------------------------
 * For `tenant.*`, `ou.*` and `role.*`, the host runs
 *
 *     `*.deleting`  →  DELETE  →  `*.deleted`
 *
 * inside a SINGLE database transaction, and only dispatches `*.deleted.async`
 * once that transaction has COMMITTED. Three consequences a plugin can rely on:
 *
 *  1. During `*.deleting` the row still exists and is readable — that is where
 *     to look up anything you need about it. During `*.deleted` it is gone
 *     (within the transaction), so read first, act second.
 *  2. Throwing {@see HookVetoException} from EITHER hook rolls the whole
 *     transaction back — the entity survives — and the API caller gets
 *     `409 Conflict` with your `reason()` in the error details. This is the only
 *     Throwable that crosses the host's per-plugin error boundary; anything else
 *     you throw is logged, isolated, and the deletion proceeds regardless.
 *  3. `*.deleted.async` fires only for a deletion that actually committed, so a
 *     relay handler never has to reason about a delete that was undone.
 *
 * Before 1.15 the DELETE committed on its own and `*.deleted` fired afterwards,
 * so a plugin's cleanup was best-effort: it could neither veto the deletion nor
 * undo it, and a failure left its rows orphaned against a parent that no longer
 * existed. Plugins targeting `^1.15` may rely on the guarantees above; on an
 * older host, treat `*.deleted` as a pure notification.
 *
 * `user.deleted` is deliberately NOT part of this contract: it has no
 * `user.deleting` counterpart, and its core listeners send mail, which must not
 * hold a transaction open nor be able to undo a membership removal.
 *
 * The string values are the wire contract; the constants exist so plugin code
 * gets IDE completion and typo safety. New events are added in minor SDK
 * versions (additive policy).
 */
final class Events
{
    // ---- users ----
    public const USER_CREATING = 'user.creating';
    public const USER_CREATED = 'user.created';
    public const USER_CREATED_ASYNC = 'user.created.async';
    public const USER_UPDATED = 'user.updated';
    public const USER_DELETED = 'user.deleted';

    // ---- tenants ----
    public const TENANT_CREATING = 'tenant.creating';
    public const TENANT_CREATED = 'tenant.created';
    public const TENANT_CREATED_ASYNC = 'tenant.created.async';
    public const TENANT_UPDATING = 'tenant.updating';
    public const TENANT_UPDATED = 'tenant.updated';
    public const TENANT_DELETING = 'tenant.deleting';
    public const TENANT_DELETED = 'tenant.deleted';
    public const TENANT_DELETED_ASYNC = 'tenant.deleted.async';

    // ---- roles ----
    public const ROLE_CREATING = 'role.creating';
    public const ROLE_CREATED = 'role.created';
    public const ROLE_CREATED_ASYNC = 'role.created.async';
    public const ROLE_UPDATING = 'role.updating';
    public const ROLE_UPDATED = 'role.updated';
    public const ROLE_DELETING = 'role.deleting';
    public const ROLE_DELETED = 'role.deleted';
    public const ROLE_DELETED_ASYNC = 'role.deleted.async';

    // ---- organizational units ----
    public const OU_CREATING = 'ou.creating';
    public const OU_CREATED = 'ou.created';
    public const OU_CREATED_ASYNC = 'ou.created.async';
    public const OU_UPDATING = 'ou.updating';
    public const OU_UPDATED = 'ou.updated';
    public const OU_UPDATED_ASYNC = 'ou.updated.async';
    public const OU_DELETING = 'ou.deleting';
    public const OU_DELETED = 'ou.deleted';
    public const OU_DELETED_ASYNC = 'ou.deleted.async';
    public const OU_ROLE_ASSIGNED = 'ou.role_assigned';
    public const OU_ROLE_REMOVED = 'ou.role_removed';

    // ---- platform ----
    public const NAVIGATION_REGISTER = 'navigation.register';
    public const PERMISSION_REGISTERED = 'permission.registered';
    public const WORKER_BOOT = 'worker.boot';
    public const WORKER_REQUEST_START = 'worker.request.start';
    public const WORKER_REQUEST_END = 'worker.request.end';

    /**
     * The event name to dispatch for one of THIS plugin's own events (SDK 1.29).
     *
     * The constants above name events the HOST dispatches. A plugin's own
     * events live in the same flat event space and are namespaced under the
     * plugin, so `Events::forPlugin('Acme', 'task.completed')` is
     * `acme:task.completed` — the name to dispatch, and the name
     * {@see \Whity\Sdk\PluginEventsInterface} declarations are bound to.
     *
     * Spelling it by hand is the thing this method exists to prevent. The
     * prefix is a SLUG of the plugin name rather than the name itself
     * ({@see \Whity\Sdk\PluginNamespace::slug()}), so `Acme\Widgets\Plugin`
     * prefixes as `plugin` and a hand-written `Acme\Widgets\Plugin:…` matches
     * no listener at all — silently, since a dispatch nobody listens to is
     * indistinguishable from one whose listeners did nothing.
     *
     * Namespacing a plugin's events is worth doing even without an audit
     * declaration: two plugins that both dispatch `item.created` otherwise run
     * each other's listeners, which is a bug neither author can see in their
     * own repository.
     *
     * @param string $pluginName The declaring plugin's name ({@see \Whity\Sdk\PluginInterface::getName()}).
     * @param string $bareEvent  The BARE event name, e.g. `task.completed`.
     * @return string The namespaced event name, e.g. `acme:task.completed`.
     *
     * @throws \InvalidArgumentException When the plugin name yields no usable namespace slug.
     */
    public static function forPlugin(string $pluginName, string $bareEvent): string
    {
        return PluginNamespace::qualify($pluginName, $bareEvent);
    }

    /**
     * Static catalogue only — never instantiated.
     */
    private function __construct()
    {
    }
}
