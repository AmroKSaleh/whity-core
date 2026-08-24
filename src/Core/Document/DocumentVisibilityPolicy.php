<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * Row-level visibility for ISSUED DOCUMENTS (#947 items 1 and 3).
 *
 * Not to be confused with {@see DocumentAccessPolicy}, which governs TEMPLATES
 * and blocks by `scope` / `required_permission`. A template is a design asset
 * that gets published to an audience; a document is a business record that gets
 * raised by a person and routed to people. Same subsystem, two genuinely
 * different questions, so two policies rather than one with a mode flag.
 *
 * THE RULE
 * --------
 *   you raised it,
 *   OR you hold `documents:read:all`,
 *   OR a route reached you,
 *   OR you hold a role granted on this document through
 *      `resource_role_assignments`.
 *
 * The last two disjuncts are item 3's, and item 1 left them exactly this home:
 * one method, one boolean expression, so the missing clause had an obvious place
 * to go and nothing else in the subsystem had to move.
 *
 * Fails closed: a caller matching none of them is told the document does not
 * exist (404), never refused (403), which would confirm it does — the same
 * posture the template policy already takes.
 *
 * `documents:read` (the route gate) is NOT sufficient on its own, on purpose.
 * That permission means "may use the designer" and is held broadly; letting it
 * also mean "may read everything the tenant has ever issued" would have widened
 * an existing grant silently. See migration 109.
 *
 * WHY "A ROUTE REACHED YOU" IS EVER, NOT CURRENTLY
 * -----------------------------------------------
 * {@see RouteRecipientRepository::hasAnyForProfile()} matches CLOSED rows as
 * well as open ones, and that is deliberate. "I no longer have it in my inbox"
 * is not "I was never sent it": a person who forwarded something last week must
 * still be able to open what they forwarded — to answer a question about it, or
 * to add the correcting note that is the only way to amend an append-only
 * trail. Restricting visibility to open items would make the trail readable
 * exactly until the moment somebody needed to correct it.
 *
 * WHY THE RESOURCE-ROLE CLAUSE IGNORES EVERYONE-GRANTS
 * ---------------------------------------------------
 * Only grants naming the caller count. Migration 088 defines a
 * `profile_id IS NULL` row as "everyone WITH ACCESS to this resource gets role R
 * here" — it modifies what reachable people may DO and is not itself access.
 * Reading it as access would make one everyone-grant publish a document to the
 * whole tenant. {@see ResourceRoleAssignmentRepository::hasProfileGrantAt()}
 * enforces that, and says so.
 *
 * COLLABORATORS ARE REQUIRED, NOT OPTIONAL
 * ----------------------------------------
 * Both are constructor-required rather than nullable-with-a-fallback. A nullable
 * collaborator here would mean an unwired policy silently answering the
 * migration-109 interim rule — hiding documents from the very people a route was
 * built to reach, with nothing in any log to say why. That is the failure shape
 * {@see \Whity\Core\Container\HostWiredService} exists to refuse, and a policy
 * is the last place to accept it.
 *
 * Stateless apart from its two repositories — worker-safe.
 */
final class DocumentVisibilityPolicy
{
    public function __construct(
        private readonly RouteRecipientRepository $recipients,
        private readonly ResourceRoleAssignmentRepository $resourceRoles,
    ) {
    }

    /**
     * Whether the caller may see this document.
     *
     * Ordered cheapest-first: two in-memory checks, then at most two indexed
     * single-row lookups. PHP short-circuits `||`, so the ordinary cases — your
     * own document, or an auditor holding the tenant-wide grant — cost no
     * queries at all.
     *
     * @param array<string, mixed>   $document      A normalized `documents` row.
     * @param int                    $callerId      The caller's profile id.
     * @param callable(string): bool $hasPermission Resolves a permission in the caller's tenant.
     */
    public function canView(array $document, int $callerId, callable $hasPermission): bool
    {
        if ($hasPermission(CorePermissions::DOCUMENTS_READ_ALL)) {
            return true;
        }
        if (($document['created_by'] ?? null) === $callerId) {
            return true;
        }

        $tenantId = (int) ($document['tenant_id'] ?? 0);
        $documentId = (int) ($document['id'] ?? 0);
        if ($tenantId <= 0 || $documentId <= 0) {
            // A row without them is not a document this policy can reason about,
            // and guessing would be guessing in the permissive direction.
            return false;
        }

        if ($this->recipients->hasAnyForProfile($tenantId, $documentId, $callerId)) {
            return true;
        }

        return $this->resourceRoles->hasProfileGrantAt(
            $tenantId,
            ResourceTypeRegistry::TYPE_DOCUMENT,
            $documentId,
            $callerId,
        );
    }

    /**
     * The `created_by` value a LIST query must be restricted to, or null when
     * the caller may see the whole tenant's documents.
     *
     * The list path deliberately does not reuse {@see canView()} over a fetched
     * page: documents accumulate without bound, so a post-filter returns short
     * pages and a total that disagrees with them.
     *
     * WHAT THIS RETURN VALUE NOW MEANS. It is still "the caller's own id, or
     * null for everything", but it is no longer the WHOLE predicate — since item
     * 3 a restricted list is "mine OR routed to me OR granted to me", and the
     * two extra disjuncts are pushed into SQL by
     * {@see DocumentRepository::listForTenant()} as `EXISTS` clauses keyed on the
     * same profile id. They are expressed there rather than returned from here
     * because they are not values; they are joins, and a policy that returned
     * SQL fragments would be a policy the predicate guard could not read.
     *
     * @param callable(string): bool $hasPermission
     */
    public function restrictToCreator(int $callerId, callable $hasPermission): ?int
    {
        return $hasPermission(CorePermissions::DOCUMENTS_READ_ALL) ? null : $callerId;
    }
}
