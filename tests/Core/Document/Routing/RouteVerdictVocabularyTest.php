<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Settings\SettingsRegistry;

/**
 * #1014's two closed vocabularies — VERDICTS and QUORUMS — are each declared in
 * more than one place, and every pair of places can drift.
 *
 * This is the same pin {@see RouteActionVocabularyTest} puts on the action
 * vocabulary, and it exists for the same reason: drift in either direction fails
 * at a different time and reads as a different bug.
 *
 *   - a value in PHP but not in the CHECK is a constant every writer can
 *     reference and the DATABASE refuses at insert, arriving at run time, inside
 *     a transaction, as a driver error nobody attributes to a vocabulary;
 *   - a value in the CHECK but not in PHP is one the schema admits and no reader
 *     can render — and `document_route_events` is APPEND-ONLY, so such a row
 *     cannot be corrected afterwards.
 *
 * VERDICT is declared THREE times: {@see RouteVerdict}, the CHECK on
 * `document_route_events.verdict`, and the CHECK on
 * `document_route_edges.verdict`. The third matters as much as the other two: an
 * edge whose verdict the engine never produces is an edge that is drawn in the
 * editor, stored, and silently never traversed.
 *
 * QUORUM is declared three times too: {@see RouteQuorum}, the CHECK on
 * `document_route_steps.decision_quorum`, and the validator for the
 * `documents.routing_approval_quorum` setting — where a value the setting admits
 * and the engine does not would silently fall back to `all` for the whole
 * tenant, which is a change of approval policy nobody chose.
 *
 * The migration source is read as TEXT rather than introspected from a live
 * database, exactly as the action pin is: this has to fail on the SQLite unit run
 * too, and SQLite exposes a CHECK constraint nowhere it can be queried back.
 */
final class RouteVerdictVocabularyTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../../database/migrations/119_add_route_verdicts_and_branching.php';

    /** @return list<string> */
    private static function verbsIn(string $source, string $pattern): array
    {
        $matched = preg_match($pattern, $source, $m);
        self::assertSame(1, $matched, "migration 119 must still carry the constraint matched by {$pattern}");

        // `?? ''` rather than assertArrayHasKey: PHPStan reasons about
        // preg_match's return shape, not about a runtime assertion, so the
        // coalesce is what narrows it. The emptiness check is the real guard — a
        // match with no capture would otherwise compare [] against [] and pass
        // while checking nothing.
        $list = $m[1] ?? '';
        self::assertNotSame('', $list, 'the constraint must expose its value list');

        preg_match_all("/'([a-z_]+)'/", $list, $values);
        $out = $values[1];
        sort($out);

        return $out;
    }

    private static function source(): string
    {
        $source = file_get_contents(self::MIGRATION);
        self::assertIsString($source, 'migration 119 must be readable');

        return $source;
    }

    public function testTheEventVerdictCheckAndThePhpVocabularyAgree(): void
    {
        $inPhp = RouteVerdict::all();
        sort($inPhp);

        self::assertSame(
            self::verbsIn(
                self::source(),
                "/CHECK \(verdict IS NULL OR verdict IN \(([^)]*)\)\)/"
            ),
            $inPhp,
            'RouteVerdict::all() and the CHECK on document_route_events.verdict have drifted. One of them '
            . 'now admits a value the other refuses, and the trail is append-only, so the row that '
            . 'results cannot be cleaned up.'
        );
    }

    public function testTheEdgeVerdictCheckAndThePhpVocabularyAgree(): void
    {
        $inPhp = RouteVerdict::all();
        sort($inPhp);

        self::assertSame(
            self::verbsIn(self::source(), "/CHECK \(verdict IN \(([^)]*)\)\)/"),
            $inPhp,
            'RouteVerdict::all() and the CHECK on document_route_edges.verdict have drifted. An edge whose '
            . 'verdict the engine never produces is one an author draws, the database stores, and nothing '
            . 'ever traverses.'
        );
    }

    public function testTheQuorumCheckAndThePhpVocabularyAgree(): void
    {
        $inPhp = RouteQuorum::all();
        sort($inPhp);

        self::assertSame(
            self::verbsIn(
                self::source(),
                "/CHECK \(decision_quorum IS NULL OR decision_quorum IN \(([^)]*)\)\)/"
            ),
            $inPhp,
            'RouteQuorum::all() and the CHECK on document_route_steps.decision_quorum have drifted.'
        );
    }

    public function testTheSettingAcceptsExactlyTheQuorumVocabulary(): void
    {
        // Derived from the vocabulary, not from a copy of the validator's own
        // list: the point is that the SETTING and the ENGINE admit the same set,
        // and re-stating the set here would only prove the test agrees with
        // itself.
        foreach (RouteQuorum::all() as $quorum) {
            self::assertNull(
                SettingsRegistry::validate(SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM, $quorum),
                "the setting must accept the quorum '{$quorum}' that the engine implements"
            );
        }

        foreach (['', 'ALL', 'none', 'any ', 'majority-of-two', 'unanimous'] as $rejected) {
            self::assertNotNull(
                SettingsRegistry::validate(SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM, $rejected),
                "the setting must refuse '{$rejected}', which the engine would silently read as `all`"
            );
        }
    }

    public function testTheDefaultQuorumIsTheStrictestOne(): void
    {
        // Not a style preference. The two ways of being wrong are asymmetric:
        // too few approvals is a silent authority failure found in an audit years
        // later, too many is a document that visibly stops and a complaint the
        // same afternoon. Changing this default is a product decision, so it gets
        // a test that has to be edited deliberately.
        self::assertSame(
            RouteQuorum::ALL,
            SettingsRegistry::defaults()[SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM] ?? null,
            'the default approval quorum must be `all` — see RouteQuorum for the argument'
        );
    }

    public function testEveryConstantIsInTheAllList(): void
    {
        foreach ([RouteVerdict::class, RouteQuorum::class] as $class) {
            $declared = array_values(array_filter(
                (new \ReflectionClass($class))->getConstants(),
                static fn (mixed $v): bool => is_string($v)
            ));
            sort($declared);

            /** @var callable(): list<string> $all */
            $all = [$class, 'all'];
            $values = $all();
            sort($values);

            self::assertSame($declared, $values, "every string constant on {$class} must appear in all()");
        }
    }

    public function testAVerdictIsCarriedByAnAcknowledgementAndNothingElse(): void
    {
        // Which act carries a verdict is a contract every client codes against,
        // so it is pinned rather than left to a docblock. `forwarded` is the one
        // that must NOT: it means the actor chose the destination, which is
        // exactly what a decision step takes away from them.
        self::assertSame(RouteAction::ACKNOWLEDGED, RouteVerdict::carriedBy());
        self::assertNotSame(RouteAction::FORWARDED, RouteVerdict::carriedBy());
        self::assertContains(RouteVerdict::carriedBy(), RouteAction::recipientActions());
    }
}
