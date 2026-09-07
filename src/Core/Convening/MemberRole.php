<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * The SEAT a person holds on a convening body.
 *
 * THIS IS NOT A PERMISSION, and the distinction is the reason the class carries
 * a docblock at all. Holding the chair grants nothing in RBAC: every act in this
 * subsystem is gated by {@see \Whity\Core\RBAC\CorePermissions::CONVENING_MANAGE}
 * and `CONVENING_DECIDE`, resolved by the same {@see \Whity\Auth\RoleChecker}
 * every other route uses. A body could be chaired by somebody who may not so
 * much as read the tenant's meetings, and that would be a configuration mistake
 * rather than a privilege escalation.
 *
 * What the seat IS for is {@see DecisionRouteBridge}: when a body's decision has
 * to be answered to the routing engine, the engine will only accept it from
 * somebody the route actually asked, and the seats give a defensible ORDER in
 * which to look for such a person. A chair speaks for the body by construction;
 * a secretary is who minutes it; an ordinary member is the last resort. That
 * order decides only WHOSE NAME goes on the routing act among people who ALL
 * already hold an open recipient row — it can never manufacture authority for
 * somebody the route did not reach.
 *
 * Three values and no more. `deputy chair`, `observer` and `co-opted member` are
 * all real seats in real institutions and all of them are the same three
 * questions to this code — may they speak for the body, do they minute it, are
 * they on it — so they are a LABEL an organisation puts on a membership, not a
 * fourth branch here. A vocabulary that grows with every institution's
 * constitution stops being a vocabulary.
 */
final class MemberRole
{
    /** Speaks for the body. First candidate to carry its decision to a route. */
    public const CHAIR = 'chair';

    /** Minutes the body. Second candidate, and usually the person recording. */
    public const SECRETARY = 'secretary';

    /** Sits on the body. */
    public const MEMBER = 'member';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::CHAIR, self::SECRETARY, self::MEMBER];
    }

    public static function isValid(string $role): bool
    {
        return in_array($role, self::all(), true);
    }

    /**
     * How strongly a seat speaks for the body, lower first.
     *
     * Used ONLY to order candidates in {@see DecisionRouteBridge}. It is a
     * function rather than a bare array so a caller cannot accidentally sort by
     * the alphabetical order of the constants, which would put `chair` after
     * neither and `member` before `secretary` — an order with no meaning that
     * would still look deliberate.
     */
    public static function precedence(string $role): int
    {
        return match ($role) {
            self::CHAIR => 0,
            self::SECRETARY => 1,
            default => 2,
        };
    }
}
