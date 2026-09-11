<?php

declare(strict_types=1);

namespace Whity\Core\Licensing;

use RuntimeException;

/**
 * A licensing operation that could not proceed.
 *
 * ITS MESSAGE IS SHOWN TO THE PERSON WHO TYPED THE CODE, which is unusual for
 * an exception in this codebase and is the reason this class exists rather than
 * a bare RuntimeException. The redeeming caller may be a student with no
 * account and no support contact, so "This code has expired — please ask for a
 * replacement" is the entire difference between them solving their own problem
 * and someone opening a ticket.
 *
 * So the messages are written for that reader: what happened, and what to do.
 * They must never contain a tenant name, a device serial, an internal id, or
 * anything that distinguishes "no such code" from "a code you are not allowed
 * to use" — an unauthenticated caller holding a well-formed guess must not be
 * able to learn which codes exist. {@see ActivationService::explainFailedRedemption()}
 * is where that line is drawn.
 */
final class LicensingException extends RuntimeException
{
}
