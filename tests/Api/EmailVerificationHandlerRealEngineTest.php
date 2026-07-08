<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\EmailVerificationHandler;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\EmailVerificationService;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Identity\TokenEmailVerificationProvider;
use Whity\Core\Mail\Mailer;
use Whity\Core\Request;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * Real-engine tests for the public email-verification endpoints (WC-235).
 *
 * Drives the REAL {@see EmailVerificationHandler} (with the real service,
 * provider, shared-store throttle and audit logger) against the migration-built
 * schema. Proves: request (re)issues + delivers only for a known unverified
 * address, is generic (no enumeration) and rate-limited; confirm consumes a
 * valid token and rejects bad ones; both write audit records.
 */
final class EmailVerificationHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private EmailVerificationService $service;
    private ProfileEmailRepository $emails;
    /** @var Mailer&object{sent: list<array{to: string, subject: string, body: string}>} */
    private Mailer $mailer;
    private EmailVerificationHandler $handler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->service = new EmailVerificationService($this->pdo);
        $this->emails = new ProfileEmailRepository($this->pdo);

        $this->mailer = new class implements Mailer {
            /** @var list<array{to: string, subject: string, body: string}> */
            public array $sent = [];

            public function send(string $toEmail, string $subject, string $textBody): void
            {
                $this->sent[] = ['to' => $toEmail, 'subject' => $subject, 'body' => $textBody];
            }
        };

        $provider = new TokenEmailVerificationProvider(
            $this->service,
            $this->emails,
            $this->mailer,
            'https://app.test/verify-email'
        );

        $this->handler = new EmailVerificationHandler(
            $this->service,
            $this->emails,
            $provider,
            new DatabaseSharedStore($this->pdo),
            new AuditLogger($this->pdo, new NullLogger())
        );
    }

    /** Insert a profile + one profile_email; return its profile_emails.id. */
    private function seedEmail(string $email, bool $verified): int
    {
        $this->pdo->prepare(
            'INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_secret,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (:dn, :ph, false, NULL, 0, 0, NOW(), NOW())'
        )->execute([':dn' => 'User', ':ph' => password_hash('x', PASSWORD_BCRYPT)]);
        $profileId = (int) $this->pdo->lastInsertId();

        return $this->emails->insert($profileId, $email, $verified, true);
    }

    private function request(string $email): \Whity\Sdk\Http\Response
    {
        return $this->handler->request(
            new Request('POST', '/api/email/request-verification', [], (string) json_encode(['email' => $email]))
        );
    }

    private function confirm(string $token): \Whity\Sdk\Http\Response
    {
        return $this->handler->confirm(
            new Request('POST', '/api/email/verify', [], (string) json_encode(['token' => $token]))
        );
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :a');
        if ($stmt === false) {
            self::fail('prepare failed');
        }
        $stmt->execute([':a' => $action]);
        return (int) $stmt->fetchColumn();
    }

    /** Run a scalar query with the result type narrowed away from `false`. */
    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    // ── request-verification ──────────────────────────────────────────────────

    public function testRequestForKnownUnverifiedEmailSendsLinkAndAudits(): void
    {
        $peid = $this->seedEmail('known@acme.test', false);

        $res = $this->request('known@acme.test');
        self::assertSame(202, $res->getStatusCode(), $res->getBody());

        // A link was delivered, carrying a token query param.
        self::assertCount(1, $this->mailer->sent);
        self::assertSame('known@acme.test', $this->mailer->sent[0]['to']);
        self::assertStringContainsString('https://app.test/verify-email?token=', $this->mailer->sent[0]['body']);

        // A verification token row now exists for the email.
        self::assertSame(1, (int) $this->col(
            "SELECT COUNT(*) FROM email_verifications WHERE profile_email_id = {$peid}"
        ));

        self::assertSame(1, $this->auditCount('email.verification.requested'));
    }

    public function testRequestForUnknownEmailIsGeneric202AndSendsNothing(): void
    {
        $known = $this->request('nobody@acme.test');
        self::assertSame(202, $known->getStatusCode());
        self::assertSame([], $this->mailer->sent, 'no email is sent for an unknown address');
        self::assertSame(0, (int) $this->col('SELECT COUNT(*) FROM email_verifications'));
        // No audit "requested" event either (nothing was dispatched).
        self::assertSame(0, $this->auditCount('email.verification.requested'));
    }

    public function testRequestBodyIsIdenticalForKnownAndUnknownAddresses(): void
    {
        $this->seedEmail('exists@acme.test', false);

        $existing = $this->request('exists@acme.test');
        $missing  = $this->request('missing@acme.test');

        self::assertSame($existing->getStatusCode(), $missing->getStatusCode());
        self::assertSame($existing->getBody(), $missing->getBody(), 'response must not reveal whether the address exists');
    }

    public function testRequestForAlreadyVerifiedEmailSendsNothing(): void
    {
        $this->seedEmail('done@acme.test', true);

        $res = $this->request('done@acme.test');
        self::assertSame(202, $res->getStatusCode());
        self::assertSame([], $this->mailer->sent);
    }

    public function testRequestWithMalformedEmailIs422(): void
    {
        self::assertSame(422, $this->request('not-an-email')->getStatusCode());
        self::assertSame(422, $this->request('')->getStatusCode());
    }

    public function testRequestIsRateLimitedPerEmail(): void
    {
        // EMAIL_MAX (5) requests are allowed in the window; the 6th is throttled.
        for ($i = 0; $i < 5; $i++) {
            self::assertSame(202, $this->request('flood@acme.test')->getStatusCode(), "request {$i}");
        }

        $throttled = $this->request('flood@acme.test');
        self::assertSame(429, $throttled->getStatusCode());
        self::assertArrayHasKey('retry-after', array_change_key_case($throttled->getHeaders()));
    }

    // ── verify ──────────────────────────────────────────────────────────────

    public function testConfirmValidTokenVerifiesAndAudits(): void
    {
        $peid = $this->seedEmail('verify@acme.test', false);
        $raw = $this->service->issue($peid);

        $res = $this->confirm($raw);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true);
        self::assertTrue($data['data']['verified']);
        self::assertSame('verify@acme.test', $data['data']['email']);

        self::assertContains(
            (string) $this->col("SELECT verified FROM profile_emails WHERE id = {$peid}"),
            ['1', 't', 'true']
        );
        self::assertSame(1, $this->auditCount('email.verification.confirmed'));
    }

    public function testConfirmInvalidTokenIs400AndAudits(): void
    {
        $res = $this->confirm('deadbeef-not-a-real-token');
        self::assertSame(400, $res->getStatusCode());
        self::assertSame(1, $this->auditCount('email.verification.failed'));
    }

    public function testConfirmEmptyTokenIs422(): void
    {
        self::assertSame(422, $this->confirm('')->getStatusCode());
    }
}
