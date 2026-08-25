<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\RouteTemplate;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\RouteTemplate\RouteTemplateContract;

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
 * {@see RouteTemplateContract} exists only because #1014 and #1027 were built on
 * separate branches and an authoring surface cannot reference classes that are
 * not on its branch. That makes it a MIRROR, and an unchecked mirror is just a
 * second opinion waiting to differ.
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
 * PROVEN TO COMPARE, NOT MERELY TO RUN. Mutating one member of the mirrored
 * vocabulary on BOTH template sides at once — the constant and this feature's own
 * migration CHECK together, so they still agree with each other — leaves
 * {@see testTemplateMigrationMatchesTheContractConstants()} passing and fails
 * {@see testTemplateVocabularyMirrorsTheEngineVocabulary()} alone. That is the
 * isolation worth having: the second test is reading the ENGINE, not a copy of
 * this feature's own input.
 */
final class RouteTemplateVocabularyTest extends TestCase
{
    private const MIGRATIONS = __DIR__ . '/../../../../../database/migrations';

    /**
     * The template migration's own CHECK constraints must match the constants.
     *
     * This half never skips: both sides ship in this change, so a disagreement
     * here is always a real bug on this branch.
     */
    public function testTemplateMigrationMatchesTheContractConstants(): void
    {
        $sql = $this->templateMigrationSource();

        self::assertSame(
            RouteTemplateContract::verdicts(),
            $this->checkValues($sql, 'verdict'),
            "The template edges CHECK and RouteTemplateContract::verdicts() disagree. A verdict the class "
            . 'admits and the database refuses is a save that fails at insert; the reverse is a stored row '
            . 'no reader can interpret.'
        );

        self::assertSame(
            RouteTemplateContract::quorums(),
            $this->checkValues($sql, 'decision_quorum'),
            'The template steps CHECK and RouteTemplateContract::quorums() disagree.'
        );
    }

    /**
     * The template vocabulary must equal the ENGINE's, once both are present.
     *
     * A MISSING ENGINE MIGRATION IS A FAILURE, NOT A SKIP. It used to skip, while
     * #1014 was unmerged and there was genuinely nothing to compare against. That
     * is no longer true, and leaving the skip in would be the more dangerous of
     * the two options: a test that skips is indistinguishable from one that
     * passes in every summary anybody reads, so a rename that moved the engine
     * migration out of reach of the finder would silently retire the only check
     * comparing the two vocabularies — while CI stayed green.
     */
    public function testTemplateVocabularyMirrorsTheEngineVocabulary(): void
    {
        $engine = $this->engineMigrationSource();
        if ($engine === null) {
            self::fail(
                'No migration creates `document_route_edges`, so the template vocabulary has nothing to be '
                . 'compared against. That is a FAILURE rather than a skip: the engine side is on develop, so '
                . 'its absence means the finder no longer locates it — and a check that quietly stops '
                . 'comparing is the exact failure this test exists to prevent, one layer up.'
            );
        }

        self::assertSame(
            $this->checkValues($engine, 'verdict'),
            RouteTemplateContract::verdicts(),
            'The route TEMPLATE verdicts and the ENGINE verdicts have diverged. A template that names a '
            . 'verdict the engine cannot route on saves cleanly and does nothing when it is run.'
        );

        self::assertSame(
            $this->checkValues($engine, 'decision_quorum'),
            RouteTemplateContract::quorums(),
            'The route TEMPLATE quorums and the ENGINE quorums have diverged. A quorum the engine cannot '
            . 'count is an approval rule that silently falls back to something else.'
        );
    }

    /**
     * The setting a step with no explicit quorum defers to must be the one the
     * engine reads.
     *
     * Mirrored as a literal string on this branch because the key is #1014's and
     * `SettingsRegistry` does not know it here. Two different literals would mean
     * the editor drawing one default while the engine applied another — and the
     * editor's whole claim is that what it draws is what will happen.
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
            RouteTemplateContract::SETTING_APPROVAL_QUORUM,
            (string) file_get_contents($quorum),
            'RouteTemplateContract::SETTING_APPROVAL_QUORUM names a settings key RouteQuorum does not. '
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
