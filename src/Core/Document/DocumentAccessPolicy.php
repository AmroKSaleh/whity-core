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
 * TWO INDEPENDENT QUESTIONS, BOTH OF WHICH MUST PASS
 * --------------------------------------------------
 * Migration 059 shipped one: `required_permission`, WHAT KIND OF PERSON may see
 * this row. Migration 117 adds the other: `owner_ou_id`, WHERE IN THE
 * ORGANISATION the row belongs, answered against the caller's reach
 * ({@see \Whity\Core\Ou\OuReachResolver}).
 *
 * They are orthogonal on purpose, and the requirement needs both:
 *
 *   a secretary for a dean might have access to templates and design blocks
 *   more than a secretary of a department head
 *
 * Both secretaries hold `documents:write`, so the permission gate cannot tell
 * them apart — it is a flat, tenant-wide answer, and no tag fixes that because
 * what differs between the two is not their kind but their place. Reach tells
 * them apart: the dean's secretary stands at the Faculty and reaches every
 * department beneath it, the department head's secretary stands at one
 * department and reaches only that. Meanwhile the permission gate is what still
 * keeps a technician standing in the same faculty from seeing contract
 * templates. Drop either predicate and one of the two cases breaks.
 *
 * Visibility by scope:
 *   - personal → only the creator. Placement is not consulted: creator-only is
 *     already narrower than any placement could make it.
 *   - system   → everyone in the tenant who REACHES it (seeded starters are
 *     unplaced, so in practice everyone — but an operator who places one is
 *     obeyed rather than special-cased).
 *   - tenant / global → reach AND the row's `required_permission` gate: null =
 *     everyone who reaches it; otherwise only callers who hold that permission
 *     AT the placement unit.
 *   - anything else → fail closed (hidden).
 *
 * An AUTHOR always reaches their own row, whatever it is filed against — see
 * passesPlacement(), which records why publishing would otherwise be a one-way
 * door for the administrators most likely to do it.
 *
 * WHY THE PERMISSION IS RESOLVED *AT* THE PLACEMENT UNIT
 * -----------------------------------------------------
 * `$hasPermission` takes an optional unit id, and a placed row passes it. That
 * is what makes `resource_role_assignments` pull its weight in the capability
 * dimension as well as the reach one: a role granted to one profile at one unit
 * lends its permissions when the question is asked there and nowhere else. So
 * "let this department's secretary see the contract templates in HER department"
 * is one grant row, rather than granting her role the contracts tag tenant-wide
 * and thereby handing her every other unit's contract templates too.
 *
 * Resource-scoped resolution is additive ({@see \Whity\Auth\RoleChecker}: "a
 * resource grant can only widen authority at that resource, never narrow it"),
 * which is exactly right for that job and exactly why it cannot do reach's job.
 * See {@see \Whity\Core\Ou\OuReachResolver} for that argument in full.
 *
 * Publishing (making a row tenant/global, attaching a required_permission, or
 * placing it in the organisation) is a separate, stronger action gated on
 * documents:publish — see needsPublish().
 *
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
     * @param array<string, mixed> $row      A normalized template/block row.
     * @param int                  $callerId The caller's profile id.
     * @param callable(string, int|null=): bool $hasPermission Resolves whether the caller holds a
     *                                      permission in the tenant; the second argument asks it
     *                                      AT one organizational unit instead of tenant-wide.
     * @param callable(int): bool  $reachesOu Whether the caller has standing at a unit — their
     *                                      own units and everything beneath them. REQUIRED, not
     *                                      nullable: an unwired reach predicate would answer
     *                                      "yes" for every unit and silently publish every placed
     *                                      row to the whole tenant, which is the failure shape
     *                                      {@see DocumentVisibilityPolicy} refuses for the same
     *                                      reason.
     */
    public function canView(array $row, int $callerId, callable $hasPermission, callable $reachesOu): bool
    {
        $scope = (string) ($row['scope'] ?? self::SCOPE_PERSONAL);

        return match ($scope) {
            self::SCOPE_PERSONAL => ($row['created_by'] ?? null) === $callerId,
            self::SCOPE_SYSTEM   => $this->passesPlacement($row, $callerId, $reachesOu),
            self::SCOPE_TENANT, self::SCOPE_GLOBAL => $this->passesPlacement($row, $callerId, $reachesOu)
                && $this->passesRequiredPermission($row, $hasPermission),
            default              => false,
        };
    }

    /**
     * Filter a list of rows to those the caller may see (preserving order).
     *
     * @param list<array<string, mixed>> $rows
     * @param callable(string, int|null=): bool $hasPermission
     * @param callable(int): bool          $reachesOu
     * @return list<array<string, mixed>>
     */
    public function filterVisible(array $rows, int $callerId, callable $hasPermission, callable $reachesOu): array
    {
        return array_values(array_filter(
            $rows,
            fn (array $row): bool => $this->canView($row, $callerId, $hasPermission, $reachesOu),
        ));
    }

    /**
     * Whether the target scope / required_permission / placement requires the
     * documents:publish capability to set (i.e. it is NOT a plain, unplaced
     * personal row). Used to gate create/update: writing a tenant/global row,
     * attaching a permission tag, or FILING A ROW IN THE ORGANISATION is a
     * publish action, not an ordinary write.
     *
     * Placement counts even on a personal row. A personal row is creator-only,
     * so placing one changes nothing anybody can observe — but storing a
     * placement an ordinary writer chose means the row silently acquires an
     * audience the moment somebody with publish rights promotes its scope. It is
     * cheaper to refuse the meaningless combination than to explain that.
     */
    public function needsPublish(?string $scope, ?string $requiredPermission, ?int $ownerOuId = null): bool
    {
        if ($requiredPermission !== null && $requiredPermission !== '') {
            return true;
        }

        if ($ownerOuId !== null) {
            return true;
        }

        return $scope !== null && $scope !== self::SCOPE_PERSONAL;
    }

    /**
     * Whether the caller's reach covers where this row is filed.
     *
     * An UNPLACED row (`owner_ou_id` null) is tenant-wide and passes for
     * everybody — the behaviour every row had before migration 117, which is why
     * that migration changes no existing template's audience.
     *
     * THE AUTHOR IS ALWAYS WITHIN REACH OF THEIR OWN ROW
     * --------------------------------------------------
     * Without this, publishing was a one-way door: a tenant administrator
     * typically holds no membership OU at all, so their reach is empty, so the
     * moment they filed a template at a unit it vanished from their own list.
     * They could create it and never see it again — and the 404-not-403 posture
     * meant nothing on screen would say why.
     *
     * It is a statement about REACH, not a hole in the gate: authorship is
     * standing over the thing you wrote, which is the same rule
     * `scope = personal` already applies. `required_permission` is NOT waived —
     * an author who does not hold their own row's tag still cannot see it, which
     * is exactly how it behaved before 116.
     *
     * It does not make an administrator omniscient, deliberately. Somebody who
     * must see every unit's templates belongs at the ROOT of the tree, where
     * reach is the whole organisation — that is what a root unit is for, and it
     * is a membership an operator can grant and revoke rather than a permission
     * that quietly outranks placement everywhere.
     *
     * @param array<string, mixed> $row
     * @param callable(int): bool  $reachesOu
     */
    private function passesPlacement(array $row, int $callerId, callable $reachesOu): bool
    {
        $ouId = $row['owner_ou_id'] ?? null;
        if ($ouId === null) {
            return true;
        }

        if (($row['created_by'] ?? null) === $callerId) {
            return true;
        }

        return $reachesOu((int) $ouId);
    }

    /**
     * @param array<string, mixed>         $row
     * @param callable(string, int|null=): bool $hasPermission
     */
    private function passesRequiredPermission(array $row, callable $hasPermission): bool
    {
        $required = $row['required_permission'] ?? null;
        if (!is_string($required) || $required === '') {
            return true; // no tag → visible to everyone who reaches it
        }

        $ouId = $row['owner_ou_id'] ?? null;

        return $hasPermission($required, $ouId === null ? null : (int) $ouId);
    }
}
