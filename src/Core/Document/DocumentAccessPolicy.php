<?php

declare(strict_types=1);

namespace Whity\Core\Document;

/**
 * Row-level visibility policy for document templates & blocks (WC-docdesigner).
 *
 * Applied SERVER-SIDE on top of the tenant predicate + the route's
 * documents:read gate, so list/get return ONLY the rows a caller may see — a
 * technician never receives a gated contracts template even in the payload
 * (defense in depth: the client hides, the server withholds).
 *
 * Visibility by scope:
 *   - personal → only the creator (created_by === caller).
 *   - system   → everyone in the tenant (seeded starters).
 *   - tenant / global → the row's required_permission gate: null = everyone in
 *     the tenant; otherwise only callers who hold that permission.
 *   - anything else → fail closed (hidden).
 *
 * Publishing (making a row tenant/global, or attaching a required_permission)
 * is a separate, stronger action gated on documents:publish — see needsPublish().
 * Stateless — worker-safe.
 */
final class DocumentAccessPolicy
{
    public const SCOPE_PERSONAL = 'personal';
    public const SCOPE_TENANT   = 'tenant';
    public const SCOPE_GLOBAL   = 'global';
    public const SCOPE_SYSTEM   = 'system';

    public const SCOPES = [self::SCOPE_PERSONAL, self::SCOPE_TENANT, self::SCOPE_GLOBAL, self::SCOPE_SYSTEM];

    /**
     * Whether the caller may see this row.
     *
     * @param array<string, mixed>      $row           A normalized template/block row.
     * @param int                       $callerId      The caller's profile id.
     * @param callable(string): bool    $hasPermission Resolves whether the caller holds a permission in the tenant.
     */
    public function canView(array $row, int $callerId, callable $hasPermission): bool
    {
        $scope = (string) ($row['scope'] ?? self::SCOPE_PERSONAL);

        return match ($scope) {
            self::SCOPE_PERSONAL => ($row['created_by'] ?? null) === $callerId,
            self::SCOPE_SYSTEM   => true,
            self::SCOPE_TENANT, self::SCOPE_GLOBAL => $this->passesRequiredPermission($row, $hasPermission),
            default              => false,
        };
    }

    /**
     * Filter a list of rows to those the caller may see (preserving order).
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(string): bool     $hasPermission
     * @return list<array<string, mixed>>
     */
    public function filterVisible(array $rows, int $callerId, callable $hasPermission): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->canView($row, $callerId, $hasPermission),
        ));
    }

    /**
     * Whether the target scope/required_permission requires the documents:publish
     * capability to set (i.e. it is NOT a plain personal row). Used to gate
     * create/update: writing a tenant/global row or attaching a permission tag is
     * a publish action, not an ordinary write.
     */
    public function needsPublish(?string $scope, ?string $requiredPermission): bool
    {
        if ($requiredPermission !== null && $requiredPermission !== '') {
            return true;
        }

        return $scope !== null && $scope !== self::SCOPE_PERSONAL;
    }

    /**
     * @param array<string, mixed>   $row
     * @param callable(string): bool $hasPermission
     */
    private function passesRequiredPermission(array $row, callable $hasPermission): bool
    {
        $required = $row['required_permission'] ?? null;
        if (!is_string($required) || $required === '') {
            return true; // no tag → visible to everyone in the tenant
        }

        return $hasPermission($required);
    }
}
