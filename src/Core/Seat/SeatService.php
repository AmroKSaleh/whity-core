<?php

declare(strict_types=1);

namespace Whity\Core\Seat;

use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * SEATS: how many people a tenant may have, and whether it may take one more.
 *
 * The limit itself already existed and was already sold. `members.max` is an
 * ENTITLEMENT — documented as "maximum number of active members the tenant may
 * have", assignable to a plan, asserted in the plans test — and it was read by
 * NOTHING. An operator could put 50 on a plan and a tenant could add five
 * thousand. This is the half that was missing.
 *
 * WHAT COUNTS AS A SEAT IS CONFIGURABLE, because instances genuinely differ.
 * `seats.count_invited` decides whether an outstanding invitation holds one.
 * True is the default and the honest reading — an invitation is a seat somebody
 * has been promised, and not counting it lets a tenant invite a thousand people
 * past a limit of ten and reach it the moment they accept. An instance that
 * means "people actually working" turns it off. A SUSPENDED membership never
 * counts either way: suspending somebody is how a tenant frees a seat.
 *
 * WHAT HAPPENS AT THE LIMIT IS ALSO CONFIGURABLE, and this is the question
 * nobody should answer by guessing. `seats.enforcement` is `off`, `warn` or
 * `block`. Three levels rather than the payment wall's four because a seat limit
 * is only ever consulted when something is being ADDED, so "block writes" and
 * "block everything" would be the same rule.
 *
 * BOTH SETTINGS ARE GLOBAL-ONLY, like the wall's own strictness. That is a
 * safety property rather than a limitation: a tenant-overridable
 * `seats.enforcement` would let any tenant admin holding `settings:write` set
 * their own instance to `off` and walk past the limit they were sold. Operator
 * policy has to live where the operator is. A genuine per-tenant exception
 * belongs on a column the operator writes — the shape `tenant_plan.
 * enforcement_mode` already uses for the payment wall — not on a key the tenant
 * can reach.
 *
 * IT NEVER REMOVES ANYBODY. Going over the limit — a plan downgraded beneath the
 * headcount, an entitlement lowered, invitations counted where they were not
 * before — leaves every existing member exactly as they are and only refuses the
 * NEXT addition. Auto-suspending to fit would lock real people out of their work
 * to satisfy a number an administrator changed, and no seat policy is worth
 * that. An operator who wants people removed removes them.
 */
final class SeatService
{
    /** The system tenant is never seat-limited, as it is never payment-walled. */
    public const SYSTEM_TENANT_ID = 0;

    public const MODE_OFF = 'off';
    public const MODE_WARN = 'warn';
    public const MODE_BLOCK = 'block';

    public function __construct(
        private readonly PDO $db,
        private readonly EntitlementService $entitlements,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * How the instance treats this tenant's limit.
     *
     * Read through `effective()` so a future per-tenant column can slot in
     * without every caller changing, but the SETTING itself is global-only —
     * see the class note on why a tenant must not be able to lower its own.
     *
     * An unrecognised stored value falls back to `warn` rather than to `block`:
     * a value nobody meant must not be the one that starts refusing people.
     */
    public function enforcementMode(int $tenantId): string
    {
        $mode = $this->settings->effective($tenantId)[SettingsRegistry::SEATS_ENFORCEMENT] ?? self::MODE_WARN;

        return in_array($mode, [self::MODE_OFF, self::MODE_WARN, self::MODE_BLOCK], true)
            ? $mode
            : self::MODE_WARN;
    }

    /** Does an outstanding invitation hold a seat on this instance? */
    public function countsInvited(int $tenantId): bool
    {
        return ($this->settings->effective($tenantId)[SettingsRegistry::SEATS_COUNT_INVITED] ?? 'true') === 'true';
    }

    /**
     * Seats the tenant has bought, or {@see EntitlementRegistry::UNLIMITED}.
     */
    public function limit(int $tenantId): int
    {
        return $this->entitlements->limit($tenantId, EntitlementRegistry::MEMBERS_MAX);
    }

    /**
     * Seats currently held: one per PERSON.
     *
     * `memberships` carries UNIQUE (profile_id, tenant_id), so a person is in a
     * tenant at most once and DISTINCT changes no answer today. It is written
     * that way regardless, because the number this returns is what a tenant is
     * charged for — if that constraint is ever relaxed to let somebody hold two
     * roles as two rows, a plain count would start billing them twice, and it
     * would do it quietly.
     */
    public function used(int $tenantId): int
    {
        $statuses = [MembershipRepository::STATUS_ACTIVE];
        if ($this->countsInvited($tenantId)) {
            $statuses[] = MembershipRepository::STATUS_INVITED;
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT profile_id) FROM memberships
              WHERE tenant_id = ? AND status IN ({$placeholders})"
        );
        $stmt->execute([$tenantId, ...$statuses]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * May this tenant take one more person?
     *
     * @param int $profileId Pass it when the person may ALREADY hold a seat here
     *        — a repeated invitation, a caller that cannot tell an insert from
     *        an update — and they are not asked to find a second one. Omitted,
     *        the check is for somebody certainly new.
     *
     *        Note that re-activating a SUSPENDED member does need a seat: a
     *        suspended membership holds none, so bringing it back takes one.
     *        That is the same rule read from the other side, not an exception.
     */
    public function decide(int $tenantId, ?int $profileId = null): SeatDecision
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return SeatDecision::allowed(self::MODE_OFF, -1, 0);
        }

        $mode = $this->enforcementMode($tenantId);
        if ($mode === self::MODE_OFF) {
            return SeatDecision::allowed(self::MODE_OFF, -1, 0);
        }

        $limit = $this->limit($tenantId);
        if ($limit === EntitlementRegistry::UNLIMITED) {
            return SeatDecision::allowed($mode, $limit, $this->used($tenantId));
        }

        $used = $this->used($tenantId);

        // Somebody who already holds a seat here is not taking another one.
        if ($profileId !== null && $this->holdsSeat($tenantId, $profileId)) {
            return SeatDecision::allowed($mode, $limit, $used);
        }

        if ($used < $limit) {
            return SeatDecision::allowed($mode, $limit, $used);
        }

        // At or over. `warn` still reports — that is the whole difference
        // between it and `off`, and it is what lets an operator see the limit
        // biting before they turn blocking on.
        return $mode === self::MODE_BLOCK
            ? SeatDecision::blocked($mode, $limit, $used)
            : SeatDecision::warned($mode, $limit, $used);
    }

    /** Does this profile already occupy a seat in this tenant? */
    private function holdsSeat(int $tenantId, int $profileId): bool
    {
        $statuses = [MembershipRepository::STATUS_ACTIVE];
        if ($this->countsInvited($tenantId)) {
            $statuses[] = MembershipRepository::STATUS_INVITED;
        }

        $placeholders = implode(',', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare(
            "SELECT 1 FROM memberships
              WHERE tenant_id = ? AND profile_id = ? AND status IN ({$placeholders})
              LIMIT 1"
        );
        $stmt->execute([$tenantId, $profileId, ...$statuses]);

        return $stmt->fetchColumn() !== false;
    }
}
