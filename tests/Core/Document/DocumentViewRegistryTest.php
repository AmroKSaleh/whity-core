<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Organizer\CoreDocumentSubstrates;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\Document\Organizer\DocumentSubstrate;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentView;
use Whity\Core\Document\Organizer\DocumentViewContext;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Organizer\DocumentViewResolution;
use Whity\Core\Document\Organizer\PdoSchemaPresence;
use Whity\Core\Document\Organizer\SchemaPresence;
use Whity\Core\Ou\OuSubtree;

/**
 * The capability seam (#978) — the one thing in the document organizer worth
 * failing a build over.
 *
 * #947 item 5 specifies six folders, three of which read #947 item 3's routing
 * facts. The failure this suite exists to prevent is those three rendering as
 * EMPTY rather than being ABSENT on an installation that does not record those
 * facts: an empty "Awaiting me" states *"nothing awaits you"*, a claim that is
 * false and that the person it misleads cannot check from outside. A document
 * somebody was supposed to act on failing to appear is indistinguishable from
 * having nothing to do.
 *
 * WHAT CHANGED WHEN THE ROUTING FOLDERS WERE BUILT, AND WHAT DID NOT
 * ------------------------------------------------------------------
 * #978 shipped this suite with an assertion that no registered view named either
 * routing substrate WHILE BOTH RESOLVED — the state where the shortcut was
 * available and refused. That assertion has been inverted rather than deleted,
 * because the property it protected did not go away: what it now asserts is that
 * the three folders exist, name exactly the substrates they read, and are
 * therefore still absent wherever those substrates are not satisfied. Deleting
 * it would have left the shortcut it caught unguarded in the other direction — a
 * folder whose declaration drifts off the fact it reads is a folder that
 * survives the migration being missing.
 *
 * The FICTIONAL-substrate tests are untouched on purpose. They are what proves
 * the mechanism without requiring core to ship the stub the design refuses, and
 * they keep proving it now that every core substrate resolves on a migrated
 * schema and could no longer demonstrate absence by itself.
 *
 * So the assertions here are, in order of how much they matter:
 *
 *  1. A view whose substrate is absent is not listed AND cannot be requested.
 *  2. The three routing folders name the routing substrates and nothing else,
 *     so removing migration 112's tables removes exactly them.
 *  3. The gating is per SUBSTRATE, not global: removing collections must take
 *     the two collection folders and leave the unit ones alone; removing the
 *     trail must leave the inbox.
 *  4. The three-way distinction survives — absent (not listed), unanchored
 *     (listed, disabled, with a reason), and genuinely empty.
 *  5. A view that arrives LATER slots in without this registry changing, which
 *     is asserted by registering one from the test rather than by inspection.
 */
final class DocumentViewRegistryTest extends TestCase
{
    private const TENANT = 1;
    private const CALLER = 10;

    // ── 1. absent substrate → absent view ───────────────────────────────────

    /**
     * The mechanism itself, exercised with a FICTIONAL view so that proving it
     * works does not require core to ship the stub this whole design refuses.
     */
    public function testAViewWhoseSubstrateIsAbsentIsNeitherListedNorRequestable(): void
    {
        $substrates = new DocumentSubstrateRegistry($this->schema(['documents' => ['id', 'created_by']]));
        $substrates->register(new DocumentSubstrate(
            'acme.escalations',
            'Escalation rows a plugin records against a document.',
            ['acme_escalations'],
            'a plugin that is not installed here',
        ));

        $views = new DocumentViewRegistry($substrates);
        $views->register($this->view('escalated-to-me', ['acme.escalations']));
        $views->register($this->view('created-by-me', []));

        self::assertSame(
            ['created-by-me'],
            array_map(static fn (DocumentView $v): string => $v->key, $views->available()),
            'a folder whose fact source is absent must not be listed'
        );
        self::assertNull(
            $views->get('escalated-to-me'),
            'and must not be openable either — a listed-but-closed folder still asserts it exists'
        );
        self::assertNotNull($views->get('created-by-me'));
    }

    /** An unregistered substrate fails CLOSED, rather than being treated as "no requirement". */
    public function testAViewNamingASubstrateNobodyRegisteredIsUnavailable(): void
    {
        $substrates = new DocumentSubstrateRegistry($this->schema(['documents' => ['id']]));
        $views = new DocumentViewRegistry($substrates);
        $views->register($this->view('speculative', ['nobody.registered.this']));

        self::assertSame([], $views->available());
        self::assertNull($views->get('speculative'));
    }

    /**
     * A substrate is satisfied by the SCHEMA, not by whoever declared it. This
     * is the rolling-deploy case: the code that registers the substrate is live
     * and its migration has not run.
     */
    public function testADeclaredSubstrateIsStillAbsentUntilItsColumnExists(): void
    {
        $withoutColumn = new DocumentSubstrateRegistry($this->schema(['documents' => ['id', 'title']]));
        CoreDocumentSubstrates::registerInto($withoutColumn);
        self::assertFalse(
            $withoutColumn->isAvailable(CoreDocumentSubstrates::AUTHORSHIP),
            'declaring a substrate must not be enough to make it available'
        );

        $withColumn = new DocumentSubstrateRegistry($this->schema(['documents' => ['id', 'title', 'created_by']]));
        CoreDocumentSubstrates::registerInto($withColumn);
        self::assertTrue($withColumn->isAvailable(CoreDocumentSubstrates::AUTHORSHIP));
    }

