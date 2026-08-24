<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\AuthMethod;
use Whity\Core\Identity\LocalPasswordRefusedException;

/**
 * The CLI service principal, and the terminal `auth_method` that protects it
 * (#928, migrations 106/107).
 *
 * WHY THE PRINCIPAL EXISTS
 * ------------------------
 * `RbacMiddleware` requires an integer `profile_id` and then checks the role
 * against the AUTHORITATIVE STORE, so the CLI cannot authorize with a
 * fabricated identity — it needs a profile that genuinely holds `admin` in the
 * system tenant. Borrowing a real administrator's would be worse: whatever the
 * CLI authorizes as becomes the audit ACTOR, so every operator shell command
 * would be attributed to a colleague.
 *
 * WHY 'service' IS TERMINAL, AND WHAT THESE TESTS ARE REALLY GUARDING
 * -------------------------------------------------------------------
 * The principal holds deployment-wide authority, so it must never become a
 * login. One refusal is not enough, because the transitions compose into an
 * escalation that is built entirely from individually legitimate steps:
 *
 *     link an external identity  → 'service' would recompute to 'idp'
 *     set a password with override → 'idp' legitimately becomes 'both'
 *     → a deployment-wide admin account someone can now sign in as
 *
 * Neither step is suspicious on its own, and the second is a documented
 * administrative capability. So the guard cannot be "refuse the password write";
 * it has to be "nothing leaves this state", and each exit is asserted closed
 * below rather than assumed.
 *
 * WHY tests/Integration
 * ---------------------
 * So it runs on BOTH engines, as `AuthMethodRealEngineTest` does. Migration 106
 * widens a CHECK constraint that exists on PostgreSQL and not on SQLite, so a
 * SQLite-only green would prove nothing about the deployments that matter.
 */
final class ServicePrincipalRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    /** The seeded principal's id. */
    private function principalId(): int
    {
        $stmt = $this->pdo->query(
            "SELECT id FROM profiles WHERE auth_method = '" . AuthMethod::SERVICE . "' ORDER BY id ASC"
        );
        self::assertNotFalse($stmt);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

        self::assertCount(
            1,
            $ids,
            'Exactly one service principal: the CLI resolves it by this fact alone, so a second '
            . 'one makes which identity the CLI acts as depend on row order.'
        );

        return (int) $ids[0];
    }

    private function authMethod(int $profileId): string
    {
        $stmt = $this->pdo->prepare('SELECT auth_method FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return (string) $stmt->fetchColumn();
    }

    public function testTheMigrationSeedsExactlyOnePrincipalWithSystemTenantAdmin(): void
    {
        $id = $this->principalId();

        $stmt = $this->pdo->prepare(
            "SELECT m.tenant_id, m.status, r.name AS role
               FROM memberships m
               JOIN roles r ON r.id = m.role_id
              WHERE m.profile_id = :id"
        );
        $stmt->execute([':id' => $id]);
        $membership = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($membership, 'The principal must hold a membership or RBAC resolves nothing.');
        self::assertSame('0', (string) $membership['tenant_id'], 'Platform-wide scope is tenant 0.');
        self::assertSame('active', (string) $membership['status']);
        self::assertSame('admin', (string) $membership['role']);
    }

    /**
     * No email row: the login endpoint resolves strictly through
     * `profile_emails`, so the principal is not addressable there at all. This
     * is the structural half of the protection, independent of any code path.
     */
    public function testThePrincipalHasNoEmailAndSoCannotBeAddressedAtLogin(): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM profile_emails WHERE profile_id = :id');
        $stmt->execute([':id' => $this->principalId()]);

        self::assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testThePrincipalHoldsNoCredential(): void
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $this->principalId()]);

        self::assertSame('', (string) $stmt->fetchColumn());
        self::assertFalse(AuthMethod::holdsLocalCredential(AuthMethod::SERVICE));
        self::assertTrue(AuthMethod::isService(AuthMethod::SERVICE));
    }

    public function testAPasswordWriteIsRefused(): void
    {
        $authMethod = new AuthMethod($this->pdo);
        $id = $this->principalId();

        self::assertTrue($authMethod->refusesLocalPassword($id));

        $this->expectException(LocalPasswordRefusedException::class);
        $authMethod->setPasswordHash($id, password_hash('irrelevant', PASSWORD_BCRYPT));
    }

    /**
     * The sharp one. `$override` exists so an administrator can give an
     * IdP-backed account a local password — a legitimate capability that must
     * not extend here, or it becomes the single call that turns the CLI's
     * system-tenant admin into a login.
     */
    public function testAPasswordWriteIsRefusedEvenWithOverride(): void
    {
        $authMethod = new AuthMethod($this->pdo);
        $id = $this->principalId();

        try {
            $authMethod->setPasswordHash($id, password_hash('irrelevant', PASSWORD_BCRYPT), true);
            self::fail('override must not reach a service principal');
        } catch (LocalPasswordRefusedException) {
            // expected
        }

        self::assertSame(AuthMethod::SERVICE, $this->authMethod($id));

        $stmt = $this->pdo->prepare('SELECT password_hash FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $id]);
        self::assertSame('', (string) $stmt->fetchColumn(), 'No credential may be written.');
    }

    /** Linking an external identity must not recompute the principal to 'idp'. */
    public function testLinkingAnExternalIdentityDoesNotLeaveTheServiceState(): void
    {
        $authMethod = new AuthMethod($this->pdo);
        $id = $this->principalId();

        $authMethod->onExternalIdentityLinked($id);

        self::assertSame(
            AuthMethod::SERVICE,
            $this->authMethod($id),
            'Recomputing to idp would open the override path and complete the escalation.'
        );
    }

    /** Nor may unlinking recompute it to 'local', which refuses nothing. */
    public function testUnlinkingDoesNotLeaveTheServiceState(): void
    {
        $authMethod = new AuthMethod($this->pdo);
        $id = $this->principalId();

        $authMethod->onExternalIdentityUnlinked($id);

        self::assertSame(AuthMethod::SERVICE, $this->authMethod($id));
    }

    /**
     * `compose()` derives a value from the two credential booleans, and
     * `'service'` is not derivable from them — that is exactly why it is held
     * rather than computed. A caller that recomposed a service principal would
     * silently downgrade it.
     */
    public function testComposeNeverProducesTheServiceState(): void
    {
        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$local, $idp]) {
            self::assertNotSame(AuthMethod::SERVICE, AuthMethod::compose($local, $idp));
        }
    }

    public function testServiceIsAPermittedStoredValue(): void
    {
        self::assertTrue(AuthMethod::isValid(AuthMethod::SERVICE));
        self::assertContains(AuthMethod::SERVICE, AuthMethod::all());
    }

    /**
     * Re-running the seed must not produce a second principal.
     *
     * A migration that already ran is never re-run, but this one is also the
     * repair path for a database restored mid-upgrade, and two principals would
     * make the CLI's identity depend on row order.
     */
    public function testSeedingIsIdempotent(): void
    {
        $before = $this->principalId();

        $pdo = $this->pdo;
        \Database\Migrations\SeedCliServicePrincipal::up(
            \Whity\Database\Database::withFactory(static fn (): PDO => $pdo)
        );

        self::assertSame($before, $this->principalId());
    }
}
