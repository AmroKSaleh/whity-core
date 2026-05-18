<?php

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\PluginsCommand;
use Whity\Core\Response;

/**
 * Tests for PluginsCommand
 */
class PluginsCommandTest extends TestCase
{
    /**
     * Test plugin list command
     */
    public function testPluginList(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => [
                [
                    'id' => 'AdminStats',
                    'name' => 'AdminStats',
                    'enabled' => true,
                    'route' => '/api/admin/stats',
                    'method' => 'GET'
                ]
            ]
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('GET', '/api/plugins')
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['list']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('AdminStats', $output);
        $this->assertStringContainsString('Enabled', $output);
    }

    /**
     * Test plugin enable command
     */
    public function testPluginEnable(): void
    {
        $command = $this->getMockBuilder(PluginsCommand::class)
            ->onlyMethods(['callApi'])
            ->getMock();

        $mockResponse = new Response(200, json_encode([
            'data' => ['message' => 'Plugin AdminStats enabled']
        ]));

        $command->expects($this->once())
            ->method('callApi')
            ->with('POST', '/api/plugins/AdminStats/enable')
            ->willReturn($mockResponse);

        ob_start();
        $exitCode = $command->execute(['enable', 'AdminStats']);
        $output = ob_get_clean();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('enabled successfully', $output);
    }
}
