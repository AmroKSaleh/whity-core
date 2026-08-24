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
 *   | different units, one permission-tagged     | in different units, seeing different     |
 *   |                                            | sets — and the permission gate on top    |
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
 *  3. FINDING A SHIPPED STARTER TEMPLATE BY ITS KEY. Every document here is
 *     issued from one of the demo's OWN templates, whose ids this class holds
 *     from `create()`, because a starter's id cannot be resolved through
 *     {@see DocumentTemplateRepository}: `starterKeysForTenant()` returns keys
 *     without ids, and `normalizeRow()` deliberately withholds `starter_key`
 *     from the rows `listForTenant()` returns. Reaching past the repository with
 *     a raw SELECT to work around that would have been a silent, permanent
 *     second query surface for a table the tenant-predicate guard polices, so it
 *     is reported as a gap instead.
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
 * DEV FIXTURES ONLY
 * -----------------
 * Called from {@see \Whity\Cli\Commands\SeedCommand} under the same gate as the
 * `*@example.com` accounts: `APP_ENV=development`, or `--with-fixtures` said
 * deliberately. Seven logins, an invented faculty and five fake documents have
 * no business appearing in a production tenant by accident.
 */
final class DocumentDemoSeeder
{
    // ── template identities (stable `starter_key`s, never the display names) ──
    private const TPL_FACULTY_CIRCULAR = 'demo-faculty-circular';
    private const TPL_CIVIL_WORKS_ORDER = 'demo-civil-works-order';
    private const TPL_MECHANICAL_REPORT = 'demo-mechanical-test-report';
    private const TPL_CIVIL_CONTRACT = 'demo-civil-contract-restricted';

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
        // Seeded here rather than left to the tenant-creation hook because that
        // hook never fires for this tenant: `Seeder::seed()` creates the default
        // tenant with an INSERT, so `tenant.created` is not dispatched and the
        // designer is empty on every fresh dev install. That is a gap in its own
        // right, reported rather than papered over here.
        $this->starters->seedForTenant($tenantId, $tenantName);

        $templateIds = $this->seedTemplates($org);
        $blockCount = $this->seedBlocks($org);
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
                    . 'and %d blocks placed at specific units, one of them permission-tagged.',
                    count($templateIds),
                    $blockCount,
                ),
                sprintf('Documents: %d, each with a route the engine issued and resolved.', count($documentIds)),
            ],
            $collectionLines,
        );
    }

    // ── templates and blocks, placed (#1004 / migration 117) ─────────────────

    /**
     * Four templates at three different units.
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
     *     shows why {@see DocumentAccessPolicy} needs both.
     *
     * @return array<string, int> starter_key => template id
     */
    private function seedTemplates(DemoOrganisation $org): array
    {
        $tenantId = $org->tenantId;

        /** @var array<string, array{name: string, ou: string, author: string, permission: ?string, heading: string, lines: list<string>}> $declared */
        $declared = [
            self::TPL_FACULTY_CIRCULAR => [
                'name' => 'Demo faculty circular',
                'ou' => DemoOrganisationSeeder::OU_FACULTY,
                'author' => DemoOrganisationSeeder::DEAN,
                'permission' => null,
                'heading' => 'FACULTY CIRCULAR',
                'lines' => ['Ref: {{reference}}', 'Date: {{date}}', 'To: all departments'],
            ],
            self::TPL_CIVIL_WORKS_ORDER => [
                'name' => 'Demo civil works order',
                'ou' => DemoOrganisationSeeder::OU_DEPT_CIVIL,
                'author' => DemoOrganisationSeeder::CIVIL_HEAD,
                'permission' => null,
                'heading' => 'WORKS ORDER',
                'lines' => ['Order: {{order_no}}', 'Site: {{site}}', 'Requested by: {{requester}}'],
            ],
            self::TPL_MECHANICAL_REPORT => [
                'name' => 'Demo mechanical test report',
                'ou' => DemoOrganisationSeeder::OU_DEPT_MECHANICAL,
                'author' => DemoOrganisationSeeder::MECHANICAL_HEAD,
                'permission' => null,
                'heading' => 'TEST REPORT',
                'lines' => ['Specimen: {{specimen}}', 'Rig: {{rig}}', 'Operator: {{operator}}'],
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
            ],
        ];

        $existing = array_fill_keys($this->templates->starterKeysForTenant($tenantId), true);

        $ids = [];
        foreach ($declared as $starterKey => $spec) {
            $found = $this->findTemplateByName($tenantId, $spec['name']);
            if (isset($existing[$starterKey]) && $found !== null) {
                $ids[$starterKey] = $found;
                continue;
            }

            $ids[$starterKey] = $this->templates->create($tenantId, [
                'name' => $spec['name'],
                'data' => self::templateBody($spec['name'], $spec['heading'], $spec['lines']),
                // `tenant` rather than `system`: a system-scoped row skips the
                // `required_permission` gate entirely
                // ({@see DocumentAccessPolicy::canView()}), which would make the
                // publish-tagged template above visible to everybody and quietly
                // delete half of what this fixture is for.
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
     * Two blocks, placed like the templates.
     *
     * Blocks get the same treatment as templates because migration 117 gave both
     * tables the column and {@see DocumentAccessPolicy} is applied to both — so
     * a demo that placed only templates would leave half of #1004 unverifiable
     * by eye.
     */
    private function seedBlocks(DemoOrganisation $org): int
    {
        $tenantId = $org->tenantId;

        /** @var array<string, array{name: string, ou: string, author: string, text: string}> $declared */
        $declared = [
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

        $existing = array_fill_keys($this->blocks->starterKeysForTenant($tenantId), true);

        $seeded = 0;
        foreach ($declared as $starterKey => $spec) {
            $seeded++;
            if (isset($existing[$starterKey])) {
                continue;
            }

            $this->blocks->create($tenantId, [
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

        return $seeded;
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

    /**
     * A template by exact name, so a re-run reuses the row rather than creating
     * a second one.
     *
     * Paired with the `starter_key` check rather than replacing it: the key is
     * the identity (a user may rename the row), and the name is how the id is
     * recovered, because {@see DocumentTemplateRepository} cannot look a row up
     * by key. Both must agree for the row to be treated as already seeded, so a
     * RENAMED demo template is re-created under its original name rather than
     * silently leaving the demo one template short.
     */
    private function findTemplateByName(int $tenantId, string $name): ?int
    {
        foreach ($this->templates->listForTenant($tenantId) as $row) {
            if ((string) $row['name'] === $name) {
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
     * @return array<string, mixed>
     */
    private static function templateBody(string $name, string $heading, array $lines): array
    {
        $elements = [self::textElement(1, 15, 15, 180, 12, $heading, 20, 'bold')];

        $y = 35.0;
        $z = 1;
        foreach ($lines as $line) {
            $z++;
            $elements[] = self::textElement($z, 15, $y, 180, 6, $line, 11, 'normal');
            $y += 8.0;
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
