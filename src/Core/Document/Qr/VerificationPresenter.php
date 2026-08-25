<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Settings\SettingsRegistry;

/**
 * What a STRANGER is told when they scan a document (#1036).
 *
 * This class is the entire public disclosure surface of the feature, and it is
 * one class on purpose: "what may an anonymous caller learn" is the question
 * most likely to be answered slightly differently in three places, and the
 * difference would be invisible until somebody complained.
 *
 * THE AUDIENCE
 * ------------
 * A courier, a ministry clerk, a citizen holding a printed decision. None of
 * them is a user of the tenant, none has a session, and the reason the public
 * half exists at all is that they need to know the paper is real. They are also,
 * in the worst case, somebody who found the paper in a bin.
 *
 * So the default is the MINIMUM that satisfies verification, and everything
 * beyond it is the tenant's decision — `documents.qr_public_detail`, resolved
 * per-tenant ?? global ?? registry default like every other tunable.
 *
 * WHAT `minimal` DISCLOSES, AND WHY EACH FIELD EARNS ITS PLACE
 * ------------------------------------------------------------
 *   verified   — the answer they came for.
 *   issuer     — the ORGANISATION's name. "Issued by X" is the fact that makes
 *                verification meaningful: a document that verifies without
 *                naming who stands behind it verifies nothing.
 *   issued_on  — the DATE, not the timestamp. A date is what is printed on the
 *                paper and what a holder can compare; the time of day is an
 *                internal detail with no verification value.
 *   reference  — a prefix of the token the holder already has, formatted to be
 *                read aloud. It lets somebody confirm the page in front of them
 *                belongs to the sheet in their hand.
 *
 * WHAT IT DELIBERATELY DOES NOT DISCLOSE, AT ANY LEVEL
 * ----------------------------------------------------
 *   The TITLE, and any content, recipient, note or attachment. #1036 is explicit
 *   and the reason is that a title is content: "Termination of employment —
 *   Fatima Al-Amin" verifies nothing extra and discloses everything.
 *
 *   The DOCUMENT ID. Not because it is a secret — it is not, and RBAC does not
 *   depend on it being one — but because there is no reason to hand it out, and
 *   the signed-in path does not need it from here: a caller with reach asks
 *   `GET /api/v1/documents/by-verification/{token}`, which is an ordinary gated
 *   route, and a caller without reach gets the 404 they get today.
 *
 *   ANY PERSON'S OR UNIT'S NAME. This matters for a reason beyond privacy, and
 *   it is #1019: resolving a person's or a unit's NAME in this platform
 *   currently requires `users:read` / `ous:read` — a directory-LISTING right. An
 *   anonymous caller holds no rights at all, so a public page that named the
 *   issuing officer or the unit a document sits in would either need a
 *   permission nobody can hold, or would have to bypass the check and become
 *   the directory-disclosure route the permission exists to prevent. Naming the
 *   ORGANISATION avoids the question entirely: `tenants.name` is not directory
 *   data, it is the identity of the party doing the issuing, and it is read
 *   through the token's own `tenant_id` rather than through anything the caller
 *   said.
 *
 * WHAT `stage` ADDS, AND WHERE IT STOPS
 * -------------------------------------
 * A closed VERB from {@see RouteAction} — `issued`, `forwarded`,
 * `acknowledged`, `returned`, `noted` — and its date. That answers "has this
 * been acted on since it was issued", which some institutions want a holder to
 * be able to see.
 *
 * It stops short of "awaiting the Dean's approval", which #1036 raises as the
 * example of a leak. Naming the UNIT or the PERSON a document is currently with
 * discloses the organisation's internal structure and workload to whoever picked
 * the paper up, and it runs straight back into #1019. A tenant that genuinely
 * wants that is asking for a third disclosure level and should get one
 * deliberately, with that trade written down, rather than inheriting it from a
 * level that reads like it only adds a status word.
 *
 * `stage` also makes a REVOKED code distinguishable from an unrecognised one,
 * which is the "unless the tenant has chosen otherwise" half of #1036's
 * existence-oracle requirement. See {@see refusal()}.
 */
final class VerificationPresenter
{
    /** The default: the minimum that satisfies verification. */
    public const DETAIL_MINIMAL = 'minimal';

    /** Adds the current routing stage, and tells a revoked code from an unknown one. */
    public const DETAIL_STAGE = 'stage';

    /**
     * The single answer every way a code can fail to verify collapses to at the
     * default disclosure level.
     *
     * Unknown, malformed, withdrawn and superseded are ONE string, so none of
     * them is distinguishable from the others and the endpoint cannot be used to
     * ask "does this document exist". #1036 requires exactly this, and
     * {@see \Whity\Api\InvitationAcceptHandler}'s `GENERIC_INVALID` is the same
     * discipline on the same kind of surface.
     */
    public const REASON_UNRECOGNISED = 'unrecognised';

    private function __construct()
    {
    }

