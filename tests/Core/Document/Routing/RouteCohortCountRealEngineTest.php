<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RoutingPresenter;

/**
 * How many times a step SETTLED, read back from the trail (#1140).
 *
 * #1058 framed double settlement as a property of actor-relative rules. It is
 * really a property of TIMING: de-duplication at a step is over OPEN rows only
 * — migration 112's unique index is partial — so a second arrival is absorbed
 * into the first cohort while that cohort is still open, and opens a NEW one
 * once it has closed. Any rule kind can do it; a merge stage reached by two
 * transitions does it whenever one path travels the long way round through a
 * rework loop.
 *
 * Nothing could report that. The canvas cannot: whether the second arrival is
 * late depends on who acts and how long the loop takes, not on the graph an
 * author drew. So it can only be read back afterwards, and until now nothing
 * read it.
 *
 * WHAT THESE TESTS COVER, PRECISELY. The derivation — that a second cohort at a
 * step is counted as a second settlement, that one cohort reads as one, and
 * that the count reaches the wire. They do NOT drive a full converging graph
 * through the router to produce the second cohort; that scenario deserves its
 * own test against {@see \Whity\Core\Document\Routing\DocumentRouter} and is
 * called out in #1140 rather than quietly folded in here. What is asserted
 * below is the measure, on rows shaped exactly as the router writes them.
 */
final class RouteCohortCountRealEngineTest extends TestCase
{
    private const TENANT = 6;
    private const DOCUMENT = 88;
    private const ROUTE = 12;
    private const MERGE_STEP = 300;
    private const ORDINARY_STEP = 301;

