<?php

declare(strict_types=1);

namespace Whity\Core\Payment;

use RuntimeException;

/**
 * A payment provider could not do what was asked, or said something the
 * platform cannot make sense of.
 *
 * Carries no customer-facing text. Handlers must not interpolate
 * {@see \Throwable::getMessage()} into a response — `ExceptionLeakageTest`
 * forbids it, and here the reason is sharper than usual: a provider's error
 * strings routinely contain merchant identifiers, endpoint URLs and occasionally
 * the tail of an instrument.
 */
class PaymentProviderException extends RuntimeException
{
}
