<?php

declare(strict_types=1);

namespace Tests\Plugins;

use HelloWorld\Documents\GreetingFieldProvider;
use HelloWorld\HelloWorldPlugin;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\PluginDocumentsInterface;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/HelloWorldPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/Documents/GreetingFieldProvider.php';

/**
 * The reference plugin's documents (SDK 1.56, WHIT-742): one slot, and
 * greetings as a record type read per workspace with `hello:view`.
 */
final class HelloWorldDocumentsTest extends TestCase
{
    private PDO $pdo;
    private GreetingFieldProvider $provider;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->exec('CREATE TABLE hello_greetings (id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, message TEXT NOT NULL)');
        $this->pdo->exec("INSERT INTO hello_greetings (id, tenant_id, message) VALUES (7, 1, 'Hello from Example Bakery'), (8, 2, 'Another workspace')");
        $pdo = $this->pdo;
        $this->provider = new GreetingFieldProvider(static fn (): PDO => $pdo);
    }

    public function testThePluginDeclaresItsSlotForItsOwnRecordType(): void
    {
        $plugin = new HelloWorldPlugin();
        self::assertInstanceOf(PluginDocumentsInterface::class, $plugin);

        $slots = $plugin->getTemplateSlots();
        self::assertCount(1, $slots);
        self::assertSame('greeting_card', $slots[0]->name);
        self::assertSame('HelloWorld.greeting', $slots[0]->recordType);
        self::assertStringStartsWith($plugin->getName() . '.', $plugin->getDocumentFieldProviders()[0]->recordType());
        self::assertFalse($plugin->issuesInBackground());
    }

    public function testAGreetingResolvesForAReaderInItsWorkspace(): void
    {
        $values = $this->provider->values(1, 10, static fn (string $p): bool => $p === 'hello:view', '7');

        self::assertSame('#7 · Hello from Example Bakery', $values['label'] ?? null);
        self::assertSame('Hello from Example Bakery', $values['values']['greeting.message'] ?? null);
    }

    public function testWithoutHelloViewOrInAnotherWorkspaceThereIsNoGreeting(): void
    {
        self::assertNull($this->provider->values(1, 10, static fn (): bool => false, '7'));
        self::assertNull($this->provider->values(1, 10, static fn (): bool => true, '8'), "another workspace's greeting");
        self::assertSame([], $this->provider->records(1, 10, static fn (): bool => false, '', 10));
    }

    public function testThePickerFindsGreetingsByMessage(): void
    {
        $found = $this->provider->records(1, 10, static fn (): bool => true, 'bakery', 10);

        self::assertSame([['id' => '7', 'label' => '#7 · Hello from Example Bakery']], $found);
    }
}
