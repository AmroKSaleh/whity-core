<?php

declare(strict_types=1);

namespace Tests\Plugins;

use DemoCatalog\DemoCatalogPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\CoreTables;
use Whity\Sdk\Schema\ReferenceDeclarations;
use Whity\Sdk\Schema\UndeclaredReferenceLinter;

require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/DemoCatalogPlugin.php';

/**
 * The reference guard, run against a REAL plugin's real migrations and real
 * declaration — the same way `scripts/ci-undeclared-reference-guard.php` runs
 * it, and the same way an out-of-repo plugin would run it in its own CI.
 *
 * Synthetic fixtures (see
 * {@see \Tests\Unit\Sdk\Schema\UndeclaredReferenceLinterTest}) prove the rule.
 * This proves the rule FIRES ON THIS CODEBASE, which is a different claim and
 * the one a linter usually fails: a resolver that quietly matches nothing looks
 * exactly like a clean pass, and would ship as a permanently green no-op.
 *
 * So the experiment is run in both directions on the same schema:
 *
 *  - with DemoCatalog's real declaration, the migrations are clean;
 *  - with the declaration REMOVED and nothing else changed, the guard flags
 *    both of the plugin's real child tables — columns carrying no foreign key,
 *    pointing at `demo_catalog_items`, which core would then know nothing
 *    about. That is exactly the orphaning bug this exists for.
 *
 * Both halves of the reference graph are covered by the same run, which is the
 * point of treating them as one set: `demo_catalog_item_notes` is declared as a
 * `blocks_delete` guard (rows that must outlive the item) and
 * `demo_catalog_item_lines` as a `cascade_delete` composition (rows that die
 * with it). Opposite answers to what happens to the children; identical answers
 * to "does core know this edge exists?".
 */
final class DemoCatalogReferenceDeclarationTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../plugins/DemoCatalog/Migrations';

    public function testDemoCatalogsRealSchemaPassesWithItsRealDeclaration(): void
    {
        $violations = $this->linter($this->realDeclarations())->lintDirectory(self::MIGRATIONS);

        self::assertSame(
            [],
            $violations,
            'A plugin whose references are declared passes — with, note, not one foreign '
            . 'key between its own tables. The convention is not the defect.'
        );
    }

    public function testRemovingTheDeclarationMakesTheSameSchemaFail(): void
    {
        // THE TEETH, on real code. Nothing about the schema changes; only what
        // core has been told.
        $violations = $this->linter(new ReferenceDeclarations())->lintDirectory(self::MIGRATIONS);

        $flagged = array_map(
            static fn (array $v): string => $v['table'] . '.' . $v['column'] . ' -> ' . $v['target'],
            $violations
        );
        sort($flagged);

        self::assertSame(
            [
                'demo_catalog_item_lines.item_id -> demo_catalog_items',
                'demo_catalog_item_notes.item_id -> demo_catalog_items',
            ],
            $flagged,
            'Undeclared, both of the plugin\'s real child tables must be flagged — otherwise '
            . 'the resolver is matching nothing and this guard is a permanently green no-op. '
            . 'Note the resolved target: plugin tables are PREFIXED, so a column is routinely '
            . 'named `item_id` for a table called `demo_catalog_items`, and a linter that only '
            . 'matched the column stem outright would see no reference here at all.'
        );
    }

    /**
     * Each half of the graph silences its own edge, and only its own.
     *
     * `demo_catalog_item_notes` is declared through `blocks_delete` and
     * `demo_catalog_item_lines` through `cascade_delete`. Removing one kind of
     * declaration must leave exactly the other kind's edge clean — which is
     * what proves the linter is reading BOTH lists rather than one of them and
     * getting lucky.
     *
     * @dataProvider oneListAtATime
     * @param 'blocks_delete'|'cascade_delete' $keep
     */
    public function testEachDeclarationListIsReadIndependently(string $keep, string $expectedFlagged): void
    {
        $plugin = new DemoCatalogPlugin();

        $stripped = [];
        foreach ($plugin->getDataTypes() as $slug => $declaration) {
            unset($declaration[$keep === 'blocks_delete' ? 'cascade_delete' : 'blocks_delete']);
            $stripped[$slug] = $declaration;
        }

        $violations = $this
            ->linter(ReferenceDeclarations::fromDataTypes($stripped, $plugin->getName()))
            ->lintDirectory(self::MIGRATIONS);

        self::assertCount(1, $violations, "Keeping only {$keep} must leave exactly the other edge undeclared.");
        self::assertSame($expectedFlagged, $violations[0]['table']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function oneListAtATime(): array
    {
        return [
            'only blocks_delete kept' => ['blocks_delete', 'demo_catalog_item_lines'],
            'only cascade_delete kept' => ['cascade_delete', 'demo_catalog_item_notes'],
        ];
    }

    public function testTheGuardIsSilentAboutTheAbsentForeignKeysThemselves(): void
    {
        // The rule this must never become. DemoCatalog has zero foreign keys
        // between its own tables — the established convention — and the clean
        // run above already proves the guard says nothing about that. This
        // pins the premise so a future change to the rule cannot quietly turn
        // it into "every *_id needs an FK" and be ignored within a day.
        $source = (string) file_get_contents(self::MIGRATIONS . '/CreateDemoCatalogItemNotesTable.php');

        self::assertStringNotContainsString(
            'REFERENCES',
            $source,
            'This fixture is only meaningful while the plugin genuinely has no foreign keys.'
        );
    }

    /**
     * The declaration DemoCatalog actually ships, read the way the CI script
     * reads it — not a copy of it, so this test cannot pass against a
     * declaration the plugin no longer makes.
     */
    private function realDeclarations(): ReferenceDeclarations
    {
        $plugin = new DemoCatalogPlugin();

        return ReferenceDeclarations::fromDataTypes($plugin->getDataTypes(), $plugin->getName());
    }

    /**
     * A linter that knows every table the platform can resolve: core's, plus
     * every table DemoCatalog declares it owns.
     */
    private function linter(ReferenceDeclarations $declarations): UndeclaredReferenceLinter
    {
        $known = CoreTables::all();
        foreach (array_keys((new DemoCatalogPlugin())->getOwnedTables()) as $table) {
            $known[] = (string) $table;
        }

        return new UndeclaredReferenceLinter($known, $declarations);
    }
}
