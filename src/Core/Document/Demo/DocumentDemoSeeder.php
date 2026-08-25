<?php

declare(strict_types=1);

namespace Whity\Core\Document\Demo;

use PDO;
use RuntimeException;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentStarterSeeder;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Qr\VerificationPresenter;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * A demo dataset for the document system: the states its screens distinguish
 * between, seeded so that they can be looked at.
 *
 * WHY THIS EXISTS
 * ---------------
 * Every document surface renders an honest empty state, and honest empty states
 * are indistinguishable from each other. "Awaiting me", "Acted on by me" and
 * "Passed through my unit" all show the same blank panel on an unseeded
 * database; so do a faculty secretary's and a department secretary's template
 * lists; so does a document with one artifact and a document with none. The work
 * is there and correct and none of it can be SEEN, which is the gap this closes.
 *
 * The dataset is chosen by that criterion and no other: each piece is here
 * because it is the smallest thing that makes one distinction visible, and
 * anything that would look the same as something already seeded is left out.
 *
 *   | Seeded                                     | What it makes visible                    |
 *   |--------------------------------------------|------------------------------------------|
 *   | A faculty with two departments             | "raised by my unit" / "below my unit" /  |
 *   |                                            | "passed through my unit" as three        |
 *   |                                            | different answers rather than one        |
 *   | One document awaiting one named person     | an open recipient row: the inbox         |
 *   | One document already acted on, plus a note | closed rows + an append-only trail, and  |
 *   |                                            | that a correction ADDS rather than edits |
 *   | Two fanned-out documents where SOME        | per-step counts (#1000). A single global |
 *   | recipients acted and some did not          | progress bar would misreport this, and   |
 *   |                                            | a linear document cannot exhibit it      |
 *   | One document raised in a department and    | "passed through my unit" as distinct     |
 *   | forwarded through the faculty              | from "raised by my unit"                 |
 *   | Templates and blocks placed at three       | #1004: two holders of ONE role, standing |
 *   | different units, two permission-tagged     | in different units, seeing different     |
 *   |                                            | sets — and the permission gate on top    |
 *   | A template gated on a capability the       | that a technician sees less than the     |
 *   | secretary holds and the technician does    | SECRETARY BESIDE HER, not merely less    |
 *   | not, both standing in one unit             | than the dean two levels up              |
 *   | Block POINTERS across three units, one of  | that a block is a REFERENCE and not a    |
 *   | them from a template a department          | copy: a usage count above zero, and the  |
 *   | secretary cannot see                       | "N templates, M you cannot see"          |
 *   |                                            | disclosure with a real M in it           |
 *   | A starred collection AND a custom one      | that starring IS a collection (migration |
 *   |                                            | 114) rather than a second concept        |
 *   | One document with TWO artifacts            | #986's "version N of M" and its          |
 *   |                                            | superseded-version warning               |
 *   | One document raised by somebody in NO unit | that "below my unit" and "passed through |
 *   |                                            | my unit" are different queries, and the  |
 *   |                                            | `unanchored` answer for a person with no |
 *   |                                            | unit (#951)                              |
 *
 * THE SECOND TRANCHE: OUTCOMES, NOT ONLY CIRCULATION
 * --------------------------------------------------
 * Everything above is about WHERE A DOCUMENT IS. v0.2.8 shipped five things that
 * are about WHAT WAS DECIDED — and none of them could be looked at, because the
 * only way to reach any of them was for several people to sign in and act.
 * Measured rather than assumed: against the release, `template_id` appeared zero
 * times in this fixture and `qr` zero times, and while `decision` and `quorum`
 * appeared, no OUTCOME of either did.
 *
 *   | Seeded                                     | What it makes visible                    |
 *   |--------------------------------------------|------------------------------------------|
 *   | A quorum stopped PART WAY: two of three    | the answer `decided: null` — the STEP's  |
 *   | approvals in, the step still undecided     | conclusion, not the caller's verdict      |
 *   |                                            | (#1041). Reachable before only by three  |
 *   |                                            | people acting by hand, in order          |
 *   | One document APPROVED at a gate and one    | #1030: an approve edge and a reject edge |
 *   | REJECTED at the SAME gate of the SAME      | send the same document to two different  |
 *   | design                                     | places. Two documents, one design, so    |
 *   |                                            | the destination is the only variable     |
 *   | One document rejected at a gate that has   | that a rejection with no edge ENDS the   |
 *   | NO reject edge                             | chain — it never inherits the approval's |
 *   |                                            | destination, and the step it would have  |
 *   |                                            | opened never opens                       |
 *   | One document round a REWORK LOOP twice     | a backwards reject edge, and #1037: the  |
 *   |                                            | third lap is indistinguishable from the  |
 *   |                                            | first, because nothing counts laps       |
 *   | Three ROUTE TEMPLATES, and four documents  | #1056's `template_id` / `template_name`  |
 *   | whose routes were applied from them        | provenance, and a flow editor that opens |
 *   |                                            | on a real design instead of blank canvas |
 *   | A merge stage whose rule is ACTOR-RELATIVE | #1058: "Paths merge here — settles once" |
 *   |                                            | is FALSE here. Two arrivals resolve to   |
 *   |                                            | two different people, nothing            |
 *   |                                            | de-duplicates, and the stage carries two |
 *   |                                            | independent cohorts                      |
 *   | A stage SATISFIED BY DELIVERY (#1054)      | recipients who were TOLD and are not     |
 *   |                                            | asked to act: rows closed the instant    |
 *   |                                            | they were opened, an empty "Awaiting me" |
 *   |                                            | beside a populated recipient list        |
 *   | A LIVE verification code and a REVOKED one | #1036/#1051: a revoked code answers, by  |
 *   |                                            | default, exactly what an unknown token   |
 *   |                                            | answers, so ONE of them proves nothing   |
 *
 * The QR pair also needs the tenant switch — `documents.qr_enabled` defaults to
 * FALSE, deliberately, because turning it on publishes an unauthenticated
 * verification surface. See {@see seedQrCodes()} for why the seed writes it only
 * when the tenant has no opinion of its own.
 *
 * DRIVEN THROUGH THE REAL SERVICES — WHICH IS THE POINT, NOT A PREFERENCE
 * ----------------------------------------------------------------------
 * Documents are created by {@see DocumentIssuer::issue()}, corrections by
 * {@see DocumentIssuer::appendArtifact()}, routes by
 * {@see DocumentRouter::issue()}, and every recipient act by
 * {@see DocumentRouter::act()}. Not one row in `documents`,
 * `document_artifacts`, `document_routes`, `document_route_steps`,
 * `document_route_events` or `document_route_recipients` is written by this file.
 *
 * The alternative was a page of INSERTs and it would have been shorter. It would
 * also have been able to express states the engine cannot produce, and that is
 * the specific harm: a hand-written recipient row can sit beside a step naming a
 * rule that resolves to somebody else entirely, and nothing would ever complain
 * — not the schema, not a test, not the screen, which would render it perfectly.
 * The demo would then teach a routing behaviour the product does not have, to
 * exactly the people who are looking at it to learn what the product does. Every
 * `ou_id` on a recipient row here was decided by a resolver; every `to_ou_id` on
 * a trail event was computed by {@see DocumentRouter}'s "one unit or none" rule;
 * every closed row was closed by an act that had to be legal to be accepted.
 *
 * The corollary is that this seeder can be WRONG and will say so. Declaring an
 * act by somebody who holds no open item is a {@see \Whity\Core\Document\Routing\RoutingRejectedException},
 * not a plausible-looking row — the fixture is checked by the engine as it is
 * built.
 *
 * WHAT COULD NOT BE DRIVEN THROUGH A SERVICE
 * ------------------------------------------
 *  1. THE ORGANISATION — units, roles, permission grants, profiles,
 *     memberships. There is no service; see {@see DemoOrganisationSeeder}, which
 *     also records why direct SQL is defensible there and would not be here.
 *  2. THE ARTIFACT BYTES. {@see \Whity\Core\Document\Render\DocumentRenderer}
 *     needs the opt-in `whity_render` container; see {@see DemoPdf}. The
 *     persistence half is real, the payload is local.
 * (There used to be a third: RESOLVING A ROW BY ITS `starter_key`.
 * `starterKeysForTenant()` returns keys without ids and the rows came back with
 * no key on them, so this class could not ask the repository "which row is
 * starter X" and worked around it with a lookup by NAME. #1013 put `starter_key`
 * back on the row, which is what makes the block POINTERS below possible at all —
 * a pointer needs the id of the block it points at.)
 *
 * IDEMPOTENT, AND RESUMABLE
 * -------------------------
 * `seed` is run repeatedly against dev databases. Templates and blocks are
 * keyed by `starter_key` and inserted only when absent — the mechanism
 * {@see DocumentStarterSeeder} already uses, and for its reason: a name is
 * user-renameable and therefore cannot be an identity. Documents are keyed by
 * TITLE, which for this table is safe in a way it would not be for most:
 * {@see DocumentRepository} has no update path at all, so a document's title is
 * fixed at issue.
 *
 * Insert-when-absent means NEVER UPDATE, and that has one consequence worth
 * saying out loud: a database seeded before a change to this file keeps the rows
 * it already has. A demo seeded before #1024 therefore keeps templates with no
 * `blockInstance` in them — re-running `seed` will not add the pointers, exactly
 * as re-running it has never re-written a template somebody edited. A demo that
 * has to show the new thing is seeded into a fresh database.
 *
 * Each document and its whole routing history are written inside ONE
 * transaction, so the title check is a complete answer: either the document and
 * its route and its acts all exist, or none of them do. Without that, a failure
 * between the issue and the route would leave a document whose title makes the
 * next run skip it, and it would never acquire the route it is supposed to
 * demonstrate. A rollback can leave an unreferenced object in storage, which is
 * the loss {@see DocumentIssuer} already documents and accepts.
 *
 * NOT SWALLOWED
 * -------------
 * Unlike {@see DocumentStarterSeeder::seedForTenant()}, which logs and swallows
 * because it hangs off a tenant-creation request that must not fail with it,
 * this throws. A half-seeded demo is worse than an unseeded one: the operator
 * would be looking at folders that are empty for a reason nobody told them
 * about, which is the exact confusion the seed exists to remove.
 *
 * ASKED FOR BY NAME, NEVER IMPLIED
 * --------------------------------
 * Called from {@see \Whity\Cli\Commands\SeedCommand} only under
 * `--with-document-demo`, which is off by default in EVERY environment,
 * `APP_ENV=development` included. Eight logins, an invented faculty and a folder
 * of fake documents have no business appearing in a tenant by accident.
 *
 * It briefly rode `--with-fixtures` — the demo-ACCOUNTS flag — and that is worth
 * recording because of how it failed rather than that it did. The E2E suite
 * passes `--with-fixtures` because it must log in as `admin@example.com`, so it
 * silently received this whole dataset; the eight demo memberships then pushed
 * that account off the first page of a users table paginating at ten, and two
 * specs about authentication and users failed on a missing table cell. Nothing
 * about documents was involved. A gate shared with something a test suite needs
 * makes every future change to this file able to break an unrelated spec — and
 * the fix belonged here, not in the specs, because scoping them around this data
 * would pin the tests to the seed's contents and the seed is meant to stay free
 * to change.
 *
 * The gate covers {@see DemoOrganisationSeeder} too, and that failure is the
 * reason: the rows that broke E2E were PEOPLE, not documents.
 */
final class DocumentDemoSeeder
{
    // ── template identities (stable `starter_key`s, never the display names) ──
    private const TPL_FACULTY_CIRCULAR = 'demo-faculty-circular';
    private const TPL_CIVIL_WORKS_ORDER = 'demo-civil-works-order';
    private const TPL_MECHANICAL_REPORT = 'demo-mechanical-test-report';
    private const TPL_CIVIL_CONTRACT = 'demo-civil-contract-restricted';
    private const TPL_CIVIL_REQUISITION = 'demo-civil-requisition-drafters-only';

    private const BLOCK_FACULTY_LETTERHEAD = 'demo-faculty-letterhead';
    private const BLOCK_CIVIL_SAFETY = 'demo-civil-safety-notice';

    /** The one custom collection, beside the well-known `starred` one. */
    private const COLLECTION_BUDGET_FILE = 'Demo budget file 2026';

    /**
     * ROUTE-template identities — the DESIGNS, not the page templates above.
     *
     * Keyed by NAME rather than by a `starter_key`, because
     * `document_route_templates` has no such column: {@see RouteTemplateRepository::findByName()}
     * is the duplicate check the API itself uses, so it is the identity available
     * here. The same compromise the DOCUMENTS make, and acceptable for the same
     * reason — a demo row an operator renames stops being found and is left
     * alone, which is the safe direction for a fixture that never updates.
     */
    private const ROUTE_TPL_PURCHASE_APPROVAL = 'Demo purchase approval (approve files it, reject refers it)';
    private const ROUTE_TPL_DRAFTING_LOOP = 'Demo drafting loop (rejection goes back to the drafter)';
    private const ROUTE_TPL_TWO_DEPARTMENT_REVIEW = 'Demo two-department review (the paths merge)';

    /**
     * The two documents that carry a verification code (#1036/#1051).
     *
     * Named as constants because {@see seedQrCodes()} resolves them out of the
     * title => id map {@see seedDocuments()} returns, and a typo there would be a
     * silently missing demo rather than an error. Both are documents that already
     * exist for another reason: a QR code is an ATTRIBUTE of a document, not a
     * routing state, so inventing two more documents to carry one would break the
     * rule the rest of this file follows.
     */
    private const QR_LIVE_DOCUMENT = 'Demo tenant-wide registry notice';
    private const QR_REVOKED_DOCUMENT = 'Demo safety inspection report';

    public function __construct(
        private readonly PDO $db,
        private readonly DemoOrganisationSeeder $organisation,
        private readonly DocumentStarterSeeder $starters,
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentBlockRepository $blocks,
        private readonly DocumentRepository $documents,
        private readonly DocumentIssuer $issuer,
        private readonly DocumentRouter $router,
        private readonly DocumentCollectionRepository $collections,
        private readonly RouteTemplateRepository $routeTemplates,
        private readonly RouteTemplateGraph $routeGraph,
        private readonly SettingsService $settings,
        private readonly DocumentQrService $qr,
        // Beside the service, not instead of it. Every WRITE goes through
        // {@see DocumentQrService}; this is here for one READ the service does
        // not expose — "has this document EVER had a code" — which is what makes
        // the revoked-code fixture idempotent. `active()` answers only about the
        // live one, and a document whose only code has been revoked has none, so
        // a guard built on it would mint and revoke a fresh token on every run.
        private readonly DocumentQrTokenRepository $qrTokens,
    ) {
    }

    /**
     * Seed the whole demo for one tenant.
     *
     * @return list<string> Lines describing what is now there, for the CLI to
     *         print. Returned rather than echoed so the class is testable and so
     *         the command owns its own output formatting.
     */
    public function seedForTenant(int $tenantId, string $tenantName): array
    {
        $org = $this->organisation->seed($tenantId);

        // The shipped starters first, so the designer has its normal contents
        // underneath the demo's placed rows. They are UNPLACED
        // (`owner_ou_id` null), which is what makes the contrast legible: both
        // secretaries see all four starters and different placed sets, so the
        // difference is visibly about placement rather than about one of them
        // seeing nothing.
        //
        // Called even though `Seeder::seed()` now provisions starters itself
        // (#1012): this seeder is also driven directly by its own tests and by
        // anyone seeding the demo into a tenant that came from somewhere else,
        // and it is idempotent, so the cheap call is better than the assumption.
        // It used to be here because the starters reached NO tenant this way —
        // the tenant-creation hook never fired for the default tenant — which is
        // the bug that has since been fixed at its source.
        $this->starters->seedForTenant($tenantId, $tenantName);

        // BLOCKS BEFORE TEMPLATES, because a template body now carries POINTERS
        // at them (#1024) and a pointer needs the id it points at.
        $blockIds = $this->seedBlocks($org);
        $templateIds = $this->seedTemplates($org, $blockIds);
        // ROUTE templates before documents, for the same reason blocks come
        // before page templates: four of the documents below have their routes
        // APPLIED from one of these designs (#1056), and applying a design needs
        // the id it is being applied from.
        $routeTemplateIds = $this->seedRouteTemplates($org);
        $documentIds = $this->seedDocuments($org, $templateIds, $routeTemplateIds);
        $collectionLines = $this->seedCollections($org, $documentIds);
        $qrLines = $this->seedQrCodes($org, $documentIds);

        return array_merge(
            [
                sprintf(
                    'Demo organisation: %d units (a faculty and its departments), %d roles, %d people '
                    . '(all @demo.example.com, one shared password from %s).',
                    count($org->units()),
                    count($org->roles()),
                    count($org->people()),
                    DemoOrganisationSeeder::PASSWORD_ENV_VAR,
                ),
                sprintf(
                    'Designer: the shipped starter set (unplaced, so everyone sees it) + %d templates '
                    . 'and %d blocks placed at specific units, %d of the templates permission-tagged, '
                    . '%d block references placed across them.',
                    count($templateIds),
                    count($blockIds),
                    // Counted, never typed. A literal here was already one
                    // template out of date the moment a second tagged row was
                    // added, and it would have gone out of date SILENTLY —
                    // which is the exact failure the seed exists to spare its
                    // reader. Same argument as {@see DemoOrganisation::units()}.
                    count(array_filter(
                        self::templateDeclarations(),
                        static fn (array $spec): bool => $spec['permission'] !== null,
                    )),
                    // Counted from the same declaration for the same reason.
                    array_sum(array_map(
                        static fn (array $spec): int => count($spec['blocks']),
                        self::templateDeclarations(),
                    )),
                ),
                sprintf('Documents: %d, each with a route the engine issued and resolved.', count($documentIds)),
                sprintf(
                    'Route designs: %d, drawn through the editor\'s own validator; %d of the documents '
                    . 'above carry `template_id` provenance from one of them (#1056).',
                    count($routeTemplateIds),
                    // Counted from the declaration, never typed — same argument
                    // as the tagged-template count above.
                    count(array_filter(
                        self::documentDeclarations($org),
                        static fn (array $spec): bool => ($spec['route_template'] ?? null) !== null,
                    )),
                ),
            ],
            $collectionLines,
            $qrLines,
        );
    }

    // ── templates and blocks, placed (#1004 / migration 117) ─────────────────

    /**
     * Five templates at three different units.
     *
     * The set is built so that reach ALONE separates the two secretaries and so
     * that the permission gate is separately visible:
     *
     *   - the faculty circular, at the Faculty: the dean's secretary reaches it
     *     (she stands at the Faculty), the civil secretary does not (reach is
     *     DOWNWARD — she stands one level below it);
     *   - the civil works order, at Civil Engineering: BOTH reach it, the
     *     faculty secretary through the subtree;
     *   - the mechanical test report, at Mechanical Engineering: the faculty
     *     secretary reaches it, the civil secretary does not — so neither
     *     secretary's set is a prefix of some ordering, it is genuinely a
     *     subtree;
     *   - the civil contract, at Civil Engineering AND tagged
     *     `documents:publish`: within BOTH secretaries' consideration by
     *     placement (the civil one stands there, the faculty one reaches it) and
     *     visible to NEITHER, because neither holds the tag. That is the second
     *     predicate on its own, with placement held constant — the case that
     *     shows why {@see DocumentAccessPolicy} needs both;
     *   - the civil requisition, at Civil Engineering and tagged
     *     `documents:write`: see below — it is the only row here that separates
     *     two people STANDING IN THE SAME UNIT.
     *
     * WHY A SECOND TAGGED TEMPLATE, WITH A DIFFERENT TAG
     * -------------------------------------------------
     * {@see DocumentAccessPolicy}'s own docblock states the claim the tag is for:
     * "the permission gate is what still keeps a technician standing in the same
     * faculty from seeing contract templates". With only the publish-tagged
     * contract, the fixture did not show that, and it was measured rather than
     * assumed: `civil-technician@` and `civil-secretary@` saw EXACTLY the same
     * five templates and three blocks. Both stand in Civil Engineering, so reach
     * cannot separate them; the only tag in the set was `documents:publish`,
     * which NEITHER holds, so the gate could not either. Two people in one
     * office, one of whom is meant to see less, whose screens were identical.
     *
     * "A technician sees fewer templates" was therefore true only against the
     * DEAN — two levels up, in another unit, holding four more permissions —
     * where a difference proves nothing in particular because everything about
     * them differs. The comparison a customer makes is between the two people
     * sitting next to each other, and it is the comparison this row restores.
     *
     * `documents:write` is the tag, chosen because it is the one capability the
     * demo secretary holds and the demo technician does not
     * ({@see DemoOrganisationSeeder::seedRoles()}) — so the difference is one
     * permission wide, which is what makes it readable. It also reads as the
     * right rule in prose: a template you draft FROM belongs to the people who
     * draft.
     *
     * Authored by the head of the department it is filed in, unlike the contract
     * above: the head holds `documents:write`, so this row IS visible to its own
     * author, and the contract's deliberately awkward authorship stays the one
     * place that case is made rather than becoming the pattern.
     *
     * @param array<string, int> $blockIds starter_key => block id, from seedBlocks()
     * @return array<string, int> starter_key => template id
     */
    private function seedTemplates(DemoOrganisation $org, array $blockIds): array
    {
        $tenantId = $org->tenantId;
        $declared = self::templateDeclarations();

        // Resolved by KEY, in one read, now that a row carries its `starter_key`
        // (#1013). This used to be a key-presence check plus a second lookup by
        // NAME, because the repository could not hand back the id belonging to a
        // key — the exact gap #1013 reports, and the reason this class carried a
        // find-by-name helper it did not want.
        $existing = self::idsByStarterKey($this->templates->listForTenant($tenantId));

        $ids = [];
        foreach ($declared as $starterKey => $spec) {
            if (isset($existing[$starterKey])) {
                $ids[$starterKey] = $existing[$starterKey];
                continue;
            }

            $ids[$starterKey] = $this->templates->create($tenantId, [
                'name' => $spec['name'],
                'data' => self::templateBody(
                    $spec['name'],
                    $spec['heading'],
                    $spec['lines'],
                    array_map(
                        static fn (string $key): int => $blockIds[$key]
                            ?? throw new RuntimeException('Demo template declares an unknown block key: ' . $key),
                        $spec['blocks'],
                    ),
                ),
                // `tenant` rather than `system`: a system-scoped row skips the
                // `required_permission` gate entirely
                // ({@see DocumentAccessPolicy::canView()}), which would make BOTH
                // tagged templates in {@see templateDeclarations()} visible to
                // everybody and quietly delete half of what this fixture is for.
                'scope' => DocumentAccessPolicy::SCOPE_TENANT,
                'required_permission' => $spec['permission'],
                'is_system' => false,
                'starter_key' => $starterKey,
                'created_by' => $org->person($spec['author']),
                'owner_ou_id' => $org->ou($spec['ou']),
            ]);
        }

        return $ids;
    }

    /**
     * The template set as data, so the SEED and the REPORT read one declaration.
     *
     * Split out for exactly one reason: the summary line names how many of these
     * carry a `required_permission`, and the only way for that number to stay
     * true without anybody maintaining it is for it to be counted from the same
     * array the rows are written from. Static and side-effect-free — the `ou` and
     * `author` values are {@see DemoOrganisationSeeder} KEYS, resolved against a
     * {@see DemoOrganisation} at write time, so nothing here needs a database.
     *
     * @return array<string, array{name: string, ou: string, author: string, permission: ?string, heading: string, lines: list<string>, blocks: list<string>}>
     */
    private static function templateDeclarations(): array
    {
        return [
            self::TPL_FACULTY_CIRCULAR => [
                'name' => 'Demo faculty circular',
                'ou' => DemoOrganisationSeeder::OU_FACULTY,
                'author' => DemoOrganisationSeeder::DEAN,
                'permission' => null,
                'heading' => 'FACULTY CIRCULAR',
                'lines' => ['Ref: {{reference}}', 'Date: {{date}}', 'To: all departments'],
                'blocks' => [self::BLOCK_FACULTY_LETTERHEAD],
            ],
            self::TPL_CIVIL_WORKS_ORDER => [
                'name' => 'Demo civil works order',
                'ou' => DemoOrganisationSeeder::OU_DEPT_CIVIL,
                'author' => DemoOrganisationSeeder::CIVIL_HEAD,
                'permission' => null,
                'heading' => 'WORKS ORDER',
                'lines' => ['Order: {{order_no}}', 'Site: {{site}}', 'Requested by: {{requester}}'],
                // The only template that instances BOTH blocks, and the only one
                // the civil secretary can see among the safety notice's users.
                'blocks' => [self::BLOCK_FACULTY_LETTERHEAD, self::BLOCK_CIVIL_SAFETY],
            ],
            self::TPL_MECHANICAL_REPORT => [
                'name' => 'Demo mechanical test report',
                'ou' => DemoOrganisationSeeder::OU_DEPT_MECHANICAL,
                'author' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                'permission' => null,
                'heading' => 'TEST REPORT',
                'lines' => ['Specimen: {{specimen}}', 'Rig: {{rig}}', 'Operator: {{operator}}'],
                // The reference OUT OF REACH — see the blockDeclarations()
                // docblock. This is the row that makes the civil secretary's
                // "2 templates - 1 you cannot see" a real number.
                'blocks' => [self::BLOCK_FACULTY_LETTERHEAD, self::BLOCK_CIVIL_SAFETY],
            ],
            self::TPL_CIVIL_CONTRACT => [
                'name' => 'Demo civil contract (publish-tagged)',
                'ou' => DemoOrganisationSeeder::OU_DEPT_CIVIL,
                // Authored by the DEAN rather than by the head of the department
                // it is filed in, and that is deliberate: an author always
                // passes the PLACEMENT check on their own row but is NOT excused
                // its `required_permission`, so a template tagged
                // `documents:publish` and authored by somebody without that tag
                // would be invisible to its own author — true, documented, and a
                // baffling thing to meet in a demo.
                'author' => DemoOrganisationSeeder::DEAN,
                'permission' => CorePermissions::DOCUMENTS_PUBLISH,
                'heading' => 'CONTRACT',
                'lines' => ['Contract: {{contract_no}}', 'Counterparty: {{counterparty}}'],
                // No pointers, deliberately: a fixture in which EVERY template
                // instances something cannot show a management screen's
                // "used by nothing" state, and that state is the one that makes
                // a delete safe.
                'blocks' => [],
            ],
            self::TPL_CIVIL_REQUISITION => [
                'name' => 'Demo civil purchase requisition (drafters only)',
                'ou' => DemoOrganisationSeeder::OU_DEPT_CIVIL,
                'author' => DemoOrganisationSeeder::CIVIL_HEAD,
                // The one row in this fixture that separates two people standing
                // in the SAME unit: the civil secretary holds `documents:write`
                // and the civil technician beside her does not.
                'permission' => CorePermissions::DOCUMENTS_WRITE,
                'heading' => 'PURCHASE REQUISITION',
                'lines' => [
                    'Requisition: {{requisition_no}}',
                    'Department: {{department}}',
                    'Estimated cost: {{amount}}',
                ],
                'blocks' => [],
            ],
        ];
    }

    /**
     * Two blocks, placed like the templates, and INSTANCED by them (#1024).
     *
     * Blocks get the same placement treatment as templates because migration 117
     * gave both tables the column and {@see DocumentAccessPolicy} is applied to
     * both — so a demo that placed only templates would leave half of #1004
     * unverifiable by eye.
     *
     * @return array<string, int> starter_key => block id
     */
    private function seedBlocks(DemoOrganisation $org): array
    {
        $tenantId = $org->tenantId;
        $declared = self::blockDeclarations();

        // By KEY, in one read (#1013) — same change, same reason, as
        // {@see self::seedTemplates()}. Here it is load-bearing rather than
        // tidier: the templates seeded straight after this need these IDS to
        // point at, and before `starter_key` came back on a row there was no
        // supported way to get them.
        $existing = self::idsByStarterKey($this->blocks->listForTenant($tenantId));

        $ids = [];
        foreach ($declared as $starterKey => $spec) {
            if (isset($existing[$starterKey])) {
                $ids[$starterKey] = $existing[$starterKey];
                continue;
            }

            $ids[$starterKey] = $this->blocks->create($tenantId, [
                'name' => $spec['name'],
                // A block's `data` is a bare LIST of elements, not a template
                // body — see DocumentStarterSeeder, which passes its
                // `elements` straight through.
                'data' => [self::textElement(1, 0, 0, 180, 10, $spec['text'], 14, 'bold')],
                'scope' => DocumentAccessPolicy::SCOPE_TENANT,
                'is_system' => false,
                'starter_key' => $starterKey,
                'created_by' => $org->person($spec['author']),
                'owner_ou_id' => $org->ou($spec['ou']),
            ]);
        }

        return $ids;
    }

    /**
     * The two blocks as data, and WHO INSTANCES THEM — which is the half of this
     * fixture #1024 was filed about.
     *
     * The dataset used to place blocks and put a `blockInstance` in NOTHING: zero
     * pointers across every seeded template. So "what uses this block?" answered
     * 0 on every persona, the delete guard rendered its empty state, and the
     * "N templates, M you cannot see" disclosure showed a zero. Worse than
     * incomplete: blocks are POINTERS, not copies — an edit propagates to every
     * instance, which is the most important and most surprising thing about the
     * feature — and a demo with no instances quietly teaches the opposite, to
     * exactly the people looking at it to find out how the feature behaves.
     *
     * WHAT THE PLACEMENTS ARE CHOSEN TO SHOW
     * --------------------------------------
     *  - SHARED USAGE. The letterhead is instanced by templates at all THREE
     *    units, so a count is greater than one and an edit has somewhere to
     *    propagate to. One template ({@see self::TPL_CIVIL_CONTRACT}) instances
     *    nothing, so the used-by-nothing state is present too.
     *  - CROSS-UNIT INVISIBILITY. The civil site-safety notice is instanced by
     *    the civil works order AND by the mechanical test report. The civil
     *    secretary can see the block (it is filed in her department) and exactly
     *    one of its two users, so `GET /api/document-blocks/{id}/usage` answers
     *    her `total=2 hidden=1` and her row reads "2 templates, 1 you cannot
     *    see". That is the case that stops somebody editing a block believing
     *    they can see everything it affects, and without it the disclosure only
     *    ever renders a zero.
     *
     * A MECHANICAL TEMPLATE POINTING AT A CIVIL BLOCK IS THE POINT, NOT A SLIP.
     * Placement governs who can FIND a block in their library; it does not
     * govern who has already pointed at one, and nothing about an existing
     * pointer re-checks it. The state is reachable through the product exactly as
     * seeded — the dean reaches both units and can place it — and if the fixture
     * refused to contain it, the one number the disclosure exists to produce
     * would be unreachable in the demo.
     *
     * @return array<string, array{name: string, ou: string, author: string, text: string}>
     */
    private static function blockDeclarations(): array
    {
        return [
            self::BLOCK_FACULTY_LETTERHEAD => [
                'name' => 'Demo faculty letterhead',
                'ou' => DemoOrganisationSeeder::OU_FACULTY,
                'author' => DemoOrganisationSeeder::DEAN,
                'text' => '{{company_name}} — Faculty of Engineering',
            ],
            self::BLOCK_CIVIL_SAFETY => [
                'name' => 'Demo civil site-safety notice',
                'ou' => DemoOrganisationSeeder::OU_DEPT_CIVIL,
                'author' => DemoOrganisationSeeder::CIVIL_HEAD,
                'text' => 'Hard hats and hi-vis required beyond this point.',
            ],
        ];
    }

    /**
     * starter_key => id over rows a designer repository returned.
     *
     * One place, because both seeders above need it and because the thing it
     * relies on — `starter_key` surviving the designer repositories' row mapping
     * — is new (#1013). Rows without a key are anything a user made; they are not
     * demo rows and are skipped.
     *
     * @param list<array<string, mixed>> $rows
     * @return array<string, int>
     */
    private static function idsByStarterKey(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $key = $row['starter_key'] ?? null;
            if (is_string($key) && $key !== '') {
                $out[$key] = (int) $row['id'];
            }
        }

        return $out;
    }

    // ── route designs (#1027 / #1031 / #1056) ─────────────────────────

    /**
     * Three route templates — the DESIGNS a route can be applied from.
     *
     * TWO THINGS THIS FIXES AT ONCE.
     *
     * The first is the FIRST-RUN EXPERIENCE. The flow editor (#1027) is a canvas
     * for drawing these, and on an unseeded install there is nothing to open it
     * on: the list is empty, so the only way to see what a design looks like is to
     * draw one, which is the position somebody evaluating the product is least
     * able to be in. Three real designs make the editor openable on something,
     * and between them they use every graph shape it can draw — a branch, a
     * skip, a merge and a backwards edge.
     *
     * The second is PROVENANCE. A route issued from a design carries `template_id`
     * and a `template_name` snapshot (#1056), and until now no route in this
     * fixture carried either, so the record page's routing panel had no way to
     * show the difference between a route somebody composed by hand and one they
     * applied. Four of the documents below are applied from these.
     *
     * WHY THE DESIGNS ARE THE ONES THEY ARE — one graph shape each, and nothing
     * that repeats:
     *
     *   PURCHASE APPROVAL   a gate with BOTH edges drawn, whose approve edge
     *                       SKIPS the next ordinal. Two documents are applied from
     *                       it and answer it differently, which is the only way to
     *                       see that the two verdicts lead to two places (#1030).
     *   DRAFTING LOOP       a gate whose reject edge points BACKWARDS. A cycle,
     *                       and the commonest real approval design there is.
     *   TWO-DEPARTMENT      a stage with two arriving transitions whose rule is
     *   REVIEW              ACTOR-RELATIVE — the case the canvas labels wrongly
     *                       (#1058).
     *
     * SAVED THROUGH THE EDITOR'S OWN VALIDATOR, never straight into the
     * repository. {@see RouteTemplateGraph::validate()} is what `PUT /graph`
     * runs, so a design this seeder draws is by construction one the editor could
     * have saved — the same argument that puts every routing state through
     * {@see DocumentRouter}. A fixture that bypassed it could contain a canvas
     * nobody can edit, in the demo whose purpose is to show the canvas.
     *
     * IDEMPOTENT BY NAME, and the graph is written ONCE. A design that already
     * exists is reused and NOT redrawn, exactly as an existing template is not
     * rewritten: `replaceGraph()` would silently discard whatever an operator had
     * moved, renamed or rewired while looking at the demo, which is the one thing
     * a demo dataset must never do to somebody's work.
     *
     * @return array<string, int> design name => template id
     */
    private function seedRouteTemplates(DemoOrganisation $org): array
    {
        $tenantId = $org->tenantId;
        $maxSteps = $this->routingMaxSteps($tenantId);

        $ids = [];
        foreach (self::routeTemplateDeclarations($org) as $name => $design) {
            $existing = $this->routeTemplates->findByName($name, $tenantId);
            if ($existing !== null) {
                $ids[$name] = (int) $existing['id'];
                continue;
            }

            $id = $this->routeTemplates->create(
                $tenantId,
                $name,
                $design['description'],
                $org->person($design['author']),
            );

            $validated = $this->routeGraph->validate($design['stages'], $design['edges'], $maxSteps);
            $this->routeTemplates->replaceGraph($id, $tenantId, $validated['steps'], $validated['edges']);

            $ids[$name] = $id;
        }

        return $ids;
    }

    /**
     * The three designs as data, in the wire shape `PUT /graph` accepts.
     *
     * `canvas_x` / `canvas_y` are real coordinates rather than zeroes, and that is
     * not decoration: the editor lays out from them, and three designs stacked at
     * the origin would open as one illegible pile of nodes on top of each other —
     * which is a worse first-run experience than the empty canvas this exists to
     * replace. Laid out left to right at a node's width apart, with branches
     * separated vertically, so the SHAPE of each design reads before any label
     * does.
     *
     * @return array<string, array{description: string, author: string,
     *         stages: list<array<string, mixed>>, edges: list<array{from: int, to: int, verdict: string}>}>
     */
    private static function routeTemplateDeclarations(DemoOrganisation $org): array
    {
        $head = $org->role(DemoOrganisationSeeder::ROLE_HEAD);
        $dean = $org->role(DemoOrganisationSeeder::ROLE_DEAN);
        $technician = $org->role(DemoOrganisationSeeder::ROLE_TECHNICIAN);

        return [
            self::ROUTE_TPL_PURCHASE_APPROVAL => [
                'description' => 'Demo fixture: one gate, two destinations. Approval skips straight to '
                    . 'filing; refusal goes to the registry instead, which approval never reaches.',
                'author' => DemoOrganisationSeeder::DEAN,
                'stages' => [
                    self::stage(1, RoutingRuleRegistry::KIND_ROLE, ['role_id' => $dean], 'The dean decides', 0, 0, decision: true),
                    self::stage(2, RoutingRuleRegistry::KIND_EXPLICIT, [
                        'profile_ids' => [$org->person(DemoOrganisationSeeder::REGISTRY_OFFICER)],
                    ], 'Referred to the registry officer', 300, 180),
                    self::stage(3, RoutingRuleRegistry::KIND_EXPLICIT, [
                        'profile_ids' => [$org->person(DemoOrganisationSeeder::FACULTY_SECRETARY)],
                    ], 'Filed by the faculty secretary', 300, -180),
                ],
                // The approve edge JUMPS stage 2. Without it an approval would
                // fall through to the next ordinal and land on the referral
                // stage, which is the destination a refusal is supposed to have
                // — so the two verdicts would be indistinguishable on this
                // design and the pair of documents applied from it would prove
                // nothing.
                'edges' => [
                    ['from' => 1, 'to' => 3, 'verdict' => RouteVerdict::APPROVED],
                    ['from' => 1, 'to' => 2, 'verdict' => RouteVerdict::REJECTED],
                ],
            ],

            self::ROUTE_TPL_DRAFTING_LOOP => [
                'description' => 'Demo fixture: the rework loop. A refusal at the gate sends the document '
                    . 'BACK to the drafting stage, and the rule there is resolved again on arrival.',
                'author' => DemoOrganisationSeeder::CIVIL_HEAD,
                'stages' => [
                    // A NAMED person rather than a role, so that every lap
                    // returns to the same desk and the laps are comparable. An
                    // actor-relative rule here would resolve differently on lap
                    // two (relative to the dean, who rejected it, rather than to
                    // the head who raised it), which is interesting and is
                    // exactly what the two-department design already shows.
                    self::stage(1, RoutingRuleRegistry::KIND_EXPLICIT, [
                        'profile_ids' => [$org->person(DemoOrganisationSeeder::CIVIL_TECHNICIAN)],
                    ], 'The drafter', 0, 0),
                    self::stage(2, RoutingRuleRegistry::KIND_ROLE, ['role_id' => $dean], 'The dean decides', 300, 0, decision: true),
                ],
                'edges' => [
                    ['from' => 2, 'to' => 1, 'verdict' => RouteVerdict::REJECTED],
                ],
            ],

            self::ROUTE_TPL_TWO_DEPARTMENT_REVIEW => [
                'description' => 'Demo fixture: two departments review in parallel and both paths reach '
                    . 'the final stage. Its rule is actor-relative, so the two arrivals reach two '
                    . 'different people — see #1058.',
                'author' => DemoOrganisationSeeder::DEAN,
                'stages' => [
                    self::stage(1, RoutingRuleRegistry::KIND_ROLE, ['role_id' => $head], 'Both department heads', 0, 0),
                    self::stage(2, RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR, [
                        'role_id' => $technician,
                    ], 'A technician in the receiving department decides', 300, 0, decision: true),
                    self::stage(3, RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR, [
                        'role_id' => $head,
                    ], 'Back to the head to rework', 600, 180),
                    // THE MERGE. Two transitions arrive: stage 2's drawn approve
                    // edge and stage 3's positional fallthrough — which is what
                    // the editor counts when it decides to draw "Paths merge
                    // here — settles once" on a node. The rule is
                    // actor-relative, so that label is false here.
                    self::stage(4, RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR, [
                        'role_id' => $head,
                    ], 'Signed off by the head above whoever sent it', 900, 0, decision: true),
                ],
                'edges' => [
                    ['from' => 2, 'to' => 4, 'verdict' => RouteVerdict::APPROVED],
                    ['from' => 2, 'to' => 3, 'verdict' => RouteVerdict::REJECTED],
                ],
            ],
        ];
    }

    /**
     * One stage in the wire shape `PUT /graph` accepts.
     *
     * Every key is written out, including the nulls. The validator defaults the
     * absent ones, but a demo fixture is also a worked example of the payload, and
     * a reader who has to know which keys are optional to understand the shape is
     * reading a worse example.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function stage(
        int $position,
        string $kind,
        array $config,
        string $label,
        int $x,
        int $y,
        bool $decision = false,
        ?string $quorum = null,
        string $satisfiedBy = RouteSatisfaction::ACT,
    ): array {
        return [
            'position' => $position,
            'rule_kind' => $kind,
            'rule_config' => $config,
            'label' => $label,
            'decision' => $decision,
            'decision_quorum' => $quorum,
            'satisfied_by' => $satisfiedBy,
            'canvas_x' => $x,
            'canvas_y' => $y,
        ];
    }

    /**
     * The tenant's effective `documents.routing_max_steps`, which is the ceiling
     * {@see RouteTemplateGraph} applies.
     *
     * Read through the settings chain rather than passed a literal, because the
     * ceiling is per-tenant ?? global ?? registry default and a second copy of it
     * here would be free to disagree with the one the engine applies when a
     * document is finally issued from the design.
     */
    private function routingMaxSteps(int $tenantId): int
    {
        $effective = $this->settings->effective($tenantId);
        $configured = (int) ($effective[SettingsRegistry::DOCUMENTS_ROUTING_MAX_STEPS] ?? 0);

        return $configured > 0 ? $configured : 1;
    }

    // ── documents, and the routing states that distinguish them ──────────────

    /**
     * Issue every declared document, in order.
     *
     * @param array<string, int> $templateIds
     * @param array<string, int> $routeTemplateIds
     * @return array<string, int> title => document id
     */
    private function seedDocuments(DemoOrganisation $org, array $templateIds, array $routeTemplateIds): array
    {
        $ids = [];
        foreach (self::documentDeclarations($org) as $spec) {
            $ids[$spec['title']] = $this->seedOneDocument($org, $templateIds, $routeTemplateIds, $spec);
        }

        return $ids;
    }

    /**
     * Every demo document as data — one entry per routing state that looks
     * different from all the others.
     *
     * Read as a table of states rather than as a story. D2 is the pristine inbox
     * item, D3 is the finished one, D1 and D5 are the mixed fan-outs (which no
     * linear document can be — D1 at step 1 through the scoped rule, D5 at step 1
     * through the unscoped one), D4 is the one that leaves the unit it was raised
     * in AND is mixed at step 2, D6 belongs to no unit at all, and D7 is the
     * fan-out gate nobody has answered. D8 onwards are the OUTCOMES — see the
     * class docblock's second table. Nothing here is a second example of
     * something already seeded.
     *
     * SPLIT OUT OF {@see seedDocuments()} for the same reason
     * {@see templateDeclarations()} is split out of {@see seedTemplates()}: the
     * summary line names how many of these carry route-design provenance, and the
     * only way for that number to stay true without anybody maintaining it is for
     * it to be counted from the array the rows are written from.
     *
     * Takes the {@see DemoOrganisation} rather than being pure, because a routing
     * rule's config names ROLE and PROFILE IDS and those do not exist until the
     * organisation is seeded. It still writes nothing and reads no database.
     *
     * @return list<array{
     *     title: string, template: string, raiser: string, route: ?string,
     *     route_template?: string,
     *     steps: list<array{rule_kind: string, rule_config: array<string, mixed>, label: string,
     *                       decision?: bool, decision_quorum?: string, satisfied_by?: string,
     *                       on_approved?: int, on_rejected?: int}>,
     *     acts: list<array{actor: string, action: string, note: ?string, verdict?: string}>,
     *     corrections: int, captions: list<string>
     * }>
     */
    private static function documentDeclarations(DemoOrganisation $org): array
    {
        $head = $org->role(DemoOrganisationSeeder::ROLE_HEAD);
        $dean = $org->role(DemoOrganisationSeeder::ROLE_DEAN);
        $technician = $org->role(DemoOrganisationSeeder::ROLE_TECHNICIAN);
        $secretary = $org->role(DemoOrganisationSeeder::ROLE_SECRETARY);

        return [
            // D1. The MIXED FAN-OUT, and the reason #1000 renders per-step counts
            // instead of one progress bar. `role_below_actor` resolves relative
            // to whoever reaches it, so step 1 fans out to both department heads
            // from the dean's faculty; ONE of them forwards, which opens step 2
            // for their own department's technician while the other head's item
            // stays open. A single bar over this document would have to pick a
            // number, and every number it could pick is wrong.
            [
                'title' => 'Demo semester circular 2026/1',
                'template' => self::TPL_FACULTY_CIRCULAR,
                'raiser' => DemoOrganisationSeeder::DEAN,
                'route' => 'Circulate to departments, then to technicians',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                        'rule_config' => ['role_id' => $head],
                        'label' => 'Department heads below me',
                    ],
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                        'rule_config' => ['role_id' => $technician],
                        'label' => 'Technicians below the receiving head',
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_HEAD,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Passing to the civil technicians for action.',
                    ],
                    // Mechanical's head deliberately does NOT act. The unacted
                    // half is the whole point of the fixture; an "obviously
                    // incomplete" demo dataset is the one that shows the
                    // behaviour.
                ],
                'corrections' => 0,
                'captions' => ['Circulated to every department.', 'Demo fixture — not a real circular.'],
            ],

            // D2. AWAITING ONE NAMED PERSON, AND ASKING THEM TO DECIDE. A
            // single-step `role` route with one holder: exactly one open
            // recipient row, no trail beyond `issued`. This is the folder that
            // must not be confusable with an empty one.
            //
            // It is a DECISION step (#1014), and it was not until #1041 — which
            // made this fixture the only thing in the demo that lied. The route
            // is called "Dean's approval" and the artifact is captioned
            // "Awaiting approval", and what the dean was actually offered was an
            // acknowledgement: "I saw this". A route that says approval and
            // records circulation is precisely the confusion the verdict column
            // exists to remove, and a demo that shows it is the version of the
            // problem that teaches it to everybody who opens the demo.
            //
            // Deliberately left UNANSWERED, unlike D3 and D6. A decision anybody
            // can walk up to and answer is the only way the acting half is
            // visible at all: sign in as the dean and there are two buttons that
            // exist nowhere else in this dataset.
            [
                'title' => 'Demo equipment purchase request',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_HEAD,
                'route' => "Dean's approval",
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $dean],
                        'label' => 'The dean',
                        // One holder, so `all`, `any` and `majority` are the same
                        // rule here and no quorum is named — which is also why
                        // the panel shows no quorum block for it. The fan-out
                        // case where they differ is D7.
                        'decision' => true,
                    ],
                ],
                'acts' => [],
                'corrections' => 0,
                'captions' => ['Awaiting the dean’s decision.', 'Demo fixture.'],
            ],

            // D3. ALREADY ACTED ON, plus the append-only correction, plus TWO
            // artifacts. The acknowledgement closes the only open row, so this
            // document is in every "acted on" folder and no "awaiting" one; the
            // `noted` event afterwards adds a row without changing what
            // happened; and the second artifact is what makes #986's "version 1
            // of 2" and its superseded warning something you can look at.
            [
                'title' => 'Demo safety inspection report',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_TECHNICIAN,
                'route' => 'Report to the dean',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $dean],
                        'label' => 'The dean',
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'note' => 'Read and filed.',
                    ],
                    [
                        // A correction AFTER the acknowledgement, by somebody
                        // whose row is closed — which `noted` allows and the
                        // other three verbs do not. The original stays.
                        'actor' => DemoOrganisationSeeder::CIVIL_TECHNICIAN,
                        'action' => RouteAction::NOTED,
                        'note' => 'Correction: the inspection date in the first version was wrong; see version 2.',
                    ],
                ],
                'corrections' => 1,
                'captions' => ['Site inspection carried out.', 'Demo fixture.'],
            ],

            // D4. RAISED IN ONE UNIT, PASSED THROUGH ANOTHER. Raised in Civil
            // Engineering, forwarded by the dean at the Faculty — so the Faculty
            // appears in this document's trail without ever being its origin.
            // That is precisely the difference between "raised by my unit" and
            // "passed through my unit", which on a flat tenant are the same
            // query.
            [
                'title' => 'Demo annual budget submission',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_HEAD,
                'route' => 'Up to the dean, then out to the secretariat',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $dean],
                        'label' => 'The dean',
                    ],
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                        'rule_config' => ['role_id' => $secretary],
                        'label' => 'Secretariat below the dean',
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Approved at faculty level; distribute for filing.',
                    ],
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_SECRETARY,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'note' => 'Filed in the department copy.',
                    ],
                    // The faculty secretary's step-2 item stays open, so this
                    // document is ALSO a mixed fan-out — at step 2 rather than
                    // step 1, which is a different shape again in #1000's
                    // per-step rendering.
                ],
                'corrections' => 0,
                'captions' => ['Departmental budget for the coming year.', 'Demo fixture.'],
            ],

            // D5. The UNSCOPED rule beside D1's scoped one. `role` reaches every
            // technician in the tenant regardless of where they sit, so its
            // distribution spans two departments and the trail event records NO
            // single destination — `to_ou_id` is null, because naming one of the
            // two units would make "passed through my unit" report a unit
            // nobody chose.
            [
                'title' => 'Demo lab equipment calibration',
                'template' => self::TPL_MECHANICAL_REPORT,
                'raiser' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                'route' => 'All technicians, wherever they sit',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $technician],
                        'label' => 'Every technician in the tenant',
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::MECHANICAL_TECHNICIAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'note' => 'Calibration scheduled.',
                    ],
                ],
                'corrections' => 0,
                'captions' => ['Annual calibration round.', 'Demo fixture.'],
            ],

            // D6. RAISED FROM NO UNIT AT ALL. The registry officer holds an
            // active membership naming no unit, so this document's
            // `origin_ou_id` is NULL — and that is what makes the three unit
            // folders three DIFFERENT numbers at the top of the tree instead of
            // two. Without it, every document originates somewhere inside the
            // faculty, so "everything below my unit" and "passed through my
            // unit" return the same five rows for the dean, and a reader would
            // reasonably conclude they are the same query. Here they are not:
            // this document is below nothing and passes through the faculty.
            //
            // It is also the state the organizer answers with `unanchored`
            // rather than an empty page (#951): "raised by my unit" cannot be
            // computed for somebody who has no unit, which is a different fact
            // from computing it and finding nothing.
            [
                'title' => 'Demo tenant-wide registry notice',
                'template' => self::TPL_FACULTY_CIRCULAR,
                'raiser' => DemoOrganisationSeeder::REGISTRY_OFFICER,
                'route' => 'For the dean to note',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $dean],
                        'label' => 'The dean',
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'note' => 'Noted.',
                    ],
                ],
                'corrections' => 0,
                'captions' => ['Issued centrally, from no unit.', 'Demo fixture.'],
            ],

            // D7. A DECISION STEP THAT FANS OUT, which is the only shape where a
            // quorum means anything (#1014, #1041).
            //
            // `all`, `any` and `majority` are the SAME rule for the single
            // approver of D2 — and for the "the dean signs off" step that is most
            // real approval steps. They differ exactly here, where one node is a
            // TYPE of person and resolves to several: the standing requirement
            // behind the whole feature is "you can put nodes for all 1000
            // instructors but you can say instructors in one node", and this is
            // that node with two people in it instead of a thousand.
            //
            // Left with NOTHING recorded on purpose. The state worth being able
            // to walk up to is the one between the first answer and the last:
            // sign in as one technician and approve, and the answer is "your
            // approval is recorded, this step is not approved yet"; sign in as
            // the other and approve, and only then has the step approved. Seeding
            // the first approval would hand somebody the second half and hide the
            // half that is easy to get wrong.
            [
                'title' => 'Demo workshop safety sign-off',
                'template' => self::TPL_MECHANICAL_REPORT,
                'raiser' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                'route' => 'Both technicians must sign off',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $technician],
                        'label' => 'Every technician in the tenant',
                        'decision' => true,
                    ],
                ],
                'acts' => [],
                'corrections' => 0,
                'captions' => [
                    'Needs every technician’s approval before the workshop reopens.',
                    'Demo fixture.',
                ],
            ],
            // D8. A QUORUM STOPPED PART WAY — the state #1041's answer is about,
            // and the one that could not be looked at.
            //
            // D7 leaves a fan-out gate with NOTHING recorded, so somebody can
            // walk up and answer it. That shows the FIRST act and the LAST. It
            // cannot show the state between them, and reaching that state took
            // three people signing in and answering in order — which is exactly
            // the cost this whole fixture exists to remove.
            //
            // Three approvers, quorum `all`, and TWO approvals already recorded.
            // The step's conclusion is still null. `act()` returns `decided` —
            // what the STEP concluded — rather than the verdict the caller just
            // gave, so both people who have answered were told "your approval is
            // recorded, this step is not approved yet", and the document has not
            // moved anywhere.
            //
            // WHY THREE AND NOT TWO. In a cohort of two with one approval in, the
            // one remaining approver is also the LAST approver: the intermediate
            // state and the settled state are one act apart, and a reader cannot
            // tell "not yet" from "not yet, and I am the only reason". Three
            // separates them — two people answered and were both told the step
            // is still open, which is the sentence that surprises people.
            //
            // NAMED PEOPLE (`explicit`), the only such rule in this fixture, and
            // not because a committee is better modelled that way. No demo role
            // has three holders, and adding a third technician or a third
            // secretary would silently change what D4, D5 and D7 fan out to —
            // the same argument {@see DemoOrganisationSeeder::ROLE_REGISTRY_OFFICER}
            // makes for giving the unaffiliated person a role of her own. It has
            // the side benefit of putting the third core rule kind in the demo.
            [
                'title' => 'Demo research grant sign-off',
                'template' => self::TPL_MECHANICAL_REPORT,
                'raiser' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                'route' => 'Grant committee: all three must approve',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                        'rule_config' => [
                            'profile_ids' => [
                                $org->person(DemoOrganisationSeeder::DEAN),
                                $org->person(DemoOrganisationSeeder::CIVIL_HEAD),
                                $org->person(DemoOrganisationSeeder::FACULTY_SECRETARY),
                            ],
                        ],
                        'label' => 'The grant committee (three named members)',
                        'decision' => true,
                        // Named ON THE STEP rather than left to the settings
                        // chain, so the fixture shows the same thing in a tenant
                        // whose default is `any` — under which this route would
                        // have concluded on the dean's approval and the state
                        // this document exists for would not exist.
                        'decision_quorum' => RouteQuorum::ALL,
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::APPROVED,
                        'note' => 'Approved against the faculty research line.',
                    ],
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_HEAD,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::APPROVED,
                        'note' => 'Civil Engineering has no objection.',
                    ],
                    // The faculty secretary has NOT answered, and that is the
                    // fixture. Sign in as her and one act settles the step.
                ],
                'corrections' => 0,
                'captions' => [
                    'Two of three approvals recorded; the step is not approved yet.',
                    'Demo fixture.',
                ],
            ],

            // D9 and D10. THE SAME GATE, THE SAME DESIGN, TWO DESTINATIONS.
            //
            // #1030's claim is that an approval and a rejection send a document
            // to DIFFERENT places, and that a rejection never inherits the
            // approval's destination. A single document can only take one branch,
            // so the claim is not checkable from one — and two documents on two
            // different designs would leave a reader wondering which of the
            // differences did the work.
            //
            // So: ONE design, two documents, one answer each, and the answer is
            // the only variable. The approve edge jumps over stage 2 entirely and
            // lands on filing; the reject edge lands on stage 2, with the registry
            // officer, which an approval never reaches. Open the two record pages
            // side by side and the whole of #1030 is one comparison.
            [
                'title' => 'Demo stationery purchase order',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_HEAD,
                'route_template' => self::ROUTE_TPL_PURCHASE_APPROVAL,
                // Null because the design supplies both the steps and the route's
                // title — see {@see seedOneDocument()} for why the title is taken
                // off the template row rather than typed a second time here.
                'route' => null,
                'steps' => [],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::APPROVED,
                        'note' => 'Approved. Send it straight to filing.',
                    ],
                ],
                'corrections' => 0,
                'captions' => ['Approved at the gate; it skipped the referral stage.', 'Demo fixture.'],
            ],

            [
                'title' => 'Demo overtime claim',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_HEAD,
                'route_template' => self::ROUTE_TPL_PURCHASE_APPROVAL,
                'route' => null,
                'steps' => [],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::REJECTED,
                        'note' => 'Not approved at faculty level; refer it to the registry.',
                    ],
                ],
                'corrections' => 0,
                'captions' => ['Rejected at the gate; it went to the registry, not to filing.', 'Demo fixture.'],
            ],

            // D11. A REJECTION AT A GATE WITH NO REJECT EDGE — the chain ENDS.
            //
            // The other half of #1030, and the half that is invisible without a
            // seeded example, because what it produces is an ABSENCE: a route
            // whose second step never opened, no open recipient row anywhere, and
            // a document that has simply stopped. Every other settled document in
            // this fixture (D3, D5, D6) got there by being acknowledged at its
            // LAST step, so "nothing open" already had a reading — "it finished".
            // This is the other reading, and the two are told apart only by the
            // verdict on the trail and by the step sitting there with nobody at
            // it.
            //
            // Hand-composed rather than applied from a design, deliberately: an
            // ABSENT edge is the one piece of configuration a canvas cannot show,
            // and burying it in a list of three designs would hide the very thing
            // the document is for.
            [
                'title' => 'Demo conference travel request',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_HEAD,
                'route' => 'Dean approves, then the secretariat files it',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $dean],
                        'label' => 'The dean',
                        'decision' => true,
                        // No `on_rejected`. An approval would have fallen through
                        // to step 2 — that fallthrough is what makes a gate usable
                        // in a plain linear route — and a rejection gets no
                        // fallback at all.
                    ],
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                        'rule_config' => [
                            'profile_ids' => [$org->person(DemoOrganisationSeeder::FACULTY_SECRETARY)],
                        ],
                        'label' => 'The faculty secretary files it',
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::REJECTED,
                        'note' => 'Not approved. There is no budget this quarter.',
                    ],
                ],
                'corrections' => 0,
                'captions' => ['Refused at the gate. Step 2 was never opened.', 'Demo fixture.'],
            ],

            // D12. ROUND THE REWORK LOOP TWICE — and the reason #1037 is filed.
            //
            // A reject edge pointing BACKWARDS is the commonest real approval
            // design ("send it back to be fixed"), it is a cycle, and the rule at
            // the stage it returns to is re-resolved fresh on every arrival. All
            // of that was true, tested, and absent from the demo.
            //
            // Seeded on its THIRD arrival at the drafting stage, with two
            // rejections behind it — because ONE lap is indistinguishable from a
            // document that merely has an open item, and because two laps is the
            // cheapest way to turn #1037's finding into something a person can
            // look at rather than read. Open this record page: the third lap is
            // rendered exactly like the first. One open row, no lap number,
            // nothing that says "returned twice". The only place the laps exist at
            // all is as repetition in the trail, which IS the report.
            [
                'title' => 'Demo laboratory refurbishment plan',
                'template' => self::TPL_CIVIL_WORKS_ORDER,
                'raiser' => DemoOrganisationSeeder::CIVIL_HEAD,
                'route_template' => self::ROUTE_TPL_DRAFTING_LOOP,
                'route' => null,
                'steps' => [],
                'acts' => [
                    // Lap one.
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_TECHNICIAN,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'First draft, submitted for approval.',
                    ],
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::REJECTED,
                        'note' => 'The costings are missing. Back to the drafter.',
                    ],
                    // Lap two — a NEW recipient row for the same person, because
                    // un-closing the first would erase the fact that she acted on
                    // lap one.
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_TECHNICIAN,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Costings added; resubmitted.',
                    ],
                    [
                        'actor' => DemoOrganisationSeeder::DEAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::REJECTED,
                        'note' => 'Still no ventilation figures. Back again.',
                    ],
                    // …and it is now sitting on lap three, open at the drafting
                    // stage, looking exactly like lap one.
                ],
                'corrections' => 0,
                'captions' => ['Rejected twice; back with the drafter for a third time.', 'Demo fixture.'],
            ],

            // D13. THE MERGE THE CANVAS DESCRIBES WRONGLY (#1058).
            //
            // `packages/ui`'s flow editor draws "Paths merge here — settles once"
            // on any stage with more than one arriving transition. The claim is
            // TRUE when the arrivals resolve to the same people — the second
            // arrival de-duplicates against an item they already hold — and FALSE
            // when the stage's rule is ACTOR-RELATIVE, which is the rule kind a
            // merge is most likely to carry, because an actor-relative rule
            // upstream is what put the two chains there in the first place.
            //
            // Both cases are pinned by
            // {@see \Tests\Core\Document\RouteTemplate\RouteTemplateInstantiationRealEngineTest}
            // and NEITHER was seeded, so a wrong label on a canvas was visible
            // only to somebody reading a test. This document is the second case:
            // two chains reach stage 4, resolve to two DIFFERENT heads, nothing
            // de-duplicates, and the stage ends up holding two independent
            // cohorts — two open items at a node the editor has just said settles
            // once.
            //
            // Left with BOTH stage-4 items open. Answering them settles the stage
            // TWICE, which is the finding; seeding that instead would spend the
            // walk-up and leave a finished document, and two open rows at a merge
            // are already the contradiction. Reported, not worked around —
            // nothing here is bent to hide it.
            [
                'title' => 'Demo cross-department equipment review',
                'template' => self::TPL_FACULTY_CIRCULAR,
                'raiser' => DemoOrganisationSeeder::DEAN,
                'route_template' => self::ROUTE_TPL_TWO_DEPARTMENT_REVIEW,
                'route' => null,
                'steps' => [],
                'acts' => [
                    // Both departments take it up, so the two chains are real.
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_HEAD,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Passing to Civil for a technical opinion.',
                    ],
                    [
                        'actor' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Passing to Mechanical for a technical opinion.',
                    ],
                    // Chain A approves and takes the drawn edge straight to the
                    // merge, where the rule resolves below HER unit — so it
                    // reaches the civil head.
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_TECHNICIAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::APPROVED,
                        'note' => 'Civil is satisfied with the specification.',
                    ],
                    // Chain B rejects and goes to the rework stage…
                    [
                        'actor' => DemoOrganisationSeeder::MECHANICAL_TECHNICIAN,
                        'action' => RouteAction::ACKNOWLEDGED,
                        'verdict' => RouteVerdict::REJECTED,
                        'note' => 'Mechanical wants the load figures reworked.',
                    ],
                    // …and arrives at the SAME merge stage by the other path,
                    // where the same rule resolves below HIS unit and reaches
                    // somebody else entirely. Nothing de-duplicates.
                    [
                        'actor' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Reworked; sending it on to the review stage.',
                    ],
                ],
                'corrections' => 0,
                'captions' => [
                    'Two chains arrived at one review stage and did not merge.',
                    'Demo fixture.',
                ],
            ],

            // D14. A STAGE SATISFIED BY DELIVERY (#1054) — people who are TOLD.
            //
            // The motivating shape, and the one that makes the feature
            // comprehensible at a glance: circulate to the heads, one of them
            // passes it on, and the last stage tells every technician. Their
            // recipient rows are opened so that WHO WAS TOLD is recorded as
            // durably as who acted, and closed by the very event that opened them
            // — so they appear in the document's recipient list and in nobody's
            // "Awaiting me", and in nobody's "Acted on by me" either, because they
            // did not act.
            //
            // THE STEP IS THE ONLY VARIABLE, DELIBERATELY. Its rule is the same
            // unscoped `role` fan-out D5 uses, spanning the same two units and
            // reaching the same two people. D5 ASKS them and one of its rows is
            // still open; this one TELLS them and neither row ever was. Put the
            // two record pages side by side and the difference is `satisfied_by`
            // and nothing else.
            //
            // NO ACT IS DECLARED AT THE DELIVERY STEP, and none could be: nobody
            // there holds an open item, so `act()` refuses. That refusal is this
            // seeder's safety property working exactly as intended — see the
            // class docblock on why every act goes through the engine — and not
            // an obstacle to route around.
            //
            // THE POSITIVE CONTROL is the mechanical head, whose step-1 item is
            // still open. "The technicians are awaiting nothing" is a claim an
            // empty folder satisfies for many reasons — a route that never
            // issued, a rule that resolved to nobody, a broken folder predicate —
            // and only somebody on the SAME document who IS still waiting rules
            // all of them out.
            [
                'title' => 'Demo workshop reopening notice',
                'template' => self::TPL_FACULTY_CIRCULAR,
                'raiser' => DemoOrganisationSeeder::DEAN,
                'route' => 'To the departments, then tell every technician',
                'steps' => [
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $head],
                        'label' => 'Both department heads',
                    ],
                    [
                        'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                        'rule_config' => ['role_id' => $technician],
                        'label' => 'Every technician in the tenant — for information',
                        'satisfied_by' => RouteSatisfaction::DELIVERY,
                    ],
                ],
                'acts' => [
                    [
                        'actor' => DemoOrganisationSeeder::CIVIL_HEAD,
                        'action' => RouteAction::FORWARDED,
                        'note' => 'Noted; passing it on to the workshops.',
                    ],
                    // The mechanical head has NOT acted: the positive control.
                ],
                'corrections' => 0,
                'captions' => [
                    'The technicians were told. Nobody is waiting on them.',
                    'Demo fixture.',
                ],
            ],
        ];
    }

    /**
     * Issue one document, its artifacts, its route and its acts — all inside one
     * transaction, so the title check that guards it is a complete answer.
     *
     * @param array<string, int> $templateIds
     * @param array<string, int> $routeTemplateIds
     * @param array{
     *     title: string, template: string, raiser: string, route: ?string,
     *     route_template?: string,
     *     steps: list<array{rule_kind: string, rule_config: array<string, mixed>, label: string,
     *                       decision?: bool, decision_quorum?: string, satisfied_by?: string,
     *                       on_approved?: int, on_rejected?: int}>,
     *     acts: list<array{actor: string, action: string, note: ?string, verdict?: string}>,
     *     corrections: int, captions: list<string>
     * } $spec
     */
    private function seedOneDocument(
        DemoOrganisation $org,
        array $templateIds,
        array $routeTemplateIds,
        array $spec,
    ): int {
        $tenantId = $org->tenantId;

        $existing = $this->findDocumentByTitle($tenantId, $spec['title']);
        if ($existing !== null) {
            return $existing;
        }

        if (!isset($templateIds[$spec['template']])) {
            throw new RuntimeException("Demo document '{$spec['title']}' names an unseeded template.");
        }
        $template = $this->templates->findById($templateIds[$spec['template']], $tenantId);
        if ($template === null) {
            throw new RuntimeException("Demo template '{$spec['template']}' could not be read back.");
        }

        $raiser = $org->person($spec['raiser']);

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            // The real issuer: it stamps `origin_ou_id` from the raiser's primary
            // membership unit ({@see \Whity\Core\Ou\PrimaryMembershipOu}), writes
            // the object through the configured storage driver and records the
            // checksum. Choosing an origin unit here instead would let a
            // document's origin and its own issue event's `from_ou_id` disagree
            // — for the same person, in the same fixture.
            $issued = $this->issuer->issue(
                $tenantId,
                $raiser,
                $template,
                $spec['title'],
                DemoPdf::page($spec['title'], $spec['captions']),
            );
            $document = $issued['document'];

            for ($version = 2; $version <= $spec['corrections'] + 1; $version++) {
                $this->issuer->appendArtifact(
                    $tenantId,
                    $raiser,
                    $document,
                    DemoPdf::page(
                        $spec['title'],
                        array_merge($spec['captions'], ['Corrected version ' . $version . '.'])
                    ),
                );
            }

            $route = $this->issueRoute($org, $routeTemplateIds, $document, $spec);

            foreach ($spec['acts'] as $act) {
                // Every act goes through the engine, which means an act this
                // fixture got wrong is a refusal rather than a row. That is the
                // check that makes the dataset trustworthy: `act()` will not
                // forward from the last step, will not return from the first,
                // will not forward from a DECISION step (#1014), will not accept
                // a verdict on a circulation step, and will not accept an actor
                // holding no open item — which is why no act is declared at a
                // delivery stage (#1054), where every row is closed the instant
                // it exists.
                $this->router->act(
                    $tenantId,
                    $org->person($act['actor']),
                    $route['route'],
                    $act['action'],
                    $act['note'],
                    // Null on every circulation act, which is what `verdict IS
                    // NULL` means: "this act said nothing about approval". It is
                    // never a third verdict and never a refusal.
                    $act['verdict'] ?? null,
                );
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return (int) $document['id'];
    }

    /**
     * Issue the route for one document — either from a stored DESIGN (#1056) or
     * from steps the declaration composed itself.
     *
     * WHY BOTH PATHS EXIST IN A FIXTURE. Applying a design is not a shorthand for
     * composing the same steps: it writes `template_id` and a `template_name`
     * SNAPSHOT onto the route, and those two columns are the whole of #1056's
     * provenance. A demo with no route carrying them cannot show the difference
     * between a route somebody composed and a route somebody APPLIED, which is
     * the question the record page's routing panel answers.
     *
     * THE TITLE COMES OFF THE TEMPLATE ROW, not out of the declaration, because
     * that is what {@see \Whity\Api\DocumentRoutingApiHandler::createFromTemplate()}
     * does. A fixture that typed the title separately could disagree with the
     * design it names — rename the design and the demo would quietly keep
     * claiming the old name in one place and the new one in the other, which is
     * exactly the drift the snapshot column exists to make legible rather than to
     * create.
     *
     * THE CONVERSION IS THE REAL ONE. {@see RouteTemplateInstantiation::toRouteSteps()}
     * is called here, not a private copy of it, so a design this seeder draws that
     * the converter would refuse fails LOUDLY at seed time instead of becoming a
     * demo route the product could not have produced.
     *
     * @param array<string, int>   $routeTemplateIds
     * @param array<string, mixed> $document
     * @param array<string, mixed> $spec
     * @return array{route: array<string, mixed>, steps: list<array<string, mixed>>,
     *               edges: list<array<string, mixed>>, resolved: int, delivered: int}
     */
    private function issueRoute(
        DemoOrganisation $org,
        array $routeTemplateIds,
        array $document,
        array $spec,
    ): array {
        $tenantId = $org->tenantId;
        $raiser = $org->person($spec['raiser']);
        $designName = $spec['route_template'] ?? null;

        if ($designName === null) {
            $title = $spec['route'] ?? null;
            if (!is_string($title) || trim($title) === '') {
                throw new RuntimeException(
                    "Demo document '{$spec['title']}' names no route design and no route title."
                );
            }

            /** @var list<array<string, mixed>> $steps */
            $steps = $spec['steps'];

            return $this->router->issue($tenantId, $raiser, $document, $title, $steps);
        }

        if ($spec['steps'] !== []) {
            // Refused rather than ignored. A declaration carrying both would have
            // one of them silently discarded, and the reader of this file would
            // have no way to know which — the same argument the engine makes for
            // refusing a quorum on a non-decision step.
            throw new RuntimeException(
                "Demo document '{$spec['title']}' declares both a route design and its own steps. "
                . 'It can have one or the other.'
            );
        }

        if (!isset($routeTemplateIds[$designName])) {
            throw new RuntimeException("Demo document '{$spec['title']}' names an unseeded route design.");
        }

        $designId = $routeTemplateIds[$designName];
        $design = $this->routeTemplates->findById($designId, $tenantId);
        if ($design === null) {
            throw new RuntimeException("Demo route design '{$designName}' could not be read back.");
        }

        return $this->router->issue(
            $tenantId,
            $raiser,
            $document,
            (string) $design['name'],
            RouteTemplateInstantiation::toRouteSteps(
                $this->routeTemplates->stepsFor($designId, $tenantId),
                $this->routeTemplates->edgesFor($designId, $tenantId),
            ),
            $designId,
            (string) $design['name'],
        );
    }

    // ── collections, and why `starred` is one of them ─────────────────────────

    /**
     * A starred collection and a custom one for the dean, plus a starred one for
     * a department secretary.
     *
     * Both halves are needed to show the distinction migration 114 argues for:
     * starring is a collection carrying a well-known `system_key`, not a second
     * concept with its own table. With only a star, a reader would assume a
     * `document_stars` table; with only a custom collection, they would not know
     * the star is one.
     *
     * The starred collection is created the way the API creates it — look it up
     * by its key, create it if the person has none — rather than by asserting an
     * id, because it is created LAZILY on the first star and its absence is the
     * normal state of a new account.
     *
     * @param array<string, int> $documentIds
     * @return list<string>
     */
    private function seedCollections(DemoOrganisation $org, array $documentIds): array
    {
        $tenantId = $org->tenantId;
        $dean = $org->person(DemoOrganisationSeeder::DEAN);
        $civilSecretary = $org->person(DemoOrganisationSeeder::CIVIL_SECRETARY);

        $deanStarred = $this->ensureStarred($tenantId, $dean);
        foreach (['Demo semester circular 2026/1', 'Demo equipment purchase request'] as $title) {
            $this->collections->addItem($tenantId, $deanStarred, $documentIds[$title]);
        }

        $budgetFile = $this->ensureCollection($tenantId, $dean, self::COLLECTION_BUDGET_FILE);
        $this->collections->addItem($tenantId, $budgetFile, $documentIds['Demo annual budget submission']);

        $secretaryStarred = $this->ensureStarred($tenantId, $civilSecretary);
        $this->collections->addItem(
            $tenantId,
            $secretaryStarred,
            $documentIds['Demo annual budget submission']
        );

        return [
            sprintf(
                'Collections: %s has a "%s" collection (1) and 2 starred; %s has 1 starred. '
                . 'Starring IS a collection (system_key "%s"), which is why both are seeded.',
                DemoOrganisationSeeder::DEAN,
                self::COLLECTION_BUDGET_FILE,
                DemoOrganisationSeeder::CIVIL_SECRETARY,
                DocumentCollectionRepository::STARRED_KEY,
            ),
        ];
    }

    private function ensureStarred(int $tenantId, int $profileId): int
    {
        $found = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $profileId
        );
        if ($found !== null) {
            return (int) $found['id'];
        }

        return $this->collections->create(
            $tenantId,
            $profileId,
            DocumentCollectionRepository::STARRED_DEFAULT_NAME,
            DocumentCollectionRepository::STARRED_KEY
        );
    }

    private function ensureCollection(int $tenantId, int $profileId, string $name): int
    {
        foreach ($this->collections->listOwned($tenantId, $profileId) as $collection) {
            if ((string) $collection['name'] === $name) {
                return (int) $collection['id'];
            }
        }

        return $this->collections->create($tenantId, $profileId, $name);
    }

    // ── verification codes (#1036 / #1051) ─────────────────────────────

    /**
     * One LIVE verification code, and one that has been REVOKED.
     *
     * WHY BOTH, AND WHY ONE OF THEM PROVES NOTHING ALONE. The public verification
     * page answers a revoked code and an unknown one with the SAME body by
     * default — deliberately, so that a stranger probing tokens cannot learn
     * which strings name a real document ({@see VerificationPresenter::refusal()}
     * calls that the oracle question and decides it there). A fixture with only a
     * revoked code therefore renders exactly like a fixture with no code at all
     * and a typo in the URL. The LIVE one is what gives the refusal something to
     * be different from.
     *
     * THEY GO ON DOCUMENTS THAT ALREADY EXIST. A code is an ATTRIBUTE of a
     * document, not a routing state, so inventing two more documents to carry one
     * would break the rule the rest of this file follows — nothing is seeded that
     * would look like something already seeded.
     *
     *   LIVE      the registry notice: issued centrally, acknowledged, finished.
     *             The shape of thing that gets printed and handed over, which is
     *             the only reason a verification code exists.
     *   REVOKED   the safety inspection report, which is also the one document
     *             carrying TWO artifact versions. "The printing that went out
     *             with version 1 has been withdrawn" is the story its own
     *             correction already tells, so the two fixtures explain each
     *             other instead of each needing their own explanation.
     *
     * THE REVOKED DOCUMENT IS LEFT WITH NO LIVE CODE, which is the state
     * {@see DocumentQrService::revoke()} produces and documents: withdrawing a
     * code and issuing a new one are different decisions, and folding them
     * together would mean an operator who withdrew a forgery's code immediately
     * published one that verifies.
     *
     * BOTH URLS ARE REPORTED, and that is not a convenience. The record panel
     * publishes the LIVE code's URL only ({@see \Whity\Api\DocumentQrApiHandler::show()}
     * returns `token: null` when none is in force), so there is no screen in the
     * product from which a revoked code can be scanned. Printing it here is
     * currently the only way the revoked half of this fixture can be reached at
     * all. See the seeding report for the finding.
     *
     * @param array<string, int> $documentIds
     * @return list<string>
     */
    private function seedQrCodes(DemoOrganisation $org, array $documentIds): array
    {
        $tenantId = $org->tenantId;

        if (!$this->qr->isConfigured()) {
            // An INSTANCE fault, not a tenant preference, and reported as one:
            // sending somebody to the tenant's settings page for a missing
            // APP_URL is the exact confusion `isConfigured()` exists to prevent.
            return [
                'Verification codes SKIPPED: this instance has never been told its own public address '
                . '(APP_URL), so a minted code would encode a URL nothing can follow. Set APP_URL and '
                . 're-run to seed a live code and a revoked one.',
            ];
        }

        $lines = $this->enableQrForTenant($tenantId);

        $live = $this->ensureQrCode(
            $tenantId,
            $this->documentId($documentIds, self::QR_LIVE_DOCUMENT),
            $org->person(DemoOrganisationSeeder::REGISTRY_OFFICER),
            revoke: false,
        );
        $revoked = $this->ensureQrCode(
            $tenantId,
            $this->documentId($documentIds, self::QR_REVOKED_DOCUMENT),
            $org->person(DemoOrganisationSeeder::CIVIL_TECHNICIAN),
            revoke: true,
        );

        $lines[] = sprintf(
            'Verification: "%s" carries a LIVE code — %s',
            self::QR_LIVE_DOCUMENT,
            $live === null ? '(could not be read back)' : $this->qr->verificationUrl($live),
        );
        $lines[] = sprintf(
            'Verification: "%s" carries a WITHDRAWN one, and no live code — %s. Scan both: the second '
            . 'is what a retired printing answers, which by default is what an unknown token answers '
            . 'too (documents.qr_public_detail).',
            self::QR_REVOKED_DOCUMENT,
            $revoked === null ? '(could not be read back)' : $this->qr->verificationUrl($revoked),
        );

        return $lines;
    }

    /**
     * Turn the tenant switch on — ONCE, and never over an operator's own answer.
     *
     * `documents.qr_enabled` defaults to FALSE, and the default is not an
     * oversight: switching it on publishes an unauthenticated verification
     * surface for that tenant's documents, which
     * {@see \Whity\Core\Document\Qr\DocumentQrPolicy} says is a decision
     * somebody should make rather than inherit. The demo makes that decision on
     * the demo tenant's behalf, because a QR fixture in a tenant with the feature
     * off is a fixture that renders nothing.
     *
     * WRITTEN ONLY WHEN THE TENANT HAS NO OPINION. `overriddenKeys()` is asked
     * whether a per-tenant row exists at all, not what it says — so an operator
     * who looked at the demo and deliberately switched the feature back OFF keeps
     * their answer on the next run. That is the same "insert when absent, never
     * update" discipline the templates, blocks and documents follow, applied to
     * the one thing here that is a setting rather than a row.
     *
     * `qr_public_detail` IS SET TOO, AND THAT IS A JUDGEMENT WORTH SEEING. Left
     * at its default (`minimal`) a revoked code and an unknown token produce
     * BYTE-IDENTICAL answers, so the revoked half of this fixture would be
     * unreachable by inspection — it would seed correctly and demonstrate
     * nothing, which is the one failure mode a demo dataset must not have. At
     * `stage` a revoked code says it has been retired. The privacy trade is real
     * and is exactly the one the setting exists to let an operator make; the demo
     * tenant is where "tell holders where their paper stands" is the intended
     * posture, and one `settings` write puts it back.
     *
     * @return list<string>
     */
    private function enableQrForTenant(int $tenantId): array
    {
        $overridden = $this->settings->overriddenKeys($tenantId);
        $lines = [];

        if (!in_array(SettingsRegistry::DOCUMENTS_QR_ENABLED, $overridden, true)) {
            $this->settings->setTenant($tenantId, SettingsRegistry::DOCUMENTS_QR_ENABLED, 'true');
            $lines[] = sprintf(
                'Settings: %s turned ON for this tenant (it defaults to false — it publishes an '
                . 'unauthenticated verification page). Left alone on later runs.',
                SettingsRegistry::DOCUMENTS_QR_ENABLED,
            );
        }

        if (!in_array(SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL, $overridden, true)) {
            $this->settings->setTenant(
                $tenantId,
                SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL,
                VerificationPresenter::DETAIL_STAGE,
            );
            $lines[] = sprintf(
                'Settings: %s set to "%s", so a revoked code says it was retired instead of answering '
                . 'exactly what an unknown token answers. Set it back to "%s" to see the private default.',
                SettingsRegistry::DOCUMENTS_QR_PUBLIC_DETAIL,
                VerificationPresenter::DETAIL_STAGE,
                VerificationPresenter::DETAIL_MINIMAL,
            );
        }

        return $lines;
    }

    /**
     * Give one document a code, and optionally withdraw it — at most once, ever.
     *
     * THE GUARD IS "HAS THIS DOCUMENT EVER HAD A CODE", not "does it have a live
     * one", and the difference is the whole of the idempotency. A withdrawn code
     * leaves the document with NO code in force, so a guard built on
     * {@see DocumentQrService::active()} would see nothing, mint again, withdraw
     * again, and add two rows to `document_qr_tokens` on every single run —
     * drift that no table-count test of the DOCUMENTS would ever notice.
     *
     * @return string|null The raw token, or null if it could not be read back.
     */
    private function ensureQrCode(int $tenantId, int $documentId, int $actorId, bool $revoke): ?string
    {
        $existing = $this->qrTokens->listForDocument($tenantId, $documentId);
        if ($existing !== []) {
            // Already seeded. The first row is the newest (`ORDER BY id DESC`),
            // which for both fixtures is the one this method minted.
            return isset($existing[0]['token']) ? (string) $existing[0]['token'] : null;
        }

        $minted = $this->qr->mint($tenantId, $documentId, $actorId);
        if ($minted === null) {
            return null;
        }

        $token = (string) $minted['token'];

        if ($revoke) {
            // Through the service, which latches `revoked_at` and stamps
            // `withdrawn` as the reason. Writing the column here instead would
            // let this fixture express a revocation the product cannot perform,
            // which is the same mistake as a hand-written recipient row.
            $this->qr->revoke($tenantId, $documentId, $actorId);
        }

        return $token;
    }

    /**
     * One seeded document's id, by title.
     *
     * THROWS on a title this fixture does not contain, deliberately, and for the
     * reason {@see DemoOrganisation}'s accessors throw: a demo that quietly stops
     * discriminating is the one failure mode a demo dataset must not have. A
     * silent null here would mean a renamed document takes its verification code
     * with it and nobody is told.
     *
     * @param array<string, int> $documentIds
     */
    private function documentId(array $documentIds, string $title): int
    {
        if (!isset($documentIds[$title])) {
            throw new RuntimeException(
                "The demo document '{$title}' was not seeded, so nothing can be attached to it."
            );
        }

        return $documentIds[$title];
    }

    // ── lookups used for idempotency ─────────────────────────────────────────

    /**
     * A document by exact title, through the repository's own criteria path.
     *
     * `search` is a case-insensitive LIKE, so the exact title is re-checked in
     * PHP: "Demo faculty circular" must not match a demo document somebody named
     * "Demo faculty circular (old)". Going through
     * {@see DocumentRepository::listForCriteria()} rather than adding a
     * `findByTitle()` keeps the number of statements touching `documents` where
     * it is, and the seeder is not a hot path.
     */
    private function findDocumentByTitle(int $tenantId, string $title): ?int
    {
        $rows = $this->documents->listForCriteria(
            $tenantId,
            new DocumentCriteria(search: $title),
            50,
            0
        );

        foreach ($rows as $row) {
            if ((string) $row['title'] === $title) {
                return (int) $row['id'];
            }
        }

        return null;
    }

    // ── template/block bodies ────────────────────────────────────────────────

    /**
     * A minimal but REAL DocTemplate body (`version: 2`), in the shape
     * {@see DocumentStarterSeeder} produces and the designer consumes.
     *
     * Not borrowed from that class, whose builders are private — and deliberately
     * not made public there. Those helpers describe the SHIPPED starter set,
     * which is a product decision; a demo fixture reusing them would couple two
     * things that are free to change independently, and the first divergence
     * would land in whichever of them was not being edited.
     *
     * @param list<string> $lines
     * @param list<int>    $blockIds Blocks this template INSTANCES, in order.
     * @return array<string, mixed>
     */
    private static function templateBody(string $name, string $heading, array $lines, array $blockIds = []): array
    {
        $elements = [self::textElement(1, 15, 15, 180, 12, $heading, 20, 'bold')];

        $y = 35.0;
        $z = 1;
        foreach ($lines as $line) {
            $z++;
            $elements[] = self::textElement($z, 15, $y, 180, 6, $line, 11, 'normal');
            $y += 8.0;
        }

        // The pointers, stacked under the body. Laid out plainly and not
        // designed: nothing in the demo RENDERS a template body (artifact bytes
        // come from {@see DemoPdf}, which needs no browser), so what these
        // elements are for is being real, well-formed references that the usage
        // count, the delete guard and the disclosure can all see.
        $y += 4.0;
        foreach ($blockIds as $blockId) {
            $z++;
            $elements[] = self::blockInstanceElement($z, 15, $y, 180, 12, $blockId);
            $y += 14.0;
        }

        return [
            'version' => 2,
            'name' => $name,
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#ffffff'],
            'placeholders' => [
                ['key' => 'reference', 'label' => 'Reference', 'sample' => 'DEMO-0001'],
                ['key' => 'date', 'label' => 'Date', 'sample' => '2026-01-15'],
            ],
            'pages' => [['id' => 'p1', 'elements' => $elements]],
        ];
    }

    /**
     * One `blockInstance` element: a POINTER at a block, never a copy of it.
     *
     * The shape is the client's ({@see web/lib/documents/types.ts}) and matches
     * what both readers of it expect — PHP's
     * {@see DocumentTemplateRepository::referencingTemplates()} and its
     * TypeScript twin `collectBlockIds` in `@amroksaleh/ui/documents/blocks`.
     * `blockId` is a STRING there, and both scanners compare it as one, so it is
     * written as one here rather than as the integer the id actually is; an
     * integer would be silently invisible to the count this fixture exists to
     * make non-zero.
     *
     * @return array<string, mixed>
     */
    private static function blockInstanceElement(
        int $z,
        float $x,
        float $y,
        float $w,
        float $h,
        int $blockId,
    ): array {
        return [
            'id' => 'demo-block-instance-' . $z,
            'type' => 'blockInstance',
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'rotation' => 0,
            'z' => $z,
            'blockId' => (string) $blockId,
        ];
    }

    /**
     * One `text` element, carrying the full style object the designer expects —
     * a partial style is not a smaller element, it is an element the client has
     * to guess the rest of.
     *
     * @return array<string, mixed>
     */
    private static function textElement(
        int $z,
        float $x,
        float $y,
        float $w,
        float $h,
        string $text,
        float $fontSize,
        string $fontWeight,
    ): array {
        return [
            'id' => 'demo-text-' . $z,
            'type' => 'text',
            'x' => $x,
            'y' => $y,
            'w' => $w,
            'h' => $h,
            'rotation' => 0,
            'z' => $z,
            'text' => $text,
            'style' => [
                'fontSize' => $fontSize,
                'fontWeight' => $fontWeight,
                'fontStyle' => 'normal',
                'align' => 'left',
                'vAlign' => 'top',
                'color' => '#111111',
                // 'auto', not 'ltr': the demo has to survive an Arabic tenant,
                // and a hardcoded direction is the thing that breaks there.
                'direction' => 'auto',
                'lineHeight' => 1.2,
                'letterSpacing' => 0,
            ],
        ];
    }
}
