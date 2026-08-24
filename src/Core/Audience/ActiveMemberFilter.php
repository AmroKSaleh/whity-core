<?php

declare(strict_types=1);

namespace Whity\Core\Audience;

use PDO;
use Whity\Sdk\Routing\ResolvedRecipient;

/**
 * The host's answer to a rule's answer: intersect it with the tenant's ACTIVE
 * MEMBERSHIP list (#999, extracted from {@see \Whity\Core\Document\Routing\DocumentRouter}).
 *
 * WHY THIS IS ITS OWN CLASS NOW
 * -----------------------------
 * #989 put this check inside the routing engine, as a private method, because
 * routing was the only thing that asked a resolver anything. It was never a
 * routing rule: it is THE security boundary for plugin-supplied rules, and it
 * belongs wherever a resolver's answer is turned into something the system acts
 * on.
 *
 * #999 gives it a second caller — a named user group resolving its own rule —
 * and the wrong move at that point is to write the same query twice. Two copies
 * of a security boundary are two things to update when the membership model
 * changes, and the copy that gets missed is the one nobody was looking at. So it
 * moved here and the router calls it.
 *
 * WHAT IT GUARANTEES
 * ------------------
 * A resolver — core's or a plugin's, correct or buggy or hostile — RETURNS
 * SUGGESTIONS. Nothing it names becomes an inbox row, a group member or a
 * preview count until it has been checked against the tenant the question was
 * asked about. So a resolver cannot place a document in another tenant's inbox,
 * cannot make a departed employee a member of a group, and cannot leak the
 * existence of a profile it should not have read.
 *
 * The check lives HERE rather than being a rule every resolver author has to
 * remember, which is the whole reason plugin-supplied rules are safe to offer at
 * all.
 *
 * DROPPING, NOT FAILING
 * ---------------------
 * A profile that fails the check is silently removed, not turned into an error.
 * "This person has left" is an ordinary answer, and refusing the whole
 * resolution over one departed member would strand everybody else — a barrier
 * created by an error path, which in routing defeats "distribution fans out, it
 * does not block" from the side, and in a group preview would make a perfectly
 * good rule unreadable because one person resigned.
 *
 * DE-DUPLICATION BY PROFILE, FIRST `ouId` WINS
 * -------------------------------------------
 * A rule may legitimately return the same person twice — two memberships
 * holding the same role — and once is what both an inbox row and a group
 * membership mean. The first `ouId` seen wins, which is stable because the
 * caller's ordering is.
 *
 * A STATIC HELPER OVER A HANDLE, deliberately, in the shape
 * {@see \Whity\Core\Ou\OuSubtree} and {@see \Whity\Core\Ou\PrimaryMembershipOu}
 * already use: it holds no state, needs no configuration, and every caller
 * already has the PDO. Making it an injected service would add a constructor
 * argument to the router, the CLI bootstrap and every test that builds one, to
 * buy nothing that could be substituted — the one substitution anybody would
 * want (a stub that skips the check) is the substitution this class exists to
 * prevent.
 */
final class ActiveMemberFilter
{
    /**
     * Static-only.
     */
    private function __construct()
    {
    }

    /**
     * Keep only the recipients who are ACTIVE members of this tenant.
     *
     * `status = 'active'` — a suspended or merely invited member is not part of
     * the organisation for the purpose of receiving work or of being counted in
     * a group. That is the same predicate core's own resolvers apply, and having
     * it here as well is not redundancy: a plugin's resolver applies whatever it
     * likes, and this is the line that holds regardless.
     *
     * @param list<ResolvedRecipient> $resolved What the rule answered.
     * @return list<ResolvedRecipient> De-duplicated, tenant-checked.
     */
    public static function apply(PDO $db, int $tenantId, array $resolved): array
    {
        /** @var array<int, ResolvedRecipient> $byProfile */
        $byProfile = [];
        foreach ($resolved as $recipient) {
            if ($recipient->profileId > 0 && !isset($byProfile[$recipient->profileId])) {
                $byProfile[$recipient->profileId] = $recipient;
            }
        }
        if ($byProfile === []) {
            return [];
        }

        // Placeholders are generated positionally because the candidate list has
        // no fixed width; every value is an int already coerced above, and the
        // statement carries a literal tenant predicate so
        // scripts/ci-tenant-predicate-guard.php can verify it by reading this
        // file.
        $placeholders = [];
        $params = [':tenant_id' => $tenantId];
        foreach (array_keys($byProfile) as $i => $profileId) {
            $name = ':p' . $i;
            $placeholders[] = $name;
            $params[$name] = $profileId;
        }

        $stmt = $db->prepare(
            "SELECT DISTINCT profile_id FROM memberships
              WHERE tenant_id = :tenant_id
                AND status = 'active'
                AND profile_id IN (" . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);

        $out = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $profileId = (int) $row['profile_id'];
            if (isset($byProfile[$profileId])) {
                $out[] = $byProfile[$profileId];
            }
        }

        return $out;
    }
}
