<?php

declare(strict_types=1);

namespace Tests\Unit\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptRegistry;

/**
 * TDD tests for PluginLoader::collectMcpPrompts() (WC-7abb732f).
 *
 * Drives the real PluginLoader over on-disk fixture plugins so the discovery
 * and namespace wiring run exactly as in production. Verifies that
 * plugin-contributed MCP prompts are registered in the PromptRegistry
 * fail-closed: invalid descriptors are skipped, duplicate names are skipped
 * (first registration wins), and a throwing getMcpPrompts() never crashes.
 */
final class PluginLoaderMcpTest extends TestCase
{
    private static string $pluginDir;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_mcp_' . uniqid();
        mkdir(self::$pluginDir . '/McpA', 0755, true);
        mkdir(self::$pluginDir . '/McpB', 0755, true);
        mkdir(self::$pluginDir . '/McpC', 0755, true);
        mkdir(self::$pluginDir . '/McpPlain', 0755, true);
        mkdir(self::$pluginDir . '/McpThrows', 0755, true);

        // McpA: implements PluginMcpInterface with two prompts
        file_put_contents(self::$pluginDir . '/McpA/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace McpA;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginMcpInterface;

final class Plugin implements PluginInterface, PluginMcpInterface
{
    public function getName(): string    { return 'McpA'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getMcpPrompts(): array
    {
        return [
            ['name' => 'prompt-alpha', 'description' => 'Alpha prompt from McpA'],
            ['name' => 'prompt-beta',  'description' => 'Beta prompt from McpA', 'requiredRole' => 'admin'],
        ];
    }
}
PHP);

        // McpB: implements PluginMcpInterface — collides on prompt-alpha
        file_put_contents(self::$pluginDir . '/McpB/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace McpB;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginMcpInterface;

final class Plugin implements PluginInterface, PluginMcpInterface
{
    public function getName(): string    { return 'McpB'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getMcpPrompts(): array
    {
        return [
            ['name' => 'prompt-alpha',  'description' => 'Alpha from McpB (collides)'],
            ['name' => 'prompt-gamma',  'description' => 'Gamma prompt from McpB', 'requiredPermission' => 'items:read'],
        ];
    }
}
PHP);

        // McpC: invalid descriptor (missing name) + one valid
        file_put_contents(self::$pluginDir . '/McpC/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace McpC;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginMcpInterface;

final class Plugin implements PluginInterface, PluginMcpInterface
{
    public function getName(): string    { return 'McpC'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getMcpPrompts(): array
    {
        return [
            ['description' => 'No name key at all'],
            ['name' => '', 'description' => 'Empty name'],
            ['name' => 'prompt-delta', 'description' => 'Delta is valid'],
        ];
    }
}
PHP);

        // McpPlain: does NOT implement PluginMcpInterface
        file_put_contents(self::$pluginDir . '/McpPlain/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace McpPlain;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return 'McpPlain'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP);

        // McpThrows: getMcpPrompts() throws
        file_put_contents(self::$pluginDir . '/McpThrows/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace McpThrows;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginMcpInterface;

final class Plugin implements PluginInterface, PluginMcpInterface
{
    public function getName(): string    { return 'McpThrows'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }

    public function getMcpPrompts(): array
    {
        throw new \RuntimeException('getMcpPrompts explosion');
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['McpA', 'McpB', 'McpC', 'McpPlain', 'McpThrows'] as $dir) {
            @unlink(self::$pluginDir . '/' . $dir . '/Plugin.php');
            @rmdir(self::$pluginDir . '/' . $dir);
        }
        @rmdir(self::$pluginDir);
    }

    private function makeLoader(): PluginLoader
    {
        return new PluginLoader(
            self::$pluginDir,
            new Router(''),
            new PermissionRegistry(),
            new HookManager(),
        );
    }

    // ── Baseline: no plugins ──────────────────────────────────────────────────

    public function testCollectMcpPrompts_doesNothing_whenNoPluginsLoaded(): void
    {
        $loader   = new PluginLoader(sys_get_temp_dir() . '/empty_' . uniqid(), new Router(''), new PermissionRegistry(), new HookManager());
        $registry = new PromptRegistry();

        $loader->collectMcpPrompts($registry);

        self::assertSame([], $registry->all());
    }

    // ── Plain plugin (no PluginMcpInterface) ──────────────────────────────────

    public function testCollectMcpPrompts_skipsPlugin_thatDoesNotImplementInterface(): void
    {
        // Use a distinct namespace to avoid redeclaration with McpPlain from shared fixture.
        $dir = sys_get_temp_dir() . '/whity_plainonly_' . uniqid();
        mkdir($dir . '/McpPlainOnly', 0755, true);
        file_put_contents($dir . '/McpPlainOnly/Plugin.php', <<<'PHP'
<?php
declare(strict_types=1);
namespace McpPlainOnly;

use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface
{
    public function getName(): string    { return 'McpPlainOnly'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array      { return []; }
    public function getPermissions(): array { return []; }
    public function getHooks(): array       { return []; }
    public function getMigrations(): array  { return []; }
}
PHP);

        $loader = new PluginLoader($dir, new Router(''), new PermissionRegistry(), new HookManager());
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        self::assertSame([], $registry->all());

        @unlink($dir . '/McpPlainOnly/Plugin.php');
        @rmdir($dir . '/McpPlainOnly');
        @rmdir($dir);
    }

    // ── Valid prompts from McpA ───────────────────────────────────────────────

    public function testCollectMcpPrompts_registersValidPrompts_fromPlugin(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        $alpha = $registry->find('prompt-alpha');
        self::assertInstanceOf(Prompt::class, $alpha);
        self::assertSame('Alpha prompt from McpA', $alpha->description);
    }

    public function testCollectMcpPrompts_sets_requiredRole_fromDescriptor(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        $beta = $registry->find('prompt-beta');
        self::assertNotNull($beta);
        self::assertSame('admin', $beta->requiredRole);
        self::assertNull($beta->requiredPermission);
    }

    // ── Collision handling ────────────────────────────────────────────────────

    public function testCollectMcpPrompts_firstRegistrationWins_onNameCollision(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        // McpA registers 'prompt-alpha' first; McpB also tries 'prompt-alpha'.
        $alphas = array_filter($registry->all(), fn (Prompt $p): bool => $p->name === 'prompt-alpha');
        self::assertCount(1, $alphas);
        self::assertSame('Alpha prompt from McpA', array_values($alphas)[0]->description);
    }

    public function testCollectMcpPrompts_skipsCollision_withCorePrompt(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $registry->register(new Prompt('prompt-alpha', 'Core prompt'));
        $loader->collectMcpPrompts($registry);

        $alphas = array_filter($registry->all(), fn (Prompt $p): bool => $p->name === 'prompt-alpha');
        self::assertCount(1, $alphas);
        self::assertSame('Core prompt', array_values($alphas)[0]->description);
    }

    // ── Permission-gated prompt from McpB ────────────────────────────────────

    public function testCollectMcpPrompts_sets_requiredPermission_fromDescriptor(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        $gamma = $registry->find('prompt-gamma');
        self::assertNotNull($gamma);
        self::assertNull($gamma->requiredRole);
        self::assertSame('items:read', $gamma->requiredPermission);
    }

    // ── Invalid descriptors (McpC) ────────────────────────────────────────────

    public function testCollectMcpPrompts_skipsDescriptor_withMissingName(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        // McpC declares 3 prompts: 2 invalid (no name / empty name), 1 valid.
        self::assertNull($registry->find(''));
        self::assertNotNull($registry->find('prompt-delta'));
    }

    public function testCollectMcpPrompts_registersValidDescriptor_fromPluginWithMixedDescriptors(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();
        $loader->collectMcpPrompts($registry);

        $delta = $registry->find('prompt-delta');
        self::assertInstanceOf(Prompt::class, $delta);
        self::assertSame('Delta is valid', $delta->description);
    }

    // ── Throwing plugin (McpThrows) ───────────────────────────────────────────

    public function testCollectMcpPrompts_doesNotThrow_whenGetMcpPromptsThrows(): void
    {
        $loader   = $this->makeLoader();
        $loader->load();
        $registry = new PromptRegistry();

        // Must not propagate the RuntimeException from McpThrows
        $loader->collectMcpPrompts($registry);

        // Other plugins' prompts must still be registered
        self::assertNotNull($registry->find('prompt-alpha'));
    }
}