    // ── 2. the three routing folders, and what they are gated on ────────────

    /**
     * The inverted seam assertion. #978 shipped this test asserting that NO
     * registered view named either routing substrate while both resolved — the
     * state in which the shortcut was available and refused. The folders are
     * built now, so the same test asserts the same property from the other side:
     * each of the three exists, and each names EXACTLY the substrates it reads.
     *
     * The declaration is the whole point, which is why it is asserted rather
     * than the folders' mere presence. A routing folder that named no substrate,
     * or named `documents.records`, would work perfectly on a migrated
     * installation and render an empty "Awaiting me" on one that never ran
     * migration 112 — the failure #978 exists to prevent, reintroduced by a
     * declaration instead of by a conditional.
     */
    public function testTheThreeRoutingFoldersExistAndNameTheFactsTheyRead(): void
    {
        $substrates = new DocumentSubstrateRegistry($this->schema($this->fullSchema()));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        // Precondition: the fact sources are genuinely there.
        self::assertTrue($substrates->isAvailable(CoreDocumentSubstrates::ROUTING_RECIPIENTS));
        self::assertTrue($substrates->isAvailable(CoreDocumentSubstrates::ROUTING_TRAIL));

        $expected = [
            // The inbox reads recipient rows and nothing else — notably NOT the
            // trail, even though the row points into it, because open-ness is a
            // column on the recipient row rather than a question for the trail.
            CoreDocumentViews::AWAITING_ME => [CoreDocumentSubstrates::ROUTING_RECIPIENTS],
            CoreDocumentViews::ACTED_ON_BY_ME => [CoreDocumentSubstrates::ROUTING_TRAIL],
            // #1014's two verdict folders declare ROUTING_VERDICT and NOT
            // ROUTING_TRAIL, even though they query the same table. A column is
            // the fact they need; naming the coarser substrate as well would
            // make them available on an installation that has the trail without
            // the verdict column, where they would render an empty page that
            // reads as "you have approved nothing".
            CoreDocumentViews::APPROVED_BY_ME => [CoreDocumentSubstrates::ROUTING_VERDICT],
            CoreDocumentViews::REJECTED_BY_ME => [CoreDocumentSubstrates::ROUTING_VERDICT],
            // Two, and neither is `documents.origin_ou`: the trail's own unit
            // columns, plus a hierarchy to walk them against.
            CoreDocumentViews::PASSED_THROUGH_MY_UNIT => [
                CoreDocumentSubstrates::ROUTING_TRAIL,
                CoreDocumentSubstrates::OU_TREE,
            ],
        ];

        foreach ($expected as $key => $requires) {
            $view = $views->get($key);
            self::assertNotNull($view, "'{$key}' must exist on a migrated installation");
            self::assertSame(
                $requires,
                $view->requires,
                "'{$key}' must declare exactly the fact sources it reads — no more, so it is not "
                    . 'hidden for an unrelated reason, and no fewer, so it cannot render on an '
                    . 'installation that does not record them'
            );
        }

        // And the folders that do NOT read routing must not have acquired a
        // dependency on it, which would hide them on an installation with
        // documents and no routing.
        foreach ([CoreDocumentViews::ALL, CoreDocumentViews::CREATED_BY_ME, CoreDocumentViews::STARRED] as $key) {
            $view = $views->get($key);
            self::assertNotNull($view);
            self::assertNotContains(CoreDocumentSubstrates::ROUTING_RECIPIENTS, $view->requires);
            self::assertNotContains(CoreDocumentSubstrates::ROUTING_TRAIL, $view->requires);
        }
    }

