<?php

declare(strict_types=1);

namespace Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;
use Whity\Core\Observability\ErrorScrubber;

/**
 * The scrubber is a SECURITY boundary, not a formatting nicety
 * (WC-error-tracking).
 *
 * Everything an error tracker stores or transmits passes through it. In a
 * multi-tenant deployment whose isolation is CI-enforced, an unscrubbed payload
 * is a cross-tenant exfiltration path — and when the provider is remote, it
 * exfiltrates into someone else's UI. These tests pin the redactions that matter
 * rather than the exact replacement text.
 */
final class ErrorScrubberTest extends TestCase
{
    private ErrorScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new ErrorScrubber();
    }

    public function testRedactsCredentialsEmbeddedInADsn(): void
    {
        $out = $this->scrubber->text('failed connecting to https://abc123key@errors.example.com/42');

        self::assertStringNotContainsString('abc123key', $out);
    }

    public function testRedactsJwts(): void
    {
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJwcm9maWxlX2lkIjoyfQ.c2lnbmF0dXJlX2hlcmU';
        $out = $this->scrubber->text("token was {$jwt} when it failed");

        self::assertStringNotContainsString('eyJwcm9maWxlX2lkIjoyfQ', $out);
    }

    public function testRedactsEmailAddresses(): void
    {
        // Personal data, and in this system almost always a tenant's user.
        $out = $this->scrubber->text('no account for alice@customer.example');

        self::assertStringNotContainsString('alice@customer.example', $out);
    }

    public function testRedactsLongHexRunsSuchAsKeysAndSessionIds(): void
    {
        $out = $this->scrubber->text('session 9f8e7d6c5b4a39281706f5e4d3c2b1a0 expired');

        self::assertStringNotContainsString('9f8e7d6c5b4a39281706f5e4d3c2b1a0', $out);
    }

    public function testRedactsInlineAssignmentsOfSecrets(): void
    {
        $out = $this->scrubber->text('connect failed (password=hunter2 user=root)');

        self::assertStringNotContainsString('hunter2', $out);
    }

    public function testDropsValuesOfSensitiveContextKeys(): void
    {
        $out = $this->scrubber->context([
            'db_password' => 'hunter2',
            'Authorization' => 'Bearer abc',
            'ENCRYPTION_KEY' => 'k',
            'tenant_id' => 7,
        ]);

        self::assertSame(ErrorScrubber::REDACTED, $out['db_password']);
        self::assertSame(ErrorScrubber::REDACTED, $out['Authorization']);
        self::assertSame(ErrorScrubber::REDACTED, $out['ENCRYPTION_KEY']);
        // Diagnostics that are not secrets survive — a scrubber that redacts
        // everything makes the tracker useless.
        self::assertSame(7, $out['tenant_id']);
    }

    public function testScrubsNestedContextValues(): void
    {
        $out = $this->scrubber->context([
            'request' => ['user' => 'bob@example.com', 'api_key' => 'secret'],
        ]);

        self::assertIsArray($out['request']);
        self::assertStringNotContainsString('bob@example.com', (string) $out['request']['user']);
        self::assertSame(ErrorScrubber::REDACTED, $out['request']['api_key']);
    }

    public function testStopsRecursingOnDeeplyNestedStructures(): void
    {
        // A self-referential or pathologically deep payload must not spin.
        $deep = ['v' => 'x'];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['child' => $deep];
        }

        $out = $this->scrubber->context($deep);

        self::assertIsArray($out);
    }

    public function testTruncatesEnormousStrings(): void
    {
        $out = $this->scrubber->text(str_repeat('a', 20000));

        self::assertLessThan(20000, mb_strlen($out));
    }

    public function testReplacesObjectsRatherThanSerialisingThem(): void
    {
        $out = $this->scrubber->context(['payload' => new \stdClass()]);

        self::assertSame('[object]', $out['payload']);
    }
}
