<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Routing\RouteEffectStatus;

/**
 * {@see RouteEffectStatus} and migration 139's CHECK constraint must say the
 * same three things (#1032).
 *
 * Two ways they can drift, and both hurt:
 *
 *   - a value in PHP but not in the CHECK is one the runner will happily try to
 *     record and the DATABASE will refuse at insert — arriving as a driver
 *     error, from inside a fail-soft handler, about a vocabulary nobody was
 *     looking at;
 *   - a value in the CHECK but not in PHP is one the schema admits and nothing
 *     can produce or render. For THIS column that is quietly bad: every reader
 *     of an attempt log is asking "did this stage actually reach the world",
 *     and a status the reader cannot interpret is indistinguishable from one it
 *     did not look at.
 *
 * The migration source is read as TEXT rather than introspected from a live
 * database, exactly as the action, verdict and satisfaction pins are: this must
 * fail on the SQLite unit run too, and SQLite exposes a CHECK constraint
 * nowhere it can be queried back.
 */
final class RouteEffectStatusVocabularyTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../../database/migrations/139_create_route_step_effects.php';

    public function testTheCheckConstraintAndThePhpVocabularyAgree(): void
    {
        $source = file_get_contents(self::MIGRATION);
        self::assertIsString($source, 'migration 139 must be readable');

        $matched = preg_match(
            "/status\s+VARCHAR\(16\)\s+NOT NULL\s+CHECK \(status IN \(([^)]*)\)\)/",
            $source,
            $m
        );
        self::assertSame(1, $matched, 'migration 139 must declare the status CHECK inline on the column');

        $valueList = $m[1] ?? null;
        self::assertIsString($valueList, 'the CHECK must capture its value list');

        $inSql = array_map(
            static fn (string $value): string => trim($value, " '"),
            explode(',', $valueList)
        );
        sort($inSql);

        $inPhp = RouteEffectStatus::all();
        sort($inPhp);

        self::assertSame($inSql, $inPhp);
    }

    public function testSkippedIsOneOfThem(): void
    {
        // Named on its own rather than left to the set comparison above, because
        // it is the value most likely to be "tidied away" by somebody who reads
        // a three-valued outcome as a two-valued one with an odd extra. It is
        // not an extra: an effect with nobody to notify has neither succeeded
        // nor failed, and recording nothing is the silent no-op migration 112
        // refused to ship this feature without an answer to.
        self::assertContains(RouteEffectStatus::SKIPPED, RouteEffectStatus::all());
        self::assertTrue(RouteEffectStatus::isValid('skipped'));
        self::assertFalse(RouteEffectStatus::isValid('pending'));
    }

    public function testTheAttemptTableDoesNotBorrowTheActionVocabulary(): void
    {
        // The constraint migration 112 states and #1032 restates: an effect's
        // outcome has its own shape and must not be recorded as a sixth
        // `document_route_events.action` verb.
        //
        // What is forbidden is precise, so the assertion is too. REFERENCING
        // that table is fine and is exactly what an attempt does — it points at
        // the event it fired from. What must never appear is an ALTER of it, or
        // a second `action IN (…)` list: those are the two edits that would
        // widen the closed vocabulary, and both are one line.
        $source = preg_replace('#/\*\*.*?\*/#s', '', (string) file_get_contents(self::MIGRATION)) ?? '';

        self::assertDoesNotMatchRegularExpression(
            '/ALTER TABLE\s+document_route_events/i',
            $source,
            'Migration 139 must not alter document_route_events. An effect attempt is not a human '
            . 'act, and putting both shapes in one table gives up what the closed vocabulary buys.'
        );
        self::assertDoesNotMatchRegularExpression(
            '/action\s+IN\s*\(/i',
            $source,
            'Migration 139 must declare no action vocabulary. Effects record their own status; the '
            . 'five routing verbs stay exactly five.'
        );
    }
}