    /**
     * The inverse, and the property the whole registry exists for: take
     * migration 112's tables away and the three folders are gone — not listed,
     * not requestable — while the six that read `documents` are untouched.
     *
     * Asserted rather than inspected, because "these views declare a substrate"
     * and "these views disappear when it does" are different claims and only the
     * second one is what a person on an un-migrated installation experiences.
     */
    public function testRemovingTheRoutingTablesTakesExactlyTheRoutingFolders(): void
    {
        $schema = $this->fullSchema();
        unset($schema['document_route_recipients'], $schema['document_route_events']);

        $substrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        self::assertSame(
            [
                CoreDocumentViews::ALL,
                CoreDocumentViews::CREATED_BY_ME,
                CoreDocumentViews::RAISED_BY_MY_UNIT,
                CoreDocumentViews::BELOW_MY_UNIT,
                CoreDocumentViews::STARRED,
                CoreDocumentViews::COLLECTION,
            ],
            array_map(static fn (DocumentView $v): string => $v->key, $views->available()),
            'without routing the rail is exactly what #978 shipped'
        );

        foreach (
            [
                CoreDocumentViews::AWAITING_ME,
                CoreDocumentViews::ACTED_ON_BY_ME,
                CoreDocumentViews::APPROVED_BY_ME,
                CoreDocumentViews::REJECTED_BY_ME,
                CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
            ] as $key
        ) {
            self::assertNull(
                $views->get($key),
                "'{$key}' must not be openable where routing is not recorded — a listed-but-closed "
                    . 'folder still asserts it exists, and an open one would answer with an empty page'
            );
        }

        // The absence is EXPLAINED rather than silent (#951): both routing fact
        // sources are reported missing, each pointing at the work that supplies
        // them, so an operator asking "why is there no inbox here" has an answer.
        $missing = array_map(
            static fn (DocumentSubstrate $s): string => $s->key,
            $substrates->unavailable()
        );
        self::assertEqualsCanonicalizing(
            [
                CoreDocumentSubstrates::ROUTING_RECIPIENTS,
                CoreDocumentSubstrates::ROUTING_TRAIL,
                CoreDocumentSubstrates::ROUTING_VERDICT,
            ],
            $missing
        );
        // Each names the work that supplies it, and they do not all name the
        // same one: the verdict arrived in 118, and an operator told to run 112
        // when what they are missing is 118 has been sent to the wrong place.
        $provenance = array_map(
            static fn (DocumentSubstrate $s): string => (string) $s->provenance,
            $substrates->unavailable()
        );
        foreach ($provenance as $text) {
            self::assertMatchesRegularExpression('/migration 11[29]/', $text);
        }
    }

    /**
     * The gating is per FACT SOURCE, not per feature. Recipient rows and the
     * trail are separate substrates precisely so that losing one does not take
     * the other's folder, and this is the assertion that makes that split earn
     * its keep rather than being a tidy-looking pair of constants.
     */
    public function testRemovingOnlyTheTrailLeavesTheInboxStanding(): void
    {
        $schema = $this->fullSchema();
        unset($schema['document_route_events']);

        $substrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $keys = array_map(static fn (DocumentView $v): string => $v->key, $views->available());

        self::assertContains(CoreDocumentViews::AWAITING_ME, $keys, 'the inbox reads recipients, not the trail');
        self::assertNotContains(CoreDocumentViews::ACTED_ON_BY_ME, $keys);
        self::assertNotContains(CoreDocumentViews::PASSED_THROUGH_MY_UNIT, $keys);
        // The verdict folders read a COLUMN on the trail, so losing the table
        // takes them as well — but by their own declaration, not by borrowing
        // the trail substrate's.
        self::assertNotContains(CoreDocumentViews::APPROVED_BY_ME, $keys);
        self::assertNotContains(CoreDocumentViews::REJECTED_BY_ME, $keys);
    }

    /**
     * An installation on migration 112 but not 118 keeps its three routing
     * folders and loses exactly the two verdict ones.
     *
     * This is what the separate substrate BUYS, and it is asserted rather than
     * described because the tempting declaration — adding `verdict` to the trail
     * substrate — passes every other test in this file while making "acted on by
     * me" and "passed through my unit" vanish on a half-migrated installation,
     * for a reason that has nothing to do with either of them.
     */
    public function testTheVerdictColumnGoingMissingTakesOnlyTheVerdictFolders(): void
    {
        $schema = $this->fullSchema();
        $schema['document_route_events'] = array_values(array_filter(
            $schema['document_route_events'],
            static fn (string $c): bool => $c !== 'verdict'
        ));

        $substrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $keys = array_map(static fn (DocumentView $v): string => $v->key, $views->available());

        self::assertContains(CoreDocumentViews::ACTED_ON_BY_ME, $keys);
        self::assertContains(CoreDocumentViews::PASSED_THROUGH_MY_UNIT, $keys);
        self::assertContains(CoreDocumentViews::AWAITING_ME, $keys);
        self::assertNotContains(CoreDocumentViews::APPROVED_BY_ME, $keys);
        self::assertNotContains(CoreDocumentViews::REJECTED_BY_ME, $keys);
    }

    /**
     * And the other half of the trail's declaration: it names BOTH unit columns,
     * because "passed through my unit" asks about either end of a transition. A
     * trail recording only where things came from would answer half the question
     * while looking like it answered all of it.
     */
    public function testTheTrailSubstrateNeedsBothEndsOfATransition(): void
    {
        foreach (['from_ou_id', 'to_ou_id'] as $column) {
            $schema = $this->fullSchema();
            $schema['document_route_events'] = array_values(array_filter(
                $schema['document_route_events'],
                static fn (string $c): bool => $c !== $column
            ));

            $substrates = new DocumentSubstrateRegistry($this->schema($schema));
            CoreDocumentSubstrates::registerInto($substrates);

            self::assertFalse(
                $substrates->isAvailable(CoreDocumentSubstrates::ROUTING_TRAIL),
                "a trail with no {$column} does not record the transition the folder queries"
            );
        }
    }

