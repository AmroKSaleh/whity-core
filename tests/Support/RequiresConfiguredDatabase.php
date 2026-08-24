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
 * prompts anybody to look. So this separates the situations the old one
 * collapsed into a single reassuring answer:
 *
 *   NOTHING IS CONFIGURED   nothing anywhere says where a database is. There is
 *                           nothing to test against and no expectation to
 *                           disappoint: SKIP. This is the CI unit job, and every
 *                           developer without a local Postgres.
 *   CONFIGURED, NOT THERE   somebody DID say where the database is and it was
 *                           not there. A broken environment or a wrong variable:
 *                           FAIL, naming the address that was tried.
 *   CONFIGURED WHERE THE    exported into the process but absent from `$_ENV`,
 *   APP CANNOT SEE IT       which is the only channel
 *                           {@see \Whity\Database\Database::connect()} reads.
 *                           The command under test therefore cannot connect
 *                           however healthy the database is: FAIL, and say which
 *                           of the two channels is empty. This is not
 *                           hypothetical — the CI workflow writes a `.env` file
 *                           rather than exporting variables precisely because a
 *                           runner's `variables_order` need not include `E`.
 *
 * The DSN is assembled exactly as `Database::connect()` assembles it — host,
 * PORT, dbname — so a probe that succeeds proves the thing the command under
 * test is about to do will also succeed, against the same database.
 */
trait RequiresConfiguredDatabase
{
    /**
     * A live connection to the configured database.
     *
     * Skips the calling test when no database is configured anywhere; fails it
     * when one is configured and the command under test could not reach it.
     */
    private function connectToConfiguredDatabase(): PDO
    {
        // $_ENV, because that is the ONLY channel Database::connect() consults;
        // a probe reading more than the code under test reads would go green on
        // configuration that code cannot use.
        $user     = self::appEnv('DB_USER');
        $password = self::appEnv('DB_PASSWORD');

        // The same two variables Database::connect() calls REQUIRED. Host, port
        // and database name all have defaults there, so their absence is not
        // "unconfigured" — these two are the signal.
        if ($user === null || $password === null) {
            if (self::processEnv('DB_USER') !== null || self::processEnv('DB_PASSWORD') !== null) {
                self::fail(
                    'DB_USER/DB_PASSWORD are set in the process environment but not in $_ENV, which is the '
                    . 'only place Database::connect() looks — so the command under test cannot connect no '
                    . 'matter what the database is doing. Either PHP\'s variables_order has no "E", or the '
                    . 'values arrived after start-up. Put them in a .env file (as the CI workflow does) or '
                    . 'set $_ENV directly.'
                );
            }

            $this->markTestSkipped(
                'No database is configured (DB_USER / DB_PASSWORD are unset), so there is nothing '
                . 'for this test to run against. Set them — and DB_HOST/DB_PORT/DB_NAME if they are '
                . 'not localhost:5432/whity_core — to exercise it.'
            );
        }

        $host   = self::appEnv('DB_HOST') ?? 'localhost';
        // The line the old probe did not have at all, and the whole of #1013's
        // second half: a DSN without a port silently means 5432, so a stack on
        // any other port was reported as "no database" and both tests skipped.
        $port   = self::appEnv('DB_PORT') ?? '5432';
        $dbName = self::appEnv('DB_NAME') ?? 'whity_core';

        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, $port, $dbName);

        try {
            return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (Throwable $e) {
            self::fail(sprintf(
                'A database is configured at %s:%s/%s (DB_USER=%s) but could not be reached: %s. '
                . 'This is deliberately a failure rather than a skip — "you told me where the database '
                . 'is and it was not there" is a broken environment, and reporting it as "no database '
                . 'available" is what let this test pass without running.',
                $host,
                $port,
                $dbName,
                $user,
                $e->getMessage()
            ));
        }
    }

    /**
     * Whether ANY database looks configured, without opening a socket.
     *
     * The question a test wanting the NO-database path has to ask, and it reads
     * BOTH channels on purpose — the inverse of the method above. There, reading
     * more than the app reads would green-light unusable configuration; here,
     * reading less would run a "there is no database" assertion in an
     * environment where somebody plainly believes there is one.
     */
    private static function aDatabaseIsConfigured(): bool
    {
        foreach (['DB_USER', 'DB_PASSWORD'] as $name) {
            if (self::appEnv($name) !== null || self::processEnv($name) !== null) {
                return true;
            }
        }

        return false;
    }

    /** An `$_ENV` value the application would see, or null. */
    private static function appEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** A process-environment value the application would NOT see, or null. */
    private static function processEnv(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
