<?php

declare(strict_types=1);

namespace Whity\Tests\Console;

use PHPUnit\Framework\TestCase;
use Whity\Console\GenerateOpenApiSchemaCommand;

/**
 * The generate:openapi command. Output goes to a TEMP path via --output so
 * the suite never mutates the tracked public/openapi.json — regenerating the
 * real file is an explicit developer/deploy action, and on a dev machine
 * with a real plugin installed (see docs/wiki/Plugin-Distribution.md) a
 * test-side rewrite would corrupt the working tree with deployment state.
 */
class GenerateOpenApiSchemaCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        $this->outputPath = sys_get_temp_dir() . '/whity_openapi_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
    }

    private function execute(): int
    {
        ob_start();
        $result = GenerateOpenApiSchemaCommand::execute([
            'generate:openapi',
            '--output=' . $this->outputPath,
        ]);
        ob_end_clean();

        return $result;
    }

    public function testGenerateOpenApiSchemaCommand(): void
    {
        $this->assertEquals(0, $this->execute());
        $this->assertFileExists($this->outputPath);
    }

    public function testGeneratedSchemaIsValidJson(): void
    {
        $this->execute();

        $content = file_get_contents($this->outputPath);
        $spec = json_decode((string) $content, true);

        $this->assertIsArray($spec);
        $this->assertArrayHasKey('openapi', $spec);
        $this->assertEquals('3.0.0', $spec['openapi']);
    }

    public function testGeneratedSchemaHasRequiredComponents(): void
    {
        $this->execute();

        $content = file_get_contents($this->outputPath);
        $spec = json_decode((string) $content, true);

        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
    }

    public function testCommandDoesNotTouchTheTrackedSpec(): void
    {
        $tracked = dirname(__DIR__, 2) . '/public/openapi.json';
        $before = file_get_contents($tracked);

        $this->execute();

        $this->assertSame(
            $before,
            file_get_contents($tracked),
            'generate:openapi with --output must never rewrite the tracked spec'
        );
    }
}