    /**
     * The routing substrates are declared against the REAL table and column
     * names, so a fully migrated schema reports nothing missing.
     *
     * Worth its own test because the first draft of this branch required
     * `routes`, `route_steps` and `recipients` — #947's prose names — while the
     * shipped tables are `document_route_*`. That declaration reported the
     * substrate absent for ever, which is the answer that HIDES folders, and it
     * read as correct and cautious.
     */
    public function testTheRoutingSubstratesNameTheTablesThatActuallyShipped(): void
    {
        $substrates = new DocumentSubstrateRegistry($this->schema($this->fullSchema()));
        CoreDocumentSubstrates::registerInto($substrates);

        self::assertSame(
            [],
            $substrates->unavailable(),
            'a fully migrated schema must satisfy every substrate core declares'
        );

        // And they really do depend on routing: drop the trail and only the
        // trail substrate goes.
        $withoutTrail = $this->fullSchema();
        unset($withoutTrail['document_route_events']);
        $partial = new DocumentSubstrateRegistry($this->schema($withoutTrail));
        CoreDocumentSubstrates::registerInto($partial);

        self::assertFalse($partial->isAvailable(CoreDocumentSubstrates::ROUTING_TRAIL));
        self::assertFalse(
            $partial->isAvailable(CoreDocumentSubstrates::ROUTING_VERDICT),
            'the verdict lives on the trail table, so it cannot outlive it'
        );
        self::assertTrue($partial->isAvailable(CoreDocumentSubstrates::ROUTING_RECIPIENTS));
        $missing = array_map(
            static fn (DocumentSubstrate $s): string => $s->key,
            $partial->unavailable()
        );
        self::assertEqualsCanonicalizing(
            [CoreDocumentSubstrates::ROUTING_TRAIL, CoreDocumentSubstrates::ROUTING_VERDICT],
            $missing
        );
    }

    // ── 3. gating is per substrate ──────────────────────────────────────────

    public function testRemovingTheCollectionsTablesTakesOnlyTheCollectionFolders(): void
    {
        $schema = $this->fullSchema();
        unset($schema['document_collections'], $schema['document_collection_items']);

        $substrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $keys = array_map(static fn (DocumentView $v): string => $v->key, $views->available());

        self::assertNotContains(CoreDocumentViews::STARRED, $keys);
        self::assertNotContains(CoreDocumentViews::COLLECTION, $keys);
        self::assertContains(CoreDocumentViews::ALL, $keys);
        self::assertContains(CoreDocumentViews::CREATED_BY_ME, $keys);
        self::assertContains(CoreDocumentViews::RAISED_BY_MY_UNIT, $keys);
        self::assertContains(CoreDocumentViews::BELOW_MY_UNIT, $keys);
    }

    /**
     * Every folder that NAMES a unit goes, and only those. That is three
     * declarations reaching the same conclusion by two different routes — the
     * two `documents.origin_ou` folders and the routing folder that declares
     * `ou.tree` — which is the point of declaring the tree separately: the
     * routing folder does not read `documents.origin_ou_id`, and it still cannot
     * walk a hierarchy that is not there.
     */
    public function testRemovingTheOuTableTakesEveryFolderThatNamesAUnit(): void
    {
        $schema = $this->fullSchema();
        unset($schema['organizational_units']);

        $substrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        $keys = array_map(static fn (DocumentView $v): string => $v->key, $views->available());

        self::assertNotContains(CoreDocumentViews::RAISED_BY_MY_UNIT, $keys);
        self::assertNotContains(CoreDocumentViews::BELOW_MY_UNIT, $keys);
        self::assertNotContains(CoreDocumentViews::PASSED_THROUGH_MY_UNIT, $keys);
        self::assertContains(CoreDocumentViews::CREATED_BY_ME, $keys);
        self::assertContains(CoreDocumentViews::STARRED, $keys);
        // The routing folders that do NOT walk the tree survive, which is what
        // says the OU dependency was declared where it is actually needed rather
        // than bolted onto `routing.trail` for the convenience of one folder.
        self::assertContains(CoreDocumentViews::AWAITING_ME, $keys);
        self::assertContains(CoreDocumentViews::ACTED_ON_BY_ME, $keys);
    }

    /** On a fully migrated schema, every core folder is computable, in rail order. */
    public function testAFullyMigratedSchemaOffersEveryCoreFolderInRailOrder(): void
    {
        $views = $this->coreRegistry();

        self::assertSame(
            [
                CoreDocumentViews::ALL,
                CoreDocumentViews::CREATED_BY_ME,
                CoreDocumentViews::RAISED_BY_MY_UNIT,
                CoreDocumentViews::BELOW_MY_UNIT,
                CoreDocumentViews::AWAITING_ME,
                CoreDocumentViews::ACTED_ON_BY_ME,
                CoreDocumentViews::APPROVED_BY_ME,
                CoreDocumentViews::REJECTED_BY_ME,
                CoreDocumentViews::PASSED_THROUGH_MY_UNIT,
                CoreDocumentViews::STARRED,
                CoreDocumentViews::COLLECTION,
            ],
            array_map(static fn (DocumentView $v): string => $v->key, $views->available())
        );
    }

    // ── 4. absent vs unanchored vs empty ────────────────────────────────────