    /**
     * The tenant's chosen disclosure level, defaulting safely.
     *
     * Anything unrecognised reads as `minimal`. The registry validates the value
     * on write, so an unknown one means the row was written by something that
     * bypassed the API — and the safe reading of a corrupted privacy setting is
     * the private one.
     *
     * @param array<string, string> $effectiveSettings
     */
    public static function detailLevel(array $effectiveSettings): string
    {
        $level = $effectiveSettings[SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL] ?? self::DETAIL_MINIMAL;

        return $level === self::DETAIL_STAGE ? self::DETAIL_STAGE : self::DETAIL_MINIMAL;
    }

    /**
     * The body for a code that verifies.
     *
     * @param array<string, mixed>      $tokenRow  From {@see DocumentQrTokenRepository::findByToken()}.
     * @param array<string, mixed>      $document  The `documents` row, read tenant-scoped.
     * @param array<string, mixed>|null $lastEvent The newest trail row, or null.
     * @return array<string, mixed>
     */
    public static function verified(
        array $tokenRow,
        array $document,
        string $reference,
        string $detailLevel,
        ?array $lastEvent,
    ): array {
        $body = [
            'verified' => true,
            'reference' => $reference,
            'issuer' => self::issuerName($tokenRow),
            'issued_on' => self::dateOnly($document['created_at'] ?? null),
        ];

        if ($detailLevel !== self::DETAIL_STAGE) {
            return $body;
        }

        // A document that was never circulated has no trail, and `issued` is the
        // honest word for it — it says the organisation raised it and nothing
        // has happened since, which is exactly true and is not a fabricated
        // routing event.
        $action = is_array($lastEvent) ? (string) ($lastEvent['action'] ?? '') : '';
        $body['stage'] = in_array($action, RouteAction::all(), true) ? $action : RouteAction::ISSUED;
        $body['stage_on'] = is_array($lastEvent)
            ? self::dateOnly($lastEvent['occurred_at'] ?? null)
            : $body['issued_on'];

        return $body;
    }

    /**
     * The body for a code that does not verify — unknown, malformed, withdrawn
     * or superseded.
     *
     * THE ORACLE QUESTION IS DECIDED HERE. At `minimal` all four collapse to one
     * string, so a caller probing tokens learns nothing about which ones name a
     * real document. The HTTP status is 200 for every one of them for the same
     * reason: a 404 for "unknown" beside a 200 for "withdrawn" would restore the
     * distinction the body just removed, which is the shape of mistake that gets
     * made when the two decisions live in different files.
     *
     * At `stage` a revoked code says so, because a tenant that has chosen to
     * tell holders where a document sits has already accepted that a holder
     * learns their paper exists — and "this printing has been replaced" is far
     * more useful to them than "unrecognised", which reads as "you scanned it
     * wrong".
     *
     * `revoked_on` is a DATE for the reason `issued_on` is.
     *
     * @param array<string, mixed>|null $tokenRow Null when nothing resolved.
     * @return array{verified: false, reason: string, revoked_on?: string|null}
     */
    public static function refusal(?array $tokenRow, string $detailLevel): array
    {
        if ($detailLevel !== self::DETAIL_STAGE
            || $tokenRow === null
            || !isset($tokenRow['revoked_at'])) {
            return ['verified' => false, 'reason' => self::REASON_UNRECOGNISED];
        }

        $reason = (string) ($tokenRow['revoked_reason'] ?? '');

        return [
            'verified' => false,
            'reason' => QrRevocationReason::isKnown($reason) ? $reason : self::REASON_UNRECOGNISED,
            'revoked_on' => self::dateOnly($tokenRow['revoked_at']),
        ];
    }

    /**
     * The organisation's name, or a neutral fallback.
     *
     * The `LEFT JOIN` that supplies it can yield null in exactly one state — a
     * tenant row that vanished between the token being minted and the code being
     * scanned, which the CASCADE makes vanishingly unlikely — and a public page
     * that printed "issued by " with nothing after it would look like a bug in
     * the thing somebody is trying to trust. The neutral string is not an
     * assertion about who issued it; it is the absence of one.
     *
     * @param array<string, mixed> $tokenRow
     */
    private static function issuerName(array $tokenRow): string
    {
        $name = $tokenRow['tenant_name'] ?? null;

        return is_string($name) && trim($name) !== '' ? $name : 'Unnamed organisation';
    }

    /**
     * A timestamp reduced to its date, on both engines.
     *
     * PostgreSQL returns `2026-08-25 14:02:11` and SQLite the same shape, so the
     * first ten characters are the date on either — but a value that is not a
     * string, or is shorter than a date, is passed through as null rather than
     * truncated into something that looks like a date and is not.
     */
    private static function dateOnly(mixed $timestamp): ?string
    {
        if (!is_string($timestamp) || strlen($timestamp) < 10) {
            return null;
        }

        return substr($timestamp, 0, 10);
    }
}
