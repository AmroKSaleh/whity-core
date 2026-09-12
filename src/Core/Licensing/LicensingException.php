<?php

declare(strict_types=1);

namespace Whity\Core\Licensing;

use RuntimeException;

/**
 * A licensing operation that could not proceed.
 *
 * IT CARRIES A REASON CODE, NOT A SENTENCE, and that is the whole design. The
 * first version of this class put the user-facing wording in the exception
 * message and the handler returned it verbatim — which the exception-leakage
 * guard refused, correctly. A handler that echoes `$e->getMessage()` publishes
 * whatever any throw site happens to say, today and after every future edit,
 * and nobody reviews an exception message the way they review a response body.
 *
 * So the codes below are the contract, and the handler owns the words. That
 * makes the text an administrator can actually be shown a short, reviewable
 * list in one place, and it means adding a throw site cannot accidentally
 * publish an internal detail.
 *
 * The distinction still matters for the person typing the code: they may be a
 * student with no account and no support contact, so "this code expired" versus
 * "this code was already used" is the difference between solving their own
 * problem and giving up. The codes preserve that difference; they simply stop
 * the wording leaking out of the domain layer.
 */
final class LicensingException extends RuntimeException
{
    /** Well-formed but unusable, or not a code at all. Deliberately one reason. */
    public const REASON_INVALID = 'invalid';

    public const REASON_EXPIRED = 'expired';
    public const REASON_REVOKED = 'revoked';
    public const REASON_ALREADY_USED = 'already_used';

    /** The code is open and the caller named no device, or none that matched. */
    public const REASON_DEVICE_REQUIRED = 'device_required';
    public const REASON_DEVICE_UNKNOWN = 'device_unknown';

    /** Issuing-side problems. Never reached by an unauthenticated caller. */
    public const REASON_BAD_REDEMPTION_LIMIT = 'bad_redemption_limit';
    public const REASON_MINT_FAILED = 'mint_failed';

    private function __construct(public readonly string $reason, string $developerMessage)
    {
        // The message is for logs and stack traces. It is NOT what a caller
        // sees — see the class docblock, and the guard that enforces it.
        parent::__construct($developerMessage);
    }

    public static function of(string $reason, string $developerMessage): self
    {
        return new self($reason, $developerMessage);
    }
}
