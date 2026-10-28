<?php

declare(strict_types=1);

namespace Whity\Auth\Oidc;

/**
 * Fetches and caches provider JWKS (JSON Web Key Sets) for OIDC ID-token
 * verification (WC-24a1).
 *
 * Identity providers publish their signing public keys at a `jwks_uri` and
 * rotate them periodically. This provider caches each JWKS in-process with a TTL
 * (worker-scoped instance state — persists across requests on a FrankenPHP
 * worker, which is exactly the desired caching) and, because keys ROTATE, will
 * force a single refresh when asked for a `kid` the cached set does not contain
 * (a freshly-rotated key) before giving up.
 *
 * Network I/O is delegated to an injected fetcher `(string $jwksUri): array`, so
 * this class is deterministic and unit-testable; the composition root wires a
 * real HTTP GET.
 */
final class JwksProvider
{
    /** @var \Closure(string): array<string, mixed> */
    private \Closure $fetcher;

    private int $ttlSeconds;

    /** @var array<string, array{jwks: array<string, mixed>, fetchedAt: int}> */
    private array $cache = [];

    /**
     * Least time between two unknown-kid refreshes of one uri. 0 refreshes on
     * every miss, which suits a sign-in callback. A public endpoint such as /mcp
     * sets it, or anyone could send made-up `kid`s and make this server refetch
     * the issuer's keys on every request (WHIT-709).
     */
    private int $minRefreshSeconds;

    /**
     * @param callable(string): array<string, mixed> $fetcher Fetches + decodes a JWKS by uri.
     * @param int                                     $ttlSeconds Cache lifetime (default 1h).
     * @param int                                     $minRefreshSeconds See {@see $minRefreshSeconds}.
     */
    public function __construct(callable $fetcher, int $ttlSeconds = 3600, int $minRefreshSeconds = 0)
    {
        $this->fetcher = \Closure::fromCallable($fetcher);
        $this->ttlSeconds = $ttlSeconds;
        $this->minRefreshSeconds = $minRefreshSeconds;
    }

    /**
     * Return the JWKS for a uri, fetching (and caching) if absent or stale.
     *
     * @return array<string, mixed>
     */
    public function get(string $jwksUri): array
    {
        $entry = $this->cache[$jwksUri] ?? null;
        if ($entry !== null && (time() - $entry['fetchedAt']) < $this->ttlSeconds) {
            return $entry['jwks'];
        }

        return $this->refresh($jwksUri);
    }

    /**
     * Return a JWKS that contains $kid if at all possible: serve the cached set,
     * but if it lacks $kid (a rotated-in key), force ONE refresh before returning
     * — even within the TTL window. A null/empty kid just returns the cached set.
     *
     * @return array<string, mixed>
     */
    public function getForKid(string $jwksUri, ?string $kid): array
    {
        $jwks = $this->get($jwksUri);

        if ($kid === null || $kid === '' || $this->containsKid($jwks, $kid)) {
            return $jwks;
        }

        // Fetched too recently to have rotated since: the kid is just unknown.
        $fetchedAt = $this->cache[$jwksUri]['fetchedAt'] ?? 0;
        if ($this->minRefreshSeconds > 0 && (time() - $fetchedAt) < $this->minRefreshSeconds) {
            return $jwks;
        }

        // Cache miss on the kid → the provider likely rotated keys; refetch once.
        return $this->refresh($jwksUri);
    }

    /**
     * @return array<string, mixed>
     */
    private function refresh(string $jwksUri): array
    {
        $jwks = ($this->fetcher)($jwksUri);
        $this->cache[$jwksUri] = ['jwks' => $jwks, 'fetchedAt' => time()];

        return $jwks;
    }

    /**
     * @param array<string, mixed> $jwks
     */
    private function containsKid(array $jwks, string $kid): bool
    {
        $keys = $jwks['keys'] ?? null;
        if (!is_array($keys)) {
            return false;
        }
        foreach ($keys as $key) {
            if (is_array($key) && ($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }
}
