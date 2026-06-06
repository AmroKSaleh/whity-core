<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when a grantor attempts to delegate a permission they do not themselves
 * currently hold (WC-34).
 *
 * The HARD delegation invariant is that a role-holder may only delegate a SUBSET
 * of their OWN effective permissions: a grantor can NEVER delegate a permission
 * they lack. {@see \Whity\Core\Delegation\DelegationService} computes the
 * grantor's effective permission set (via {@see \Whity\Auth\RoleChecker}) and
 * throws this exception for any requested permission outside that set. The
 * delegation API handler catches it and translates it into a safe `422`
 * Unprocessable Entity response — the request is well-formed but semantically
 * invalid, no delegation row is written, and the internal reason is never leaked.
 */
class PermissionNotDelegableException extends RuntimeException
{
    /**
     * The permissions the grantor tried to delegate but does not hold.
     *
     * @var array<int, string>
     */
    private array $deniedPermissions;

    /**
     * @param array<int, string> $deniedPermissions The permissions outside the grantor's effective set.
     */
    public function __construct(array $deniedPermissions)
    {
        $this->deniedPermissions = array_values($deniedPermissions);

        parent::__construct(
            'Cannot delegate one or more permissions the grantor does not hold'
        );
    }

    /**
     * Build an exception describing the rejected delegation.
     *
     * @param array<int, string> $deniedPermissions The permissions the grantor lacks.
     * @return self
     */
    public static function forPermissions(array $deniedPermissions): self
    {
        return new self($deniedPermissions);
    }

    /**
     * The permissions that could not be delegated because the grantor lacks them.
     *
     * Used only for structured server-side logging; never returned verbatim to a
     * client beyond the safe top-level error message.
     *
     * @return array<int, string>
     */
    public function getDeniedPermissions(): array
    {
        return $this->deniedPermissions;
    }
}
