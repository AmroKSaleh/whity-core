<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RateLimit\ClientIp;
use Whity\Sdk\Http\Request;

/**
 * WC-c0fb3700: client-IP extraction for rate-limit keys.
 *
 * Mirrors the forwarding-header logic already used for audit (X-Forwarded-For
 * first hop, then X-Real-IP), centralised so the limiter and other consumers
 * derive the IP identically.
 */
final class ClientIpTest extends TestCase
{
    public function testPrefersFirstForwardedForHop(): void
    {
        $request = new Request('GET', '/api/x', ['X-Forwarded-For' => '203.0.113.7, 10.0.0.1, 10.0.0.2']);
        self::assertSame('203.0.113.7', ClientIp::fromRequest($request));
    }

    public function testFallsBackToRealIp(): void
    {
        $request = new Request('GET', '/api/x', ['X-Real-IP' => '198.51.100.9']);
        self::assertSame('198.51.100.9', ClientIp::fromRequest($request));
    }

    public function testForwardedForTakesPriorityOverRealIp(): void
    {
        $request = new Request('GET', '/api/x', [
            'X-Forwarded-For' => '203.0.113.7',
            'X-Real-IP'       => '198.51.100.9',
        ]);
        self::assertSame('203.0.113.7', ClientIp::fromRequest($request));
    }

    public function testReturnsNullWhenNoForwardingHeaders(): void
    {
        self::assertNull(ClientIp::fromRequest(new Request('GET', '/api/x')));
    }

    public function testTrimsWhitespace(): void
    {
        $request = new Request('GET', '/api/x', ['X-Forwarded-For' => '  203.0.113.7  , 10.0.0.1']);
        self::assertSame('203.0.113.7', ClientIp::fromRequest($request));
    }

    public function testCapsAtIpv6MaxLength(): void
    {
        $long = str_repeat('a', 100);
        $request = new Request('GET', '/api/x', ['X-Real-IP' => $long]);
        $ip = ClientIp::fromRequest($request);
        self::assertNotNull($ip);
        self::assertSame(45, strlen($ip));
    }

    public function testReturnsNullForEmptyForwardedForHeader(): void
    {
        $request = new Request('GET', '/api/x', ['X-Forwarded-For' => '   ']);
        self::assertNull(ClientIp::fromRequest($request));
    }
}
