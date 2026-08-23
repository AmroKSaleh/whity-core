<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use Whity\Core\RBAC\CorePermissions;

/**
 * Row-level visibility for ISSUED DOCUMENTS (#947 item 1).
 *
 * Not to be confused with {@see DocumentAccessPolicy}, which governs TEMPLATES
 * and blocks by `scope` / `required_permission`. A template is a design asset
 * that gets published to an audience; a document is a business record that gets
 * raised by a person and, eventually, routed to people. Same subsystem, two
 * genuinely different questions, so two policies rather than one with a mode
 * flag.
 *
 * THE RULE TODAY
 * --------------
 *   you raised it, OR you hold `documents:read:all`.
 *
 * Fails closed: a caller matching neither is told the document does not exist
 * (404), never refused (403), which would confirm it does — the same posture
 * the template policy already takes.
 *
 * `documents:read` (the route gate) is NOT sufficient on its own, on purpose.
 * That permission means "may use the designer" and is held broadly; letting it
 * also mean "may read everything the tenant has ever issued" would have widened
 * an existing grant silently the day this shipped. See migration 109.
 *
 * WHERE ITEM 3 PLUGS IN
 * ---------------------
 * This is the interim rule and it is one method with one boolean expression so
 * that the missing clause has an obvious home. When routing lands, "or I am a
 * recipient of this document, or I hold a role granted on it through
 * `resource_role_assignments`" is an additional disjunct in {@see canView()},
 * and a corresponding widening of {@see restrictToCreator()} for the list
 * query. Nothing else in the subsystem has to move.
 *
 * Stateless — worker-safe.
 */
final class DocumentVisibilityPolicy
{
    /**
     * Whether the caller may see this document.
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

        return ($document['created_by'] ?? null) === $callerId;
    }

    /**
     * The `created_by` value a LIST query must be restricted to, or null when
     * the caller may see the whole tenant's documents.
     *
     * The list path deliberately does not reuse {@see canView()} over a fetched
     * page: documents accumulate without bound, so a post-filter returns short
     * pages and a total that disagrees with them. The two are the same rule
     * expressed for the two shapes of question, and
     * {@see DocumentRepository::listForTenant()} binds the result as a real
     * predicate.
     *
     * @param callable(string): bool $hasPermission
     */
    public function restrictToCreator(int $callerId, callable $hasPermission): ?int
    {
        return $hasPermission(CorePermissions::DOCUMENTS_READ_ALL) ? null : $callerId;
    }
}
