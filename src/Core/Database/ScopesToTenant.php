<?php

namespace Whity\Core\Database;

use Whity\Core\Tenant\TenantContext;

/**
 * Automatic Query Scoping and Tenant Boundary Validation Trait
 *
 * This trait provides helper methods for automatic tenant scoping in ORM models.
 * When applied to a model class, it ensures that records are automatically scoped
 * to the current tenant context and validates that operations do not cross tenant
 * boundaries.
 *
 * ORM Integration:
 * ================
 * In Phase 2, when moving to an ORM like Eloquent or Doctrine, this trait's
 * bootScopesToTenant() method will be enhanced to register global query scopes
 * that automatically add WHERE tenant_id = $currentTenant to all queries,
 * preventing accidental data leakage across tenant boundaries.
 *
 * Current Structure:
 * ==================
 * - bootScopesToTenant(): Hook called by PHP reflection; empty placeholder
 *   for ORM integration
 * - setTenantIdBeforePersist(): Sets tenant_id if not already set
 * - validateTenantBoundary(): Validates record's tenant matches context
 *
 * Example Usage:
 * ==============
 * ```php
 * class User extends Model {
 *     use ScopesToTenant;
 *
 *     protected function setTenantIdBeforePersist()
 *     {
 *         parent::setTenantIdBeforePersist();
 *         // Additional logic...
 *     }
 * }
 *
 * // In a request handler with TenantContext set:
 * TenantContext::setTenantId(42);
 * $user = new User();
 * $user->setTenantIdBeforePersist(); // Sets user->tenant_id = 42
 * ```
 */
trait ScopesToTenant
{
    /**
     * Boot the ScopesToTenant trait
     *
     * This protected static method is called by ORM systems during model initialization.
     * In Eloquent-based ORMs, this is invoked automatically via bootTraitName() convention.
     *
     * Future Enhancement (Phase 2):
     * When integrated with an ORM, this method will register a global query scope that
     * automatically adds WHERE tenant_id = $currentTenant to all queries on this model:
     *
     * ```php
     * protected static function bootScopesToTenant()
     * {
     *     static::addGlobalScope(new TenantScope);
     * }
     * ```
     *
     * For now, this is a placeholder that documents the integration point.
     *
     * @return void
     */
    protected static function bootScopesToTenant(): void
    {
        // Placeholder for ORM integration in Phase 2
        // When using Eloquent or similar ORM:
        // - Register global query scope
        // - Add automatic WHERE tenant_id filtering
        // - Prevent cross-tenant data access
    }

    /**
     * Set the tenant_id before persisting the model
     *
     * If the model's tenant_id is not already set, this method automatically
     * sets it to the current tenant from TenantContext. This ensures that when
     * a new record is created within a tenant's request, it is automatically
     * associated with that tenant.
     *
     * Call this method before saving/persisting the model to the database:
     *
     * ```php
     * $user = new User();
     * $user->name = 'John';
     * $user->setTenantIdBeforePersist();
     * $user->save();
     * ```
     *
     * Throws RuntimeException if TenantContext is not set, indicating a request
     * is being processed outside of tenant context (which is a security issue).
     *
     * @return void
     * @throws \RuntimeException If TenantContext is not set
     */
    protected function setTenantIdBeforePersist(): void
    {
        // Only set if not already explicitly set
        if ($this->tenant_id === null) {
            if (!TenantContext::hasTenant()) {
                throw new \RuntimeException(
                    'Cannot persist ' . static::class . ' without active TenantContext'
                );
            }

            $this->tenant_id = TenantContext::getTenantId();
        }
    }

    /**
     * Validate that this record's tenant matches the current context
     *
     * This method ensures that operations on a record (read, update, delete) are
     * only allowed if the record belongs to the current tenant. This is a critical
     * security boundary check that prevents one tenant from accessing another's data.
     *
     * Use this in ORM event hooks (beforeUpdate, beforeDelete) or before returning
     * a record to request handlers:
     *
     * ```php
     * $user = $this->getUserById($id);
     * $user->validateTenantBoundary(); // Throws if user belongs to different tenant
     * return $user;
     * ```
     *
     * Throws RuntimeException in two cases:
     * 1. TenantContext is not set (request outside tenant context)
     * 2. Record's tenant_id does not match the current tenant (cross-tenant access attempt)
     *
     * @return void
     * @throws \RuntimeException If tenant boundary is violated
     */
    protected function validateTenantBoundary(): void
    {
        if (!TenantContext::hasTenant()) {
            throw new \RuntimeException(
                'Cannot validate tenant boundary without active TenantContext'
            );
        }

        $currentTenant = TenantContext::getTenantId();
        if ($this->tenant_id !== $currentTenant) {
            throw new \RuntimeException(
                sprintf(
                    'Tenant boundary violation: Record belongs to tenant %d, but current context is tenant %d',
                    $this->tenant_id,
                    $currentTenant
                )
            );
        }
    }
}
