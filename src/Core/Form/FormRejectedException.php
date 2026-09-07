<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * A form, field or submission was refused for a reason the CALLER can act on.
 *
 * TWO MESSAGES, ONE EXCEPTION — AND WHY THE SPLIT IS A FIELD RATHER THAN A
 * HABIT
 * -----------------------------------------------------------------------
 * `$clientMessage` is written FOR the person on the other end of the request and
 * is safe to return verbatim. `getMessage()` is written for whoever is reading
 * the logs and may name a column, a row id, or a driver error.
 *
 * The two are separate FIELDS rather than a convention about how carefully to
 * word `getMessage()` for exactly the reason WC-186 records and
 * {@see \Whity\Core\TimeWindow\WindowRejectedException} restates: a convention is
 * kept by whoever remembers it, and the one time somebody forgets, an internal
 * detail is returned to an unauthenticated caller in a 422 body. A field cannot
 * be forgotten — a handler that returns `->getMessage()` is visibly doing
 * something different from every other catch site in the file.
 *
 * Every catch site in this subsystem returns `->clientMessage`, never
 * `->getMessage()`.
 */
final class FormRejectedException extends \RuntimeException
{
    /**
     * @param string $clientMessage Text written for the caller, safe to return.
     * @param string $logMessage    Text written for the operator; defaults to the
     *                              client message when there is nothing extra to
     *                              say, so the common case stays a one-argument
     *                              call.
     */
    public function __construct(
        public readonly string $clientMessage,
        string $logMessage = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $clientMessage, 0, $previous);
    }
}
