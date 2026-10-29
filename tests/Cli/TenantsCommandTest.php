<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\CliRunner;
use Whity\Cli\Commands\TenantsCommand;
use Whity\Core\Response;

/**
 * Tests for TenantsCommand
 */
class TenantsCommandTest extends TestCase
{
    private CliRunner $runner;

    /** @var array<string, mixed> $_ENV entries setUp() replaced, restored by tearDown() */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->runner = new CliRunner();

        // Set up environment variables for testing. They are put back in
        // tearDown(): left behind, DB_USER/DB_PASSWORD tell every later test in
        // the process that a database is configured, and MigrationsCommandTest
        // (RequiresConfiguredDatabase) then FAILS on the missing server instead
        // of skipping. Serial runs only escaped it because this file happened
        // to sort after that one (WHIT-715).
        foreach ([
            'JWT_SECRET' => 'test_secret-padded-for-hs256-min-32-byte-key',
            'DB_USER' => 'test',
            'DB_PASSWORD' => 'test',
        ] as $name => $value) {
            $this->savedEnv[$name] = $_ENV[$name] ?? null;
            $_ENV[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }
        $this->savedEnv = [];
    }

    /**
     * Test tenant list command
     */
    public function testTenantList(): void
    {
        // We need to mock the command and its callApi method
        $command = $this->getMockBuilder(TenantsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => [
                [
                    'id' => 1,
                    'name' => 'Default Tenant',
                    'slug' => 'default',
                    'userCount' => 5,
                    'created_at' => '2026-05-18 12:00:00'
                ]
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('GET', '/api/tenants')
            ->willReturn($mockResponse);

        // Capture output
        ob_start();
        $exitCode = $command->execute(['list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Default Tenant', $output);
        $this->assertStringContainsString('default', $output);
    }

    /**
     * Test tenant create command
     */
    public function testTenantCreate(): void
    {
        $command = $this->getMockBuilder(TenantsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(201, json_encode([
            'data' => [
                'id' => 2,
                'name' => 'New Tenant',
                'slug' => 'new-tenant'
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/tenants', ['name' => 'New Tenant', 'slug' => 'new-tenant'])
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['create', 'New Tenant', '--slug=new-tenant']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Tenant created successfully', $output);
        $this->assertStringContainsString('New Tenant', $output);
    }

    /**
     * Test tenant create fails with missing name
     */
    public function testTenantCreateMissingName(): void
    {
        $command = new TenantsCommand();

        ob_start();
        $exitCode = $command->execute(['create']);
        $output = ob_get_clean();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Missing tenant name', $output);
    }
}
