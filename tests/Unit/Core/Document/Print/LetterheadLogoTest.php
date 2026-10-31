<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Print;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Print\LetterheadLogo;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Storage\LocalStorageDriver;
use Whity\Storage\StorageKey;

/**
 * The tenant logo on a printed letterhead (stage 6, 6.2). Every "no" answers
 * null, so the letterhead falls back to the name and the render still runs.
 */
final class LetterheadLogoTest extends TestCase
{
    private const TENANT = 7;

    /** A 1×1 PNG: the real bytes, so the expected URI is built from them, not from the code under test. */
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89"
        . "\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private SettingsService $settings;
    private LocalStorageDriver $storage;
    private string $root;

    protected function setUp(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (7, 'tenant-7', 'tenant-7') ON CONFLICT DO NOTHING");
        $this->settings = new SettingsService(new GlobalSettingsRepository($pdo), new TenantSettingsRepository($pdo));
        $this->root = sys_get_temp_dir() . '/letterhead-logo-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->storage = new LocalStorageDriver($this->root);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testNoLogoMeansNoImage(): void
    {
        self::assertNull($this->logo()->dataUri(self::TENANT));
    }

    public function testTheWideLogoIsPreferredOverTheSquareOne(): void
    {
        $this->store(SettingsRegistry::BRANDING_LOGO_SQUARE, 'logo_square-aaaa.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->store(SettingsRegistry::BRANDING_LOGO_WIDE, 'logo_wide-bbbb.png', self::PNG);

        self::assertSame('data:image/png;base64,' . base64_encode(self::PNG), $this->logo()->dataUri(self::TENANT));
    }

    public function testTheSquareLogoIsUsedWhenThereIsNoWideOne(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"/>';
        $this->store(SettingsRegistry::BRANDING_LOGO_SQUARE, 'logo_square-aaaa.svg', $svg);

        self::assertSame('data:image/svg+xml;base64,' . base64_encode($svg), $this->logo()->dataUri(self::TENANT));
    }

    public function testAMissingObjectPrintsTheNameAlone(): void
    {
        $this->settings->setTenantAsset(self::TENANT, SettingsRegistry::BRANDING_LOGO_WIDE, StorageKey::build(self::TENANT, 'branding', 'logo_wide-gone.png'));

        self::assertNull($this->logo()->dataUri(self::TENANT));
    }

    public function testALogoTooBigForWhityRenderIsLeftOut(): void
    {
        $this->store(SettingsRegistry::BRANDING_LOGO_WIDE, 'logo_wide-cccc.png', self::PNG . str_repeat("\x00", LetterheadLogo::MAX_URI_LENGTH));

        self::assertNull($this->logo()->dataUri(self::TENANT));
    }

    private function logo(): LetterheadLogo
    {
        return new LetterheadLogo($this->settings, $this->storage);
    }

    private function store(string $settingKey, string $name, string $bytes): void
    {
        $key = StorageKey::build(self::TENANT, 'branding', $name);
        $this->storage->put($key, $bytes);
        $this->settings->setTenantAsset(self::TENANT, $settingKey, $key);
    }
}
