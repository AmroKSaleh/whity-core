<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PasswordResetHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetMailer;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * Real-engine tests for the public "forgot password" endpoints
 * (WC-password-reset-2fa-recovery).
 *
 * Drives the REAL {@see PasswordResetHandler} (with the real service, mailer,
 * shared-store throttle, audit logger, and settings service) against the
 * migration-built schema. Proves: generic/no-enumeration responses regardless
 * of address existence AND regardless of whether self-service reset is
 * enabled; rate limiting; the approval-required branching; and — critically —
 * that a normal reset-confirm never touches an account's 2FA fields.
 */
final class PasswordResetHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PasswordResetService $service;
    private ProfileEmailRepository $emails;
    private SettingsService $settings;
    /** @var Mailer&object{sent: list<array{to: string, subject: string, body: string}>} */
    private Mailer $mailer;
    private PasswordResetHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->service = new PasswordResetService($this->pdo);
        $this->emails = new ProfileEmailRepository($this->pdo);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        // Secure defaults per the design: reset OPEN, approval OFF. Individual
        // tests flip these explicitly to exercise the other branches.
        $this->settings->setGlobal(SettingsRegistry::SELF_PASSWORD_RESET_ENABLED, 'true');
        $this->settings->setGlobal(SettingsRegistry::PASSWORD_RESET_APPROVAL_REQUIRED, 'false');
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET, 'true');

        $this->mailer = new class implements Mailer {
            /** @var list<array{to: string, subject: string, body: string, html: ?string}> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody, 'html' => $htmlBody];
            }
        };

        $mailer = new PasswordResetMailer($this->mailer, 'https://app.test/reset-password', new EmailLayout(), $this->settings);

        $this->handler = new PasswordResetHandler(
            $this->service,
            $this->emails,
            $mailer,
            new DatabaseSharedStore($this->pdo),
            new AuditLogger($this->pdo, new NullLogger()),
            $this->settings
        );
    }

    // ==================== forgot ====================

    public function testForgotForKnownEmailSendsLinkAndAudits(): void
    {
        $this->seedProfile('known@acme.test', 'old-password');

        $res = $this->forgot('known@acme.test');
        self::assertSame(202, $res->getStatusCode(), $res->getBody());

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('known@acme.test', $this->mailer->sent[0]['to']);
        self::assertStringContainsString('https://app.test/reset-password?token=', $this->mailer->sent[0]['body']);
        self::assertSame(1, $this->auditCount('auth.password_reset.requested'));
    }

    public function testForgotForUnknownEmailIsGenericAndSendsNothing(): void
    {
        $res = $this->forgot('nobody@acme.test');
        self::assertSame(202, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent);
        self::assertSame(0, $this->auditCount('auth.password_reset.requested'));
    }

    public function testForgotResponseIsIdenticalForKnownAndUnknownAddresses(): void
    {
        $this->seedProfile('exists@acme.test', 'x');

        $known = $this->forgot('exists@acme.test');
        $unknown = $this->forgot('ghost@acme.test');

        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($known->getBody(), $unknown->getBody(), 'must never reveal whether the address exists');
    }

    public function testForgotResponseIsIdenticalWhetherSelfServiceIsEnabledOrNot(): void
    {
        $this->seedProfile('flag@acme.test', 'x');

        $enabled = $this->forgot('flag@acme.test');

        $this->settings->setGlobal(SettingsRegistry::SELF_PASSWORD_RESET_ENABLED, 'false');
        $disabled = $this->forgot('flag@acme.test');

        self::assertSame($enabled->getStatusCode(), $disabled->getStatusCode());
        self::assertSame($enabled->getBody(), $disabled->getBody(), 'must never reveal whether the flag is enabled');
        // Only the FIRST (enabled) call should have dispatched anything.
        self::assertCount(1, $this->mailer->sent);
    }

    public function testForgotWithMalformedEmailIs422(): void
    {
        self::assertSame(422, $this->forgot('not-an-email')->getStatusCode());
        self::assertSame(422, $this->forgot('')->getStatusCode());
    }

    public function testForgotIsRateLimitedPerEmail(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(202, $this->forgot('flood@acme.test')->getStatusCode(), "request {$i}");
        }

        $throttled = $this->forgot('flood@acme.test');
        self::assertSame(429, $throttled->getStatusCode());
        self::assertArrayHasKey('retry-after', array_change_key_case($throttled->getHeaders()));
    }

    // ==================== reset (confirm) ====================

    public function testResetAppliesImmediatelyWhenApprovalNotRequired(): void
    {
        $profileId = $this->seedProfile('reset@acme.test', 'old-password');
        $token = $this->service->issue($profileId);

        $res = $this->reset($token, 'brand-new-password-1');
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true);
        self::assertSame('applied', $data['data']['status']);

        self::assertTrue(password_verify(
            'brand-new-password-1',
            (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}")
        ));
        self::assertSame(1, $this->auditCount('auth.password_reset.completed'));
    }

    public function testResetStagesForApprovalWhenRequired(): void
    {
        $this->settings->setGlobal(SettingsRegistry::PASSWORD_RESET_APPROVAL_REQUIRED, 'true');

        $profileId = $this->seedProfile('staged@acme.test', 'stays-the-same');
        $originalHash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}");
        $token = $this->service->issue($profileId);

        $res = $this->reset($token, 'staged-new-password');
        self::assertSame(200, $res->getStatusCode());
        $data = json_decode($res->getBody(), true);
        self::assertSame('awaiting_approval', $data['data']['status']);

        // profiles is UNTOUCHED until an admin approves.
        self::assertSame($originalHash, (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$profileId}"));
        self::assertSame(1, $this->auditCount('auth.password_reset.submitted_for_approval'));
    }

    public function testResetRejectsInvalidTokenGenerically(): void
    {
        $res = $this->reset('not-a-real-token', 'irrelevant-password-1');
        self::assertSame(400, $res->getStatusCode());
        self::assertSame(1, $this->auditCount('auth.password_reset.failed'));
    }

    public function testResetRejectsWeakPassword(): void
    {
        $profileId = $this->seedProfile('weak@acme.test', 'x');
        $token = $this->service->issue($profileId);

        self::assertSame(422, $this->reset($token, 'short')->getStatusCode());
    }

    public function testResetEmptyTokenIs422(): void
    {
        self::assertSame(422, $this->reset('', 'a-strong-password-here')->getStatusCode());
    }

    // ==================== HARD INVARIANT: never touches 2FA ====================

    public function testResetConfirmNeverTouchesTwoFactorFieldsWhenApplyingImmediately(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('has2fa@acme.test');
        $token = $this->service->issue($profileId);

        $res = $this->reset($token, 'new-password-keep-2fa');
        self::assertSame(200, $res->getStatusCode());

        $this->assertTwoFactorUntouched($profileId);
    }

    public function testResetConfirmNeverTouchesTwoFactorFieldsWhenStagedForApproval(): void
    {
        $this->settings->setGlobal(SettingsRegistry::PASSWORD_RESET_APPROVAL_REQUIRED, 'true');

        $profileId = $this->seedProfileWithTwoFactor('has2fa-staged@acme.test');
        $token = $this->service->issue($profileId);

        $res = $this->reset($token, 'new-password-keep-2fa-2');
        self::assertSame(200, $res->getStatusCode());

        $this->assertTwoFactorUntouched($profileId);
    }

    // ==================== helpers ====================

    private function assertTwoFactorUntouched(int $profileId): void
    {
        $stmt = $this->pdo->query(
            "SELECT two_factor_enabled, two_factor_secret, two_factor_backup_codes_version
             FROM profiles WHERE id = {$profileId}"
        );
        if ($stmt === false) {
            self::fail('query failed');
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true'], '2FA must remain enabled');
        self::assertSame('encrypted-secret-blob', (string) $row['two_factor_secret']);
        self::assertSame('4', (string) $row['two_factor_backup_codes_version']);
    }

    private function seedProfileWithTwoFactor(string $email): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('2fa user', 'x', true, 'encrypted-secret-blob', 4, 0, datetime('now'), datetime('now'))"
        );
        $profileId = (int) $this->pdo->lastInsertId();
        $this->emails->insert($profileId, $email, true, true);

        return $profileId;
    }

    private function seedProfile(string $email, string $passwordSeed): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, false, NULL, 0, 0, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([':dn' => 'Test User', ':ph' => password_hash($passwordSeed, PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();
        $this->emails->insert($profileId, $email, true, true);

        return $profileId;
    }

    private function forgot(string $email): \Whity\Sdk\Http\Response
    {
        return $this->handler->forgot(
            new Request('POST', '/api/auth/password/forgot', [], (string) json_encode(['email' => $email]))
        );
    }

    private function reset(string $token, string $password): \Whity\Sdk\Http\Response
    {
        return $this->handler->reset(
            new Request('POST', '/api/auth/password/reset', [], (string) json_encode([
                'token' => $token,
                'password' => $password,
            ]))
        );
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :a');
        $stmt->execute([':a' => $action]);

        return (int) $stmt->fetchColumn();
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
    }
}
