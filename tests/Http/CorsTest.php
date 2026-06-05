<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\Cors;

/**
 * Tests for the centralized CORS header policy (WC-53).
 */
class CorsTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        // Preserve and clear CORS_ALLOWED_ORIGINS so tests control the allowlist.
        $this->savedEnv['_ENV'] = $_ENV['CORS_ALLOWED_ORIGINS'] ?? false;
        $this->savedEnv['getenv'] = getenv('CORS_ALLOWED_ORIGINS');
        unset($_ENV['CORS_ALLOWED_ORIGINS']);
        putenv('CORS_ALLOWED_ORIGINS');
    }

    protected function tearDown(): void
    {
        if ($this->savedEnv['_ENV'] === false) {
            unset($_ENV['CORS_ALLOWED_ORIGINS']);
        } else {
            $_ENV['CORS_ALLOWED_ORIGINS'] = $this->savedEnv['_ENV'];
        }

        if ($this->savedEnv['getenv'] === false) {
            putenv('CORS_ALLOWED_ORIGINS');
        } else {
            putenv('CORS_ALLOWED_ORIGINS=' . $this->savedEnv['getenv']);
        }
    }

    public function testAllowlistedOriginIsReflectedWithCredentials(): void
    {
        $allowed = ['https://app.example.com', 'http://localhost:3000'];

        $headers = Cors::headers('https://app.example.com', $allowed);

        $this->assertSame('https://app.example.com', $headers['Access-Control-Allow-Origin']);
        $this->assertSame('true', $headers['Access-Control-Allow-Credentials']);
        $this->assertSame('Origin', $headers['Vary']);
    }

    public function testNonAllowlistedOriginIsNotReflected(): void
    {
        $allowed = ['https://app.example.com'];

        $headers = Cors::headers('https://evil.example.com', $allowed);

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
        $this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
        // Methods/headers are always present so preflight still works.
        $this->assertArrayHasKey('Access-Control-Allow-Methods', $headers);
        $this->assertArrayHasKey('Access-Control-Allow-Headers', $headers);
    }

    public function testWildcardIsNeverEmitted(): void
    {
        $headers = Cors::headers('https://app.example.com', ['https://app.example.com']);

        $this->assertNotContains('*', $headers, 'CORS must never emit a wildcard origin.');
    }

    public function testNullOriginIsNotReflected(): void
    {
        $headers = Cors::headers(null, ['https://app.example.com']);

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
    }

    public function testEmptyOriginIsNotReflected(): void
    {
        $headers = Cors::headers('', ['https://app.example.com']);

        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $headers);
    }

    public function testAllowlistFromEnvIsParsed(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://a.example.com, https://b.example.com ,';

        $origins = Cors::allowedOriginsFromEnv();

        $this->assertSame(['https://a.example.com', 'https://b.example.com'], $origins);
    }

    public function testDefaultAllowlistWhenEnvUnset(): void
    {
        // setUp() cleared the env var, so the dev default applies.
        $origins = Cors::allowedOriginsFromEnv();

        $this->assertSame(['http://localhost:3000'], $origins);
    }

    public function testHeadersUsesEnvAllowlistWhenNotPassed(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://configured.example.com';

        $reflected = Cors::headers('https://configured.example.com');
        $rejected = Cors::headers('https://other.example.com');

        $this->assertSame('https://configured.example.com', $reflected['Access-Control-Allow-Origin']);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $rejected);
    }
}
