<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\RouteTemplate;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Settings\SettingsRegistry;

/**
 * The template vocabulary must agree with the ENGINE's (#1027 / #1014).
 *
 * WHAT THIS IS ACTUALLY GUARDING
 * ------------------------------
 * A route template is a CONSTRUCTOR for route steps. If it can express a verdict
 * the engine cannot route on, or a quorum the engine cannot count, then an author
 * can draw a flow that saves cleanly, renders cleanly, and does something
 * different from what it draws when it is finally run — months later, on somebody
 * else's document. Nothing errors at any point.
 *
 * THERE IS ONE VOCABULARY NOW. `RouteTemplateContract` used to mirror the
 * engine's constants, because #1014 and #1027 were built on separate branches
 * and an authoring surface cannot reference classes that are not on its branch.
 * #1033 deleted it: authoring and the engine both read {@see RouteVerdict} and
 * {@see RouteQuorum}, so there is no longer a second opinion to differ.
 *
 * That removed one of this file's three tests rather than fixing it — comparing
 * the template vocabulary against the engine's became comparing a thing with
 * itself, which is a test that cannot fail. What survives is the comparison that
 * was never about the mirror: PHP constants against the DATABASE's CHECK
 * constraints, on both the template tables and the engine tables. Those are two
 * independent statements of the vocabulary and can still drift.
 *
 * WHY IT READS MIGRATION SOURCE RATHER THAN CLASSES
 * -------------------------------------------------
 * Because a constant that agrees with another constant while the DATABASE
 * refuses the value is not agreement. The CHECK constraints are what the engine
 * will actually enforce, so they are what the two sides are compared on.
 *
 * IT CANNOT SKIP, AND THAT IS DELIBERATE
 * --------------------------------------
 * An earlier version of this test SKIPPED when it could not find the engine
 * migration — correct at the time, because #1014 was unmerged and there was
 * genuinely nothing to compare against. It is merged now, so that branch has
 * been replaced by an outright failure.
 *
 * The reason is worth stating because the skip looked like the careful choice: a
 * test that skips is INDISTINGUISHABLE FROM ONE THAT PASSES in every summary
 * either a human or CI will look at. Had it stayed, a rename that moved the
 * engine migration out of reach of {@see engineMigrationSource()} would have
 * silently retired the only check comparing the two vocabularies, and the branch
 * would have gone on reporting green. That is precisely the failure this test
 * exists to prevent, reached one layer up — in the test rather than in the code.
 *
 * So: both halves run unconditionally, and anything that stops them finding what
 * they compare is a red build.
 *
 * PROVEN TO COMPARE, NOT MERELY TO RUN. Adding a member to `RouteVerdict::all()`
 * fails BOTH surviving tests — the template CHECK and the engine CHECK each stop
 * agreeing with the constants. Re-checked after #1033 removed the third test,
 * because dropping a test is exactly when the remaining ones need to be shown to
 * still bite rather than assumed to.
 */
final class RouteTemplateVocabularyTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../../../../database/migrations';

    /**
     * The template migration's own CHECK constraints must match the ENGINE constants.
     *
     * This half never skips: both sides ship in this change, so a disagreement
     * here is always a real bug on this branch.
     */
    public function testTemplateMigrationMatchesTheEngineConstants(): void
    {
        $sql = $this->templateMigrationSource();

        self::assertSame(
            RouteVerdict::all(),
            $this->checkValues($sql, 'verdict'),
            "The template edges CHECK and RouteVerdict::all() disagree. A verdict the class "
            . 'admits and the database refuses is a save that fails at insert; the reverse is a stored row '
            . 'no reader can interpret.'
        );

        self::assertSame(
            RouteQuorum::all(),
            $this->checkValues($sql, 'decision_quorum'),
            'The template steps CHECK and RouteQuorum::all() disagree.'
        );
    }

    /**
     * The ENGINE's own migration CHECKs must match the same constants.
     *
     * WHAT THIS USED TO BE. It compared the template vocabulary against the
     * engine's, because `RouteTemplateContract` mirrored the engine's constants
     * while #1014 and #1027 sat on separate branches. #1033 deleted that mirror,
     * so there are no longer two vocabularies to disagree — both sides now read
     * `RouteVerdict` / `RouteQuorum`, and a comparison of a thing with itself is
     * a test that cannot fail.
     *
     * What is still worth asserting is the half that was never about the mirror:
     * the DATABASE's engine-side CHECK constraints and the PHP constants are
     * independent statements of one vocabulary, and they can still drift.
     */
    public function testEngineMigrationMatchesTheEngineConstants(): void
    {
        $engine = $this->engineMigrationSource();
        if ($engine === null) {
            self::fail(
                'No migration creates `document_route_edges`, so the engine vocabulary has nothing to be '
                . 'compared against. That is a FAILURE rather than a skip: the engine side is on develop, so '
                . 'its absence means the finder no longer locates it — and a check that quietly stops '
                . 'comparing is the exact failure this test exists to prevent, one layer up.'
            );
        }

        self::assertSame(
            $this->checkValues($engine, 'verdict'),
            RouteVerdict::all(),
            'The engine edges CHECK and RouteVerdict::all() disagree. A verdict the class admits and the '
            . 'database refuses is a route that fails at insert; the reverse is a stored row no reader can '
            . 'interpret.'
        );

        self::assertSame(
            $this->checkValues($engine, 'decision_quorum'),
            RouteQuorum::all(),
            'The engine steps CHECK and RouteQuorum::all() disagree. A quorum the engine cannot count is an '
            . 'approval rule that silently falls back to something else.'
        );
    }

    /**
     * The setting a step with no explicit quorum defers to must be the one the
     * engine reads.
     *
     * Read from `SettingsRegistry` now rather than mirrored as a literal: the key
     * was duplicated on this branch only while #1014's registry entry was out of
     * reach (#1033). Two different literals would mean the editor drawing one
     * default while the engine applied another — and the editor's whole claim is
     * that what it draws is what will happen.
     */
    public function testTheDeferredSettingKeyIsTheOneTheEngineNames(): void
    {
        $quorum = dirname(__DIR__, 5) . '/src/Core/Document/Routing/RouteQuorum.php';
        if (!is_file($quorum)) {
            self::fail(
                'RouteQuorum is missing. It is on develop, so its absence means it moved — and the settings '
                . 'key mirrored from it can no longer be checked against anything.'
            );
        }

        self::assertStringContainsString(
            SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM,
            (string) file_get_contents($quorum),
            'SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM names a settings key RouteQuorum does not. '
            . 'The editor would draw a default the engine never reads.'
        );
    }

    /**
     * This feature's own migration, located by what it declares.
     *
     * By CONTENT and not by `120_...` for the same reason the engine's is: two
     * branches colliding on one number get renumbered, and this file has already
     * been renumbered twice while #1014 moved. A test pinned to the prefix would
     * fail for the wrong reason on the next collision, and the fix would look
     * like editing a test to make it pass.
     */
    private function templateMigrationSource(): string
    {
        $files = glob(self::MIGRATIONS . '/*.php');
        foreach ($files === false ? [] : $files as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+document_route_templates\b/i', $source) === 1) {
                return $source;
            }
        }

        self::fail(
            'No migration creates `document_route_templates`. The route-template tables are the thing this '
            . 'test exists to police, so their absence is a failure rather than a reason to skip.'
        );
    }

    /**
     * #1014's migration, by what it DECLARES rather than by its number.
     *
     * Located by content, not by `118_...`: migration numbers are renumbered
     * whenever two branches collide on one, and a test that hard-coded the prefix
     * would start silently skipping the moment that happened — which is precisely
     * when the two vocabularies are most likely to be diverging.
     *
     * MATCHED ON THE CREATE STATEMENT, NOT ON THE NAME APPEARING ANYWHERE. The
     * first version of this method tested `str_contains($source, 'document_route_edges')`
     * and matched THIS FEATURE'S OWN migration, whose docblock names that table
     * to explain what it mirrors. The comparison then ran the template vocabulary
     * against itself and reported green — a test that agreed with a copy of its
     * own input, which is exactly the failure it was written to prevent. The
     * template migration is excluded explicitly as well, so a future docblock
     * that happens to quote the engine's create statement cannot resurrect it.
     */
    private function engineMigrationSource(): ?string
    {
        $creates = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+document_route_edges\b/i';
        $isTemplateMigration = '/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+document_route_templates\b/i';

        $files = glob(self::MIGRATIONS . '/*.php');
        foreach ($files === false ? [] : $files as $path) {
            $source = (string) file_get_contents($path);
            if (preg_match($isTemplateMigration, $source) === 1) {
                continue;
            }
            if (preg_match($creates, $source) === 1 && str_contains($source, 'decision_quorum')) {
                return $source;
            }
        }

        return null;
    }

    /**
     * The values of the first `CHECK (... col ... IN ('a', 'b'))` for a column.
     *
     * Deliberately narrow: it reads the SQL that the database will actually
     * enforce, rather than trusting a docblock beside it.
     *
     * @return list<string>
     */
    private function checkValues(string $sql, string $column): array
    {
        $pattern = '/CHECK\s*\([^)]*\b' . preg_quote($column, '/') . '\b[^)]*IN\s*\(([^)]*)\)/i';
        self::assertSame(
            1,
            preg_match($pattern, $sql, $matches),
            "Could not find a CHECK constraint listing the allowed values of '{$column}'. If the constraint "
            . 'was rewritten in another shape, this test must be taught the new one rather than deleted — '
            . 'it is the only thing comparing the two vocabularies.'
        );

        preg_match_all("/'([^']+)'/", $matches[1], $values);

        return $values[1];
    }
}
