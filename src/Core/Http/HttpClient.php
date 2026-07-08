<?php

declare(strict_types=1);

namespace Whity\Core\Http;

/**
 * Minimal outbound HTTP client seam (WC-ae16 / OIDC engine).
 *
 * The relying-party engine depends on this interface (not a concrete client) so
 * it can be unit-tested with a stub, while the production {@see HttpFetcher}
 * performs real, SSRF-guarded requests. Only the two shapes the OIDC flow needs
 * are exposed: a JSON GET (discovery document, JWKS) and a form-encoded JSON POST
 * (token exchange).
 */
interface HttpClient
{
    /**
     * GET $url and decode a JSON object response.
     *
     * @return array<string, mixed>|null Decoded object, or null on transport
     *   failure / non-JSON / non-object body.
     */
    public function getJson(string $url): ?array;

    /**
     * POST application/x-www-form-urlencoded $params to $url and decode a JSON
     * object response.
     *
     * @param array<string, string> $params
     * @return array<string, mixed>|null Decoded object, or null on failure.
     */
    public function postForm(string $url, array $params): ?array;
}
