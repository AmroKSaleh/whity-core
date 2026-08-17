<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Queue;

use PHPUnit\Framework\TestCase;
use Whity\Core\Queue\CoreJobs;
use Whity\Core\Queue\InvalidPluginJobException;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\Jobs\EchoJob;
use Whity\Sdk\JobInterface;

/**
 * The SOURCE-attributed half of {@see JobRegistry}: what happens when a job
 * handler arrives from a plugin rather than from core.
 *
 * The invariant under test is that a plugin can declare any job name it likes
 * but cannot declare WHO said it. The prefix is derived from the source the
 * loader supplies, so two plugins shipping a `sync` job get two different
 * canonical names, and no declaration — however crafted — can produce a bare
 * name and take over a core job.
 */
final class JobRegistryPluginJobsTest extends TestCase
{
    private function handler(string $tag = 'x'): JobInterface
    {
        return new class ($tag) implements JobInterface {
            public function __construct(public readonly string $tag)
            {
            }

            /**
             * @param array<string, mixed> $payload
             * @return array<string, mixed>
             */
            public function handle(array $payload): array
            {
                return ['tag' => $this->tag];
            }
        };
    }

    // ── Namespacing ───────────────────────────────────────────────────────────

    public function testRegisterFromSourceNamespacesTheDeclaredName(): void
    {
        $registry = new JobRegistry();

        $registry->registerFromSource('Acme', ['sync' => $this->handler()]);

        self::assertTrue($registry->has('acme:sync'));
        self::assertFalse($registry->has('sync'), 'the bare name must never be registered');
    }

    public function testCanonicalNameIsThePrefixRuleInOnePlace(): void
    {
        self::assertSame('acme:sync', JobRegistry::canonicalName('Acme', 'sync'));
        self::assertSame('acme:sync', JobRegistry::canonicalName('Acme\\Widgets\\Acme', 'sync'));
    }

    public function testRegisterFromSourceReturnsTheCanonicalNamesItRegistered(): void
    {
        $registry = new JobRegistry();

        $names = $registry->registerFromSource('Acme', [
            'sync'   => $this->handler(),
            'import' => $this->handler(),
        ]);

        self::assertSame(['acme:sync', 'acme:import'], $names);
    }

    public function testTwoPluginsDeclaringTheSameNameDoNotCollide(): void
    {
        $registry = new JobRegistry();

        $registry->registerFromSource('Acme', ['sync' => $this->handler('acme')]);
        $registry->registerFromSource('Globex', ['sync' => $this->handler('globex')]);

        $acme = $registry->get('acme:sync');
        $globex = $registry->get('globex:sync');
        self::assertNotNull($acme);
        self::assertNotNull($globex);
        self::assertSame(['tag' => 'acme'], $acme->handle([]));
        self::assertSame(['tag' => 'globex'], $globex->handle([]));
    }

    public function testAPluginCannotShadowACoreJobName(): void
    {
        $registry = new JobRegistry();
        CoreJobs::register($registry);

        // Declaring the core name verbatim still lands in the plugin's own
        // namespace — the core handler is untouched and still submittable.
        $registry->registerFromSource('Acme', [EchoJob::NAME => $this->handler('impostor')]);

        $core = $registry->get(EchoJob::NAME);
        self::assertInstanceOf(EchoJob::class, $core);
        self::assertTrue($registry->isSubmittable(EchoJob::NAME));
        self::assertTrue($registry->has('acme:' . EchoJob::NAME));
    }

