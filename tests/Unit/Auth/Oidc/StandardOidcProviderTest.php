<?php

declare(strict_types=1);

namespace Tests\Unit\Auth\Oidc;

use PHPUnit\Framework\TestCase;
use Whity\Auth\Oidc\StandardOidcProvider;

/**
 * Unit tests for {@see StandardOidcProvider} (WC-ae16): claim mapping and the
 * Google-specific refresh-token opt-in.
 */
final class StandardOidcProviderTest extends TestCase
{
    public function testNormalizeClaimsMapsStandardOidcClaims(): void
    {
        $provider = new StandardOidcProvider('google', 'Google');
        $identity = $provider->normalizeClaims([
            'iss' => 'https://accounts.google.com',
            'sub' => '12345',
            'email' => 'a@b.com',
            'email_verified' => true,
            'name' => 'Alice',
        ]);

        self::assertSame('https://accounts.google.com', $identity->issuer);
        self::assertSame('12345', $identity->subject);
        self::assertSame('a@b.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Alice', $identity->displayName);
    }

    public function testEmailVerifiedStringFalseIsNotTreatedAsVerified(): void
    {
        $provider = new StandardOidcProvider('oidc', 'OIDC');
        // Some providers send email_verified as the STRING "false" — must not be
        // coerced to true (the (bool) "false" === true trap).
        $identity = $provider->normalizeClaims(['iss' => 'i', 'sub' => 's', 'email' => 'a@b.com', 'email_verified' => 'false']);
        self::assertFalse($identity->emailVerified);

        $verified = $provider->normalizeClaims(['iss' => 'i', 'sub' => 's', 'email_verified' => 'true']);
        self::assertTrue($verified->emailVerified);
    }

    public function testGoogleRequestsOfflineAccessOthersDoNot(): void
    {
        self::assertSame(
            ['access_type' => 'offline', 'prompt' => 'consent'],
            (new StandardOidcProvider('google', 'Google'))->authorizationParameters()
        );
        self::assertSame([], (new StandardOidcProvider('oidc', 'Generic'))->authorizationParameters());
    }

    public function testKeyDisplayNameAndScopes(): void
    {
        $provider = new StandardOidcProvider('microsoft', 'Microsoft', ['openid', 'email']);
        self::assertSame('microsoft', $provider->key());
        self::assertSame('Microsoft', $provider->displayName());
        self::assertSame(['openid', 'email'], $provider->defaultScopes());
    }
}
