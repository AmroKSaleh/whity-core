<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * #844: the CLI kernel must subscribe the audit writer to core's CRUD hooks,
 * and `seed` / `migrate` must stay out of the trail.
 *
 * The behaviour itself is covered against a real SQL engine by
 * {@see \Tests\Core\Audit\CliOriginAuditTest}. What that cannot reach is the
 * WIRING: {@see \Whity\Cli\Commands\BaseCommand::setupKernel()} opens a live
 * database connection and loads every plugin, so a unit test cannot run it — and
 * an audit subsystem that is perfect but unsubscribed records nothing at all.
 * This pins the wiring by scanning the source, the technique
 * PermissionResolverEntryPointWiringTest and PluginRoleSeederEntryPointWiringTest
 * already use for the other conventions these two entry points keep drifting on
 * (#717, #724, #727).
 */
final class CliAuditWiringTest extends TestCase
{
    /**
     * The regression itself. Without this call the CLI audits a PLUGIN's events
     * and not core's — a trail that looks like it covers the process and does
     * not, which is the reading #844 was filed to remove.
     */
    public function testTheCliKernelSubscribesTheAuditWriterToCoreCrudHooks(): void
    {
        self::assertMatchesRegularExpression(
            '/\$auditLogger->subscribe\(\$hookManager\)/',
            $this->baseCommand(),
            'BaseCommand::setupKernel() must subscribe the audit writer to the hook manager, as '
            . 'public/index.php does; otherwise core CRUD driven from a command writes no audit '
            . 'row while the plugin events beside it do.'
        );
    }

    /**
     * A CLI row must be able to say what it is.
     *
     * Subscribing without the origin would produce rows with no actor and no
     * explanation — indistinguishable from a failed login, which is the one
     * other reason the actor column is empty.
     */
    public function testTheCliAuditWriterIsBuiltWithACliOrigin(): void
    {
        self::assertMatchesRegularExpression(
            '/new\s+\\\\Whity\\\\Core\\\\Audit\\\\AuditLogger\(\s*\$db->getPdo\(\),\s*null,'
            . '\s*\\\\Whity\\\\Core\\\\Audit\\\\AuditOrigin::cli\(/',
            $this->baseCommand(),
            'The CLI kernel must give its AuditLogger an AuditOrigin, or its rows carry no '
            . 'provenance and an empty actor column becomes ambiguous.'
        );
    }

    /**
     * Only the command WORD may reach the trail.
     *
     * A command line carries secrets (`--admin-password=…`) and the trail is
     * readable by every tenant administrator holding `audit:read`. The helper
     * indirection is the guard, so pin that the origin is fed from it rather
     * than from `implode(' ', $argv)` or `$_SERVER['argv']` inline.
     */
    public function testTheOriginIsFedTheCommandWordAndNotTheCommandLine(): void
    {
        $source = $this->baseCommand();

        self::assertMatchesRegularExpression(
            '/AuditOrigin::cli\(self::invokedCommand\(\)\)/',
            $source,
            'The origin must be built from the single command word invokedCommand() returns.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/implode\([^)]*\$argv/',
            $source,
            'A joined command line must never reach the audit trail — it routinely carries secrets.'
        );
    }

    /**
     * The HTTP entry point keeps writing rows with NO origin.
     *
     * Absence of the key is what "this came from a web request" means, and it is
     * also what every row written before #844 means. Stamping web rows too would
     * make the trail claim a distinction about its own history that it cannot
     * support, and would add a constant key to every row for nothing.
     */
    public function testTheHttpEntryPointStampsNoOrigin(): void
    {
        self::assertMatchesRegularExpression(
            '/\$auditLogger\s*=\s*new\s+AuditLogger\(\$db->getPdo\(\),\s*\$logger\);/',
            $this->read(__DIR__ . '/../../public/index.php'),
            'public/index.php must construct its AuditLogger without an origin: a web row is '
            . 'identified by the absence of one.'
        );
    }

    /**
     * `seed` and `migrate` are OUT of scope, and the reason is structural rather
     * than a rule someone has to remember: neither builds the CLI kernel, so
     * neither has a hook manager an audit listener could be attached to.
     *
     * Pinned because the tempting "fix" for a future issue is to make these
     * commands extend BaseCommand for its wiring, which would silently turn a
     * bootstrap into hundreds of actor-less `user.created` rows and bury the
     * first genuine administrator action under fixture noise.
     *
     * @dataProvider unauditedCommandProvider
     */
    public function testSeedingAndMigrationsBuildNoAuditWriter(string $file): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/' . $file);

        self::assertStringNotContainsString(
            'AuditLogger',
            $source,
            "{$file} must not construct an audit writer: fixture data is not activity."
        );
        self::assertStringNotContainsString(
            'extends BaseCommand',
            $source,
            "{$file} must not inherit the CLI kernel's wiring, which would subscribe the audit "
            . 'writer to core CRUD hooks and put every seeded fixture in the trail.'
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unauditedCommandProvider(): array
    {
        return [
            'seed'    => ['SeedCommand.php'],
            'migrate' => ['MigrationsCommand.php'],
        ];
    }

    private function baseCommand(): string
    {
        return $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Unable to read {$path}");

        return $source;
    }
}
