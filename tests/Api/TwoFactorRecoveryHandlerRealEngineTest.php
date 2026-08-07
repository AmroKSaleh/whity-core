<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TwoFactorRecoveryHandler;
use Whity\Auth\BackupCodesService;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TwoFactorRecoveryMailer;
use Whity\Core\Identity\TwoFactorRecoveryService;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\Mailer;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * Real-engine tests for the public "I lost my 2FA device" recovery-request
 * endpoints (WC-password-reset-2fa-recovery).
 *
 * Drives the REAL {@see TwoFactorRecoveryHandler} against the migration-built
 * schema. Proves: generic/no-enumeration responses on `request`; rate
 * limiting; that `confirm` only CREATES the pending admin-queue entry and
 * never itself clears 2FA or touches any other profile field.
 */
final class TwoFactorRecoveryHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private TwoFactorRecoveryService $service;
    private ProfileEmailRepository $emails;
    private SettingsService $settings;
    /** @var Mailer&object{sent: list<array{to: string, subject: string, body: string}>} */
    private Mailer $mailer;
    private TwoFactorRecoveryHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $dbWrapper = new class ($this->pdo) {
            public function __construct(private readonly PDO $pdo) {}

            /**
             * @param array<int|string, mixed> $params
             */
            public function query(string $sql, array $params = []): \PDOStatement
            {
                $stmt = $this->pdo->prepare($sql);
                if ($stmt === false) {
                    throw new \RuntimeException('prepare failed');
                }
                $stmt->execute($params);

                return $stmt;
            }
        };
        $this->service = new TwoFactorRecoveryService(
            $this->pdo,
            new PasswordResetService($this->pdo),
            new BackupCodesService($dbWrapper)
        );
        $this->emails = new ProfileEmailRepository($this->pdo);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->settings->setGlobal(SettingsRegistry::SELF_2FA_RECOVERY_ENABLED, 'true');
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_PASSWORD_RESET, 'true');

        $this->mailer = new class implements Mailer {
            /** @var list<array{to: string, subject: string, body: string, html: ?string}> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
            {
                $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody, 'html' => $htmlBody];
            }
        };

        $mailer = new TwoFactorRecoveryMailer($this->mailer, 'https://app.test/account-recovery', new EmailLayout(), $this->settings);

        $this->handler = new TwoFactorRecoveryHandler(
            $this->service,
            $this->emails,
            $mailer,
            new DatabaseSharedStore($this->pdo),
            new AuditLogger($this->pdo, new NullLogger()),
            $this->settings
        );
    }

    // ==================== request ====================

    public function testRequestForKnownEmailSendsConfirmationLinkAndAudits(): void
    {
        $this->seedProfileWithTwoFactor('known@acme.test');

        $res = $this->request('known@acme.test');
        self::assertSame(202, $res->getStatusCode(), $res->getBody());

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('known@acme.test', $this->mailer->sent[0]['to']);
        self::assertStringContainsString('https://app.test/account-recovery?token=', $this->mailer->sent[0]['body']);
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.requested'));
    }

    public function testRequestForUnknownEmailIsGenericAndSendsNothing(): void
    {
        $res = $this->request('nobody@acme.test');
        self::assertSame(202, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent);
    }

    public function testRequestResponseIsIdenticalForKnownAndUnknownAddresses(): void
    {
        $this->seedProfileWithTwoFactor('exists@acme.test');

        $known = $this->request('exists@acme.test');
        $unknown = $this->request('ghost@acme.test');

        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($known->getBody(), $unknown->getBody());
    }

    public function testRequestResponseIsIdenticalWhetherFlowIsEnabledOrNot(): void
    {
        $this->seedProfileWithTwoFactor('flag@acme.test');

        $enabled = $this->request('flag@acme.test');

        $this->settings->setGlobal(SettingsRegistry::SELF_2FA_RECOVERY_ENABLED, 'false');
        $disabled = $this->request('flag@acme.test');

        self::assertSame($enabled->getStatusCode(), $disabled->getStatusCode());
        self::assertSame($enabled->getBody(), $disabled->getBody());
        self::assertCount(1, $this->mailer->sent);
    }

    public function testRequestWithMalformedEmailIs422(): void
    {
        self::assertSame(422, $this->request('not-an-email')->getStatusCode());
    }

    public function testRequestIsRateLimitedPerEmail(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(202, $this->request('flood@acme.test')->getStatusCode(), "request {$i}");
        }

        $throttled = $this->request('flood@acme.test');
        self::assertSame(429, $throttled->getStatusCode());
        self::assertArrayHasKey('retry-after', array_change_key_case($throttled->getHeaders()));
    }

    // ==================== confirm ====================

    public function testConfirmOnlySubmitsTheRequestAndNeverTouchesTwoFactor(): void
    {
        $profileId = $this->seedProfileWithTwoFactor('confirm@acme.test');
        $token = $this->service->issue($profileId);

        $res = $this->confirm($token);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true);
        self::assertSame('pending', $data['data']['status']);

        // Confirming CREATES the pending queue entry — it must NOT itself
        // clear 2FA or touch any other profile field.
        $row = $this->fetchOneRow(
            "SELECT two_factor_enabled, two_factor_secret FROM profiles WHERE id = {$profileId}"
        );
        self::assertContains((string) $row['two_factor_enabled'], ['1', 't', 'true']);
        self::assertSame('encrypted-secret-blob', (string) $row['two_factor_secret']);

        self::assertSame('pending', (string) $this->col(
            "SELECT status FROM two_factor_recovery_requests WHERE profile_id = {$profileId}"
        ));
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.submitted'));
    }

    public function testConfirmRejectsInvalidTokenGenerically(): void
    {
        $res = $this->confirm('not-a-real-token');
        self::assertSame(400, $res->getStatusCode());
        self::assertSame(1, $this->auditCount('auth.2fa_recovery.confirm_failed'));
    }

    public function testConfirmEmptyTokenIs422(): void
    {
        self::assertSame(422, $this->confirm('')->getStatusCode());
    }

    // ==================== helpers ====================

    private function seedProfileWithTwoFactor(string $email): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('2fa user', 'x', true, 'encrypted-secret-blob', 1, 0, datetime('now'), datetime('now'))"
        );
        $profileId = (int) $this->pdo->lastInsertId();
        $this->emails->insert($profileId, $email, true, true);

        return $profileId;
    }

    private function request(string $email): \Whity\Sdk\Http\Response
    {
        return $this->handler->request(
            new Request('POST', '/api/auth/2fa-recovery/request', [], (string) json_encode(['email' => $email]))
        );
    }

    private function confirm(string $token): \Whity\Sdk\Http\Response
    {
        return $this->handler->confirm(
            new Request('POST', '/api/auth/2fa-recovery/confirm', [], (string) json_encode(['token' => $token]))
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

    /**
     * @return array<string, mixed>
     */
    private function fetchOneRow(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
