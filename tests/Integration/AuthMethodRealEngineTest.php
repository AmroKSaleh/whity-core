<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\AddAuthMethodToProfiles;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\BackupCodesService;
use Whity\Core\Identity\AuthMethod;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\FederatedProviderContext;
use Whity\Core\Identity\LocalPasswordRefusedException;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Database\Database;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Real-engine tests for `profiles.auth_method` (#916, migration 104) and
 * {@see AuthMethod}, the single writer of `profiles.password_hash`.
 *
 * WHY THIS LIVES IN tests/Integration
 * -----------------------------------
 * So it runs on BOTH engines. The default suite gives it in-memory SQLite; CI's
 * postgres job runs `--testsuite Integration` against real PostgreSQL. Migration
 * 104 adds a NOT NULL column to a table that already has one, backfills it with
 * a correlated `EXISTS`, and attaches a CHECK constraint on one engine and not
 * the other — every part of that is somewhere the two can disagree, and a
 * SQLite-only green would prove nothing about the deployments that matter.
 *
 * The refusal is asserted directly and in both directions. A test that only
 * drove the happy path would pass with the defect present, which is how the
 * defect survived long enough to be reported by a customer.
 */
final class AuthMethodRealEngineTest extends TestCase
{
    private const ISS = 'https://accounts.google.com';

    private PDO $pdo;
    private AuthMethod $authMethod;
    private ExternalIdentityRepository $identities;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->authMethod = new AuthMethod($this->pdo);
        $this->identities = new ExternalIdentityRepository($this->pdo);
    }

    // ==================== the vocabulary ====================

    /**
     * compose() is the whole mapping, including the combination that has no
     * value of its own.
     *
     * The (no credential, no identity) case folds to 'local' deliberately: it is
     * an account no identity provider governs any more, so an administrator
     * giving it a password needs no override. Pinned because the alternative —
     * leaving it on 'idp' — would make a stranded ex-SSO account permanently
     * unrecoverable through the normal path, which is a worse failure than the
     * one this column exists to prevent.
     */
    public function testComposeMapsBothCredentialsAndFoldsTheStrandedCase(): void
    {
        self::assertSame(AuthMethod::LOCAL, AuthMethod::compose(true, false));
        self::assertSame(AuthMethod::IDP, AuthMethod::compose(false, true));
        self::assertSame(AuthMethod::BOTH, AuthMethod::compose(true, true));
        self::assertSame(
            AuthMethod::LOCAL,
            AuthMethod::compose(false, false),
            'an account with neither credential is governed by no external authority'
        );
    }

    public function testOnlyIdpIsTreatedAsHoldingNoLocalCredential(): void
    {
        self::assertTrue(AuthMethod::holdsLocalCredential(AuthMethod::LOCAL));
        self::assertTrue(AuthMethod::holdsLocalCredential(AuthMethod::BOTH));
        self::assertFalse(AuthMethod::holdsLocalCredential(AuthMethod::IDP));

        self::assertTrue(AuthMethod::involvesIdp(AuthMethod::IDP));
        self::assertTrue(AuthMethod::involvesIdp(AuthMethod::BOTH));
        self::assertFalse(AuthMethod::involvesIdp(AuthMethod::LOCAL));
    }

    // ==================== migration 104 ====================

    /**
     * The backfill stamps every pre-existing row with the value the old
     * inference would have produced.
     *
     * All four rows are given a deliberately WRONG value first, so the assertion
     * cannot be satisfied by the column DEFAULT — a backfill that silently did
     * nothing would otherwise pass for three of the four.
     */
    public function testTheBackfillClassifiesEveryExistingRow(): void
    {
        $local    = $this->seedProfile('local@example.com', 'pw');
        $idp      = $this->seedProfile('idp@example.com', '');
        $both     = $this->seedProfile('both@example.com', 'pw');
        $stranded = $this->seedProfile('stranded@example.com', '');

        $this->link($idp, 'sub-idp');
        $this->link($both, 'sub-both');

        // Return the table to the pre-104 state as far as this column is
        // concerned: no row's value is the one the backfill should produce.
        $this->pdo->exec("UPDATE profiles SET auth_method = 'both'");

        AddAuthMethodToProfiles::up($this->database());

        self::assertSame(AuthMethod::LOCAL, $this->stored($local), 'password, no identity');
        self::assertSame(AuthMethod::IDP, $this->stored($idp), 'identity, no password');
        self::assertSame(AuthMethod::BOTH, $this->stored($both), 'both credentials');
        self::assertSame(AuthMethod::LOCAL, $this->stored($stranded), 'neither credential');
    }

    /**
     * up() twice, then down() and up() again, on whichever engine is running.
     *
     * Re-running migrations is a real operation here — `scripts/ci-local.sh pg`
     * runs `migrate run` twice on purpose — and the PostgreSQL branch adds a
     * CHECK constraint with a statement that has no `IF NOT EXISTS`, so a naive
     * second run would fail on the deployment path rather than in a test.
     */
    public function testTheMigrationIsIdempotentAndReversible(): void
    {
        $db = $this->database();
        $id = $this->seedProfile('idempotent@example.com', 'pw');

        AddAuthMethodToProfiles::up($db);
        AddAuthMethodToProfiles::up($db);
        self::assertSame(AuthMethod::LOCAL, $this->stored($id), 'a second up() must be a no-op, not a failure');

        AddAuthMethodToProfiles::down($db);
        self::assertFalse($this->hasAuthMethodColumn(), 'down() must drop the column');

        AddAuthMethodToProfiles::up($db);
        self::assertSame(
            AuthMethod::LOCAL,
            $this->stored($id),
            'up() after down() must reconstruct the fact from data it never touched'
        );
    }

    /**
     * PostgreSQL refuses a value outside the vocabulary at the database.
     *
     * Skipped on SQLite, which cannot take an added CHECK without rebuilding a
     * table a dozen others reference — see migration 104's engine notes. The
     * SQLite half of the same guarantee is the test below.
     */
    public function testPostgresRefusesAValueOutsideTheVocabulary(): void
    {
        if (!$this->isPostgres()) {
            self::markTestSkipped('the CHECK constraint is added on PostgreSQL only');
        }

        $id = $this->seedProfile('check@example.com', 'pw');

        $this->expectException(PDOException::class);
        $this->pdo->prepare('UPDATE profiles SET auth_method = :bad WHERE id = :id')
            ->execute([':bad' => 'something-else', ':id' => $id]);
    }

    /**
     * A stored value outside the vocabulary is reported as the REFUSING one.
     *
     * Only reachable where the CHECK is absent, so this runs on SQLite. The
     * direction is the point: a column whose contents cannot be trusted must not
     * be read as permission to mint a credential.
     */
    public function testAnUnrecognisedStoredValueFailsClosed(): void
    {
        if ($this->isPostgres()) {
            self::markTestSkipped('PostgreSQL rejects the value at the database (see the test above)');
        }

        $id = $this->seedProfile('corrupt@example.com', 'pw');
        $this->pdo->prepare('UPDATE profiles SET auth_method = :bad WHERE id = :id')
            ->execute([':bad' => 'garbage', ':id' => $id]);

        self::assertSame(AuthMethod::IDP, $this->authMethod->of($id));
        self::assertTrue($this->authMethod->refusesLocalPassword($id));
    }

    public function testOfReturnsNullForAProfileThatDoesNotExist(): void
    {
        self::assertNull($this->authMethod->of(987654));
        self::assertFalse(
            $this->authMethod->refusesLocalPassword(987654),
            'a profile that does not exist is a not-found problem, not a policy one'
        );
    }

    // ==================== the refusal ====================

    /**
     * THE test for #916: an IdP-backed profile cannot be given a local password,
     * and the refusal leaves the row untouched.
     *
     * The hash and the token epoch are both re-read afterwards. Asserting only
     * the exception would let a version through that threw AFTER writing.
     */
    public function testSettingAPasswordOnAnIdpBackedProfileIsRefusedAndWritesNothing(): void
    {
        $id = $this->seedIdpProfile('sso@example.com', 'sub-sso');

        $epochBefore = $this->tokenEpoch($id);

        try {
            $this->authMethod->setPasswordHash($id, password_hash('injected', PASSWORD_BCRYPT));
            self::fail('setting a local password on an IdP-backed profile must be refused');
        } catch (LocalPasswordRefusedException $e) {
            self::assertStringContainsString('identity provider', $e->getMessage());
        }

        self::assertSame('', $this->passwordHash($id), 'no credential may be written');
        self::assertSame(AuthMethod::IDP, $this->stored($id), 'the held fact must not move');
        self::assertSame($epochBefore, $this->tokenEpoch($id), 'a refused write must not evict sessions');
        self::assertFalse(
            password_verify('injected', $this->passwordHash($id)),
            'the refused password must not verify'
        );
    }

    /**
     * The explicit override is the way through, and taking it moves the account
     * to 'both' rather than leaving the held fact lying.
     */
    public function testTheOverrideCreatesTheCredentialAndMovesTheAccountToBoth(): void
    {
        $id = $this->seedIdpProfile('sso-override@example.com', 'sub-ovr');
        $epochBefore = $this->tokenEpoch($id);

        $this->authMethod->setPasswordHash($id, password_hash('deliberate', PASSWORD_BCRYPT), true);

        self::assertTrue(password_verify('deliberate', $this->passwordHash($id)));
        self::assertSame(AuthMethod::BOTH, $this->stored($id));
        self::assertSame($epochBefore + 1, $this->tokenEpoch($id), 'a credential change evicts sessions');
    }

    /**
     * An account that already holds a local credential beside its IdP does not
     * need the override to have that credential CHANGED.
     *
     * The defect was the silent creation of a second way in, not its continued
     * existence — so refusing here would break ordinary password rotation on
     * accounts the operator has already decided about.
     */
    public function testABothAccountNeedsNoOverrideToRotateItsPassword(): void
    {
        $id = $this->seedProfile('both-rotate@example.com', 'pw');
        $this->link($id, 'sub-both-rotate');
        self::assertSame(AuthMethod::BOTH, $this->stored($id));

        $this->authMethod->setPasswordHash($id, password_hash('rotated', PASSWORD_BCRYPT));

        self::assertTrue(password_verify('rotated', $this->passwordHash($id)));
        self::assertSame(AuthMethod::BOTH, $this->stored($id), 'rotation must not change the held fact');
    }

    public function testALocalAccountKeepsItsHeldFactThroughAPasswordChange(): void
    {
        $id = $this->seedProfile('local-rotate@example.com', 'pw');

        $this->authMethod->setPasswordHash($id, password_hash('rotated', PASSWORD_BCRYPT));

        self::assertTrue(password_verify('rotated', $this->passwordHash($id)));
        self::assertSame(AuthMethod::LOCAL, $this->stored($id));
    }

    /**
     * A write naming a profile that is not there is distinguished from a
     * refusal, because the two mean opposite things to a caller.
     */
    public function testAWriteForAMissingProfileReportsThatAndNotARefusal(): void
    {
        $this->expectException(LocalPasswordRefusedException::class);
        $this->expectExceptionMessage('no profile with id 987654');

        $this->authMethod->setPasswordHash(987654, password_hash('x', PASSWORD_BCRYPT));
    }

    // ==================== keeping the fact true ====================

    public function testLinkingAnIdentityToALocalAccountMakesItBoth(): void
    {
        $id = $this->seedProfile('linkme@example.com', 'pw');
        self::assertSame(AuthMethod::LOCAL, $this->stored($id));

        $this->link($id, 'sub-link');

        self::assertSame(AuthMethod::BOTH, $this->stored($id));
    }

    public function testLinkingASecondIdentityToABothAccountKeepsItBoth(): void
    {
        $id = $this->seedProfile('twolinks@example.com', 'pw');
        $this->link($id, 'sub-one');
        $this->link($id, 'sub-two', 'microsoft', 'https://login.microsoftonline.com');

        self::assertSame(
            AuthMethod::BOTH,
            $this->stored($id),
            'a second identity must not erase the local credential the account holds'
        );
    }

    public function testLinkingASecondIdentityToAnIdpAccountKeepsItIdp(): void
    {
        $id = $this->seedIdpProfile('idp-two@example.com', 'sub-one');
        $this->link($id, 'sub-two', 'microsoft', 'https://login.microsoftonline.com');

        self::assertSame(AuthMethod::IDP, $this->stored($id));
    }

    /**
     * Removing the last identity returns the account to 'local' — the state that
     * says no external authority governs it — whether or not it holds a
     * password.
     */
    public function testUnlinkingTheLastIdentityReturnsTheAccountToLocal(): void
    {
        $withPassword = $this->seedProfile('unlink-pw@example.com', 'pw');
        $linkId = $this->link($withPassword, 'sub-unlink');
        self::assertSame(AuthMethod::BOTH, $this->stored($withPassword));

        $this->identities->unlink($linkId, $withPassword);
        self::assertSame(AuthMethod::LOCAL, $this->stored($withPassword));

        $ssoOnly = $this->seedProfile('unlink-sso@example.com', '');
        $ssoLinkId = $this->link($ssoOnly, 'sub-sso-unlink');
        self::assertSame(AuthMethod::IDP, $this->stored($ssoOnly));

        $this->identities->unlink($ssoLinkId, $ssoOnly);
        self::assertSame(
            AuthMethod::LOCAL,
            $this->stored($ssoOnly),
            'a stranded ex-SSO account must become recoverable, not stay permanently refusing'
        );
    }

    public function testUnlinkingWhileAnotherIdentityRemainsChangesNothing(): void
    {
        $id = $this->seedProfile('keeps-idp@example.com', '');
        $this->link($id, 'sub-a');
        $second = $this->link($id, 'sub-b', 'microsoft', 'https://login.microsoftonline.com');

        $this->identities->unlink($second, $id);

        self::assertSame(
            AuthMethod::IDP,
            $this->stored($id),
            'the account is still IdP-backed while any link remains'
        );
    }

    /** A failed unlink (wrong profile) must not recompute anything. */
    public function testAnUnlinkThatMatchesNoRowLeavesTheHeldFactAlone(): void
    {
        $owner = $this->seedProfile('owner@example.com', '');
        $linkId = $this->link($owner, 'sub-owner');
        $other = $this->seedProfile('other@example.com', 'pw');

        self::assertSame(0, $this->identities->unlink($linkId, $other));
        self::assertSame(AuthMethod::IDP, $this->stored($owner));
        self::assertSame(AuthMethod::LOCAL, $this->stored($other));
    }

    /**
     * The federated just-in-time provisioning path states 'idp' on the row it
     * creates rather than letting the column default to 'local'.
     *
     * This is the load-bearing one for the whole design: if a passwordless
     * profile were created on the default, ExternalIdentityRepository::link()
     * would read 'local', conclude the account holds a credential, and stamp
     * 'both' — reproducing the original defect from the other side.
     */
    public function testFederatedProvisioningStampsIdpOnTheNewProfile(): void
    {
        $linker = new FederatedIdentityLinker(
            $this->pdo,
            $this->identities,
            new ProfileEmailRepository($this->pdo),
            new MembershipRepository($this->pdo),
            new TenantEmailDomainsRepository($this->pdo),
        );

        // Global trust (the operator's own IdP, system tenant 0): no local
        // profile owns jit@example.com, so this is the just-in-time
        // provisioning branch.
        $result = $linker->resolveForLogin(
            new ExternalIdentity(self::ISS, 'jit-subject', 'jit@example.com', true, 'JIT User'),
            new FederatedProviderContext(0, 'google', 0),
        );

        self::assertSame('provisioned', $result['status']);

        // `profile_id` is optional on the return shape (the refusal statuses
        // carry none), so it is read defensively rather than asserted into
        // existence — the assertion below is what proves the branch taken.
        $profileId = isset($result['profile_id']) ? (int) $result['profile_id'] : 0;
        self::assertGreaterThan(0, $profileId, 'a provisioned result must name the new profile');

        self::assertSame('', $this->passwordHash($profileId), 'a JIT profile is passwordless');
        self::assertSame(
            AuthMethod::IDP,
            $this->stored($profileId),
            'a passwordless federated profile must SAY it holds no local credential'
        );
        self::assertTrue($this->authMethod->refusesLocalPassword($profileId));
    }

    // ==================== the reset paths ====================

    public function testIssuingAResetTokenForAnIdpBackedProfileIsRefused(): void
    {
        $id = $this->seedIdpProfile('reset-sso@example.com', 'sub-reset');
        $service = new PasswordResetService($this->pdo);

        $this->expectException(LocalPasswordRefusedException::class);

        try {
            $service->issue($id);
        } finally {
            self::assertSame(
                0,
                $this->countRows('SELECT COUNT(*) FROM password_resets'),
                'no token may be persisted for an account that cannot hold a local password'
            );
        }
    }

    /**
     * A token issued while the account was local stops working once the account
     * becomes IdP-only, and reports as an unknown token rather than as an error
     * that names the account's state.
     */
    public function testConfirmingATokenAfterTheAccountBecameIdpOnlyChangesNothing(): void
    {
        $id = $this->seedProfile('became-sso@example.com', 'pw');
        $service = new PasswordResetService($this->pdo);
        $token = $service->issue($id);

        $this->becomeIdpOnly($id);

        self::assertNull(
            $service->confirm($token, 'brand-new-password', false),
            'the token must read as unknown rather than mint a credential'
        );
        self::assertSame('', $this->passwordHash($id));
        self::assertSame(AuthMethod::IDP, $this->stored($id));
    }

    /**
     * The same guard on the admin-approval branch, where the hash was staged
     * before the account changed and is applied by a different request.
     */
    public function testApprovingAStagedResetIsRefusedOnceTheAccountIsIdpOnly(): void
    {
        $id = $this->seedProfile('staged-sso@example.com', 'pw');
        $this->seedTenantMembership($id, 1);

        $service = new PasswordResetService($this->pdo);
        $token = $service->issue($id);
        $confirmed = $service->confirm($token, 'staged-password', true);
        self::assertNotNull($confirmed);
        self::assertFalse($confirmed['applied'], 'the reset must be staged, not applied');

        $this->becomeIdpOnly($id);

        self::assertNull(
            $service->approveForTenant($confirmed['request_id'], 1),
            'an approval for an account that no longer takes local passwords finds nothing approvable'
        );
        self::assertSame('', $this->passwordHash($id), 'the staged hash must not be applied');
        self::assertSame(AuthMethod::IDP, $this->stored($id));
    }

    // ==================== fixtures ====================

    /**
     * A profile plus its verified primary email. `auth_method` is derived from
     * whether a credential was asked for — the same obligation every production
     * creator carries, and the reason a fixture may not simply leave the column
     * on its DEFAULT.
     */
    private function seedProfile(string $email, string $password): int
    {
        $hash = $password === '' ? '' : password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles
                 (display_name, password_hash, auth_method, two_factor_enabled, two_factor_secret,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, :am, false, NULL, 0, 0, NOW(), NOW())'
        );
        $stmt->execute([
            ':dn' => strstr($email, '@', true) ?: $email,
            ':ph' => $hash,
            ':am' => $hash === '' ? AuthMethod::IDP : AuthMethod::LOCAL,
        ]);
        $id = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (:pid, :email, true, true, NOW())'
        )->execute([':pid' => $id, ':email' => $email]);

        return $id;
    }

    /** A passwordless profile with one linked global-trust identity. */
    private function seedIdpProfile(string $email, string $subject): int
    {
        $id = $this->seedProfile($email, '');
        $this->link($id, $subject);

        return $id;
    }

    private function link(
        int $profileId,
        string $subject,
        string $providerKey = 'google',
        string $issuer = self::ISS,
    ): int {
        return $this->identities->link($profileId, $providerKey, $issuer, $subject, null, null);
    }

    /**
     * The state a reset token can outlive: the local credential is gone and an
     * identity provider has taken over.
     *
     * Written directly because no single API call does this — which is precisely
     * why confirm() and approveForTenant() cannot assume the check issue()
     * already made still holds.
     */
    private function becomeIdpOnly(int $profileId): void
    {
        $this->pdo->prepare("UPDATE profiles SET password_hash = '', auth_method = :am WHERE id = :id")
            ->execute([':am' => AuthMethod::IDP, ':id' => $profileId]);
    }

    private function seedTenantMembership(int $profileId, int $tenantId): void
    {
        $existing = $this->countRows("SELECT COUNT(*) FROM tenants WHERE id = {$tenantId}");
        if ($existing === 0) {
            $this->pdo->prepare('INSERT INTO tenants (id, name, created_at) VALUES (:id, :name, NOW())')
                ->execute([':id' => $tenantId, ':name' => 'tenant-' . $tenantId]);
        }

        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user' LIMIT 1");
        self::assertNotFalse($roleStmt, 'the seeded `user` role must exist');
        $roleId = (int) $roleStmt->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:pid, :tid, :rid, NULL, 'active', NOW())"
        )->execute([':pid' => $profileId, ':tid' => $tenantId, ':rid' => $roleId]);
    }

    /** A Database wrapping the test PDO, so a migration can be re-run in place. */
    private function database(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }

    private function stored(int $profileId): string
    {
        $stmt = $this->pdo->prepare('SELECT auth_method FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return (string) $stmt->fetchColumn();
    }

    private function passwordHash(int $profileId): string
    {
        $stmt = $this->pdo->prepare('SELECT password_hash FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return (string) $stmt->fetchColumn();
    }

    private function tokenEpoch(int $profileId): int
    {
        $stmt = $this->pdo->prepare('SELECT token_epoch FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return (int) $stmt->fetchColumn();
    }

    private function countRows(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, 'count query must prepare: ' . $sql);

        return (int) $stmt->fetchColumn();
    }

    private function hasAuthMethodColumn(): bool
    {
        try {
            $this->pdo->query('SELECT auth_method FROM profiles LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    private function isPostgres(): bool
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    }
    // ==================== 2FA recovery ====================

    /**
     * Approving a 2FA-recovery request for an IdP-backed account is refused, and
     * refused BEFORE anything is stripped.
     *
     * Recovery ends in a password-reset token, so for an account the provider
     * governs alone it would hand out a local credential. The ordering matters
     * as much as the refusal: a guard that fired after clearTwoFactor() would
     * leave the account with its authenticator gone and no recovery to show for
     * it — weaker than before an administrator touched it.
     */
    public function testApprovingTwoFactorRecoveryIsRefusedAndStripsNothing(): void
    {
        $id = $this->seedProfile('2fa-sso@example.com', 'pw');
        $this->seedTenantMembership($id, 1);
        $this->enrolTwoFactor($id);

        $recovery = $this->twoFactorRecovery();
        $token = $recovery->issue($id);
        $confirmed = $recovery->confirm($token);
        self::assertNotNull($confirmed, 'the ownership-proof step is unrelated to auth_method');

        // The provider takes the account over between the request and the
        // approval — the window an approval queue necessarily has.
        $this->becomeIdpOnly($id);

        self::assertNull(
            $recovery->approveForTenant((int) $confirmed['request_id'], 1),
            'an approval that would mint a local credential must find nothing approvable'
        );
        self::assertTrue($this->twoFactorEnrolled($id), 'a refused recovery must not disarm the account');
        self::assertSame('', $this->passwordHash($id));
        self::assertSame(
            0,
            $this->countRows('SELECT COUNT(*) FROM password_resets'),
            'no reset token may be minted'
        );
    }

    /**
     * The administrator's break-glass force-reset is refused on the same terms.
     *
     * It bypasses the request/confirm steps entirely, so it needs its own guard —
     * a check that lived only on the approval path would leave this one open,
     * which is the shape of hole this whole change exists to close.
     */
    public function testForcingATwoFactorResetIsRefusedAndStripsNothing(): void
    {
        $id = $this->seedProfile('2fa-force@example.com', '');
        $this->link($id, 'sub-force');
        $this->seedTenantMembership($id, 1);
        $this->enrolTwoFactor($id);

        self::assertNull(
            $this->twoFactorRecovery()->forceResetForTenant($id, 1),
            'a forced reset for an IdP-backed account must be refused'
        );
        self::assertTrue($this->twoFactorEnrolled($id));
        self::assertSame('', $this->passwordHash($id));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM password_resets'));
    }

    /**
     * The same force-reset for an ordinary local account still works.
     *
     * Without this the two tests above would pass just as well against a version
     * that refused everybody.
     */
    public function testForcingATwoFactorResetStillWorksForALocalAccount(): void
    {
        $id = $this->seedProfile('2fa-local@example.com', 'pw');
        $this->seedTenantMembership($id, 1);
        $this->enrolTwoFactor($id);

        $result = $this->twoFactorRecovery()->forceResetForTenant($id, 1);

        self::assertNotNull($result);
        self::assertNotSame('', (string) $result['reset_token']);
        self::assertFalse($this->twoFactorEnrolled($id), 'a successful recovery clears the enrolment');
    }

    private function twoFactorRecovery(): TwoFactorRecoveryService
    {
        return new TwoFactorRecoveryService(
            $this->pdo,
            new PasswordResetService($this->pdo),
            new BackupCodesService($this->pdo),
        );
    }

    private function enrolTwoFactor(int $profileId): void
    {
        $this->pdo->prepare(
            'UPDATE profiles SET two_factor_enabled = true, two_factor_secret = :s WHERE id = :id'
        )->execute([':s' => 'FIXTURESECRET', ':id' => $profileId]);
    }

    private function twoFactorEnrolled(int $profileId): bool
    {
        $stmt = $this->pdo->prepare('SELECT two_factor_enabled FROM profiles WHERE id = :id');
        $stmt->execute([':id' => $profileId]);

        return (bool) $stmt->fetchColumn();
    }
}