    /**
     * The #951 case. "Raised by my unit" is perfectly computable; THIS caller
     * belongs to no unit. It must resolve to an explained refusal, not to an
     * empty result — which would read as "my unit has raised nothing", a
     * statement about the unit rather than about the reader not having one.
     */
    public function testAUnitFolderIsUnanchoredRatherThanEmptyForACallerWithNoUnit(): void
    {
        $view = $this->coreRegistry()->get(CoreDocumentViews::RAISED_BY_MY_UNIT);
        self::assertNotNull($view);

        $resolution = $view->resolve($this->context(primaryOuId: null));

        self::assertFalse($resolution->isAvailable());
        self::assertNull($resolution->criteria);
        self::assertNotNull($resolution->unavailableReason);
        self::assertStringContainsString('unit', (string) $resolution->unavailableReason);
    }

    /**
     * A starred pile that does not exist yet resolves to an honestly EMPTY
     * result rather than to a refusal — the difference being that this claim is
     * checkable by the person reading it, who knows whether they have starred
     * anything and has the control on every row beside them.
     */
    public function testStarredResolvesToAnEmptyResultRatherThanARefusalBeforeAnythingIsStarred(): void
    {
        $view = $this->coreRegistry()->get(CoreDocumentViews::STARRED);
        self::assertNotNull($view);

        $unstarred = $view->resolve($this->context(starredCollectionId: null));
        self::assertTrue($unstarred->isAvailable(), 'never having starred anything is not an unavailable folder');
        self::assertNotNull($unstarred->criteria);
        self::assertTrue($unstarred->criteria->matchesNothing);
        self::assertNull($unstarred->criteria->inCollectionId);

        $starred = $view->resolve($this->context(starredCollectionId: 77));
        self::assertNotNull($starred->criteria);
        self::assertFalse($starred->criteria->matchesNothing);
        self::assertSame(77, $starred->criteria->inCollectionId);
    }

    public function testCreatedByMeResolvesToTheCallersOwnAuthorship(): void
    {
        $view = $this->coreRegistry()->get(CoreDocumentViews::CREATED_BY_ME);
        self::assertNotNull($view);

        $criteria = $view->resolve($this->context())->criteria;
        self::assertNotNull($criteria);
        self::assertSame(self::CALLER, $criteria->createdBy);
    }

    /**
     * "Raised by my unit" is the unit alone; "everything below" is the unit and
     * its descendants. Two folders rather than one, because they answer
     * different questions about the same anchor.
     */
    public function testTheTwoUnitFoldersDifferByExactlyTheSubtree(): void
    {
        $views = $this->coreRegistry();
        $context = $this->context(primaryOuId: 2);

        $atUnit = $views->get(CoreDocumentViews::RAISED_BY_MY_UNIT)?->resolve($context)->criteria;
        self::assertNotNull($atUnit);
        self::assertSame([2], $atUnit->originOuIds);

        $below = $views->get(CoreDocumentViews::BELOW_MY_UNIT)?->resolve($context)->criteria;
        self::assertNotNull($below);
        // Tree seeded in ouPdo(): 1 → 2 → {3, 4}, 4 → 5. The anchor is INCLUDED.
        self::assertNotNull($below->originOuIds);
        self::assertEqualsCanonicalizing([2, 3, 4, 5], $below->originOuIds);
    }

    /** An explicit anchor overrides the caller's own unit on both unit folders. */
    public function testAnExplicitAnchorOverridesTheCallersOwnUnit(): void
    {
        $views = $this->coreRegistry();
        $context = $this->context(primaryOuId: 2, anchorOuId: 4);

        $atUnit = $views->get(CoreDocumentViews::RAISED_BY_MY_UNIT)?->resolve($context)->criteria;
        self::assertNotNull($atUnit);
        self::assertSame([4], $atUnit->originOuIds);

        $below = $views->get(CoreDocumentViews::BELOW_MY_UNIT)?->resolve($context)->criteria;
        self::assertNotNull($below);
        self::assertEqualsCanonicalizing([4, 5], $below->originOuIds);
    }

    /**
     * The two caller-anchored routing folders resolve on the CALLER and never
     * refuse: a person always exists, unlike a unit. Somebody who has never been
     * routed anything gets an honest empty page, which they can check.
     */
    public function testTheCallerAnchoredRoutingFoldersResolveOnTheCallerAndNeverRefuse(): void
    {
        $views = $this->coreRegistry();
        // No unit, no anchor, nothing starred: the least-equipped caller there is.
        $context = $this->context();

        $awaiting = $views->get(CoreDocumentViews::AWAITING_ME)?->resolve($context);
        self::assertNotNull($awaiting);
        self::assertTrue($awaiting->isAvailable(), 'having no unit does not stop you having an inbox');
        self::assertNotNull($awaiting->criteria);
        self::assertSame(self::CALLER, $awaiting->criteria->awaitingProfileId);
        self::assertFalse($awaiting->criteria->matchesNothing, 'an empty inbox is a result, not a refusal');

        $acted = $views->get(CoreDocumentViews::ACTED_ON_BY_ME)?->resolve($context);
        self::assertNotNull($acted);
        self::assertNotNull($acted->criteria);
        self::assertSame(self::CALLER, $acted->criteria->actedOnByProfileId);

        // The two are separate predicates, not one slot reused: a document can be
        // awaiting you AND already acted on by you, because a return puts back in
        // your inbox something you once forwarded.
        self::assertNull($awaiting->criteria->actedOnByProfileId);
        self::assertNull($acted->criteria->awaitingProfileId);
    }

