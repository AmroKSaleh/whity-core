<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Core\Response;
use Whity\Http\Cors;
use Whity\Http\SecurityHeaders;

/**
 * Proves the WC-187 hardening headers actually reach client-visible responses
 * once merged through the same Response seam public/index.php uses.
 *
 * The FrankenPHP worker loop cannot be unit-tested directly (it depends on
 * frankenphp_handle_request), so — exactly as WorkerRuntime does for the GC/log
 * decisions — this test reconstructs the production merge expression
 *
 *     array_merge($response->getHeaders(), $corsHeaders, $securityHeaders)
 *
 * and asserts the resulting Response carries every hardening header, including
 * on the 500 error path and the 204 OPTIONS preflight path. Response normalizes
 * header names to lowercase, so the assertions read the normalized keys.
 */
class SecurityHeadersResponseMergeTest extends TestCase
{
    public function testHardeningHeadersReachASuccessJsonResponse(): void
    {
        $securityHeaders = SecurityHeaders::headers('production');
        $corsHeaders = Cors::headers('https://app.example.com', ['https://app.example.com']);

        $base = Response::json(['ok' => true], 200);
        $merged = new Response(
            $base->getStatusCode(),
            $base->getBody(),
            array_merge($base->getHeaders(), $corsHeaders, $securityHeaders)
        );

        $headers = $merged->getHeaders();
        $this->assertSame('nosniff', $headers['x-content-type-options']);
        $this->assertSame('DENY', $headers['x-frame-options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['content-security-policy']);
        $this->assertSame('no-referrer', $headers['referrer-policy']);
        $this->assertSame('max-age=31536000; includeSubDomains', $headers['strict-transport-security']);
        // The original payload + content-type and CORS headers survive the merge.
        $this->assertSame('application/json', $headers['content-type']);
        $this->assertSame('https://app.example.com', $headers['access-control-allow-origin']);
        $this->assertSame('{"ok":true}', $merged->getBody());
    }

    public function testHardeningHeadersReachThe500ErrorPath(): void
    {
        // Mirrors the catch (\Throwable) branch in public/index.php.
        $securityHeaders = SecurityHeaders::headers('production');
        $errorResponse = Response::error('Internal server error', 500);
        $merged = new Response(
            500,
            $errorResponse->getBody(),
            array_merge(
                $errorResponse->getHeaders(),
                Cors::headers(null),
                $securityHeaders
            )
        );

        $this->assertSame(500, $merged->getStatusCode());
        $headers = $merged->getHeaders();
        $this->assertSame('nosniff', $headers['x-content-type-options']);
        $this->assertSame('DENY', $headers['x-frame-options']);
        $this->assertStringContainsString("frame-ancestors 'none'", $headers['content-security-policy']);
        $this->assertSame('no-referrer', $headers['referrer-policy']);
        $this->assertSame('max-age=31536000; includeSubDomains', $headers['strict-transport-security']);
    }

    public function testHardeningHeadersReachThe204PreflightPath(): void
    {
        // Mirrors the OPTIONS branch in public/index.php.
        $securityHeaders = SecurityHeaders::headers('production');
        $corsHeaders = Cors::headers('https://app.example.com', ['https://app.example.com']);

        $preflight = new Response(204, '', array_merge($corsHeaders, $securityHeaders));

        $this->assertSame(204, $preflight->getStatusCode());
        $headers = $preflight->getHeaders();
        $this->assertSame('nosniff', $headers['x-content-type-options']);
        $this->assertSame('DENY', $headers['x-frame-options']);
        $this->assertSame('no-referrer', $headers['referrer-policy']);
        $this->assertArrayHasKey('strict-transport-security', $headers);
    }

    public function testDevelopmentResponseOmitsHsts(): void
    {
        $securityHeaders = SecurityHeaders::headers('development');
        $base = Response::json(['ok' => true], 200);
        $merged = new Response(
            $base->getStatusCode(),
            $base->getBody(),
            array_merge($base->getHeaders(), $securityHeaders)
        );

        $headers = $merged->getHeaders();
        $this->assertArrayNotHasKey(
            'strict-transport-security',
            $headers,
            'A development API response must not carry HSTS.'
        );
        // The non-HSTS defenses are still present in development.
        $this->assertSame('nosniff', $headers['x-content-type-options']);
        $this->assertSame('DENY', $headers['x-frame-options']);
    }
}
