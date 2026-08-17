<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * SDK 1.29: both entry points must hand an audit writer to the plugin loader,
 * and the HTTP one must hand it the SAME instance core's own subscriptions were
 * made on.
 *
 * The CLI is not an afterthought here. `queue:work` runs a plugin's JOBS under
 * the CLI's loader, so a worker without the writer leaves precisely the
 * background half of a plugin's activity out of the trail — a plugin whose
 * events are audited when a user clicks and not when the same work runs on a
 * queue, with nothing anywhere to say so. That is the HTTP/CLI divergence this
 * repository has already paid for three times.
 *
 * This wiring has the quiet failure mode the sibling entry-point tests exist
 * for. Nothing throws when it is missing: plugins load, routes serve, the
 * declaration is read and discarded, and the only symptom is an audit trail
 * that silently omits everything plugins do — which looks exactly like plugins
 * having done nothing. A plugin author would test their own declaration, see it
 * validated by the unit suite, ship, and be told by an operator months later
 * that the trail was empty the whole time.
 *
 * Passing a SECOND, freshly built logger would be worse than passing none: the
 * rows would be written by an instance nothing else in the process knows about,
 * so any future change to how the host builds its writer (a different PDO, a
 * different logger, a decorator) would apply to core's rows and not to plugins'
 * — a divergence with no error attached to it. The test therefore pins the
 * variable, not merely the type.
 *
 * The entry point cannot be executed in a unit test (it boots a worker and
 * opens a live DB connection), so the wiring is pinned by scanning its source —
 * the technique {@see PluginSettingsEntryPointWiringTest} and
 * {@see HealthProbeRegistryEntryPointWiringTest} already use for the same
 * drift-prone convention.
 */
final class PluginAuditEventsEntryPointWiringTest extends TestCase
{
    private const HTTP = __DIR__ . '/../../public/index.php';
    private const CLI = __DIR__ . '/../../src/Cli/Commands/BaseCommand.php';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function entryPoints(): array
    {
        return [
            'HTTP host' => [self::HTTP, 'public/index.php'],
            'CLI kernel' => [self::CLI, 'src/Cli/Commands/BaseCommand.php'],
        ];
    }

    /**
     * @dataProvider entryPoints
     */
    public function testBothEntryPointsPassTheAuditLoggerToThePluginLoader(string $path, string $label): void
    {
        $source = $this->read($path);

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$auditLogger/s',
            $source,
            "{$label} must pass the audit writer to the loader; without it a plugin's "
            . 'declaration is read and discarded, and the only symptom is an audit trail that '
            . 'silently omits everything plugins do.'
        );
    }

    public function testItIsTheSameWriterCoresOwnSubscriptionsWereMadeOn(): void
    {
        $source = $this->read(self::HTTP);

        self::assertMatchesRegularExpression(
            '/\$auditLogger = new AuditLogger\(/',
            $source,
            'public/index.php builds exactly one audit writer.'
        );
        self::assertMatchesRegularExpression(
            '/\$auditLogger->subscribe\(\$hookManager\)/',
            $source,
            'core\'s own CRUD subscriptions are made on that writer — and the loader must be '
            . 'given the same variable, so plugin rows and core rows can never be written by '
            . 'two differently-built instances.'
        );
        self::assertSame(
            1,
            preg_match_all('/new AuditLogger\(/', $source),
            'a second writer built for the loader would drift from the one core uses, with no '
            . 'error to announce it.'
        );
    }

    /**
     * The loader subscribes into the hook manager it was given, so a host that
     * wires a writer but no hook manager has a declaration it can never bind.
     *
     * @dataProvider entryPoints
     */
    public function testTheLoaderAlsoReceivesTheHookManagerThePluginsDispatchInto(string $path, string $label): void
    {
        $source = $this->read($path);

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$hookManager(?:[^;]*?)\$auditLogger/s',
            $source,
            "{$label}: the audit subscription binds on the loader's own hook manager; both must reach it."
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