    private PDO $pdo;
    private RouteRecipientRepository $recipients;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seed();
        $this->recipients = new RouteRecipientRepository($this->pdo);
    }

    public function testAStepReachedOnceReadsAsOneSettlement(): void
    {
        $this->arrival(self::ORDINARY_STEP, event: 9001, profile: 701);

        $counts = $this->recipients->cohortCountsByStep(self::ROUTE, self::TENANT);

        self::assertSame(1, $counts[self::ORDINARY_STEP]);
    }

    public function testTwoPeopleReachedByONEActAreStillOneSettlement(): void
    {
        // The distinction the measure turns on. A step that fans out to five
        // people has FIVE recipient rows and ONE cohort — counting rows would
        // report every wide step as having settled five times, which is both
        // wrong and the kind of wrong that trains a reader to ignore the field.
        $this->arrival(self::ORDINARY_STEP, event: 9001, profile: 701);
        $this->arrival(self::ORDINARY_STEP, event: 9001, profile: 702);
        $this->arrival(self::ORDINARY_STEP, event: 9001, profile: 703);

        $counts = $this->recipients->cohortCountsByStep(self::ROUTE, self::TENANT);

        self::assertSame(1, $counts[self::ORDINARY_STEP]);
    }

    public function testASecondArrivalAfterTheFirstCohortClosedIsASecondSettlement(): void
    {
        // THE CASE #1140 IS ABOUT. The first cohort is opened by event 9001 and
        // CLOSED by 9002 — at which point the partial unique index no longer
        // de-duplicates, so a later arrival for the same person at the same step
        // opens a cohort of its own. Downstream then runs a second time,
        // including anything that emails people or ends a chain.
        $this->arrival(self::MERGE_STEP, event: 9001, profile: 701, closedBy: 9002);
        $this->arrival(self::MERGE_STEP, event: 9003, profile: 701);

        $counts = $this->recipients->cohortCountsByStep(self::ROUTE, self::TENANT);

        self::assertSame(2, $counts[self::MERGE_STEP], 'the step settled twice');
    }

    public function testStepsAreCountedApart(): void
    {
        $this->arrival(self::MERGE_STEP, event: 9001, profile: 701, closedBy: 9002);
        $this->arrival(self::MERGE_STEP, event: 9003, profile: 701);
        $this->arrival(self::ORDINARY_STEP, event: 9003, profile: 702);

        $counts = $this->recipients->cohortCountsByStep(self::ROUTE, self::TENANT);

        self::assertSame(2, $counts[self::MERGE_STEP]);
        self::assertSame(1, $counts[self::ORDINARY_STEP]);
    }

    public function testAStepNothingHasReachedIsAbsentRatherThanZero(): void
    {
        // Absent from the repository's map, and rendered as 0 by the presenter.
        // The repository reports what the trail HOLDS; inventing a zero row for
        // every authored step would make it answer a question about the ROUTE
        // that it cannot see — a step could have been added after the last act.
        $this->arrival(self::ORDINARY_STEP, event: 9001, profile: 701);

        $counts = $this->recipients->cohortCountsByStep(self::ROUTE, self::TENANT);

        self::assertArrayNotHasKey(self::MERGE_STEP, $counts);
    }

    public function testTheCountIsScopedToItsOwnRouteAndTenant(): void
    {
        // A document can carry several circulations, and two tenants can hold
        // routes with the same step ids in a shared table. A count that leaked
        // across either would report a step as having settled more often than it
        // did — which reads as a bug in routing rather than a bug in the count.
        $this->arrival(self::MERGE_STEP, event: 9001, profile: 701);
        // The opening event has to exist before the row that names it —
        // `created_by_event_id` is NOT NULL and references the trail, which
        // PostgreSQL enforces and SQLite does not. Seeded explicitly here
        // because this row is written raw rather than through arrival().
        $this->event(9004);
        $this->pdo->exec(
            'INSERT INTO document_route_recipients (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id, created_at)'
            . ' VALUES (' . self::TENANT . ', ' . self::DOCUMENT . ', 13, ' . self::MERGE_STEP . ', 701, 9004, NOW())'
        );

        $counts = $this->recipients->cohortCountsByStep(self::ROUTE, self::TENANT);

        self::assertSame(1, $counts[self::MERGE_STEP], 'the other route must not be counted');
        self::assertSame([], $this->recipients->cohortCountsByStep(self::ROUTE, 999));
    }

    public function testThePresenterPublishesTheCountAndDefaultsItToZero(): void
    {
        $step = [
            'id' => self::MERGE_STEP,
            'position' => 2,
            'rule_kind' => 'role',
            'rule_config' => [],
            'label' => 'Registry',
            'decision' => false,
            'decision_quorum' => null,
        ];

        self::assertSame(2, RoutingPresenter::step($step, 0, 2)['cohort_count']);
        // Published even when nothing has arrived, so a client can tell "not yet
        // reached" from "this server is too old to say" — the same reason
        // `rejection_count` is always published.
        self::assertSame(0, RoutingPresenter::step($step)['cohort_count']);
    }

    private function arrival(int $stepId, int $event, int $profile, ?int $closedBy = null): void
    {
        $this->event($event);
        if ($closedBy !== null) {
            $this->event($closedBy);
        }

        $this->pdo->exec(
            'INSERT INTO document_route_recipients (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id, closed_by_event_id, created_at)'
            . ' VALUES (' . self::TENANT . ', ' . self::DOCUMENT . ', ' . self::ROUTE . ', ' . $stepId . ', ' . $profile
            . ', ' . $event . ', ' . ($closedBy === null ? 'NULL' : (string) $closedBy) . ', NOW())'
        );
    }

    private function event(int $id): void
    {
        $this->pdo->exec(
            'INSERT INTO document_route_events (id, tenant_id, document_id, route_id, step_id, action, occurred_at) VALUES ('
            . $id . ', ' . self::TENANT . ', ' . self::DOCUMENT . ', ' . self::ROUTE . ', ' . self::MERGE_STEP
            . ", 'forwarded', NOW()) ON CONFLICT DO NOTHING"
        );
    }

    /**
     * The rows the foreign keys require — every one of them, because PostgreSQL
     * enforces these and SQLite does not, and a fixture that passes only on the
     * engine that does not check is a fixture that proves nothing.
     */
    private function seed(): void
    {
        $this->pdo->exec('INSERT INTO tenants (id, name, slug) VALUES (' . self::TENANT . ", 'cohorts', 'cohorts') ON CONFLICT DO NOTHING");

        foreach ([701, 702, 703] as $profileId) {
            $this->pdo->exec(
                "INSERT INTO profiles (id, display_name, password_hash) VALUES ({$profileId}, 'Profile {$profileId}', 'x') ON CONFLICT DO NOTHING"
            );
        }

        $this->pdo->exec(
            'INSERT INTO documents (id, tenant_id, template_name, title, created_at) VALUES ('
            . self::DOCUMENT . ', ' . self::TENANT . ", '', 'Merged document', NOW()) ON CONFLICT DO NOTHING"
        );

        foreach ([self::ROUTE, 13] as $routeId) {
            $this->pdo->exec(
                'INSERT INTO document_routes (id, tenant_id, document_id, title, created_at) VALUES ('
                . $routeId . ', ' . self::TENANT . ', ' . self::DOCUMENT . ", 'Route {$routeId}', NOW()) ON CONFLICT DO NOTHING"
            );
        }

        foreach ([self::MERGE_STEP => 2, self::ORDINARY_STEP => 3] as $stepId => $position) {
            $this->pdo->exec(
                'INSERT INTO document_route_steps (id, tenant_id, route_id, position, rule_kind, created_at) VALUES ('
                . $stepId . ', ' . self::TENANT . ', ' . self::ROUTE . ', ' . $position . ", 'role', NOW()) ON CONFLICT DO NOTHING"
            );
        }
    }
}
