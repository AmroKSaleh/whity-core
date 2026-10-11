<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\FederatedWorkspaceProvisioner;

/**
 * The provisioner's own guards, exercised directly rather than through the
 * sign-in callback, so a future caller that skips the callback's checks still
 * meets them (WHIT-669 review: every gate the callback relies on is re-checked
 * here).
 */
final class FederatedWorkspaceProvisionerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SettingsService $settings;
    private FederatedWorkspaceProvisioner $provisioner;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::SSO_SELF_PROVISION_WORKSPACE, 'true');
        $this->provisioner = new FederatedWorkspaceProvisioner($this->pdo, $this->settings);
    }

    private function profile(string $displayName, string $email, string $status = 'active'): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (:n, '', false, NULL, 0, 0, :s, NOW(), NOW())"
        )->execute([':n' => $displayName, ':s' => $status]);
        $id = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($id, $email, true, true);
        return $id;
    }

    private static function global(): FederatedProviderContext
    {
        return new FederatedProviderContext(1, 'oidc', 0);
    }

    /** @return array<string, mixed> */
    private function tenantOf(int $tenantId): array
    {
        $stmt = $this->pdo->prepare('SELECT name, slug FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return $row;
    }

    public function testATenantsOwnProviderNeverProvisions(): void
    {
        $pid = $this->profile('Dana', 'dana@example.test');

        self::assertNull($this->provisioner->provisionFor($pid, 'dana@example.test', new FederatedProviderContext(9, 'acme', 1)));
        self::assertNotNull($this->provisioner->provisionFor($pid, 'dana@example.test', self::global()));
    }

    public function testADeactivatedProfileGetsNoWorkspace(): void
    {
        $pid = $this->profile('Mallory', 'mallory@example.test', 'inactive');

        self::assertNull($this->provisioner->provisionFor($pid, 'mallory@example.test', self::global()));
    }

    public function testThePaymentGateClosesTheWorkspaceInTheSameTransaction(): void
    {
        $this->settings->setGlobal(SettingsRegistry::REGISTRATION_PAYMENT_REQUIRED, 'true');
        $pid = $this->profile('Payer', 'payer@example.test');

        $tenantId = $this->provisioner->provisionFor($pid, 'payer@example.test', self::global());

        self::assertNotNull($tenantId);
        $stmt = $this->pdo->prepare('SELECT status, enforcement_mode FROM tenant_plan WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        self::assertSame(['status' => 'expired', 'enforcement_mode' => 'block_all'], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function testNoPaymentGateWritesNoPlanRow(): void
    {
        $pid = $this->profile('Free', 'free@example.test');

        $tenantId = $this->provisioner->provisionFor($pid, 'free@example.test', self::global());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_plan WHERE tenant_id = :t');
        $stmt->execute([':t' => $tenantId]);
        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testTheSlugIsOpaqueSoADisplayNameCannotClaimAHost(): void
    {
        $pid = $this->profile('app', 'someone@example.test');

        $tenantId = $this->provisioner->provisionFor($pid, 'someone@example.test', self::global());

        self::assertNotNull($tenantId);
        $tenant = $this->tenantOf($tenantId);
        self::assertSame('app', $tenant['name']);
        self::assertMatchesRegularExpression('/^ws-[0-9a-f]{12}$/', $tenant['slug']);
    }

    public function testAnEmailNeverBecomesTheWorkspaceName(): void
    {
        $pid = $this->profile('', 'john.doe@example.test');

        $tenant = $this->tenantOf((int) $this->provisioner->provisionFor($pid, 'john.doe@example.test', self::global()));

        self::assertStringNotContainsString('@', $tenant['name']);
        self::assertStringNotContainsString('example', $tenant['slug']);
    }

    public function testControlAndBidiCharactersAreStrippedFromTheName(): void
    {
        $pid = $this->profile("Ev\u{202E}il\u{0007} Corp\u{200F}", 'evil@example.test');

        $tenant = $this->tenantOf((int) $this->provisioner->provisionFor($pid, 'evil@example.test', self::global()));

        self::assertSame('Evil Corp', $tenant['name']);
    }

    public function testATakenNameIsNumberedWhateverItsScript(): void
    {
        $this->pdo->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('سارة', 'taken-1', NOW())");
        $pid = $this->profile('سارة', 'sara@example.test');

        $tenant = $this->tenantOf((int) $this->provisioner->provisionFor($pid, 'sara@example.test', self::global()));

        self::assertSame('سارة 2', $tenant['name']);
    }

    public function testItRefusesToRunInsideSomebodyElsesTransaction(): void
    {
        $pid = $this->profile('Nested', 'nested@example.test');
        $this->pdo->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            $this->provisioner->provisionFor($pid, 'nested@example.test', self::global());
        } finally {
            $this->pdo->rollBack();
        }
    }
}
