<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\I18nCommand;
use Whity\Core\i18n\TranslationCatalog;

/**
 * The extraction command's destructive edge.
 *
 * `i18n:extract` MIRRORS: a domain that no longer exists in source loses its
 * catalogue file, which is the property the drift gate depends on. The failure
 * that follows from it is that an empty scan is indistinguishable from "every
 * string was deleted" — and this command is shipped inside an image whose
 * `.dockerignore` deliberately excludes `web/`, so "there is no frontend source
 * here" is a completely ordinary situation to run it in.
 */
final class I18nCommandTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/whity-i18n-cli-' . bin2hex(random_bytes(6));
        mkdir($this->baseDir . '/' . TranslationCatalog::DIRECTORY, 0o775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->baseDir . '/' . TranslationCatalog::DIRECTORY . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->baseDir . '/' . TranslationCatalog::DIRECTORY);
        @rmdir($this->baseDir . '/database');
        @rmdir($this->baseDir);
    }

    public function testRefusesToDeleteTheWholeCatalogueWhenTheScanFoundNothing(): void
    {
        $path = $this->baseDir . '/' . TranslationCatalog::DIRECTORY . '/auth.json';
        file_put_contents($path, TranslationCatalog::render('auth', ['login.submit' => 'Sign in']));

        ob_start();
        $exitCode = (new I18nCommand($this->baseDir))->execute([], 'i18n:extract');
        ob_end_clean();

        self::assertSame(2, $exitCode, 'An empty scan against a non-empty catalogue is an input error, not a result.');
        self::assertFileExists($path, 'The catalogue must survive a scan that found no source at all.');
        self::assertSame(
            ['auth' => ['login.submit' => 'Sign in']],
            (new TranslationCatalog($this->baseDir))->read()
        );
    }

    public function testAnEmptyScanAgainstAnEmptyCatalogueIsSimplyEmpty(): void
    {
        ob_start();
        $exitCode = (new I18nCommand($this->baseDir))->execute([], 'i18n:extract');
        ob_end_clean();

        self::assertSame(0, $exitCode, 'Nothing to extract and nothing to lose is not a failure.');
    }

    public function testUnknownSubcommandIsAUsageError(): void
    {
        $exitCode = (new I18nCommand($this->baseDir))->execute([], 'i18n:translate-everything');

        self::assertSame(2, $exitCode);
    }
}