    /**
     * "Passed through my unit" is the SUBTREE, reached through the same
     * tenant-bound closure "everything below my unit" uses — deliberately not a
     * second subtree walk, which is how "my unit" comes to mean two different
     * sets of people in two screens of one product.
     */
    public function testPassedThroughMyUnitWalksTheSubtreeAndRefusesWithoutAnAnchor(): void
    {
        $views = $this->coreRegistry();

        // Tree seeded in ouPdo(): 1 → 2 → {3, 4}, 4 → 5. The anchor is INCLUDED.
        $criteria = $views->get(CoreDocumentViews::PASSED_THROUGH_MY_UNIT)
            ?->resolve($this->context(primaryOuId: 2))->criteria;
        self::assertNotNull($criteria);
        self::assertNotNull($criteria->routedThroughOuIds);
        self::assertEqualsCanonicalizing([2, 3, 4, 5], $criteria->routedThroughOuIds);
        self::assertNull($criteria->originOuIds, 'this folder queries the trail, never documents.origin_ou_id');

        // The same subtree the unit folder resolves, from the same closure.
        $below = $views->get(CoreDocumentViews::BELOW_MY_UNIT)
            ?->resolve($this->context(primaryOuId: 2))->criteria;
        self::assertNotNull($below);
        self::assertEqualsCanonicalizing((array) $below->originOuIds, $criteria->routedThroughOuIds);

        // An explicit anchor overrides the caller's own unit, as on both unit
        // folders — one view with an optional anchor, not two.
        $anchored = $views->get(CoreDocumentViews::PASSED_THROUGH_MY_UNIT)
            ?->resolve($this->context(primaryOuId: 2, anchorOuId: 4))->criteria;
        self::assertNotNull($anchored);
        self::assertEqualsCanonicalizing([4, 5], $anchored->routedThroughOuIds);

        // And a caller in no unit gets the #951 refusal with a reason, never an
        // empty page that would read as "nothing passed through my unit".
        $unanchored = $views->get(CoreDocumentViews::PASSED_THROUGH_MY_UNIT)
            ?->resolve($this->context(primaryOuId: null));
        self::assertNotNull($unanchored);
        self::assertFalse($unanchored->isAvailable());
        self::assertNull($unanchored->criteria);
        self::assertStringContainsString('unit', (string) $unanchored->unavailableReason);
    }

    /**
     * Every view slot survives {@see DocumentCriteria::withRequestScope()}.
     *
     * A slot added to the criteria and forgotten in that method is dropped on the
     * way to the repository, and the folder it belongs to silently widens — an
     * "awaiting me" that quietly becomes "every document in the tenant" is wrong
     * in the direction that looks like work. Nothing in the language catches
     * that, so it is asserted over every registered core view rather than over a
     * hand-written list, which would have to be remembered too.
     */
    public function testEveryViewSlotSurvivesTheRequestScopeBeingApplied(): void
    {
        $views = $this->coreRegistry();
        $context = $this->context(primaryOuId: 2, collectionId: 42, starredCollectionId: 77);

        $populated = [];
        foreach ($views->available() as $view) {
            $criteria = $view->resolve($context)->criteria;
            if ($criteria === null) {
                continue;
            }

            $scoped = $criteria->withRequestScope(self::CALLER, 'memo');

            self::assertSame(
                self::viewSlots($criteria),
                self::viewSlots($scoped),
                "'{$view->key}' lost a filter when the request scope was applied — the folder would "
                    . 'silently widen to everything the caller may see'
            );

            // The two things withRequestScope is FOR are the two that change.
            self::assertSame(self::CALLER, $scoped->restrictToCreator);
            self::assertSame('memo', $scoped->search);

            foreach (self::viewSlots($criteria) as $slot => $value) {
                if ($value !== null && $value !== false) {
                    $populated[$slot] = true;
                }
            }
        }

        // The loop only proves what it exercised. A folder set that never
        // populated `awaitingProfileId` would satisfy every assertion above by
        // comparing null with null, so require that each slot was actually
        // carried by some folder — `matchesNothing` excepted, since it is only
        // set by a caller state this context deliberately does not have.
        foreach (array_keys(self::viewSlots(DocumentCriteria::unfiltered())) as $slot) {
            if ($slot === 'matchesNothing') {
                continue;
            }
            self::assertArrayHasKey(
                $slot,
                $populated,
                "no core folder populated {$slot}, so this test proved nothing about it"
            );
        }
    }

