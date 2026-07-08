<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Oidc;

use PHPUnit\Framework\TestCase;
use Whity\Auth\Oidc\JwksProvider;

/**
 * Unit tests for {@see JwksProvider} (WC-24a1): TTL caching and the
 * refresh-on-unknown-kid behaviour that tolerates provider key rotation. The
 * fetcher is a stub so no network is touched.
 */
final class JwksProviderTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $jwksSequence one JWKS per fetch call (last repeats)
     * @return array{0: JwksProvider, 1: callable(): int}
     */
    private function providerReturning(array $jwksSequence, int $ttl = 3600): array
    {
        $calls = 0;
        $fetcher = function (string $uri) use (&$calls, $jwksSequence): array {
            $index = min($calls, count($jwksSequence) - 1);
            $calls++;
            return $jwksSequence[$index];
        };
        $provider = new JwksProvider($fetcher, $ttl);

        // By-REFERENCE closure so the caller sees the live count (an arrow fn
        // would capture $calls by value and freeze it at 0).
        return [$provider, function () use (&$calls): int {
            return $calls;
        }];
    }

    /**
     * @param list<string> $kids
     * @return array<string, mixed>
     */
    private function jwks(array $kids): array
    {
        return ['keys' => array_map(static fn(string $k): array => ['kid' => $k, 'kty' => 'RSA'], $kids)];
    }

    public function testCachesWithinTtl(): void
    {
        [$provider, $calls] = $this->providerReturning([$this->jwks(['k1'])], 3600);

        $provider->get('https://issuer/jwks');
        $provider->get('https://issuer/jwks');
        $provider->get('https://issuer/jwks');

        self::assertSame(1, $calls(), 'JWKS is fetched once and served from cache within the TTL');
    }

    public function testRefetchesWhenTtlIsZero(): void
    {
        [$provider, $calls] = $this->providerReturning([$this->jwks(['k1']), $this->jwks(['k1'])], 0);

        $provider->get('https://issuer/jwks');
        $provider->get('https://issuer/jwks');

        self::assertSame(2, $calls(), 'a zero TTL forces a refetch every call');
    }

    public function testGetForKidRefreshesOnUnknownKid(): void
    {
        // First fetch has only k1; after rotation the second fetch has k2.
        [$provider, $calls] = $this->providerReturning([$this->jwks(['k1']), $this->jwks(['k1', 'k2'])], 3600);

        // Prime the cache with the k1-only set.
        $provider->get('https://issuer/jwks');
        self::assertSame(1, $calls());

        // Asking for k2 (not in cache) forces one refresh even within the TTL.
        $jwks = $provider->getForKid('https://issuer/jwks', 'k2');
        self::assertSame(2, $calls(), 'an unknown kid forces a single refresh (key rotation)');
        $kids = array_map(static fn(array $k): string => (string) $k['kid'], $jwks['keys']);
        self::assertContains('k2', $kids);
    }

    public function testGetForKidDoesNotRefreshWhenKidPresent(): void
    {
        [$provider, $calls] = $this->providerReturning([$this->jwks(['k1', 'k2'])], 3600);

        $provider->get('https://issuer/jwks');
        $provider->getForKid('https://issuer/jwks', 'k1');

        self::assertSame(1, $calls(), 'a known kid is served from cache without a refetch');
    }

    public function testGetForKidWithNullKidServesCache(): void
    {
        [$provider, $calls] = $this->providerReturning([$this->jwks(['k1'])], 3600);

        $provider->get('https://issuer/jwks');
        $provider->getForKid('https://issuer/jwks', null);

        self::assertSame(1, $calls());
    }
}
