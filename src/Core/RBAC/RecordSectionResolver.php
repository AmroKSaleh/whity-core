<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Auth\RoleChecker;

/**
 * Per-REGION authorization for a record page (#910).
 *
 * The requirement, in the operator's words after using the roles and users
 * record pages: *"some parts have permissions, not always everything is
 * allowed."* A record page is composed of regions — the role's details, its
 * permission set, who holds it — and who may SEE a region and who may CHANGE it
 * are different questions with different answers.
 *
 * WHY THE SERVER ANSWERS THEM
 * ---------------------------
 * Because the alternative is a browser holding an opinion about authorization. A
 * client that ANDs two capability slugs into a third has invented a rule the
 * deployment never granted, and the two answers then differ in whichever
 * direction the client's copy was last edited — the same defect
 * {@see \Whity\Api\PermittedActionsApiHandler} exists to prevent for actions.
 * Worse, a client-side decision can only ever hide what was already sent: it can
 * suppress a region, not withhold it. This resolver runs where the record is
 * assembled, so the same decision that says "hidden" is the one that leaves the
 * region's data out of the response.
 *
 * THE THREE STATES, AND HOW THEY REACH THE WIRE
 * ---------------------------------------------
 * `hidden` HAS NO WIRE REPRESENTATION. A hidden region is simply not a key in
 * the returned map, and the caller's payload does not carry its data either. A
 * response that said `{"permissions": {"state": "hidden"}}` would be telling a
 * caller the exact thing withholding the region was for — that there IS a
 * permissions region, and that they were refused it. Absence is not a weaker
 * form of that statement; it is the absence of the statement.
 *
 * `read-only` and `editable` are the two states a caller is entitled to know
 * about, and a `read-only` verdict carries a DENIAL in the shape #951/#968
 * settled for a denied crud control — `{code, reason, detail}` — because it is
 * the same idea one level up. That PR's finding was that a control which is
 * merely ABSENT collapses unrelated causes into one symptom, so a correct screen
 * the viewer has no rights on is pixel-identical to a broken one. A region is a
 * bigger control; the fix is the same fix, and reusing the shape means nobody
 * has to learn a second vocabulary for the same question.
 *
 *  - `code`   the stable discriminant a client keys a localized string off.
 *  - `reason` audience-safe English prose, and the i18n FALLBACK. The server has
 *             to send prose because the desktop and mobile renderers read this
 *             too and have no catalogue to key against.
 *  - `detail` the operator-grade half, naming the exact permission the write
 *             would need — sent only to a caller the SERVER decided may read it.
 *             #968 gates its equivalent on `plugins:read`, the permission that
 *             already gates the audience who can act on it; the audience here is
 *             passed in, because who may be shown a permission slug is the
 *             record endpoint's judgement rather than this class's.
 *
 * WHAT A REGION DECLARES
 * ----------------------
 * A {@see RecordSectionRequirement} per region, listed once beside the endpoint
 * that serves the record: the permission that governs SEEING it, the permission
 * that governs CHANGING it, and whether the record's own write predicate
 * (ownership, lifecycle state, a lock) also applies. Declarative, so a second
 * record endpoint adopts it by writing a list rather than by copying a resolver,
 * and so the set of regions a page has is readable in one place instead of
 * inferred from the branches that render them.
 *
 * WHAT IT IS NOT
 * --------------
 * It is not enforcement. A verdict decides what a caller is SHOWN; the write
 * path must refuse the same thing independently, because a gate that only
 * renders is decoration — the browser is not where the request comes from. The
 * handler is expected to call {@see self::mayWrite()} on the body it received,
 * and #910 says so in as many words: "granular editing is not only a rendering
 * concern."
 */
final class RecordSectionResolver
{
    /**
     * The caller does not hold the permission this region's write requires.
     *
     * Distinct from {@see self::CODE_RECORD} because the remedies differ: this
     * one is fixed by a grant, and the other cannot be fixed by one at all.
     */
    public const CODE_PERMISSION = 'permission';

    /**
     * The RECORD refuses the write, whatever the caller holds.
     *
     * A global base role only the system tenant may edit, a closed period, a
     * locked document. Telling the two apart matters: an operator who reads "you
     * lack a permission" goes looking for a grant that would not have helped.
     */
    public const CODE_RECORD = 'record';

    public function __construct(private readonly RoleChecker $roleChecker)
    {
    }

