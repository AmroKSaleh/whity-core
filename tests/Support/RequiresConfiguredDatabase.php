<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Throwable;

/**
 * The database probe the CLI command tests gate on (#1013 item 2).
 *
 * WHY THIS IS NOT `return false` ON EVERY FAILURE
 * ----------------------------------------------
 * The probe it replaces built its DSN as `pgsql:host=$host;dbname=$name` — no
 * port — and swallowed every exception into "database not available". On the
 * default port that is invisible. On any other port, and local stacks land on
 * ephemeral ones, it reported "no database" for a database that was running,
 * both tests took their skip branch, and the run went green having executed
 * neither the seed nor the migration it exists to exercise.
 *
 * A skipped test that looks green is worse than a failing one, because nothing
 * prompts anybody to look. So this distinguishes the two situations the old one
 * collapsed into one:
 *
 *   NOTHING IS CONFIGURED  — no DB_USER / DB_PASSWORD, so nothing anywhere says
 *                            where a database is. There is nothing to test
 *                            against and no expectation to disappoint: SKIP.
 *                            This is the CI unit job and every developer without
 *                            a local Postgres.
 *   CONFIGURED, NOT THERE  — somebody DID say where the database is and it was
 *                            not there. That is a broken environment or a wrong
 *                            variable, and reporting it as "not available" hides
 *                            it: FAIL, naming the address that was tried.
 *
 * The DSN is assembled exactly as {@see \Whity\Database\Database::connect()}
 * assembles it — host, PORT, dbname — so a probe that succeeds proves the thing
 * the command under test is about to do will also succeed, and cannot again be
 * true of a different database than the one the command reaches.
 */
trait RequiresConfiguredDatabase
{
    /**
     * A live connection to the configured database.
     *
     * Skips the calling test when no database is configured at all; fails it
     * when one is configured and unreachable.
     */
    private function connectToConfiguredDatabase(): PDO
    {
        $user     = self::envValue('DB_USER');
        $password = self::envValue('DB_PASSWORD');

        // The same two variables Database::connect() calls REQUIRED. Host, port
        // and database name all have defaults there, so their absence is not
        // "unconfigured" — these two are the signal.
        if ($user === null || $password === null) {
            $this->markTestSkipped(
                'No database is configured (DB_USER / DB_PASSWORD are unset), so there is nothing '
                . 'for this test to run against. Set them — and DB_HOST/DB_PORT/DB_NAME if they are '
                . 'not localhost:5432/whity_core — to exercise it.'
            );
        }

        $host   = self::envValue('DB_HOST') ?? 'localhost';
        $port   = self::envValue('DB_PORT') ?? '5432';
        $dbName = self::envValue('DB_NAME') ?? 'whity_core';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbName);

        try {
            return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            self::fail(sprintf(
                'A database is configured at %s:%s/%s (DB_USER=%s) but could not be reached: %s. '
                . 'This is deliberately a failure rather than a skip — "you told me where the database '
                . 'is and it was not there" is a broken environment, and reporting it as "no database '
                . 'available" is what let this test pass for months without running.',
                $host,
                $port,
                $dbName,
                $user,
                $e->getMessage()
            ));
        }
    }

    /**
     * An environment variable's non-empty value, or null.
     *
     * Both channels, because they disagree in practice: the app reads `$_ENV`
     * (the CLI bootstrap loads `.env` into it), while a shell export or a
     * `docker run -e` reaches a PHP built without `E` in variables_order through
     * `getenv()` only. A probe that consulted one of them would go quiet in
     * exactly the environment that used the other.
     */
    private static function envValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? null;
        if (!is_string($value) || $value === '') {
            $fromGetenv = getenv($name);
            $value = is_string($fromGetenv) && $fromGetenv !== '' ? $fromGetenv : null;
        }

        return $value;
    }
}
