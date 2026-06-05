<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PHPUnit\Framework\TestCase;
use Whity\Database\InitialPassword;

/**
 * Tests that initial account passwords are sourced from the environment or a
 * one-time random value, and that no static literal password remains in the
 * seeder/migration source (WC-52).
 */
class InitialPasswordTest extends TestCase
{
    private const ENV_VAR = 'INITIAL_TEST_PASSWORD';

    protected function setUp(): void
    {
        unset($_ENV[self::ENV_VAR]);
        putenv(self::ENV_VAR);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV_VAR]);
        putenv(self::ENV_VAR);
    }

    public function testUsesEnvValueWhenSet(): void
    {
        $_ENV[self::ENV_VAR] = 'my-chosen-password';

        $plaintext = InitialPassword::resolvePlaintext(self::ENV_VAR, 'someone@example.com');

        $this->assertSame('my-chosen-password', $plaintext);
    }

    public function testGeneratesRandomPasswordWhenEnvAbsent(): void
    {
        ob_start();
        $plaintext = InitialPassword::resolvePlaintext(self::ENV_VAR, 'someone@example.com');
        $output = (string) ob_get_clean();

        // At least 16 chars and high-entropy (bin2hex of >= 8 bytes).
        $this->assertGreaterThanOrEqual(16, strlen($plaintext));
        // The generated password must be announced once so the operator can capture it.
        $this->assertStringContainsString($plaintext, $output);
        $this->assertStringContainsString(self::ENV_VAR, $output);
    }

    public function testRandomPasswordsDifferAcrossCalls(): void
    {
        ob_start();
        $first = InitialPassword::resolvePlaintext(self::ENV_VAR, 'a@example.com');
        $second = InitialPassword::resolvePlaintext(self::ENV_VAR, 'b@example.com');
        ob_end_clean();

        $this->assertNotSame($first, $second, 'Generated passwords must be random, not constant.');
    }

    public function testHashForReturnsVerifiableBcryptHashFromEnv(): void
    {
        $_ENV[self::ENV_VAR] = 'env-password-value';

        $hash = InitialPassword::hashFor(self::ENV_VAR, 'someone@example.com');

        $this->assertNotSame('env-password-value', $hash, 'Must return a hash, not plaintext.');
        $this->assertTrue(password_verify('env-password-value', $hash));
    }

    public function testSeederContainsNoHardcodedLiteralPassword(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Database/Seeder.php'
        );

        $this->assertStringNotContainsString("'admin123'", $source);
        $this->assertStringNotContainsString("'user123'", $source);
        // The literal hashing of any string constant must be gone.
        $this->assertDoesNotMatchRegularExpression(
            "/password_hash\\(\\s*'[^']+'/",
            $source,
            'Seeder must not hash a hardcoded string literal.'
        );
        // It must source passwords from env via the InitialPassword helper.
        $this->assertStringContainsString('InitialPassword::hashFor', $source);
        $this->assertStringContainsString('INITIAL_ADMIN_PASSWORD', $source);
        $this->assertStringContainsString('INITIAL_USER_PASSWORD', $source);
    }

    public function testSystemTenantMigrationContainsNoHardcodedLiteralPassword(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/database/migrations/010_create_system_tenant.php'
        );

        $this->assertStringNotContainsString("'system_admin_123'", $source);
        $this->assertDoesNotMatchRegularExpression(
            "/password_hash\\(\\s*'[^']+'/",
            $source,
            'Migration must not hash a hardcoded string literal.'
        );
        $this->assertStringContainsString('InitialPassword::hashFor', $source);
        $this->assertStringContainsString('INITIAL_SYSTEM_ADMIN_PASSWORD', $source);
    }
}
