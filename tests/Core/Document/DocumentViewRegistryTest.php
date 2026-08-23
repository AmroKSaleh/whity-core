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
 * #947 item 5 specifies six folders. Three of them read routing facts item 3
 * has not built. The failure this suite exists to prevent is those three
 * rendering as EMPTY rather than being ABSENT: an empty "Awaiting me" states
 * *"nothing awaits you"*, a claim that is false and that the person it misleads
 * cannot check from outside. A document somebody was supposed to act on failing
 * to appear is indistinguishable from having nothing to do.
 *
 * So the assertions here are, in order of how much they matter:
 *
 *  1. A view whose substrate is absent is not listed AND cannot be requested.
 *  2. Core ships NO view that names the absent routing substrate — not even a
 *     filtered one, because a registered-then-hidden view is a stub, and a stub
 *     is what somebody turns on.
 *  3. The gating is per SUBSTRATE, not global: removing collections must take
 *     the two collection folders and leave the unit ones alone.
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

    // ── 2. core ships no routing stub ───────────────────────────────────────

    /**
     * The assertion #978 is really about, and it survived #947 item 3 landing.
     *
     * Routing's tables now EXIST, so both routing substrates resolve — and there
     * is still no routing folder, because a resolvable substrate is a fact
     * source rather than a view. Each of item 5's three routing folders needs a
     * predicate on {@see DocumentCriteria} and a registration of its own.
     *
     * This is the assertion that catches the tempting shortcut: registering the
     * three views guarded by the substrate so they "appear automatically" when
     * routing lands. They would have appeared EMPTY, and an empty "Awaiting me"
     * states "nothing awaits you".
     */
    public function testARoutingSubstrateThatResolvesStillProducesNoRoutingFolder(): void
    {
        $substrates = new DocumentSubstrateRegistry($this->schema($this->fullSchema()));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        // Precondition: the fact sources are genuinely there.
        self::assertTrue($substrates->isAvailable(CoreDocumentSubstrates::ROUTING_RECIPIENTS));
        self::assertTrue($substrates->isAvailable(CoreDocumentSubstrates::ROUTING_TRAIL));

        foreach ($views->available() as $view) {
            self::assertNotContains(
                CoreDocumentSubstrates::ROUTING_RECIPIENTS,
                $view->requires,
                "core registered a view against routing recipients with no predicate to back it"
            );
            self::assertNotContains(CoreDocumentSubstrates::ROUTING_TRAIL, $view->requires);
        }

        // And the same statement from the other side: none of the three folder
        // keys #947 item 5 specifies for routing resolves to anything.
        foreach (['awaiting-me', 'acted-on-by-me', 'passed-through-my-unit'] as $key) {
            self::assertNull($views->get($key), "a routing folder must not exist until it is built");
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
        self::assertTrue($partial->isAvailable(CoreDocumentSubstrates::ROUTING_RECIPIENTS));
        $missing = $partial->unavailable();
        self::assertCount(1, $missing);
        self::assertStringContainsString('#947 item 3', (string) $missing[0]->provenance);
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

    public function testRemovingTheOuTableTakesOnlyTheUnitFolders(): void
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
        self::assertContains(CoreDocumentViews::CREATED_BY_ME, $keys);
        self::assertContains(CoreDocumentViews::STARRED, $keys);
    }

    /** On a fully migrated schema, all six core folders are computable, in rail order. */
    public function testAFullyMigratedSchemaOffersEveryCoreFolderInRailOrder(): void
    {
        $views = $this->coreRegistry();

        self::assertSame(
            [
                CoreDocumentViews::ALL,
                CoreDocumentViews::CREATED_BY_ME,
                CoreDocumentViews::RAISED_BY_MY_UNIT,
                CoreDocumentViews::BELOW_MY_UNIT,
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

    private function coreRegistry(): DocumentViewRegistry
    {
        $substrates = new DocumentSubstrateRegistry($this->schema($this->fullSchema()));
        CoreDocumentSubstrates::registerInto($substrates);
        $views = new DocumentViewRegistry($substrates);
        CoreDocumentViews::registerInto($views);

        return $views;
    }

    /**
     * Every table and column core's own substrates need, and nothing routing
     * would add.
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
            // #947 item 3's tables (migration 112). Present on any migrated
            // installation, and read by NO view — which is the state the
            // assertions below pin.
            'document_route_recipients' => ['id', 'tenant_id', 'document_id', 'profile_id'],
            'document_route_events' => ['id', 'tenant_id', 'document_id', 'actor_profile_id', 'from_ou_id'],
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
