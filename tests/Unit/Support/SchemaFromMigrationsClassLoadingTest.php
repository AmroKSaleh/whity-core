<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * Migration classes are declared on every path `make()` can take (#938).
 *
 * `SchemaFromMigrations` has two ways to produce a migrated database: run the
 * migrations, or clone a cached PostgreSQL template. Only the first one used to
 * `require` the migration files — the `require_once` lived inside the method
 * that executed them — so on the clone path the classes were never declared and
 * any test naming one got `Class "Database\Migrations\..." not found`.
 *
 * The clone path is taken when the template is WARM, which is to say on every
 * run after the first against a given PostgreSQL server. CI provisions a fresh
 * server per job, so its template is always cold and it never sees this. The
 * failure is reachable only locally, and only on a second run — the run a
 * developer does right after making a change, when a new error is most likely
 * to be read as their fault.
 *
 * ## What these can and cannot pin
 *
 * The property that matters — *a warm-template run gives the same verdict as a
 * cold one* — cannot be asserted from inside a single PHP process, because once
 * a class is declared it stays declared: there is no way to un-require the
 * files and re-enter `make()` as a fresh process would.
 *
 * So these tests pin the two halves that ARE checkable in-process, on any
 * engine: that loading is separable from running, and that loading actually
 * yields every class the runner will later look up by name. The end-to-end
 * property was verified by hand against a real warm template — three errors
 * before, 32/32 after — and is recorded in the PR rather than pretended at here.
 */
final class SchemaFromMigrationsClassLoadingTest extends TestCase
{
    /** @return list<string> Absolute paths of every migration file. */
    private function migrationFiles(): array
    {
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.php') ?: [];
        sort($files);

        return array_values($files);
    }

    /** The class name the loader derives from a migration filename. */
    private function classFor(string $file): string
    {
        $parts = explode('_', pathinfo($file, PATHINFO_FILENAME));
        array_shift($parts); // numeric prefix

        return 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));
    }

    public function testThereAreMigrationsToLoad(): void
    {
        // Guards the two tests below from passing vacuously if the glob ever
        // stops finding anything — an empty set satisfies "every class is
        // declared" perfectly.
        self::assertGreaterThan(80, count($this->migrationFiles()));
    }

    public function testLoadingDeclaresEveryMigrationClass(): void
    {
        SchemaFromMigrations::loadMigrationClasses();

        $missing = [];
        foreach ($this->migrationFiles() as $file) {
            $class = $this->classFor($file);
            if (!class_exists($class, false)) {
                $missing[] = basename($file) . ' => ' . $class;
            }
        }

        self::assertSame(
            [],
            $missing,
            "Every migration file must declare the class its name implies.\n"
            . "These did not:\n  " . implode("\n  ", $missing)
        );
    }

    public function testLoadingIsIdempotent(): void
    {
        // Called unconditionally from make(), so a second call must not fatal
        // with "Cannot redeclare class" — the property `require_once` gives and
        // a plain `require` would not.
        SchemaFromMigrations::loadMigrationClasses();
        SchemaFromMigrations::loadMigrationClasses();

        self::assertTrue(class_exists('Database\\Migrations\\CreateUsersRoles', false));
    }

    public function testLoadingRunsNoMigrations(): void
    {
        // The clone path calls this against a database that is ALREADY migrated,
        // so loading must define classes and touch nothing else. Requiring a
        // migration file only declares its class — `up()` is what does work —
        // and this pins that separation: with no database handed to it at all,
        // loading still succeeds.
        SchemaFromMigrations::loadMigrationClasses();

        $class = 'Database\\Migrations\\CreateUsersRoles';
        self::assertTrue(class_exists($class, false));
        self::assertTrue(
            method_exists($class, 'up'),
            'up() must exist but must NOT have been called by loading.'
        );
    }
}
