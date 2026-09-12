<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use RuntimeException;

/**
 * The billing service could not be asked, or did not answer intelligibly.
 *
 * CARRIES A REASON CODE, NEVER A MESSAGE TO ECHO. The text here is for an
 * operator reading a log: it can name a host, a status line, a transport error.
 * Handlers map {@see self::$reason} to their own wording, because a handler that
 * interpolated `getMessage()` would publish whatever any future throw site
 * happened to say — including, one day, something carrying a URL with a key in
 * it.
 *
 * DISTINGUISHING UNREACHABLE FROM REFUSED IS THE POINT. "The service is down"
 * and "the service says no" look identical to a caller that only sees an
 * exception, and they must not be treated alike: a tenant whose access check
 * failed because of a timeout must keep what they have, while a tenant the
 * service actively reports as lapsed must lose it. Collapsing the two is how an
 * outage in a payment service becomes an outage in the product.
 */
final class BillingPortalException extends RuntimeException
{
    /** The service could not be reached, or did not answer in time. */
    public const REASON_UNREACHABLE = 'unreachable';
    /** It answered, but not with something this code can read. */
    public const REASON_UNREADABLE = 'unreadable';
    /** It refused the request — a bad key, or a rejected return host. */
    public const REASON_REFUSED = 'refused';
    /** No billing service is configured on this deployment. */
    public const REASON_NOT_CONFIGURED = 'not_configured';

    private function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function unreachable(string $detail): self
    {
        return new self(self::REASON_UNREACHABLE, $detail);
    }

    public static function unreadable(string $detail): self
    {
        return new self(self::REASON_UNREADABLE, $detail);
    }

    public static function refused(string $detail): self
    {
        return new self(self::REASON_REFUSED, $detail);
    }

    public static function notConfigured(): self
    {
        return new self(
            self::REASON_NOT_CONFIGURED,
            'no billing service is configured on this deployment'
        );
    }

    /**
     * Whether this means "we do not know" rather than "the answer is no".
     *
     * The reconciler and the access check both need this distinction to decide
     * whether silence is safe to act on. It is a method rather than a
     * comparison at each call site so there is one place to be right.
     */
    public function isTransient(): bool
    {
        return $this->reason === self::REASON_UNREACHABLE;
    }
}
