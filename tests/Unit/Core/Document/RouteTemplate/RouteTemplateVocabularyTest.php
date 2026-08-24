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
 * The comparison has to work in three states of the world, and only one of them
 * has both class trees present:
 *
 *   - #1027 alone (this branch today): `RouteVerdict`/`RouteQuorum` do not exist.
 *   - #1014 alone: this test does not exist.
 *   - both merged: the real state this is written for.
 *
 * Reading the CHECK constraints out of the migration files works in all three,
 * and it also checks the thing that actually matters — a constant that agrees
 * with another constant while the DATABASE refuses the value is not agreement.
 *
 * WHY IT SKIPS RATHER THAN PASSES WHEN #1014 IS ABSENT
 * ----------------------------------------------------
 * A test that quietly returns green when it could not find the thing it compares
 * against is the exact false-green this repo has been bitten by. Skipping says
 * "not checked" out loud, and the day #1014's migration lands on the same branch
 * the comparison starts running by itself — with no follow-up ticket and nobody
 * needing to remember.
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
     * @see the class docblock for why this skips rather than passes.
     */
    public function testTemplateVocabularyMirrorsTheEngineVocabulary(): void
    {
        $engine = $this->engineMigrationSource();
        if ($engine === null) {
            self::markTestSkipped(
                "#1014's migration is not on this branch, so the template vocabulary has nothing to be "
                . 'compared against. This test starts checking automatically once both are merged.'
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
            self::markTestSkipped("#1014's RouteQuorum is not on this branch.");
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