    /**
     * Resolve every region's verdict for one caller and one record.
     *
     * @param list<RecordSectionRequirement> $requirements   The record's regions, declared once.
     * @param int                            $profileId      The CALLER's profile (never read from input).
     * @param int                            $tenantId       The request's resolved tenant (0 = system).
     * @param bool                           $recordWritable The record's own write predicate — the
     *                                                       per-record rule no route table can express
     *                                                       (for a role: `roleManageableByTenant()`).
     * @param string|null                    $recordReason   Audience-safe prose for a RECORD refusal,
     *                                                       supplied by the endpoint because only it
     *                                                       knows what its own records are ("a global
     *                                                       base role", "a closed period").
     * @param bool                           $includeDetail  Whether this caller may be shown the
     *                                                       operator-grade half, which names permission
     *                                                       slugs. The ENDPOINT decides that audience —
     *                                                       see the class docblock.
     * @return array<string, array{state: string, denial: array{code: string, reason: string,
     *         detail: string|null}|null}> Verdicts by region key. A region the caller may not SEE is
     *         absent from this map, which is the only way this contract has of saying so.
     */
    public function resolve(
        array $requirements,
        int $profileId,
        int $tenantId,
        bool $recordWritable,
        ?string $recordReason = null,
        bool $includeDetail = false
    ): array {
        $verdicts = [];

        foreach ($requirements as $requirement) {
            if (!$this->holds($requirement->readPermission, $profileId, $tenantId)) {
                // Hidden. No key, no denial, no trace — see the class docblock.
                continue;
            }

            // Ordered so the more fundamental refusal is the one reported: a
            // caller who lacks the permission AND is looking at a record nobody
            // can edit here is told about the permission, because that is the
            // gate that would still refuse them on a record that was writable.
            $denial = null;
            if (!$this->holds($requirement->writePermission, $profileId, $tenantId)) {
                $denial = [
                    'code' => self::CODE_PERMISSION,
                    'reason' => $requirement->deniedReason
                        ?? 'You do not have permission to change this.',
                    // The one identifier worth naming, and the reason `detail`
                    // is gated at all: a permission slug enumerates part of the
                    // RBAC taxonomy for somebody who does not hold it.
                    'detail' => $includeDetail && $requirement->writePermission !== null
                        ? sprintf(
                            "changing this requires the '%s' permission",
                            $requirement->writePermission
                        )
                        : null,
                ];
            } elseif ($requirement->recordScoped && !$recordWritable) {
                $denial = [
                    'code' => self::CODE_RECORD,
                    'reason' => $recordReason ?? 'This record cannot be changed here.',
                    // Nothing to add. A record refusal is not fixable by a grant,
                    // so there is no slug that would help anyone reading it.
                    'detail' => null,
                ];
            }

            $verdicts[$requirement->key] = $denial === null
                ? ['state' => 'editable', 'denial' => null]
                : ['state' => 'read-only', 'denial' => $denial];
        }

        return $verdicts;
    }

    /**
     * Whether this caller may SEE one named region.
     *
     * Needed wherever a region's data is reachable by a route of its own —
     * `GET /roles/{id}/permissions` serves the same rows the `permissions`
     * region carries, so withholding the region from the record while that route
     * answered would be a gate with a bypass one path segment away.
     *
     * @param list<RecordSectionRequirement> $requirements
     */
    public function mayRead(
        array $requirements,
        string $key,
        int $profileId,
        int $tenantId
    ): bool {
        foreach ($requirements as $requirement) {
            if ($requirement->key === $key) {
                return $this->holds($requirement->readPermission, $profileId, $tenantId);
            }
        }

        // An undeclared region is not readable, for the same reason it is not
        // writable: a typo in a key must not be a way past a gate.
        return false;
    }

    /**
     * Whether this caller may WRITE one named region — the enforcement half.
     *
     * Deliberately not "is the verdict editable": a handler asking this has a
     * body in hand and needs a yes/no about the write it is about to perform,
     * and routing that through a map built for rendering would make enforcement
     * depend on a structure shaped for a different purpose. Both paths call the
     * same two checks in the same order, so they cannot disagree; they simply
     * return different things to different callers.
     *
     * @param list<RecordSectionRequirement> $requirements
     */
    public function mayWrite(
        array $requirements,
        string $key,
        int $profileId,
        int $tenantId,
        bool $recordWritable
    ): bool {
        foreach ($requirements as $requirement) {
            if ($requirement->key !== $key) {
                continue;
            }
            // A caller who may not SEE a region may certainly not write it. Read
            // is checked rather than assumed to be implied by write: the two are
            // independent slugs, and "implied" is how a deployment that granted
            // one without the other gets a surprise.
            return $this->holds($requirement->readPermission, $profileId, $tenantId)
                && $this->holds($requirement->writePermission, $profileId, $tenantId)
                && (!$requirement->recordScoped || $recordWritable);
        }

        // An undeclared region is not writable. A typo in a key must not be a
        // way past a gate.
        return false;
    }

    /**
     * A single permission check, with `null` meaning "no permission gates this".
     *
     * `null` is a deliberate, readable "this region has no gate of its own"
     * rather than an accident of an unset field: a record's primary details are
     * usually governed by the route that served the record, and inventing a slug
     * for them would be a gate nobody could grant.
     */
    private function holds(?string $permission, int $profileId, int $tenantId): bool
    {
        if ($permission === null) {
            return true;
        }
        return $this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId);
    }
}
