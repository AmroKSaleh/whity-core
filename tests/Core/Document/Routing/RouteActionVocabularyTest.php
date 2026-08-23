<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Routing\RouteAction;

/**
 * The trail's action vocabulary is CLOSED, and closed in two places that must
 * agree: {@see RouteAction} in PHP, and the CHECK constraint migration 112 puts
 * on `document_route_events.action`.
 *
 * WHY THIS TEST EXISTS RATHER THAN A COMMENT SAYING "KEEP THESE IN SYNC"
 * ---------------------------------------------------------------------
 * The two can drift in both directions, and each direction fails at a different
 * time and reads differently:
 *
 *  - a verb added to PHP but not the CHECK is a `RouteAction::` constant that
 *    every writer can reference and the DATABASE refuses at insert, so it fails
 *    at run time, in a transaction, as a driver error;
 *  - a verb added to the CHECK but not PHP is a value the schema admits and no
 *    reader in the engine can render, and since the trail is append-only such a
 *    row cannot be corrected afterwards.
 *
 * Both are cheap to prevent and expensive to discover, which is exactly the
 * shape that earns a pin. The migration source is read as TEXT rather than
 * introspected from a live database on purpose: this has to fail on the SQLite
 * unit run too, and SQLite does not expose a CHECK constraint anywhere it can be
 * queried back.
 */
final class RouteActionVocabularyTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../../database/migrations/112_create_document_routing.php';

    public function testThePhpVocabularyAndTheSchemaCheckConstraintAgree(): void
    {
        $source = file_get_contents(self::MIGRATION);
        self::assertIsString($source, 'migration 112 must be readable');

        $matched = preg_match(
            "/CHECK \(action IN \(([^)]*)\)\)/",
            $source,
            $m
        );
        self::assertSame(
            1,
            $matched,
            'migration 112 must still CHECK-constrain `action`. A trail whose action column accepts '
            . 'any string is a trail whose readers must handle any string, and the first typo becomes '
            . 'a permanent row nothing renders.'
        );

        // assertSame above guarantees the match succeeded, but PHPStan reasons
        // about preg_match's shape rather than about the assertion, so the group
        // is narrowed explicitly. Asserting it is also the honest thing: a regex
        // that matched with no capture would otherwise compare an empty list
        // against an empty list and pass while checking nothing.
        // `?? ''` rather than an assertArrayHasKey: PHPStan reasons about
        // preg_match's return shape, not about a runtime assertion, so the
        // coalesce is what actually narrows it. The emptiness check below is the
        // real guard — a regex that matched with no capture would otherwise
        // compare an empty list against an empty list and pass while checking
        // nothing.
        $verbList = $m[1] ?? '';
        self::assertNotSame('', $verbList, 'the CHECK constraint must expose its verb list');

        preg_match_all("/'([a-z_]+)'/", $verbList, $verbs);
        $inSchema = $verbs[1];
        sort($inSchema);

        $inPhp = RouteAction::all();
        sort($inPhp);

        self::assertSame(
            $inSchema,
            $inPhp,
            'RouteAction::all() and migration 112\'s CHECK constraint have drifted. A verb in one and '
            . 'not the other is either a constant the database refuses at insert, or a value the '
            . 'schema admits that no reader can render — and the trail is append-only, so the second '
            . 'cannot be cleaned up afterwards.'
        );
    }

    public function testEveryConstantIsInTheAllList(): void
    {
        // all() is what the pin above compares, so a constant missing from it
        // would be invisible to that comparison — the pin has to be complete to
        // mean anything.
        $reflection = new \ReflectionClass(RouteAction::class);
        $declared = array_values(array_filter(
            $reflection->getConstants(),
            static fn (mixed $v): bool => is_string($v)
        ));
        sort($declared);

        $all = RouteAction::all();
        sort($all);

        self::assertSame($declared, $all, 'every string constant on RouteAction must appear in all()');
    }

    public function testIssuedIsNotSomethingARecipientCanDo(): void
    {
        // A route is issued by CREATING it. Accepting `issued` as a recipient act
        // would let somebody mint a second beginning for a circulation already
        // under way — a trail with two starts, which no reader can order.
        self::assertNotContains(RouteAction::ISSUED, RouteAction::recipientActions());
        self::assertSame(
            [RouteAction::FORWARDED, RouteAction::ACKNOWLEDGED, RouteAction::RETURNED],
            RouteAction::recipientActions()
        );
    }

    public function testOnlyTheThreeAnsweringActsCloseAnInboxRow(): void
    {
        foreach (RouteAction::recipientActions() as $action) {
            self::assertTrue(RouteAction::closesRecipient($action), "{$action} must close the actor's row");
        }

        // A note records something; it does not answer the item. That is what
        // lets anyone who can see the document add one — including somebody who
        // already acted and has nothing left to close.
        self::assertFalse(RouteAction::closesRecipient(RouteAction::NOTED));
        self::assertFalse(RouteAction::closesRecipient(RouteAction::ISSUED));
    }
}
