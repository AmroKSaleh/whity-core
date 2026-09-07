<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

/**
 * What the server did when a QR code was scanned (#1036).
 *
 * A CLOSED vocabulary matching `document_qr_scans`' CHECK constraint (migration
 * 120), for the reason {@see QrRevocationReason} is closed.
 *
 * DERIVED AT INSERT, NEVER REVISED. The outcome is read off the token row at the
 * moment of the scan and written once. A scan refused because the code had been
 * withdrawn STAYS `refused` in the trail even after a new code is minted for the
 * same document — which is exactly the fact somebody investigating a disputed
 * document needs, and exactly what a re-derived value would destroy.
 *
 * NOTE WHAT IS ABSENT: there is no `unknown`. A scan of a code that resolves to
 * nothing has no token row to hang off and no tenant to belong to, so it is not
 * recorded at all — see migration 122 for why recording it would also hand an
 * anonymous caller an unbounded write.
 */
final class QrScanOutcome
{
    /**
     * The code resolved to a live document and the public page confirmed it.
     */
    public const VERIFIED = 'verified';

    /**
     * The code resolved, but it had been withdrawn or superseded, so the page
     * did not confirm the document.
     *
     * The row exists because this is the interesting scan: somebody is holding
     * paper the organisation has stopped standing behind, and the organisation
     * should be able to see that it is still in circulation.
     */
    public const REFUSED = 'refused';

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
        return [self::VERIFIED, self::REFUSED];
    }

    /** Whether a string is one of the two outcomes. */
    public static function isKnown(string $outcome): bool
    {
        return in_array($outcome, self::all(), true);
    }
}
