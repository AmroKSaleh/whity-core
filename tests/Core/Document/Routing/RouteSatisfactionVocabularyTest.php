<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteSatisfaction;

/**
 * #1054's closed vocabulary — WHAT SETTLES A STEP — is declared in three places,
 * and every pair of them can drift.
 *
 * This is the same pin {@see RouteActionVocabularyTest} puts on the action
 * vocabulary and {@see RouteVerdictVocabularyTest} on verdicts, and it exists for
 * the same reason: drift in either direction fails at a different time and reads
 * as a different bug.
 *
 *   - a value in PHP but not in the CHECK is a constant every writer can
 *     reference and the DATABASE refuses at insert, arriving at run time inside a
 *     transaction as a driver error nobody attributes to a vocabulary;
 *   - a value in the CHECK but not in PHP is one the schema admits and no reader
 *     can render — and every reader of this column falls back to `act`, so such a
 *     step would silently become an ordinary one, which for a delivery step means
 *     handing an unclearable item to everybody it reaches.
 *
 * The THIRD declaration is the TEMPLATE table's, and it matters as much as the
 * engine's. A design that can express a satisfaction the engine cannot run is a
 * design that saves cleanly, renders cleanly on the canvas, and does something
 * else the day it is finally applied to a document — which is precisely the door
 * {@see \Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation} was written
 * to keep shut.
 *
 * The migration source is read as TEXT rather than introspected from a live
 * database, exactly as the other two pins are: this has to fail on the SQLite
 * unit run too, and SQLite exposes a CHECK constraint nowhere it can be queried
 * back.
 */
final class RouteSatisfactionVocabularyTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../../database/migrations/125_add_route_step_satisfaction.php';

    private static function source(): string
    {
        $source = file_get_contents(self::MIGRATION);
        self::assertIsString($source, 'migration 125 must be readable');

        return $source;
    }

    public function testBothCheckConstraintsAndThePhpVocabularyAgree(): void
    {
        $inPhp = RouteSatisfaction::all();
        sort($inPhp);

        $source = self::source();

        // ONE pattern, matched TWICE — deliberately, rather than two patterns
        // anchored on the two table names. The migration writes the identical
        // clause on both tables and the whole point of doing so is that they
        // cannot differ, so the assertion worth making is that every occurrence
        // in the file agrees with PHP.
        $matches = preg_match_all("/CHECK \(satisfied_by IN \(([^)]*)\)\)/", $source, $m);
        self::assertSame(
            2,
            $matches,
            'migration 125 must constrain satisfied_by on BOTH document_route_steps and '
            . 'document_route_template_steps. A design that can express a satisfaction the engine cannot '
            . 'run is one that saves cleanly and does something else when it is applied.'
        );

        foreach ($m[1] as $index => $list) {
            preg_match_all("/'([a-z_]+)'/", (string) $list, $values);
            $found = $values[1];
            sort($found);

            self::assertSame(
                $inPhp,
                $found,
                "RouteSatisfaction::all() and CHECK constraint #{$index} in migration 125 have drifted. "
                . 'One of them now admits a value the other refuses.'
            );
        }
    }

    public function testTheColumnDefaultsToActOnBothTables(): void
    {
        // Not a style point. Every step and every template stage written before
        // migration 125 gets this value, so if the default were `delivery` the
        // migration would silently convert every route in every tenant into one
        // that closes its recipients' items and moves on — a change of behaviour
        // to existing data, applied by an ALTER, reported nowhere.
        $matches = preg_match_all(
            "/ADD COLUMN IF NOT EXISTS satisfied_by VARCHAR\(16\) NOT NULL DEFAULT '([a-z_]+)'/",
            self::source(),
            $m
        );

        self::assertSame(2, $matches, 'both tables must add the column with an explicit default');
        self::assertSame(
            [RouteSatisfaction::ACT, RouteSatisfaction::ACT],
            $m[1],
            'the default must be `act` on both tables — it is what every row written before this '
            . 'migration means, and back-filling anything else would rewrite the behaviour of routes '
            . 'nobody touched'
        );
    }

    public function testTheColumnIsNotNullSoThereIsNoThirdMeaning(): void
    {
        // `verdict` (migration 119) is nullable because absence is a real third
        // fact there: "this act said nothing about approval". Here there is no
        // such fact — a step either asks somebody to act or it does not — so NULL
        // would be a state every reader had to invent a reading for, and two
        // readers would invent different ones.
        self::assertSame(
            2,
            preg_match_all("/satisfied_by VARCHAR\(16\) NOT NULL DEFAULT/", self::source()),
            'satisfied_by must be NOT NULL on both tables'
        );
    }

    public function testEveryConstantIsInTheAllList(): void
    {
        $declared = array_values(array_filter(
            (new \ReflectionClass(RouteSatisfaction::class))->getConstants(),
            static fn (mixed $v): bool => is_string($v)
        ));
        sort($declared);

        $values = RouteSatisfaction::all();
        sort($values);

        self::assertSame(
            $declared,
            $values,
            'every string constant on RouteSatisfaction must appear in all() — which is what lets this '
            . 'file assert completeness by reflection rather than against a list it also maintains'
        );
    }

    public function testTheFallbackIsActAndNotDelivery(): void
    {
        // Read by every normaliser in the subsystem for a row whose stored value
        // cannot be understood. The two ways of being wrong are not symmetric: an
        // unreadable value treated as `act` produces a document that visibly
        // waits for somebody, which somebody chases; treated as `delivery` it
        // closes every item and moves the document on, which nothing reports.
        self::assertSame(RouteSatisfaction::ACT, RouteSatisfaction::fallback());
    }

    public function testDeliveryIsRecognisedOnlyAsTheExactValue(): void
    {
        self::assertTrue(RouteSatisfaction::isDelivery(RouteSatisfaction::DELIVERY));

        // Everything else answers false, INCLUDING the shapes a loose comparison
        // would let through. `isDelivery()` guards the branch that closes a
        // person's item without their acting, so it is the one predicate in this
        // file that must never be generous.
        foreach ([null, '', 'act', 'DELIVERY', ' delivery', 1, true, ['delivery']] as $notDelivery) {
            self::assertFalse(
                RouteSatisfaction::isDelivery($notDelivery),
                'only the exact string `delivery` may close a recipient row without an act'
            );
        }
    }

    public function testSatisfactionIsNotAnActionVerb(): void
    {
        // The pin on #1054's central decision. Neither value may leak into the
        // action vocabulary: `document_route_events.action` is CHECK-constrained
        // on an APPEND-ONLY table, widening it is impossible on SQLite without
        // rebuilding that table, and a system close spelled as one of the five
        // existing verbs would have the trail assert that a person did something
        // they did not.
        foreach (RouteSatisfaction::all() as $value) {
            self::assertNotContains(
                $value,
                RouteAction::all(),
                "'{$value}' must not be an action verb — see RouteSatisfaction for the whole argument"
            );
        }

        self::assertCount(
            5,
            RouteAction::all(),
            '#1054 must not have added a sixth action verb. If this fails, read migration 125 and '
            . 'migration 119 before changing the number.'
        );
    }
}
