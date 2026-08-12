<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Both production entry points must WIRE the sequence allocator, under the SDK
 * interface name.
 *
 * A host service is only a service if a host registers it. Unregistered, the
 * container refuses to improvise one (it takes a PDO), so
 * `\Whity\app(SequenceAllocator::class)` throws — and a plugin's only remaining
 * option is the hand-written read-then-write counter this contract exists to
 * delete. Registered under the CONCRETE class alone, a plugin would have to
 * reference `Whity\Database\SequenceCounters` and take a dependency on core
 * that the SDK is meant to make unnecessary.
 *
 * Registered in only ONE entry point, document numbering would work over HTTP
 * and throw under `queue:work` — the divergence bug class #717 and #724 already
 * paid for, and the reason every other host-wired service here is pinned the
 * same way.
 *
 * public/index.php and BaseCommand::setupKernel() cannot be executed in a unit
 * test (a full worker bootstrap and a live DB connection respectively), so this
 * pins the wiring by scanning their source — the technique
 * PermissionResolverEntryPointWiringTest and PluginRoleSeederEntryPointWiringTest
 * already use.
 */
final class SequenceAllocatorEntryPointWiringTest extends TestCase
{
    public function testHttpEntryPointRegistersTheAllocatorUnderTheSdkInterface(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Sdk\\\\Sql\\\\SequenceAllocator::class/',
            $source,
            'public/index.php must register the allocator under the SDK interface name, or '
            . '\Whity\app(SequenceAllocator::class) throws and every plugin is pushed back onto '
            . 'a hand-written SELECT-then-UPDATE counter.'
        );
    }

    public function testCliEntryPointRegistersTheAllocatorUnderTheSdkInterface(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Sdk\\\\Sql\\\\SequenceAllocator::class/',
            $source,
            'The CLI kernel must register the same contract: a queue worker or an import '
            . 'command that allocates a document number has to reach the same allocator a '
            . 'web request does.'
        );
    }

    public function testBothEntryPointsBuildItOverTheLiveConnection(): void
    {
        // Over $db->getPdo(), not a second connection: the allocation must join
        // whatever transaction the caller already has open, or a rolled-back
        // request would leave its number spent while its record vanished.
        foreach (['public/index.php', 'src/Cli/Commands/BaseCommand.php'] as $relative) {
            $source = $this->read(__DIR__ . '/../../' . $relative);

            self::assertMatchesRegularExpression(
                '/new\s+\\\\?Whity\\\\Database\\\\SequenceCounters\(\s*\$db->getPdo\(\)\s*\)/',
                $source,
                "{$relative} must build the allocator over the shared connection."
            );
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Unable to read {$path}");

        return $source;
    }
}
