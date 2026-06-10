<?php

declare(strict_types=1);

namespace Whity\Sdk\Hooks;

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
     * Static catalogue only — never instantiated.
     */
    private function __construct()
    {
    }
}
