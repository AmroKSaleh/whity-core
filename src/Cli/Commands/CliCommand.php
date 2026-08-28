<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

/**
 * A command the CLI runner can execute.
 *
 * WHY THIS DID NOT EXIST, AND WHY THAT MATTERED. The runner carried
 * `/** @var BaseCommand $command *&#47;` and called `execute()` on whatever it
 * had constructed. Only three of the twelve commands extend `BaseCommand`, so
 * the annotation was false for the other nine — and static analysis believed it,
 * because an annotation is an assertion nothing checks. The code happened to
 * work only because every command defines `execute()` by convention.
 *
 * That is the same shape as the bug this arrived with: something asserted, never
 * verified, correct by luck. Removing the false annotation made the gap visible,
 * and this is the contract it was standing in for.
 *
 * TWO INTERFACES, NOT ONE. {@see NamedSubcommand} takes the typed command name
 * as a second argument, because one class serves `i18n:extract` and `i18n:sync`.
 * PHP cannot have a class satisfy both signatures, so the runner checks for the
 * two-argument form first and falls back to this. A class implementing neither
 * is a wiring mistake and is refused with a message that says so, rather than
 * fataling on an undefined method.
 */
interface CliCommand
{
    /**
     * @param list<string> $argv Arguments AFTER the command name.
     * @return int Process exit code.
     */
    public function execute(array $argv): int;
}
