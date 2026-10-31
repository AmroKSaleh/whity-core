<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\TodayFieldProvider;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * SDK 1.56 (WHIT-742) through the loader: a code plugin on disk declares
 * template slots and record types as merge fields, and the host registers
 * them under the plugin's own name. A bad declaration is a logged warning and
 * an active plugin, as every other declaration is.
 */
final class PluginDocumentsLoaderTest extends TestCase
{
    private static int $fixtureSeq = 0;

    private ?string $tempDir = null;
    private DocumentsLoaderSpyLogger $logger;
    private TemplateSlotRegistry $slots;
    private DocumentFieldRegistry $fields;

    protected function setUp(): void
    {
        $this->logger = new DocumentsLoaderSpyLogger();
        $this->slots = new TemplateSlotRegistry();
        $this->fields = new DocumentFieldRegistry();
        $this->fields->add(new TodayFieldProvider());
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null) {
            $this->removeDirectory($this->tempDir);
        }
    }

    public function testSlotsAndRecordTypesAreRegisteredUnderThePluginsName(): void
    {
        $loader = $this->load('Invoicing', $this->provider('Invoicing.invoice'), [
            "new TemplateSlot('invoice', ['en' => 'Invoice', 'ar' => 'فاتورة'], 'Invoicing.invoice')",
            "new TemplateSlot('receipt', ['en' => 'Receipt', 'ar' => 'إيصال'], 'Invoicing.invoice', ['template', 'label'])",
        ]);

        self::assertTrue($this->fields->has('Invoicing.invoice'));
        self::assertSame(['Invoicing.invoice', 'Invoicing.receipt'], array_keys($this->slots->forTenant(1)));
        $slot = $this->slots->find(1, 'Invoicing.receipt');
        self::assertNotNull($slot);
        self::assertSame(['template', 'label'], $slot->kinds);
        self::assertSame('Invoicing', $slot->plugin);
        self::assertSame('active', array_column($loader->getPluginStatuses(), 'state', 'name')['Invoicing'] ?? null);

        $resolved = $this->fields->resolve(1, 10, static fn (): bool => true, 'Invoicing.invoice', '4812');
        self::assertSame('Invoice 4812', $resolved['label'] ?? null);
    }

    public function testARecordTypeOutsideThePluginsNameIsDroppedWithAReason(): void
    {
        $loader = $this->load('Invoicing', $this->provider('document'), [
            "new TemplateSlot('invoice', ['en' => 'Invoice', 'ar' => 'فاتورة'], 'document')",
        ]);

        self::assertFalse($this->fields->has('document'), "core's type is not the plugin's to take");
        self::assertTrue($this->logger->has("which must be Invoicing.<type>"));
        self::assertSame('active', array_column($loader->getPluginStatuses(), 'state', 'name')['Invoicing'] ?? null);
    }

    public function testASlotForARecordTypeNobodyServesIsDropped(): void
    {
        $this->load('Invoicing', $this->provider('Invoicing.invoice'), [
            "new TemplateSlot('invoice', ['en' => 'Invoice', 'ar' => 'فاتورة'], 'Invoicing.invoice')",
            "new TemplateSlot('order', ['en' => 'Order', 'ar' => 'طلب'], 'Invoicing.order')",
        ]);

        self::assertSame(['Invoicing.invoice'], array_keys($this->slots->forTenant(1)));
        self::assertTrue($this->logger->has("template slot 'order' for record type 'Invoicing.order', which nothing serves"));
    }

    public function testAPluginThatDeclaresNothingContributesNothing(): void
    {
        $this->load('Plain', null, null);

        self::assertSame([], $this->slots->forTenant(1));
        self::assertSame([], $this->fields->recordTypes());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function provider(string $type): string
    {
        return <<<PHP
        new class implements \\Whity\\Sdk\\Documents\\DocumentFieldProvider {
            public function recordType(): string { return '{$type}'; }
            public function recordLabel(): string { return 'Invoice'; }
            public function groups(int \$tenantId): array { return [['group' => 'Invoice', 'items' => [['path' => 'invoice.total', 'label' => 'Total']]]]; }
            public function values(int \$tenantId, int \$callerId, callable \$hasPermission, ?string \$recordId): ?array { return ['label' => 'Invoice ' . \$recordId, 'values' => ['invoice.total' => '18.000 JOD']]; }
            public function records(int \$tenantId, int \$callerId, callable \$hasPermission, string \$query, int \$limit): array { return []; }
        }
        PHP;
    }

    /** @param list<string>|null $slots PHP expressions; null for a plugin without PluginDocumentsInterface */
    private function load(string $name, ?string $provider, ?array $slots): PluginLoader
    {
        $this->tempDir = sys_get_temp_dir() . '/whity_plugin_documents_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $class = $name . 'DocFixture' . (++self::$fixtureSeq) . 'Plugin';
        $documents = $slots === null ? '' : ', PluginDocumentsInterface';
        $methods = $slots === null ? '' : '
            public function getTemplateSlots(): array { return [' . implode(', ', $slots) . ']; }
            public function getDocumentFieldProviders(): array { return [' . ($provider ?? '') . ']; }
            public function issuesInBackground(): bool { return false; }';
        file_put_contents($this->tempDir . '/' . $class . '.php', <<<PHP
        <?php

        namespace Whity\\Plugins;

        use Whity\\Sdk\\Documents\\TemplateSlot;
        use Whity\\Sdk\\PluginDocumentsInterface;
        use Whity\\Sdk\\PluginInterface;

        class {$class} implements PluginInterface{$documents}
        {
            public function getName(): string { return '{$name}'; }
            public function getVersion(): string { return '1.0.0'; }
            public function getRoutes(): array { return []; }
            public function getPermissions(): array { return []; }
            public function getHooks(): array { return []; }
            public function getMigrations(): array { return []; }{$methods}
        }
        PHP);

        $loader = new PluginLoader($this->tempDir, new Router(''), null, null, $this->logger);
        $loader->setDocumentRegistries($this->slots, $this->fields);
        $loader->load();

        return $loader;
    }

    private function removeDirectory(string $dir): void
    {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

final class DocumentsLoaderSpyLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $messages = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->messages[] = (string) $message;
    }

    public function has(string $needle): bool
    {
        foreach ($this->messages as $message) {
            if (str_contains($message, $needle)) {
                return true;
            }
        }

        return false;
    }
}
