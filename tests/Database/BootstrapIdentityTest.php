<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PHPUnit\Framework\TestCase;
use Whity\Database\BootstrapIdentity;

/**
 * Resolution rules for the bootstrap administrator's address (WC-779).
 *
 * The reconciliation half (renaming an actual install) is proven against a
 * real engine in {@see \Tests\Integration\BootstrapIdentityRealEngineTest};
 * this covers the parsing decisions, which have no database in them.
 */
final class BootstrapIdentityTest extends TestCase
{
    /** @var string|false APP-level env as it was before each test. */
    private string|false $saved = false;

    protected function setUp(): void
    {
        $this->saved = $_ENV[BootstrapIdentity::EMAIL_ENV_VAR] ?? getenv(BootstrapIdentity::EMAIL_ENV_VAR);
        $this->clear();
    }

    protected function tearDown(): void
    {
        if (is_string($this->saved)) {
            $this->set($this->saved);
        } else {
            $this->clear();
        }
    }

    public function testDefaultsToTheHistoricalAddressWhenUnset(): void
    {
        self::assertSame('system@whity.local', BootstrapIdentity::email());
    }

    public function testUsesTheConfiguredAddress(): void
    {
        $this->set('ops@acme.example');

        self::assertSame('ops@acme.example', BootstrapIdentity::email());
    }

    public function testNormalisesCaseAndSurroundingWhitespace(): void
    {
        $this->set('  OPS@Acme.Example  ');

        self::assertSame(
            'ops@acme.example',
            BootstrapIdentity::email(),
            'The address must be normalised the same way the identity layer stores every other email.'
        );
    }

    public function testEmptyValueIsTreatedAsUnset(): void
    {
        $this->set('   ');

        self::assertSame('system@whity.local', BootstrapIdentity::email());
    }

    public function testInvalidAddressFallsBackToTheDefaultAndSaysSo(): void
    {
        $this->set('not-an-email-address');

        ob_start();
        $resolved = BootstrapIdentity::email();
        $output   = (string) ob_get_clean();

        self::assertSame(
            'system@whity.local',
            $resolved,
            'A typo in one env var must not brick migrate run — but it must not be honoured either.'
        );
        self::assertStringContainsString(BootstrapIdentity::EMAIL_ENV_VAR, $output);
        self::assertStringContainsString('not-an-email-address', $output);
    }

    private function set(string $value): void
    {
        $_ENV[BootstrapIdentity::EMAIL_ENV_VAR] = $value;
        putenv(BootstrapIdentity::EMAIL_ENV_VAR . '=' . $value);
    }

    private function clear(): void
    {
        unset($_ENV[BootstrapIdentity::EMAIL_ENV_VAR]);
        putenv(BootstrapIdentity::EMAIL_ENV_VAR);
    }
}
