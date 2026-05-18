<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Core\Response;

/**
 * Tests for MigrationsCommand
 */
class MigrationsCommandTest extends TestCase
{
    /**
     * Test migration status command
     */
    public function testMigrationStatus(): void
    {
        $command = $this->getMockBuilder(MigrationsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => [
                [
                    'name' => '001_create_users',
                    'executed' => true,
                    'executed_at' => '2026-05-18 10:00:00'
                ],
                [
                    'name' => '002_create_roles',
                    'executed' => false,
                    'executed_at' => null
                ]
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('GET', '/api/migrations')
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['status']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('001_create_users', $output);
        $this->assertStringContainsString('Executed', $output);
        $this->assertStringContainsString('002_create_roles', $output);
        $this->assertStringContainsString('Pending', $output);
    }

    /**
     * Test migration run command
     */
    public function testMigrationRun(): void
    {
        $command = $this->getMockBuilder(MigrationsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => ['count' => 2]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/migrations/run')
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['run']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Successfully ran 2 migration(s)', $output);
    }
}
