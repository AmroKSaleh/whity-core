<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Http\JsonBody;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Centralized request-body validation layer (WC-189).
 *
 * A single pipeline middleware that validates the request-body ENVELOPE
 * UNIFORMLY for the body-carrying, state-changing methods (POST/PUT/PATCH/DELETE)
 * so each auth + CRUD handler no longer re-implements ad-hoc
 * `json_decode($body, true)` + null/shape checks. Two rules, both collapsing to
 * a single GENERIC 400 that never leaks parser/exception detail (consistent with
 * the WC-186 no-leakage rule):
 *
 *  1. Size  — a body larger than {@see self::$maxBytes} (default
 *     {@see self::DEFAULT_MAX_BYTES} = 1 MiB, override via the
 *     `MAX_REQUEST_BODY_BYTES` env var or the constructor) is refused. A JSON API
 *     surface never legitimately receives a megabyte-scale body, so the cap is a
 *     cheap DoS / accidental-upload guard applied before any decode allocates.
 *
 *  2. Shape — a non-empty body must be WELL-FORMED JSON and a JSON OBJECT
 *     (`{...}`). Malformed JSON, a top-level array, or a bare scalar/null are all
 *     refused. Crucially the raw `json_last_error` text is NEVER returned; the
 *     client only ever sees the generic message.
 *
 * The `Content-Type` header is INTENTIONALLY NOT enforced. CSRF protection is
 * handled separately via the `X-Requested-With: XMLHttpRequest` requirement
 * (WC-160), and the platform's own API clients send JSON bodies without always
 * setting `Content-Type: application/json` (a string `fetch` body defaults to
 * `text/plain`). Requiring the header here rejected legitimate same-origin CRUD
 * writes, so the contract is the body SHAPE/SIZE — not its declared media type.
 *
 * On success the decoded associative array is stashed on the request under
 * {@see JsonBody::ATTR_PARSED_BODY} so downstream handlers read it via
 * {@see JsonBody::parsed()} instead of decoding php://input a second time (it is
 * read-once on a persistent worker).
 *
 * Deliberate non-targets: reads (GET/HEAD/OPTIONS) carry no body worth
 * validating and pass through untouched, as does a genuinely EMPTY body on any
 * method (a no-op PATCH/DELETE is legitimate) — those resolve to an empty array
 * for the handler. The middleware runs EARLY in the pipeline (before CSRF /
 * tenant resolution / RBAC) so a malformed or oversized envelope is rejected
 * before any auth or database work.
 */
final class RequestBodyValidator
{
    /**
     * Default maximum accepted request-body size: 1 MiB.
     *
     * A JSON API surface never legitimately receives more; the cap guards
     * against accidental large uploads and trivial memory-pressure DoS.
     */
    public const DEFAULT_MAX_BYTES = 1_048_576;

    /**
     * Methods whose body is validated. Reads are exempt (nothing to validate).
     *
     * @var list<string>
     */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** The single, stable message returned for every envelope rejection. */
    private const GENERIC_MESSAGE = 'Invalid request body';

    private int $maxBytes;

    /**
     * @param int|null $maxBytes Maximum accepted body size in bytes. When null,
     *                           resolved from the `MAX_REQUEST_BODY_BYTES` env var,
     *                           falling back to {@see self::DEFAULT_MAX_BYTES}.
     */
    public function __construct(?int $maxBytes = null)
    {
        $this->maxBytes = $maxBytes ?? self::maxBytesFromEnv();
    }

    /**
     * Validate the request-body envelope, rejecting violations with a generic 400.
     *
     * @param Request  $request The incoming HTTP request.
     * @param callable $next    The next middleware/handler in the pipeline.
     * @return Response HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        if (!in_array($request->getMethod(), self::BODY_METHODS, true)) {
            return $next($request);
        }

        $body = $request->getBody();

        // An empty body is always legitimate (e.g. a no-op PATCH/DELETE). It is
        // not subjected to a shape check; the handler sees [].
        if (trim($body) === '') {
            $request->setAttribute(JsonBody::ATTR_PARSED_BODY, []);
            return $next($request);
        }

        // 1. Size — checked before any decode so an oversized payload never
        //    allocates a decoded structure.
        if (strlen($body) > $this->maxBytes) {
            return $this->reject();
        }

        // Content-Type is deliberately NOT inspected — see the class docblock.
        // CSRF is enforced via X-Requested-With (WC-160) and the platform's API
        // clients post JSON bodies without always labeling them application/json.

        // 2. Shape — well-formed JSON object only. json_decode never throws here;
        //    a null result with a non-"null" body signals a parse error, which we
        //    collapse into the generic rejection WITHOUT exposing json_last_error.
        //    A NON-EMPTY top-level list (`[1,2,3]`) and bare scalars/null are
        //    rejected; the empty case (`{}` and `[]` both decode to []) is
        //    accepted as an empty object — handlers read named keys, so it is
        //    harmless and `{}` is a legitimate body for an all-optional payload.
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return $this->reject();
        }

        /** @var array<string, mixed> $decoded */
        $request->setAttribute(JsonBody::ATTR_PARSED_BODY, $decoded);

        return $next($request);
    }

    /**
     * Build the single generic 400 used for every envelope violation.
     *
     * Stable, detail-free message: never the raw parser/exception text.
     */
    private function reject(): Response
    {
        return Response::error(self::GENERIC_MESSAGE, 400);
    }

    /**
     * Resolve the configured maximum body size from the environment.
     *
     * A non-positive or non-numeric value falls back to the safe default so a
     * misconfiguration can never disable the cap.
     *
     * @return int The maximum accepted body size in bytes.
     */
    private static function maxBytesFromEnv(): int
    {
        $raw = $_ENV['MAX_REQUEST_BODY_BYTES'] ?? getenv('MAX_REQUEST_BODY_BYTES');
        if (is_string($raw) && ctype_digit(trim($raw)) && (int) trim($raw) > 0) {
            return (int) trim($raw);
        }

        return self::DEFAULT_MAX_BYTES;
    }
}
