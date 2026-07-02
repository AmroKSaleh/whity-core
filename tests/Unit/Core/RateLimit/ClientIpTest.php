<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RateLimit\ClientIp;
use Whity\Sdk\Http\Request;

/**
 * WC-b19ff21a: trusted client-IP extraction.
 *
 * The client IP is read ONLY from the internal {@see ClientIp::HEADER}, which the
 * trusted front proxy (the Next.js API proxy) sets from its own ingress and
 * which it strips from inbound client requests. Raw client-supplied
 * `X-Forwarded-For` / `X-Real-IP` are NO LONGER trusted — they are attacker-
 * controllable through the proxy, so honouring them let a caller spoof the
 * rate-limit key and poison audit IPs.
 */
final class ClientIpTest extends TestCase
{
    public function testReadsTrustedHeader(): void
    {
        $request = new Request('GET', '/api/x', [ClientIp::HEADER => '203.0.113.7']);
        self::assertSame('203.0.113.7', ClientIp::fromRequest($request));
    }

    public function testIgnoresClientSuppliedXForwardedFor(): void
    {
        // A spoof attempt: only the untrusted forwarding header is present.
        $request = new Request('GET', '/api/x', ['X-Forwarded-For' => '1.2.3.4']);
        self::assertNull(ClientIp::fromRequest($request), 'raw X-Forwarded-For must not be trusted');
    }

    public function testIgnoresClientSuppliedXRealIp(): void
    {
        $request = new Request('GET', '/api/x', ['X-Real-IP' => '1.2.3.4']);
        self::assertNull(ClientIp::fromRequest($request), 'raw X-Real-IP must not be trusted');
    }

    public function testTrustedHeaderWinsOverSpoofedForwardingHeaders(): void
    {
        $request = new Request('GET', '/api/x', [
            ClientIp::HEADER  => '203.0.113.7',
            'X-Forwarded-For' => '6.6.6.6',
            'X-Real-IP'       => '7.7.7.7',
        ]);
        self::assertSame('203.0.113.7', ClientIp::fromRequest($request));
    }

    public function testReturnsNullWhenTrustedHeaderAbsent(): void
    {
        self::assertNull(ClientIp::fromRequest(new Request('GET', '/api/x')));
    }

    public function testTrimsWhitespace(): void
    {
        $request = new Request('GET', '/api/x', [ClientIp::HEADER => '  203.0.113.7  ']);
        self::assertSame('203.0.113.7', ClientIp::fromRequest($request));
    }

    public function testReturnsNullForWhitespaceOnlyHeader(): void
    {
        $request = new Request('GET', '/api/x', [ClientIp::HEADER => '   ']);
        self::assertNull(ClientIp::fromRequest($request));
    }

    public function testCapsAtIpv6MaxLength(): void
    {
        $request = new Request('GET', '/api/x', [ClientIp::HEADER => str_repeat('a', 100)]);
        $ip = ClientIp::fromRequest($request);
        self::assertNotNull($ip);
        self::assertSame(45, strlen($ip));
    }
}
