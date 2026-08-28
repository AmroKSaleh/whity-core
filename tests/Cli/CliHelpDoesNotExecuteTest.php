<?php

declare(strict_types=1);

namespace Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\CliRunner;
use Whity\Cli\Commands\CliCommand;
use Whity\Cli\Commands\CommandHelp;

/**
 * Asking a command about itself must never run it.
 *
 * `whity-cli seed --help` SEEDED. Unrecognised options were ignored rather than
 * rejected and nothing handled `--help`, so the documented way to ask a command
 * what its flags are was the way to make it run. The base seed is idempotent, so
 * the instance this was found on came to no harm — luck, not design.
 *
 * The generalisation is the reason it is worth fixing rather than shrugging at.
 * `migrate`, `tenant delete`, `plugin uninstall` and `totp reencrypt` share this
 * runner and this argument handling, and none of them is idempotent. The
 * commands that DID handle `--help` matched it only in the first position, where
 * it reads as the ACTION — so `migrate --help` printed help while `migrate run
 * --help` ran the migrations.
 *
 * These use a probe command rather than the real ones: proving that help does
 * not execute must not require executing anything, and a test that seeded a
 * database to check that it does not seed would be its own punchline.
 */
final class CliHelpDoesNotExecuteTest extends TestCase
{
    /**
     * Runs the CLI against a probe registered under the name "probe".
     *
     * Whether the command RAN is read from the probe's own static by each test
     * rather than returned from here: reaching $probe::$executed through an
     * untyped object parameter is exactly the kind of assertion static analysis
     * cannot check, and the two probes deliberately do not share a base — one
     * implements CommandHelp and one implements nothing, which is the
     * difference under test.
     *
     * @param list<string>  $args
     * @param class-string  $probeClass
     * @return array{code: int, output: string}
     */
    private function invoke(array $args, string $probeClass): array
    {
        $runner = new CliRunner(['probe' => $probeClass]);

        ob_start();
        $code = $runner->run(array_merge(['whity-cli'], $args));
        $output = (string) ob_get_clean();

        return ['code' => $code, 'output' => $output];
    }

    protected function setUp(): void
    {
        HelpProbeCommand::$executed = false;
        HelpProbeCommand::$sawArgs = [];
        BareProbeCommand::$executed = false;
    }

    /** THE DEFECT, in the position it was reported in. */
    public function testHelpAfterTheCommandNameDoesNotExecute(): void
    {
        $result = $this->invoke(['probe', '--help'], HelpProbeCommand::class);

        self::assertFalse(HelpProbeCommand::$executed, '--help must not run the command');
        self::assertSame(0, $result['code'], 'asking for help is not a failure');
        self::assertStringContainsString('PROBE HELP', $result['output']);
    }

    public function testShortHelpFlagDoesNotExecute(): void
    {
        $result = $this->invoke(['probe', '-h'], HelpProbeCommand::class);

        self::assertFalse(HelpProbeCommand::$executed);
        self::assertSame(0, $result['code']);
    }

    /**
     * THE GENERALISATION, and the dangerous one: `tenant delete --help`. The
     * flag is not in the first position, so a command that matched it as its
     * action never saw it and did the thing instead.
     */
    public function testHelpAfterASubcommandDoesNotExecute(): void
    {
        $result = $this->invoke(['probe', 'delete', '17', '--help'], HelpProbeCommand::class);

        self::assertFalse(HelpProbeCommand::$executed, '--help anywhere in the arguments must not run the command');
        self::assertStringContainsString('PROBE HELP', $result['output']);
    }

    /**
     * A command with no help written still must not execute. Falling back to
     * running it is the whole bug; falling back to a usage line is honest.
     */
    public function testACommandWithNoHelpStillDoesNotExecute(): void
    {
        $result = $this->invoke(['probe', '--help'], BareProbeCommand::class);

        self::assertFalse(BareProbeCommand::$executed);
        self::assertSame(0, $result['code']);
        self::assertStringContainsString('No detailed help', $result['output']);
    }

    // ── unknown options ──────────────────────────────────────────────────────

    /**
     * The other half: a flag that is ignored rather than rejected.
     * `seed --with-fixture` (singular) seeds WITHOUT fixtures and reports
     * success, and the operator learns it from what is missing afterwards.
     */
    public function testAnUndeclaredOptionIsRefusedRatherThanIgnored(): void
    {
        $result = $this->invoke(['probe', '--with-fixture'], HelpProbeCommand::class);

        self::assertFalse(HelpProbeCommand::$executed, 'a command must not run with an option it does not accept');
        self::assertSame(1, $result['code']);
        self::assertStringContainsString('--with-fixture', $result['output']);
    }

    public function testADeclaredOptionIsAccepted(): void
    {
        $result = $this->invoke(['probe', '--known'], HelpProbeCommand::class);

        self::assertTrue(HelpProbeCommand::$executed);
        self::assertSame(['--known'], HelpProbeCommand::$sawArgs);
    }

    /** `--flag=value` is the same option as `--flag`. */
    public function testADeclaredOptionWithAValueIsAccepted(): void
    {
        $result = $this->invoke(['probe', '--known=7'], HelpProbeCommand::class);

        self::assertTrue(HelpProbeCommand::$executed);
    }

    /** Positional arguments are the command's own business, not options. */
    public function testPositionalArgumentsAreNotTreatedAsOptions(): void
    {
        $result = $this->invoke(['probe', 'delete', '17'], HelpProbeCommand::class);

        self::assertTrue(HelpProbeCommand::$executed);
        self::assertSame(['delete', '17'], HelpProbeCommand::$sawArgs);
    }

    /**
     * A command that has NOT declared its options keeps working exactly as it
     * did. "Undeclared" must not come to mean "accepts nothing", or this fix
     * becomes its own outage across nine unaudited commands.
     */
    public function testACommandThatDeclaresNoFlagsIsNotValidated(): void
    {
        $result = $this->invoke(['probe', '--anything-at-all'], BareProbeCommand::class);

        self::assertTrue(BareProbeCommand::$executed, 'an undeclared command must keep its current behaviour');
    }
}

/** Declares help and a flag set. */
final class HelpProbeCommand implements CliCommand, CommandHelp
{
    public static bool $executed = false;
    /** @var list<string> */
    public static array $sawArgs = [];

    /** @param list<string> $argv */
    public function execute(array $argv): int
    {
        self::$executed = true;
        self::$sawArgs = $argv;

        return 0;
    }

    public function printHelp(string $commandName): bool
    {
        echo "PROBE HELP\n";

        return true;
    }

    /** @return list<string> */
    public function knownFlags(): ?array
    {
        return ['--known', '--help', '-h'];
    }
}

/**
 * Runnable, but says nothing about itself — the state nine of the twelve real
 * commands are in. It must still be protected from executing on `--help`, and
 * must still accept whatever flags it accepts today.
 */
final class BareProbeCommand implements CliCommand
{
    public static bool $executed = false;

    /** @param list<string> $argv */
    public function execute(array $argv): int
    {
        self::$executed = true;

        return 0;
    }
}
