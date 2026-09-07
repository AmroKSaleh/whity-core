<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissionDescriptions;

/**
 * The symptom, asserted against a database built the way a real one is.
 *
 * The reported defect was empirical — "43 of 63 permissions carry a placeholder
 * description, verified on a fresh database" — so the fix has to be checked the
 * same way. A unit test over the PHP map proves the text EXISTS; only running
 * the migrations proves it reaches the rows the role editor reads.
 *
 * That distinction is the whole bug. The good text existed all along, in later
 * migrations. It never landed, because migration 013 had already created the row
 * and `ON CONFLICT DO NOTHING` discarded it. A test that stopped at the map would
 * have passed throughout.
 */
final class PermissionDescriptionsRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
    }

    /**
     * @return list<array{name: string, description: ?string}>
     */
    private function catalogue(): array
    {
        $stmt = $this->pdo->query('SELECT name, description FROM permissions ORDER BY name');
        self::assertNotFalse($stmt);

        /** @var list<array{name: string, description: ?string}> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /** THE DEFECT: `Core permission (groups:read)` on a freshly-migrated database. */
    public function testNoPermissionCarriesPlaceholderText(): void
    {
        $placeholders = [];
        foreach ($this->catalogue() as $row) {
            $text = (string) $row['description'];
            if (stripos($text, 'core permission (') === 0) {
                $placeholders[] = $row['name'];
            }
        }

        self::assertSame(
            [],
            $placeholders,
            'These rows still say "Core permission (x)" after migrating, which is what an '
            . "administrator would read in the role editor:\n  " . implode("\n  ", $placeholders)
        );
    }

    /** The slug repeated back is filler too — three OU permissions shipped that way. */
    public function testNoPermissionIsDescribedByItsOwnSlug(): void
    {
        $selfReferential = [];
        foreach ($this->catalogue() as $row) {
            if (trim((string) $row['description']) === $row['name']) {
                $selfReferential[] = $row['name'];
            }
        }

        self::assertSame([], $selfReferential, implode(', ', $selfReferential));
    }

    public function testNoPermissionIsMissingADescriptionEntirely(): void
    {
        $empty = [];
        foreach ($this->catalogue() as $row) {
            if ($row['description'] === null || trim((string) $row['description']) === '') {
                $empty[] = $row['name'];
            }
        }

        self::assertSame([], $empty, implode(', ', $empty));
    }

    /**
     * The stored text must be the text the code declares — not merely
     * non-placeholder. Otherwise a stale row that happens to hold some other
     * prose would pass every check above while still disagreeing with the source
     * of truth, which is the drift this whole fix is about.
     */
    public function testStoredDescriptionsMatchTheCodeCatalogue(): void
    {
        $stored = [];
        foreach ($this->catalogue() as $row) {
            $stored[$row['name']] = (string) $row['description'];
        }

        $mismatched = [];
        foreach (CorePermissionDescriptions::all() as $slug => $expected) {
            // Only rows this database actually has: the catalogue's membership is
            // decided by the migrations that declare each permission, not here.
            if (!array_key_exists($slug, $stored)) {
                continue;
            }
            if ($stored[$slug] !== $expected) {
                $mismatched[] = sprintf('%s: stored %s / expected %s', $slug, $stored[$slug], $expected);
            }
        }

        self::assertSame([], $mismatched, implode("\n  ", $mismatched));
    }

    /**
     * Re-runnable: this is the mechanism that keeps the catalogue honest, not a
     * one-off repair, so applying it twice must be indistinguishable from once.
     */
    public function testTheSyncIsIdempotent(): void
    {
        $before = $this->catalogue();

        // Same construction SchemaFromMigrations uses to run the migrations in
        // the first place — the constructor is private, and wrapping the live
        // handle is how a test drives a migration against it.
        \Database\Migrations\SyncPermissionDescriptions::up(
            \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400)
        );

        self::assertEquals($before, $this->catalogue());
    }
}
