<?php

declare(strict_types=1);

namespace Whity\Commands;

use Whity\Core\Form\FormUploadSweeper;

/**
 * Cron command: sweep form uploads nobody ever submitted.
 *
 * A `file` answer's bytes are written BEFORE the submission exists — they have
 * to be, because a person attaches the file while they are still filling the
 * form in. Every abandoned form therefore leaves an object in a tenant's storage
 * that nothing will ever reference. {@see FormUploadSweeper} explains why a TTL
 * is the right answer and what the alternatives cost; this class is the way an
 * operator runs it.
 *
 * Usage:
 *   php public/index.php form-uploads:sweep [--ttl=86400] [--limit=500]
 *
 * Cron schedule (see docs/wiki/Cron-Operations.md):
 *   30 3 * * * php /var/www/whity/public/index.php form-uploads:sweep
 *
 * THE COUNTS ARE PRINTED, INCLUDING THE BAD ONE. `unreachable` is the number of
 * objects whose delete failed after their row was already removed: bytes that
 * are now costing money and that no later sweep will find. A job that printed
 * only "42 swept" would hide exactly the number an operator needs to see, which
 * is the failure class this codebase keeps writing against.
 *
 * The exit code is 0 even with unreachable objects — they are a storage-backend
 * problem to investigate, not a reason for a scheduler to treat the sweep as
 * having failed and alert on every run.
 */
final class FormUploadsSweepCommand
{
    public function __construct(private readonly FormUploadSweeper $sweeper)
    {
    }

    /**
     * @param list<string> $argv Arguments AFTER the command name.
     */
    public function execute(array $argv = []): int
    {
        $ttl = self::intOpt($argv, 'ttl', FormUploadSweeper::DEFAULT_TTL_SECONDS);
        $limit = self::intOpt($argv, 'limit', 500);

        try {
            $result = $this->sweeper->sweep($ttl, $limit);
        } catch (\Throwable $e) {
            // Named, not swallowed: a sweep that cannot reach the database is an
            // operator problem, and a silent success would let storage grow
            // unbounded while the cron log said everything was fine.
            fwrite(STDERR, 'Form-upload sweep failed: ' . $e->getMessage() . "\n");

            return 1;
        }

        echo "Swept {$result['swept']} unclaimed form uploads "
            . "(TTL {$ttl}s); {$result['unreachable']} objects could not be deleted\n";

        return 0;
    }

    /**
     * @param list<string> $argv
     */
    private static function intOpt(array $argv, string $name, int $default): int
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, "--{$name}=")) {
                $value = (int) substr($arg, strlen($name) + 3);

                return $value > 0 ? $value : $default;
            }
        }

        return $default;
    }
}
