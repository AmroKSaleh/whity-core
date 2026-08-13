<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

/**
 * A command class that serves SEVERAL command names and needs to know which one
 * was typed — `i18n:extract` and `i18n:sync` are one class, two verbs.
 *
 * The alternative shapes both cost more than they give: a class per verb
 * duplicates the wiring, and reading the verb out of `$argv[0]` (the
 * `migrate status` style) would make the colon form `queue:work` and
 * `schedule:run` already use impossible for this command.
 */
interface NamedSubcommand
{
    /**
     * @param list<string> $argv        Arguments AFTER the command name.
     * @param string       $commandName The command name as typed, e.g. 'i18n:sync'.
     * @return int Process exit code.
     */
    public function execute(array $argv, string $commandName): int;
}
