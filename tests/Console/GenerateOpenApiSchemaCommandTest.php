<?php

namespace Whity\Tests\Console;

use PHPUnit\Framework\TestCase;
use Whity\Console\GenerateOpenApiSchemaCommand;

class GenerateOpenApiSchemaCommandTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        $this->outputPath = __DIR__ . '/../../public/openapi.json';
        // Remove file if it exists for clean test
        if (file_exists($this->outputPath)) {
            unlink($this->outputPath);
        }
    }

    public function testGenerateOpenApiSchemaCommand(): void
    {
        $result = GenerateOpenApiSchemaCommand::execute(['generate:openapi']);

        $this->assertEquals(0, $result);
        $this->assertFileExists($this->outputPath);
    }

    public function testGeneratedSchemaIsValidJson(): void
    {
        GenerateOpenApiSchemaCommand::execute(['generate:openapi']);

        $content = file_get_contents($this->outputPath);
        $spec = json_decode($content, true);

        $this->assertIsArray($spec);
        $this->assertArrayHasKey('openapi', $spec);
        $this->assertEquals('3.0.0', $spec['openapi']);
    }

    public function testGeneratedSchemaHasRequiredComponents(): void
    {
        GenerateOpenApiSchemaCommand::execute(['generate:openapi']);

        $content = file_get_contents($this->outputPath);
        $spec = json_decode($content, true);

        $this->assertArrayHasKey('info', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('title', $spec['info']);
        $this->assertArrayHasKey('version', $spec['info']);
    }
}
