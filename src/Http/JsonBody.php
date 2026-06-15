<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Sdk\Http\Request;

/**
 * Centralized accessor for the validated JSON request body (WC-189).
 *
 * Before this helper every handler re-implemented the same idiom:
 *
 *     $body = json_decode($request->getBody(), true);
 *     if (!is_array($body)) { $body = []; }
 *
 * scattered across the auth + CRUD handlers with subtly different fallbacks
 * (some returned 400, some silently coerced to []). The body ENVELOPE
 * (size / content-type / well-formed JSON object) is now validated UNIFORMLY,
 * once, by {@see \Whity\Http\Middleware\RequestBodyValidator}, which stashes the
 * decoded associative array on the request under {@see self::ATTR_PARSED_BODY}.
 *
 * Handlers call {@see self::parsed()} to read that pre-validated array instead of
 * decoding php://input a second time (it is read-once, so a re-decode in the
 * worker would yield an empty string anyway). When the request never passed
 * through the middleware — e.g. a unit test exercising a handler in isolation, or
 * any future non-HTTP caller — the accessor falls back to a defensive decode that
 * mirrors the historical "non-object => empty array" behavior, so handler logic
 * is unchanged in those paths.
 */
final class JsonBody
{
    /**
     * Request attribute under which the validator stashes the decoded body.
     *
     * @see \Whity\Http\Middleware\RequestBodyValidator
     */
    public const ATTR_PARSED_BODY = 'body.parsed';

    /**
     * Return the validated request body as an associative array.
     *
     * Reads the value the validation middleware stashed; if absent (the request
     * bypassed the pipeline), defensively decodes the raw body, returning an
     * empty array for anything that is not a JSON object.
     *
     * @param Request $request The incoming HTTP request.
     * @return array<string, mixed> The decoded body, or an empty array.
     */
    public static function parsed(Request $request): array
    {
        if ($request->hasAttribute(self::ATTR_PARSED_BODY)) {
            /** @var array<string, mixed> $stashed */
            $stashed = $request->getAttribute(self::ATTR_PARSED_BODY, []);
            return $stashed;
        }

        return self::decode($request->getBody());
    }

    /**
     * Decode a raw body string into an associative array.
     *
     * Returns an empty array for an empty body, malformed JSON, or any
     * well-formed-but-non-object JSON value (array/scalar/null). Never throws and
     * never surfaces parser detail — callers receive only a usable array.
     *
     * @param string $raw The raw request body.
     * @return array<string, mixed> The decoded object, or an empty array.
     */
    public static function decode(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        // A JSON object decodes to a non-list array; reject NON-EMPTY lists and
        // scalars so handlers only ever see a `field => value` map. The empty case
        // (`{}` and `[]` both decode to []) is kept as an empty map.
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