    /**
     * A slot the criteria gains must be added to {@see viewSlots()} or the test
     * above stops covering it, which is the same forgetting one level removed.
     * So the helper is checked against the constructor itself.
     */
    public function testTheSlotInventoryCannotDriftFromTheCriteria(): void
    {
        $constructor = (new \ReflectionClass(DocumentCriteria::class))->getConstructor();
        self::assertNotNull($constructor);

        $declared = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            $constructor->getParameters()
        );

        self::assertSame(
            // Everything except the two the REQUEST supplies, which
            // withRequestScope exists to replace rather than to preserve.
            array_values(array_diff($declared, ['restrictToCreator', 'search'])),
            array_keys(self::viewSlots(DocumentCriteria::unfiltered())),
            'DocumentCriteria gained or lost a view slot; viewSlots() must list every one, in order'
        );
    }

    /**
     * The collection folder is a TEMPLATE the client instantiates per
     * collection, so its parameter is required — which the handler turns into a
     * 400 rather than into an unavailable folder.
     */
    public function testTheCollectionFolderDeclaresItsParameterAsRequired(): void
    {
        $view = $this->coreRegistry()->get(CoreDocumentViews::COLLECTION);
        self::assertNotNull($view);
        self::assertSame(['collection_id'], $view->requiredParameters());

        // Both unit folders take an OPTIONAL anchor: without one they mean "my
        // unit", so requiring it would break the default they exist to provide.
        $unitView = $this->coreRegistry()->get(CoreDocumentViews::RAISED_BY_MY_UNIT);
        self::assertNotNull($unitView);
        self::assertSame([], $unitView->requiredParameters());
    }

    // ── 5. a later view slots in ────────────────────────────────────────────

    /**
     * What item 3 will do, done here: register a substrate whose tables exist
     * and a view naming it. Nothing in the registry, the criteria vocabulary or
     * the presenter is touched, and the view appears.
     *
     * This is the seam's actual contract, asserted rather than described.
     */
    public function testAViewRegisteredLaterAppearsWithoutTheRegistryChanging(): void
    {
        $schema = $this->fullSchema();
        $substrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        self::assertNull($views->get('escalated-to-me'), 'precondition: it does not exist yet');

        // A feature that has NOT shipped declares its substrate and its view.
        // The substrate does not resolve, so the view does not exist.
        $escalations = new DocumentSubstrate(
            'acme.escalations',
            'Escalation rows a plugin records against a document.',
            ['acme_escalations'],
            'a plugin that is not installed here',
        );
        $substrates->register($escalations);
        $views->register($this->view('escalated-to-me', ['acme.escalations']));
        self::assertNull($views->get('escalated-to-me'), 'still absent while its table does not exist');

        // Its migration runs. Same registrations, nothing else touched.
        $schema['acme_escalations'] = ['id', 'tenant_id', 'document_id', 'profile_id'];
        $laterSubstrates = new DocumentSubstrateRegistry($this->schema($schema));
        CoreDocumentSubstrates::registerInto($laterSubstrates);
        $laterSubstrates->register($escalations);
        $laterViews = new DocumentViewRegistry($laterSubstrates);
        CoreDocumentViews::registerInto($laterViews);
        $laterViews->register($this->view('escalated-to-me', ['acme.escalations']));

        self::assertNotNull(
            $laterViews->get('escalated-to-me'),
            'a view whose substrate now resolves must appear with no other change'
        );
        self::assertSame([], $laterSubstrates->unavailable(), 'and nothing is reported missing any more');
    }

    /** Re-registering a key replaces it rather than throwing — item 3 refines core's coarse declaration. */
    public function testReRegisteringASubstrateReplacesItAndDiscardsTheMemoisedAnswer(): void
    {
        $substrates = new DocumentSubstrateRegistry($this->schema(['documents' => ['id']]));
        $substrates->register(new DocumentSubstrate('x', 'needs a missing table', ['nope']));
        self::assertFalse($substrates->isAvailable('x'));

        $substrates->register(new DocumentSubstrate('x', 'needs a table that exists', ['documents']));
        self::assertTrue($substrates->isAvailable('x'), 'the memoised false must not survive re-registration');
    }

    // ── the probe itself ────────────────────────────────────────────────────

    /**
     * The probe reads the driver's own catalogue. If it only understood
     * `information_schema` every substrate would report absent under SQLite, so
     * every organizer test would pass by rendering nothing — this design's
     * failure mode arriving as green CI.
     */
    public function testThePdoProbeReadsTablesAndColumnsFromTheLiveSqliteSchema(): void
    {
        $presence = new PdoSchemaPresence($this->ouPdo());

        self::assertTrue($presence->hasTable('organizational_units'));
        self::assertTrue($presence->hasColumn('organizational_units', 'parent_id'));
        self::assertFalse($presence->hasTable('recipients'));
        self::assertFalse($presence->hasColumn('organizational_units', 'no_such_column'));
        // Case-insensitive on both levels: schema identifiers are folded by both
        // engines and a substrate declaration should not have to match casing.
        self::assertTrue($presence->hasColumn('Organizational_Units', 'Parent_Id'));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Every filter a VIEW may set, as a comparable snapshot. Deliberately not
     * `restrictToCreator` or `search`: those are the request's, and
     * {@see DocumentCriteria::withRequestScope()} is supposed to replace them.
     *
     * Pinned against the constructor by
     * {@see testTheSlotInventoryCannotDriftFromTheCriteria()}.
     *
     * @return array<string, int|bool|string|list<int>|null>
     */
    private static function viewSlots(DocumentCriteria $criteria): array
    {
        return [
            'createdBy' => $criteria->createdBy,
            'originOuIds' => $criteria->originOuIds,
            'inCollectionId' => $criteria->inCollectionId,
            'awaitingProfileId' => $criteria->awaitingProfileId,
            'actedOnByProfileId' => $criteria->actedOnByProfileId,
            'verdictByProfileId' => $criteria->verdictByProfileId,
            'verdict' => $criteria->verdict,
            'routedThroughOuIds' => $criteria->routedThroughOuIds,
            'matchesNothing' => $criteria->matchesNothing,
        ];
    }

    private function coreRegistry(): DocumentViewRegistry
    {
        $substrates = new DocumentSubstrateRegistry($this->schema($this->fullSchema()));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        return $views;
    }

    /**
     * Every table and column core's own substrates need — a fully migrated
     * installation, described rather than built.
     *
     * The individual cases UNSET from this rather than each listing what it
     * needs: a substrate that starts requiring a new column shows up as one
     * failing assertion about the whole schema instead of silently passing every
     * case that never mentioned it.
     *
     * @return array<string, list<string>>
     */
    private function fullSchema(): array
    {
        return [
            'documents' => ['id', 'tenant_id', 'title', 'created_by', 'origin_ou_id'],
            'organizational_units' => ['id', 'tenant_id', 'parent_id'],
            'document_collections' => ['id', 'tenant_id', 'profile_id', 'name', 'system_key'],
            'document_collection_items' => ['id', 'tenant_id', 'collection_id', 'document_id'],
            // #947 item 3's tables (migration 112), which the three routing
            // folders read. `closed_by_event_id` is not declared by any
            // substrate and is listed anyway, because it is the column the inbox
            // filters on and a reader of this fixture should see the shape the
            // folder actually queries.
            'document_route_recipients' => [
                'id', 'tenant_id', 'document_id', 'profile_id', 'closed_by_event_id',
            ],
            'document_route_events' => [
                'id', 'tenant_id', 'document_id', 'actor_profile_id', 'from_ou_id', 'to_ou_id',
                // #1014 (migration 119). Declared by `routing.verdict` and by
                // nothing else, which is the point: an installation on 112 but
                // not 118 loses the two verdict folders and keeps the other
                // three.
                'verdict',
            ],
        ];
    }

    /**
     * A {@see SchemaPresence} over a literal table/column map, so a test can
     * describe a half-migrated database without building one.
     *
     * @param array<string, list<string>> $tables
     */
    private function schema(array $tables): SchemaPresence
    {
        return new class ($tables) implements SchemaPresence {
            /** @param array<string, list<string>> $tables */
            public function __construct(private readonly array $tables)
            {
            }

            public function hasTable(string $table): bool
            {
                return isset($this->tables[strtolower($table)]);
            }

            public function hasColumn(string $table, string $column): bool
            {
                return in_array(strtolower($column), array_map('strtolower', $this->tables[strtolower($table)] ?? []), true);
            }
        };
    }

    /** @param list<string> $requires */
    private function view(string $key, array $requires): DocumentView
    {
        return new DocumentView(
            $key,
            ucfirst(str_replace('-', ' ', $key)),
            'A folder registered by a test.',
            CoreDocumentViews::GROUP_DERIVED,
            $requires,
            [],
            static fn (DocumentViewContext $ctx): DocumentViewResolution
                => DocumentViewResolution::of(DocumentCriteria::unfiltered()),
        );
    }

    private function context(
        ?int $primaryOuId = null,
        ?int $anchorOuId = null,
        ?int $collectionId = null,
        ?int $starredCollectionId = null,
    ): DocumentViewContext {
        return new DocumentViewContext(
            self::TENANT,
            self::CALLER,
            $primaryOuId,
            $anchorOuId,
            $collectionId,
            $starredCollectionId,
            // The narrow, tenant-bound subtree capability the handler supplies
            // in production — OuSubtree with the tenant already closed over, so
            // a view can ask what is beneath a unit and nothing else.
            fn (int $anchor): array => OuSubtree::descendantIds($this->ouPdo(), self::TENANT, [$anchor]),
        );
    }

    /**
     * A throwaway SQLite database holding only the OU tree the subtree
     * assertions walk: 1 → 2 → {3, 4}, 4 → 5, plus a second tenant's unit so
     * the walk's tenant predicate has something to exclude.
     */
    private function ouPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE organizational_units (
            id INTEGER PRIMARY KEY, tenant_id INTEGER NOT NULL, parent_id INTEGER
        )');
        $pdo->exec('INSERT INTO organizational_units (id, tenant_id, parent_id) VALUES
            (1, 1, NULL), (2, 1, 1), (3, 1, 2), (4, 1, 2), (5, 1, 4), (9, 2, NULL)');

        return $pdo;
    }
}
