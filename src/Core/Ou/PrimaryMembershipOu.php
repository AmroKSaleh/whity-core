<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

use PDO;

/**
 * "Which unit is this person acting from?" — the profile's primary active
 * membership OU in one tenant.
 *
 * A profile still holds at most one membership per tenant in effect, but the
 * rule now lives on a column rather than on the table: migration 094 replaced
 * 030's `UNIQUE (profile_id, tenant_id)` with a PARTIAL unique index over the
 * primary rows only, precisely so that a profile holding several tenant-wide
 * roles (#712 §1) becomes possible without every "what is my unit here?" read
 * turning into whichever row the query plan reached first.
 *
 * So `ORDER BY is_primary DESC, id ASC` is not defensive padding against a
 * constraint that makes it unnecessary — it is the rule migration 094 moved
 * onto the column, spelled out. A `LIMIT 1` with no ORDER BY is exactly the
 * defect 094 exists to close, and writing one here would reintroduce it on the
 * day the second row is allowed, silently and for every historical act.
 *
 * WHY IT LIVES HERE
 * -----------------
 * {@see \Whity\Core\Document\DocumentIssuer} needs it to stamp
 * `documents.origin_ou_id` at issue time, and
 * {@see \Whity\Core\Document\Routing\DocumentRouter} needs the same answer to
 * stamp `document_route_events.from_ou_id`. Two copies of "which unit is this
 * person in" that disagree by one ORDER BY would make a document's origin unit
 * and the unit its own issue event records differ — for the same person, in the
 * same request — and nothing would flag it.
 *
 * CAPTURED, NEVER DERIVED ON READ
 * -------------------------------
 * Callers store the result. People move units, and re-deriving this when a row
 * is READ would rewrite history for every past record: a document raised by the
 * Registry last year did not become a Faculty document when its author
 * transferred. Migration 108 records the same argument for the column it fills.
 *
 * Stateless — worker-safe.
 */
final class PrimaryMembershipOu
{
    /**
     * The unit the profile is acting from, or null when they belong to none.
     *
     * Primary membership first, then the oldest one holding a unit. The
     * tie-break is explicit because picking an arbitrary row would make the
     * answer depend on insertion order — stable in one database and different
     * in a restore of it.
     *
     * Only ACTIVE memberships count: an invited or suspended member is not part
     * of the organisation for the purpose of acting within it.
     */
    public static function forProfile(PDO $db, int $tenantId, int $profileId): ?int
    {
        $stmt = $db->prepare(
            "SELECT ou_id FROM memberships
              WHERE tenant_id = :tenant_id
                AND profile_id = :profile_id
                AND status = 'active'
                AND ou_id IS NOT NULL
              ORDER BY is_primary DESC, id ASC
              LIMIT 1"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        $ouId = $stmt->fetchColumn();

        return $ouId === false || $ouId === null ? null : (int) $ouId;
    }
}
