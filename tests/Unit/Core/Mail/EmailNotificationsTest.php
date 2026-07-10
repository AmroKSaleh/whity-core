<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Mail\EmailLayout;
use Whity\Core\Mail\EmailNotifications;
use Whity\Core\Mail\Mailer;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * {@see EmailNotifications} sends the welcome email on `registration.completed`
 * — gated on the toggle + account being usable, best-effort, and reused via the
 * hook manager (WC-email).
 */
final class EmailNotificationsTest extends TestCase
{
    private CapturingMailer $mailer;
    private SettingsService $settings;
    private EmailNotifications $subject;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($pdo),
            new TenantSettingsRepository($pdo),
        );
        $this->mailer = new CapturingMailer();
        $this->subject = new EmailNotifications(
            $this->mailer,
            new EmailLayout(),
            $this->settings,
            'https://app.example.test',
        );
    }

    public function testSendsWelcomeWhenEnabledAndActive(): void
    {
        // welcome default is 'true'; be explicit.
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_WELCOME, 'true');

        $this->subject->onRegistrationCompleted([
            'email' => 'owner@example.com',
            'name' => 'Amro',
            'approval_required' => false,
        ]);

        self::assertCount(1, $this->mailer->sent);
        [$to, $subject, $text, $html] = $this->mailer->sent[0];
        self::assertSame('owner@example.com', $to);
        self::assertStringContainsString('Welcome to Whity', $subject);
        self::assertStringContainsString('Welcome to Whity, Amro', (string) $html);
        self::assertStringContainsString('https://app.example.test', (string) $html); // CTA
        self::assertNotSame('', $text);
    }

    public function testNoSendWhenToggleOff(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_WELCOME, 'false');

        $this->subject->onRegistrationCompleted([
            'email' => 'owner@example.com',
            'name' => 'Amro',
            'approval_required' => false,
        ]);

        self::assertCount(0, $this->mailer->sent);
    }

    public function testNoSendWhenApprovalPending(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_WELCOME, 'true');

        // Approval still pending → welcome is deferred (to the approval email).
        $this->subject->onRegistrationCompleted([
            'email' => 'owner@example.com',
            'name' => 'Amro',
            'approval_required' => true,
        ]);

        self::assertCount(0, $this->mailer->sent);
    }

    public function testNoSendWithoutRecipient(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_WELCOME, 'true');
        $this->subject->onRegistrationCompleted(['approval_required' => false]);
        self::assertCount(0, $this->mailer->sent);
    }

    public function testFiresThroughTheHookManager(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_WELCOME, 'true');
        $hooks = new HookManager();
        $this->subject->subscribe($hooks);

        $hooks->dispatch('registration.completed', [
            'email' => 'via-hook@example.com',
            'name' => 'Hooked',
            'approval_required' => false,
        ]);

        self::assertCount(1, $this->mailer->sent);
        self::assertSame('via-hook@example.com', $this->mailer->sent[0][0]);
    }

    public function testSendsApprovedWhenEnabled(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_APPROVAL, 'true');

        $this->subject->onRegistrationApproved(['email' => 'owner@example.com', 'name' => 'Amro']);

        self::assertCount(1, $this->mailer->sent);
        [$to, $subject, , $html] = $this->mailer->sent[0];
        self::assertSame('owner@example.com', $to);
        self::assertStringContainsString('approved', $subject);
        self::assertStringContainsString('Your account is approved, Amro', (string) $html);
    }

    public function testApprovedRespectsToggle(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_APPROVAL, 'false');
        $this->subject->onRegistrationApproved(['email' => 'owner@example.com']);
        self::assertCount(0, $this->mailer->sent);
    }

    public function testSendsRejectedWhenEnabled(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_EVENT_APPROVAL, 'true');

        $this->subject->onRegistrationRejected(['email' => 'owner@example.com', 'name' => 'Amro']);

        self::assertCount(1, $this->mailer->sent);
        [$to, $subject, , $html] = $this->mailer->sent[0];
        self::assertSame('owner@example.com', $to);
        self::assertStringContainsString('registration', $subject);
        self::assertStringContainsString('About your registration, Amro', (string) $html);
        // A rejection carries no sign-in CTA.
        self::assertStringNotContainsString('https://app.example.test', (string) $html);
    }
}

final class CapturingMailer implements Mailer
{
    /** @var list<array{0:string,1:string,2:string,3:?string}> */
    public array $sent = [];

    public function send(string $toEmail, string $subject, string $textBody, ?string $htmlBody = null): void
    {
        $this->sent[] = [$toEmail, $subject, $textBody, $htmlBody];
    }
}
