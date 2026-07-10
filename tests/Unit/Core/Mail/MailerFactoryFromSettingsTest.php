<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Mail;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Mail\LogMailer;
use Whity\Core\Mail\MailerFactory;
use Whity\Core\Mail\NullMailer;
use Whity\Core\Mail\SmtpMailer;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Covers {@see MailerFactory::fromSettings()} (WC-email): transport selection
 * from global settings, SMTP config assembly, encrypted-password decryption, and
 * the fail-safe degradations (missing host/from, undecryptable password).
 */
final class MailerFactoryFromSettingsTest extends TestCase
{
    // defuse/php-encryption ASCII-safe key (32 bytes of key material, hex-encoded
    // form the library accepts via encryptWithPassword — any non-empty passphrase).
    private const KEY = 'unit-test-encryption-key-please-ignore-0123456789';

    private GlobalSettingsRepository $globals;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->globals = new GlobalSettingsRepository($pdo);
        $this->settings = new SettingsService($this->globals, new TenantSettingsRepository($pdo));
    }

    public function testTransportNoneYieldsNullMailer(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'none');
        self::assertInstanceOf(NullMailer::class, $this->build());
    }

    public function testTransportLogYieldsLogMailer(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'log');
        self::assertInstanceOf(LogMailer::class, $this->build());
    }

    public function testDefaultTransportIsNull(): void
    {
        // No mail.transport stored → registry default 'none'.
        self::assertInstanceOf(NullMailer::class, $this->build());
    }

    public function testSmtpWithMissingHostDegradesToNull(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'no-reply@example.com');
        self::assertInstanceOf(NullMailer::class, $this->build());
    }

    public function testSmtpWithMissingFromDegradesToNull(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'smtp.example.com');
        self::assertInstanceOf(NullMailer::class, $this->build());
    }

    public function testFullyConfiguredSmtpYieldsSmtpMailer(): void
    {
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'smtp.example.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_PORT, '587');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_ENCRYPTION, 'tls');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_USERNAME, 'ops@example.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'no-reply@example.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_NAME, 'Acme');
        self::assertInstanceOf(SmtpMailer::class, $this->build());
    }

    public function testUndecryptablePasswordStillBuildsSmtpMailer(): void
    {
        // A garbage ciphertext must not crash the factory — email stays best-effort.
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'smtp.example.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'no-reply@example.com');
        // Raw (non-registry) key written directly.
        $this->globals->set(MailerFactory::SMTP_PASSWORD_SETTING_KEY, 'v1:not-a-valid-ciphertext');

        self::assertInstanceOf(SmtpMailer::class, $this->build());
    }

    public function testEncryptedPasswordRoundTripsIntoConfig(): void
    {
        $secrets = new EncryptedSecretStore(['v1' => self::KEY], 'v1');
        $this->settings->setGlobal(SettingsRegistry::MAIL_TRANSPORT, 'smtp');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_HOST, 'smtp.example.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_SMTP_USERNAME, 'ops@example.com');
        $this->settings->setGlobal(SettingsRegistry::MAIL_FROM_ADDRESS, 'no-reply@example.com');
        $this->globals->set(MailerFactory::SMTP_PASSWORD_SETTING_KEY, $secrets->encrypt('hunter2'));

        // Building must succeed with a decryptable password present.
        $mailer = MailerFactory::fromSettings($this->settings, $this->globals, $secrets, new NullLogger());
        self::assertInstanceOf(SmtpMailer::class, $mailer);
    }

    public function testPasswordKeyIsNotExposedOnSettingsSurface(): void
    {
        // The encrypted password lives in app_settings but must never appear in
        // the typed settings surface (getGlobal iterates registry keys only).
        $this->globals->set(MailerFactory::SMTP_PASSWORD_SETTING_KEY, 'v1:secret-cipher');
        self::assertArrayNotHasKey(MailerFactory::SMTP_PASSWORD_SETTING_KEY, $this->settings->getGlobal());
    }

    private function build(): \Whity\Core\Mail\Mailer
    {
        return MailerFactory::fromSettings(
            $this->settings,
            $this->globals,
            new EncryptedSecretStore(['v1' => self::KEY], 'v1'),
            new NullLogger(),
        );
    }
}