    public function testADeclaredNameCarryingTheSeparatorIsRefused(): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource('Acme', ['globex:sync' => $this->handler()]);
    }

    // ── Collision between two sources that slug identically ───────────────────

    public function testASecondSourceSluggingToTheSamePrefixIsRefusedWhole(): void
    {
        $registry = new JobRegistry();
        $registry->registerFromSource('Acme\\Plugin', ['sync' => $this->handler('first')]);

        try {
            $registry->registerFromSource('Globex\\Plugin', [
                'sync'  => $this->handler('second'),
                'other' => $this->handler('second'),
            ]);
            self::fail('a canonical name already owned by another source must be refused');
        } catch (InvalidPluginJobException) {
            // The whole declaration is refused: a half-registered plugin would
            // dead-letter the jobs that did not make it, silently.
            self::assertFalse($registry->has('plugin:other'));
        }

        $kept = $registry->get('plugin:sync');
        self::assertNotNull($kept);
        self::assertSame(['tag' => 'first'], $kept->handle([]), 'first registration wins');
    }

    public function testTheSameSourceMayReRegisterItsOwnNames(): void
    {
        $registry = new JobRegistry();
        $registry->registerFromSource('Acme', ['sync' => $this->handler('first')]);
        $registry->registerFromSource('Acme', ['sync' => $this->handler('second')]);

        $handler = $registry->get('acme:sync');
        self::assertNotNull($handler);
        self::assertSame(['tag' => 'second'], $handler->handle([]));
    }

    // ── Submittability ────────────────────────────────────────────────────────

    public function testPluginJobsAreNotSubmittableUnlessDeclared(): void
    {
        $registry = new JobRegistry();

        $registry->registerFromSource(
            'Acme',
            ['sync' => $this->handler(), 'internal' => $this->handler()],
            ['sync']
        );

        self::assertTrue($registry->isSubmittable('acme:sync'));
        self::assertFalse($registry->isSubmittable('acme:internal'));
        self::assertSame(['acme:sync'], $registry->submittableNames());
    }

    public function testDeclaringSubmittabilityForAJobThePluginDoesNotShipIsRefused(): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource('Acme', ['sync' => $this->handler()], ['nonexistent']);
    }

    public function testSubmittableNamesAreDeclaredBare(): void
    {
        $registry = new JobRegistry();

        // The plugin spells a submittable job exactly as it spelled it in
        // getJobs(). A pre-prefixed name is not silently accepted: it would make
        // the host's prefix rule the plugin's problem, and a plugin that guessed
        // the prefix wrong would have declared submittability for nothing.
        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource('Acme', ['sync' => $this->handler()], ['acme:sync']);
    }

    // ── Malformed declarations are refused whole ──────────────────────────────

    public function testANonHandlerValueRefusesTheWholeDeclaration(): void
    {
        $registry = new JobRegistry();

        try {
            // The parameter is deliberately `mixed`-valued: a plugin's return is
            // untrusted data, so the registry validates it rather than trusting
            // a type declaration a plugin could simply not honour.
            $registry->registerFromSource('Acme', ['good' => $this->handler(), 'bad' => 'not a handler']);
            self::fail('a non-JobInterface value must be refused');
        } catch (InvalidPluginJobException) {
            self::assertFalse($registry->has('acme:good'), 'nothing is stored until all of it is known good');
        }
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedNames(): array
    {
        return [['Sync'], ['1sync'], [''], ['sync-job'], ['sync job'], ['_sync'], ['sync.'], ['.sync']];
    }

    /**
     * @dataProvider malformedNames
     */
    public function testAMalformedJobNameIsRefused(string $name): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource('Acme', [$name => $this->handler()]);
    }

    public function testADottedNameIsAcceptedBecauseCoreAlreadyUsesThatShape(): void
    {
        $registry = new JobRegistry();

        $registry->registerFromSource('Acme', ['catalog.sync' => $this->handler()]);

        self::assertTrue($registry->has('acme:catalog.sync'));
    }

    public function testANameTooLongForTheJobsColumnIsRefused(): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource('Acme', [str_repeat('a', JobRegistry::MAX_NAME_LENGTH) => $this->handler()]);
    }

    // ── Source attribution ────────────────────────────────────────────────────

    public function testTheCoreSourceIsReserved(): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource(JobRegistry::CORE_SOURCE, ['sync' => $this->handler()]);
    }

    public function testASourceYieldingNoUsablePrefixIsRefused(): void
    {
        $registry = new JobRegistry();

        $this->expectException(InvalidPluginJobException::class);
        $registry->registerFromSource('123', ['sync' => $this->handler()]);
    }

    public function testAnEmptyDeclarationRegistersNothingAndDoesNotThrow(): void
    {
        $registry = new JobRegistry();

        self::assertSame([], $registry->registerFromSource('Acme', []));
        self::assertSame([], $registry->names());
    }
}
