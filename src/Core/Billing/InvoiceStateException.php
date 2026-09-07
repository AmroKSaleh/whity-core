<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use RuntimeException;

/**
 * An invoice was asked to do something its current state does not permit —
 * editing an issued one, issuing an empty one, issuing one twice.
 *
 * Distinct from a payment failure, and deliberately so: this is a programming
 * or workflow error, it will not resolve on retry, and it must never reach the
 * dunning machine as evidence that a tenant has not paid.
 */
final class InvoiceStateException extends RuntimeException
{
}
