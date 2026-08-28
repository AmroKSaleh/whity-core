<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

/**
 * A command that can describe itself, and say which options it accepts.
 *
 * AN INTERFACE RATHER THAN A BASE-CLASS METHOD, because the commands do not
 * share a base class. Only three of twelve extend {@see BaseCommand} — the
 * runner's `@var BaseCommand` annotation is aspirational — so a method added
 * there would be callable on a quarter of them and fatal on the rest. That is
 * the kind of thing a docblock can assert and nothing checks.
 *
 * WHAT THIS IS FOR. `whity-cli seed --help` seeded the database. Nothing handled
 * `--help` and unrecognised options were ignored rather than rejected, so the
 * documented way to ask a command about itself was the way to run it. The
 * commands that DID handle it matched only in the first position, where the flag
 * reads as the action — so `migrate --help` printed help and `migrate run
 * --help` ran the migrations.
 *
 * {@see \Whity\Cli\CliRunner} checks for this interface before doing anything
 * else. A command that does not implement it still gets the protection: help is
 * intercepted and a generic usage line printed. Implementing it only makes the
 * help WORTH reading.
 */
interface CommandHelp
{
    /**
     * Print this command's help. Return false when it has none written, and the
     * runner prints a generic usage line instead.
     *
     * Must not touch the database, the kernel, or anything else. It is called
     * precisely because somebody has not decided to run the command yet.
     *
     * @param string $commandName The name as typed, for a class serving several.
     */
    public function printHelp(string $commandName): bool;

    /**
     * The options this command accepts, or null when it has not declared them.
     *
     * Null means "do not validate", NOT "accepts nothing". A command that has
     * not been audited must keep behaving exactly as it does now rather than
     * start rejecting a flag somebody relies on — the fix must not become its
     * own outage.
     *
     * Where a command does declare them, an option outside the list is refused
     * rather than ignored. A silently-ignored flag is the other half of the same
     * defect: `seed --with-fixture` (singular) seeds WITHOUT fixtures and reports
     * success, and the operator finds out from what is missing afterwards.
     *
     * @return list<string>|null
     */
    public function knownFlags(): ?array;
}
