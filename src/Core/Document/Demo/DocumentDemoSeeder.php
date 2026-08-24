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
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\RBAC\CorePermissions;

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
 * `APP_ENV=development` included. Eight logins, an invented faculty and six fake
 * documents have no business appearing in a tenant by accident.
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
        $documentIds = $this->seedDocuments($org, $templateIds);
        $collectionLines = $this->seedCollections($org, $documentIds);

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
            ],
            $collectionLines,
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

    // ── documents, and the routing states that distinguish them ──────────────

    /**
     * Six documents, one per routing state that looks different from the others.
     *
     * Read as a table of states rather than as a story: D2 is the pristine
     * inbox item, D3 is the finished one, D1 and D5 are the mixed fan-outs
     * (which no linear document can be — D1 at step 1 through the scoped rule,
     * D5 at step 1 through the unscoped one), D4 is the one that leaves the unit
     * it was raised in AND is mixed at step 2, and D6 belongs to no unit at all.
     * Nothing here is a second example of something already seeded.
     *
     * @param array<string, int> $templateIds
     * @return array<string, int> title => document id
     */
    private function seedDocuments(DemoOrganisation $org, array $templateIds): array
    {
        $head = $org->role(DemoOrganisationSeeder::ROLE_HEAD);
        $dean = $org->role(DemoOrganisationSeeder::ROLE_DEAN);
        $technician = $org->role(DemoOrganisationSeeder::ROLE_TECHNICIAN);
        $secretary = $org->role(DemoOrganisationSeeder::ROLE_SECRETARY);

        /**
         * @var list<array{
         *     title: string, template: string, raiser: string, route: string,
         *     steps: list<array{rule_kind: string, rule_config: array<string, mixed>, label: string}>,
         *     acts: list<array{actor: string, action: string, note: ?string}>,
         *     corrections: int, captions: list<string>
         * }> $declared
         */
        $declared = [
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

            // D2. AWAITING ONE NAMED PERSON. A single-step `role` route with one
            // holder: exactly one open recipient row, no trail beyond `issued`.
            // This is the folder that must not be confusable with an empty one.
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
                    ],
                ],
                'acts' => [],
                'corrections' => 0,
                'captions' => ['Awaiting approval.', 'Demo fixture.'],
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
        ];

        $ids = [];
        foreach ($declared as $spec) {
            $ids[$spec['title']] = $this->seedOneDocument($org, $templateIds, $spec);
        }

        return $ids;
    }

    /**
     * Issue one document, its artifacts, its route and its acts — all inside one
     * transaction, so the title check that guards it is a complete answer.
     *
     * @param array<string, int> $templateIds
     * @param array{
     *     title: string, template: string, raiser: string, route: string,
     *     steps: list<array{rule_kind: string, rule_config: array<string, mixed>, label: string}>,
     *     acts: list<array{actor: string, action: string, note: ?string}>,
     *     corrections: int, captions: list<string>
     * } $spec
     */
    private function seedOneDocument(DemoOrganisation $org, array $templateIds, array $spec): int
    {
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

            $route = $this->router->issue($tenantId, $raiser, $document, $spec['route'], $spec['steps']);

            foreach ($spec['acts'] as $act) {
                // Every act goes through the engine, which means an act this
                // fixture got wrong is a refusal rather than a row. That is the
                // check that makes the dataset trustworthy: `act()` will not
                // forward from the last step, will not return from the first,
                // and will not accept an actor holding no open item.
                $this->router->act(
                    $tenantId,
                    $org->person($act['actor']),
                    $route['route'],
                    $act['action'],
                    $act['note'],
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
