<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * Thrown by {@see RenderServiceClient} whenever the `whity_render` microservice
 * cannot be reached, times out, returns a non-2xx status, or returns a body
 * that does not look like a PDF (ADR 0012). Always caught at the API-handler
 * boundary and mapped to a generic 503 — the message here is for SERVER-SIDE
 * logs only; it must never reach a client verbatim (WC-186: no raw exception
 * text in a response body).
 */
final class RenderServiceUnavailableException extends \RuntimeException
{
}
