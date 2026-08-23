<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

/**
 * What ONE region of a record page needs, declared once (#910).
 *
 * The declaration is the point. A record page is composed of regions, and the
 * alternative to naming their requirements in a list is discovering them by
 * reading the branches that render them — at which point "which parts of this
 * record are gated, and on what" is a question only answerable by grepping.
 * Listed beside the endpoint that serves the record, the set is readable, and a
 * region added without a requirement is visibly missing one.
 *
 * It is also the shape a plugin-declared region can eventually take. #909's
 * `accessGate` lets a DESCRIBED page ask the host "may I make this request", and
 * this is the same question asked at the record's own endpoint, where the answer
 * can also decide what the payload carries. Neither lets the declaring side
 * assert its own authority: a plugin names the region, the host resolves it
 * (#895's constraint, carried forward).
 */
final class RecordSectionRequirement
{
    /**
     * @param string      $key             The region's stable key. The SAME string the client's
     *                                     section spec uses — one name, so a verdict and the region
     *                                     it governs cannot drift apart.
     * @param string|null $readPermission  The permission required to SEE the region, or null when the
     *                                     route that served the record is the only gate. A caller
     *                                     without it gets no verdict and no data for this region.
     * @param string|null $writePermission The permission required to CHANGE it, or null when seeing it
     *                                     is enough to change it. Who may see a role's permissions and
     *                                     who may set them are the two different questions #910 is
     *                                     about, so these are two fields rather than one.
     * @param bool        $recordScoped    Whether the RECORD's own write predicate also applies —
     *                                     ownership, a lifecycle state, a lock. False for a region a
     *                                     permission alone governs.
     * @param string|null $deniedReason    Audience-safe English prose for a PERMISSION refusal of this
     *                                     region, and the client's i18n fallback. Declared beside the
     *                                     slug rather than composed from it: "changing this needs
     *                                     roles:manage" is a slug restated, and a reader who does not
     *                                     know the taxonomy learns nothing from it. It also must never
     *                                     name the slug, which is what `detail` is for and what the
     *                                     server gates.
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $readPermission = null,
        public readonly ?string $writePermission = null,
        public readonly bool $recordScoped = true,
        public readonly ?string $deniedReason = null,
    ) {
    }
}
