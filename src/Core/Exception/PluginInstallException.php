<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

use RuntimeException;
use Throwable;

/**
 * Base type for the staged plugin-upload/install failure modes (WC-220).
 *
 * Every installer rejection is one of the concrete subclasses below; the
 * endpoint maps each to a uniform `{error, details?}` envelope by type. The
 * base carries a SAFE, client-facing message (set via the constructor) plus an
 * optional bag of safe details — it NEVER embeds a stack trace or an internal
 * filesystem path in {@see self::clientMessage()} / {@see self::clientDetails()},
 * so a handler can surface those two without leaking host internals (WC-186).
 *
 * The original (possibly detail-rich) Throwable may be passed as `$previous`
 * for server-side logging only; it is deliberately not exposed to clients.
 */
abstract class PluginInstallException extends RuntimeException
{
    /**
     * Optional safe, client-surfaceable details (e.g. the offending entry name
     * or the violated cap). MUST be free of stack traces and absolute paths.
     *
     * @var array<string, scalar|null>
     */
    private array $clientDetails;

    /**
     * @param string $message A safe, client-facing message (no stack/paths).
     * @param array<string, scalar|null> $details Optional safe client details.
     * @param Throwable|null $previous Original cause, for server-side logging only.
     */
    public function __construct(string $message, array $details = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->clientDetails = $details;
    }

    /**
     * The safe message to surface to the client.
     *
     * @return string
     */
    public function clientMessage(): string
    {
        return $this->getMessage();
    }

    /**
     * The safe, client-surfaceable details (may be empty).
     *
     * @return array<string, scalar|null>
     */
    public function clientDetails(): array
    {
        return $this->clientDetails;
    }
}
