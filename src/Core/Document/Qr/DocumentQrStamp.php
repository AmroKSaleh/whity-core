<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

/**
 * The two values a verification code puts on a page (#1036): the URL the QR
 * encodes, and the short reference printed beneath it.
 *
 * A VALUE OBJECT RATHER THAN TWO STRING PARAMETERS, for one reason that is worth
 * more than it looks: they are both strings, both derived from the same token,
 * and passing them positionally is passing two interchangeable arguments through
 * three call frames. Swapping them would encode `9F2A-4C11-8B03` into the QR and
 * print a URL underneath — which every unit test that checks "a code was placed"
 * would still pass, and which nobody would notice until somebody scanned a
 * printed document.
 *
 * ITS PRESENCE IS ALSO THE ON/OFF SIGNAL. {@see \Whity\Core\Document\Render\DocumentRenderer::render()}
 * takes `?DocumentQrStamp`: non-null means this document carries a code and the
 * renderer must ensure one is placed; null means it does not and the renderer
 * must REMOVE any that was authored, rather than leaving it to resolve to
 * nothing and print an empty box. One nullable parameter, two enforced
 * behaviours, and no way to ask for the code without supplying its payload.
 */
final class DocumentQrStamp
{
    /**
     * @param string $url       The absolute public verification page URL.
     * @param string $reference The human-readable reference printed beneath it.
     */
    public function __construct(
        public readonly string $url,
        public readonly string $reference,
    ) {
    }

    /**
     * The stamp for a live token.
     */
    public static function forToken(DocumentQrService $qr, string $token): self
    {
        return new self($qr->verificationUrl($token), $qr->reference($token));
    }
}
