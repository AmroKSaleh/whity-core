<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\TenantNotificationSettingsRepository;

/**
 * Real-engine tests for {@see TenantNotificationSettingsRepository}: the
 * (tenant, channel)-scoped upsert of non-secret config vs the separate write of
 * the encrypted credentials blob, redacted reads (no blob), the internal
 * with-credentials accessor, config/credentials independence, and tenant scoping.
 */
final class TenantNotificationSettingsRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;

    private PDO $pdo;
    private TenantNotificationSettingsRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'),(2,'b','b') ON CONFLICT (id) DO NOTHING");
        $this->repo = new TenantNotificationSettingsRepository($this->pdo);
    }

    public function testUpsertConfigAndRedactedReadNeverExposesCredentials(): void
    {
        $this->repo->upsertConfig(self::TENANT_A, 'email', [
            'transport'    => 'smtp',
            'from_address' => 'noreply@acme.test',
            'from_name'    => 'Acme',
            'reply_to'     => 'support@acme.test',
            'config'       => ['host' => 'smtp.acme.test', 'port' => 587],
        ]);

        $rows = $this->repo->listForTenant(self::TENANT_A);
        self::assertCount(1, $rows);
        $row = $rows[0];
        self::assertSame('email', $row['channel']);
        self::assertSame('smtp', $row['transport']);
        self::assertSame('noreply@acme.test', $row['from_address']);
        self::assertSame(['host' => 'smtp.acme.test', 'port' => 587], $row['config']);
        self::assertFalse($row['has_credentials']);
        self::assertArrayNotHasKey('credentials_encrypted', $row, 'the redacted read must never carry the secret blob');
    }

    public function testCredentialsAreWriteOnlyAndConfigCredentialsAreIndependent(): void
    {
        $this->repo->upsertConfig(self::TENANT_A, 'email', ['from_address' => 'a@b.test']);
        $this->repo->setCredentials(self::TENANT_A, 'email', 'ENCRYPTED-BLOB');

        // Redacted read: has_credentials true, blob absent.
        $redacted = $this->repo->findForChannel(self::TENANT_A, 'email');
        self::assertNotNull($redacted);
        self::assertTrue($redacted['has_credentials']);
        self::assertArrayNotHasKey('credentials_encrypted', $redacted);
        self::assertSame('a@b.test', $redacted['from_address'], 'setting credentials did not disturb config');

        // Internal accessor: the blob is available for a transport to decrypt.
        $withCreds = $this->repo->findWithCredentials(self::TENANT_A, 'email');
        self::assertNotNull($withCreds);
        self::assertSame('ENCRYPTED-BLOB', $withCreds['credentials_encrypted']);

        // Re-upserting config must preserve the stored credentials.
        $this->repo->upsertConfig(self::TENANT_A, 'email', ['from_address' => 'c@d.test', 'from_name' => 'X']);
        $stillHasCreds = $this->repo->findWithCredentials(self::TENANT_A, 'email');
        self::assertNotNull($stillHasCreds);
        self::assertSame('ENCRYPTED-BLOB', $stillHasCreds['credentials_encrypted'], 'config upsert must not clobber credentials');
        self::assertSame('c@d.test', $stillHasCreds['from_address']);

        // Clearing credentials leaves config intact.
        $this->repo->setCredentials(self::TENANT_A, 'email', null);
        $cleared = $this->repo->findForChannel(self::TENANT_A, 'email');
        self::assertNotNull($cleared);
        self::assertFalse($cleared['has_credentials']);
        self::assertSame('c@d.test', $cleared['from_address']);
    }

    public function testDeleteAndTenantScoping(): void
    {
        $this->repo->upsertConfig(self::TENANT_A, 'email', ['from_address' => 'a@b.test']);

        self::assertCount(0, $this->repo->listForTenant(2), 'another tenant sees no config');
        self::assertFalse($this->repo->delete(2, 'email'), "another tenant cannot delete this tenant's channel");
        self::assertTrue($this->repo->delete(self::TENANT_A, 'email'));
        self::assertNull($this->repo->findForChannel(self::TENANT_A, 'email'));
    }
}
