<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

/**
 * Why a printed QR code stopped being honoured (#1036).
 *
 * A CLOSED vocabulary, matching `document_qr_tokens`' CHECK constraint
 * (migration 120), for the reason {@see \Whity\Core\Document\Routing\RouteAction}
 * is closed one subsystem over: a column that accepts any string is a column
 * whose readers must handle any string, and the first typo becomes a permanent
 * row nothing renders and nothing can correct.
 *
 * Unlike routing's rule KINDS, which plugins extend, the ways a code can stop
 * being honoured are core's own semantics. A plugin adding a sixth verb would be
 * adding a lifecycle core does not implement.
 *
 * TWO VERBS, AND THEY MEAN DIFFERENT THINGS TO THE PERSON HOLDING THE PAPER:
 *
 *   WITHDRAWN  — somebody decided this code is not to be trusted. The paper may
 *                be a forgery, may have been issued in error, or the document
 *                may have been rescinded. Nothing replaces it.
 *   SUPERSEDED — a NEW code was minted for the same document, so the paper in
 *                hand is an older printing. The document is fine; this copy of
 *                it is not the current one.
 *
 * Both produce the SAME public answer at the default disclosure level — see
 * {@see VerificationPresenter} for why an anonymous caller is told neither by
 * default.
 */
final class QrRevocationReason
{
    /**
     * An operator stopped honouring this code deliberately.
     *
     * The act #1036 exists to make possible: paper cannot be recalled, so the
     * only recall available is the server refusing to confirm it.
     */
    public const WITHDRAWN = 'withdrawn';

    /**
     * A newer code was minted for the same document, retiring this one.
     *
     * Written by {@see DocumentQrService::mint()} when it rotates, never by a
     * caller — which is why there is no API route that names this reason.
     */
    public const SUPERSEDED = 'superseded';

    private function __construct()
    {
    }

    /**
     * The whole vocabulary, in the order the CHECK constraint lists it.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::WITHDRAWN, self::SUPERSEDED];
    }

    /** Whether a string is one of the two verbs. */
    public static function isKnown(string $reason): bool
    {
        return in_array($reason, self::all(), true);
    }
}
