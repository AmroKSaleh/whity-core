<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\SecurityHeaders;

/**
 * Tests for the centralized security response-header policy (WC-187).
 *
 * The non-HSTS hardening headers are emitted unconditionally on every API
 * response; HSTS is the only environment-sensitive header — it must NEVER be
 * sent over plaintext dev/local HTTP (a browser that sees it once would refuse
 * future http:// connections to the host), so it is gated on a non-development
 * APP_ENV.
 */
class SecurityHeadersTest extends TestCase
{
    /**
     * The clickjacking / sniffing / referrer-leak defenses are environment
     * independent and must be present for every environment.
     *
     * @return list<string>
     */
    private static function alwaysOnEnvironments(): array
    {
        return ['development', 'staging', 'production', '', 'anything-else'];
    }

    public function testNosniffIsAlwaysPresent(): void
    {
        foreach (self::alwaysOnEnvironments() as $env) {
            $headers = SecurityHeaders::headers($env);
            $this->assertSame(
                'nosniff',
                $headers['X-Content-Type-Options'] ?? null,
                "X-Content-Type-Options must be nosniff for APP_ENV='{$env}'"
            );
        }
    }

    public function testFrameOptionsDenyIsAlwaysPresent(): void
    {
        foreach (self::alwaysOnEnvironments() as $env) {
            $headers = SecurityHeaders::headers($env);
            $this->assertSame(
                'DENY',
                $headers['X-Frame-Options'] ?? null,
                "X-Frame-Options must be DENY for APP_ENV='{$env}'"
            );
        }
    }

    public function testCspFrameAncestorsIsAlwaysPresent(): void
    {
        foreach (self::alwaysOnEnvironments() as $env) {
            $headers = SecurityHeaders::headers($env);
            $csp = $headers['Content-Security-Policy'] ?? '';
            $this->assertStringContainsString(
                "frame-ancestors 'none'",
                $csp,
                "CSP must forbid framing for APP_ENV='{$env}'"
            );
        }
    }

    public function testReferrerPolicyIsAlwaysPresent(): void
    {
        foreach (self::alwaysOnEnvironments() as $env) {
            $headers = SecurityHeaders::headers($env);
            $this->assertSame(
                'no-referrer',
                $headers['Referrer-Policy'] ?? null,
                "Referrer-Policy must be no-referrer for APP_ENV='{$env}'"
            );
        }
    }

    public function testHstsIsAbsentInDevelopment(): void
    {
        $headers = SecurityHeaders::headers('development');

        $this->assertArrayNotHasKey(
            'Strict-Transport-Security',
            $headers,
            'HSTS must never be emitted in development (plaintext HTTP).'
        );
    }

    public function testHstsIsPresentInProduction(): void
    {
        $headers = SecurityHeaders::headers('production');

        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $headers['Strict-Transport-Security'] ?? null,
            'HSTS must be emitted in production.'
        );
    }

    public function testHstsIsPresentInStaging(): void
    {
        $headers = SecurityHeaders::headers('staging');

        $this->assertArrayHasKey(
            'Strict-Transport-Security',
            $headers,
            'HSTS must be emitted in any non-development environment (e.g. staging).'
        );
    }

    public function testHstsIsPresentForUnknownEnvironment(): void
    {
        // Fail-secure: an unrecognized/empty APP_ENV is treated as non-development
        // so a misconfigured production host still gets HSTS.
        $headers = SecurityHeaders::headers('');

        $this->assertArrayHasKey(
            'Strict-Transport-Security',
            $headers,
            'Only the explicit "development" value disables HSTS; everything else gets it.'
        );
    }

    public function testNoWildcardOrUnexpectedValues(): void
    {
        $headers = SecurityHeaders::headers('production');

        // Defense against an accidentally permissive CSP for a JSON API.
        $this->assertStringNotContainsString(
            '*',
            $headers['Content-Security-Policy'] ?? '',
            'The API CSP must not contain a wildcard source.'
        );
    }

    /**
     * WC-531: a handler (e.g. a plugin serving a self-contained HTML screen)
     * that already set its own Content-Security-Policy must have that policy
     * survive the hardening-header merge, instead of being silently
     * overwritten by the strict JSON-API default.
     */
    public function testRespectingHandlerCspDropsTheDefaultWhenResponseAlreadyHasOne(): void
    {
        $securityHeaders = SecurityHeaders::headers('production');
        $responseHeaders = [
            'content-type' => 'text/html',
            'content-security-policy' => "default-src 'self'; script-src 'unsafe-inline'",
        ];

        $merged = SecurityHeaders::respectingHandlerCsp($securityHeaders, $responseHeaders);

        $this->assertArrayNotHasKey(
            'Content-Security-Policy',
            $merged,
            'The strict default CSP must be dropped so the handler-set one survives array_merge().'
        );
    }

    public function testRespectingHandlerCspKeepsTheDefaultWhenResponseHasNone(): void
    {
        $securityHeaders = SecurityHeaders::headers('production');
        $responseHeaders = ['content-type' => 'application/json'];

        $merged = SecurityHeaders::respectingHandlerCsp($securityHeaders, $responseHeaders);

        $this->assertSame(
            $securityHeaders['Content-Security-Policy'],
            $merged['Content-Security-Policy'] ?? null,
            'A response with no CSP of its own must still get the strict default (secure by default).'
        );
    }

    public function testRespectingHandlerCspLeavesOtherHardeningHeadersUntouched(): void
    {
        $securityHeaders = SecurityHeaders::headers('production');
        $responseHeaders = ['content-security-policy' => "default-src 'self'"];

        $merged = SecurityHeaders::respectingHandlerCsp($securityHeaders, $responseHeaders);

        $this->assertSame('nosniff', $merged['X-Content-Type-Options'] ?? null);
        $this->assertSame('DENY', $merged['X-Frame-Options'] ?? null);
        $this->assertSame('no-referrer', $merged['Referrer-Policy'] ?? null);
        $this->assertSame(
            'max-age=31536000; includeSubDomains',
            $merged['Strict-Transport-Security'] ?? null
        );
    }
}
