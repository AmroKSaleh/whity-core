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
 * `json_decode($body, true)` + null/shape checks. Three rules, all collapsing to
 * a single GENERIC 400 that never leaks parser/exception detail (consistent with
 * the WC-186 no-leakage rule):
 *
 *  1. Size  — a body larger than {@see self::$maxBytes} (default
 *     {@see self::DEFAULT_MAX_BYTES} = 1 MiB, override via the
 *     `MAX_REQUEST_BODY_BYTES` env var or the constructor) is refused. A JSON API
 *     surface never legitimately receives a megabyte-scale body, so the cap is a
 *     cheap DoS / accidental-upload guard applied before any decode allocates.
 *
 *  2. Type  — a NON-EMPTY body must declare `Content-Type: application/json`
 *     (an optional `; charset=...`/parameter suffix is allowed). This rejects
 *     form-encoded, multipart and mislabeled payloads up front so the JSON
 *     handlers below never have to defend against them.
 *
 *  3. Shape — a non-empty body must be WELL-FORMED JSON and a JSON OBJECT
 *     (`{...}`). Malformed JSON, a top-level array, or a bare scalar/null are all
 *     refused. Crucially the raw `json_last_error` text is NEVER returned; the
 *     client only ever sees the generic message.
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
        // not subjected to content-type or shape checks; the handler sees [].
        if (trim($body) === '') {
            $request->setAttribute(JsonBody::ATTR_PARSED_BODY, []);
            return $next($request);
        }

        // 1. Size — checked before any decode so an oversized payload never
        //    allocates a decoded structure.
        if (strlen($body) > $this->maxBytes) {
            return $this->reject();
        }

        // 2. Type — require a JSON content type for any non-empty body.
        if (!$this->isJsonContentType($request->getHeader('Content-Type'))) {
            return $this->reject();
        }

        // 3. Shape — well-formed JSON object only. json_decode never throws here;
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
     * Whether a Content-Type header declares `application/json`.
     *
     * Accepts an optional parameter suffix (e.g. `; charset=utf-8`) and is
     * case-insensitive, matching how browsers and HTTP clients emit the header.
     *
     * @param string|null $contentType The raw Content-Type header value.
     * @return bool True when the media type is application/json.
     */
    private function isJsonContentType(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        // Strip any `; charset=...` / parameter suffix, then compare the bare
        // media type case-insensitively.
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        return $mediaType === 'application/json';
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
