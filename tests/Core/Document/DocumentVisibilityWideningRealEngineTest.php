<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * The two disjuncts #947 item 3 adds to document visibility, asserted where they
 * are actually enforced — in {@see DocumentVisibilityPolicy::canView()} for a
 * single row, and in {@see DocumentRepository}'s WHERE clause for the list.
 *
 * TWO PLACES, ONE RULE, AND THAT IS THE RISK
 * ------------------------------------------
 * The policy answers "may this caller see this row"; the repository answers the
 * same question as a predicate, because documents accumulate without bound and
 * post-filtering a page returns short pages and a total the caller cannot reach.
 * Two expressions of one rule can disagree, so both are exercised over the same
 * fixture and the LIST and the COUNT are compared against each other.
 *
 * THE PLACEHOLDER HAZARD THIS EXISTS TO CATCH
 * -------------------------------------------
 * The widened predicate reuses `:tenant_id` and `:created_by` three times each.
 * PDO's handling of a REPEATED named placeholder depends on the driver and on
 * whether prepare emulation is on, which makes it the classic "green on SQLite,
 * 500 on PostgreSQL" defect. Running this against a real engine
 * ({@see SchemaFromMigrations::make()} with PHPUNIT_PG_DSN set) is the point of
 * the file.
 *
 * AND THE ONE THAT WOULD BE A LEAK
 * --------------------------------
 * An everyone-grant (`resource_role_assignments.profile_id IS NULL`) must NOT
 * widen visibility. Migration 088 defines it as "everyone WITH ACCESS to this
 * resource gets role R here" — it modifies what already-reachable people may DO
 * and is not itself access. Reading it as access would publish a document to
 * every profile in the tenant, which is why it gets its own assertion rather
 * than being covered incidentally.
 */
final class DocumentVisibilityWideningRealEngineTest extends TestCase
{
    private const TENANT = 900;
    private const OTHER_TENANT = 901;

    /** The caller under test. */
    private const ME = 901;
    /** A colleague who raises documents the caller has nothing to do with. */
    private const THEM = 902;

    private PDO $pdo;
    private DocumentRepository $documents;
    private DocumentVisibilityPolicy $policy;

    /** @var array<string, int> */
    private array $ids = [];

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();
        $this->documents = new DocumentRepository($this->pdo);
        $this->policy = new DocumentVisibilityPolicy(
            new RouteRecipientRepository($this->pdo),
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );

        $this->ids['mine'] = $this->documents->create(self::TENANT, [
            'template_name' => 'T', 'title' => 'Mine', 'created_by' => self::ME,
        ]);
        $this->ids['theirs'] = $this->documents->create(self::TENANT, [
            'template_name' => 'T', 'title' => 'Theirs', 'created_by' => self::THEM,
        ]);
        $this->ids['routed'] = $this->documents->create(self::TENANT, [
            'template_name' => 'T', 'title' => 'Routed to me', 'created_by' => self::THEM,
        ]);
        $this->ids['granted'] = $this->documents->create(self::TENANT, [
            'template_name' => 'T', 'title' => 'Granted to me', 'created_by' => self::THEM,
        ]);
        $this->ids['everyone'] = $this->documents->create(self::TENANT, [
            'template_name' => 'T', 'title' => 'Everyone-granted', 'created_by' => self::THEM,
        ]);

        $this->routeTo($this->ids['routed'], self::ME);
        $this->grantAt($this->ids['granted'], self::ME);
        $this->grantAt($this->ids['everyone'], null);
    }

    public function testTheListAndTheCountApplyTheSamePredicate(): void
    {
        $rows = $this->documents->listForTenant(self::TENANT, self::ME, 25, 0);
        $total = $this->documents->countForTenant(self::TENANT, self::ME);

        $titles = array_map(static fn (array $r): string => (string) $r['title'], $rows);
        sort($titles);

        self::assertSame(['Granted to me', 'Mine', 'Routed to me'], $titles);
        // A total the caller cannot reach is the defect the SQL predicate exists
        // to avoid, so the two are compared against each other and not merely
        // each against a constant.
        self::assertSame(count($rows), $total, 'the pagination total must match what the page returns');
        self::assertSame(3, $total);
    }

    public function testAnEveryoneGrantDoesNotWidenVisibility(): void
    {
        $rows = $this->documents->listForTenant(self::TENANT, self::ME, 25, 0);
        $titles = array_map(static fn (array $r): string => (string) $r['title'], $rows);

        self::assertNotContains(
            'Everyone-granted',
            $titles,
            'a profile_id IS NULL grant modifies what already-reachable people may DO (migration 088); '
            . 'reading it as access would publish the document to every profile in the tenant'
        );

        $document = $this->documents->findById($this->ids['everyone'], self::TENANT);
        self::assertNotNull($document);
        self::assertFalse($this->policy->canView($document, self::ME, $this->holdsNothing()));
    }

    public function testTheSingleRowPolicyAgreesWithTheListForEveryFixture(): void
    {
        $visibleInList = array_map(
            static fn (array $r): int => (int) $r['id'],
            $this->documents->listForTenant(self::TENANT, self::ME, 25, 0)
        );

        foreach ($this->ids as $label => $id) {
            $document = $this->documents->findById($id, self::TENANT);
            self::assertNotNull($document);

            $byPolicy = $this->policy->canView($document, self::ME, $this->holdsNothing());
            $byList = in_array($id, $visibleInList, true);

            self::assertSame(
                $byList,
                $byPolicy,
                "the policy and the list disagree about '{$label}' — two expressions of one rule have drifted"
            );
        }
    }

    public function testARecipientKeepsVisibilityAfterTheirItemIsClosed(): void
    {
        // "I no longer have it in my inbox" is not "I was never sent it": a
        // person who forwarded something must still be able to open it, not least
        // because appending a correcting note is the only way to amend an
        // append-only trail.
        $this->pdo->exec(
            'UPDATE document_route_recipients SET closed_by_event_id = created_by_event_id
              WHERE tenant_id = ' . self::TENANT . ' AND profile_id = ' . self::ME
        );

        $document = $this->documents->findById($this->ids['routed'], self::TENANT);
        self::assertNotNull($document);
        self::assertTrue($this->policy->canView($document, self::ME, $this->holdsNothing()));

        $titles = array_map(
            static fn (array $r): string => (string) $r['title'],
            $this->documents->listForTenant(self::TENANT, self::ME, 25, 0)
        );
        self::assertContains('Routed to me', $titles, 'the list must agree with the single-row policy');
    }

    public function testTheTenantWideGrantStillSeesEverything(): void
    {
        self::assertNull(
            $this->policy->restrictToCreator(self::ME, $this->holds(CorePermissions::DOCUMENTS_READ_ALL)),
            'documents:read:all means no restriction at all'
        );
        self::assertSame(5, $this->documents->countForTenant(self::TENANT, null));
    }

    public function testAnotherTenantSeesNoneOfIt(): void
    {
        self::assertSame(0, $this->documents->countForTenant(self::OTHER_TENANT, self::ME));
        self::assertSame([], $this->documents->listForTenant(self::OTHER_TENANT, self::ME, 25, 0));

        // Every subquery in the predicate re-binds the tenant, so a recipient row
        // or a grant in one tenant cannot make another tenant's document appear.
        foreach ($this->ids as $id) {
            self::assertNull($this->documents->findById($id, self::OTHER_TENANT));
        }
    }

    // -- fixtures -----------------------------------------------------------

    /**
     * A route on `$documentId` whose first step reached `$profileId`.
     *
     * Inserted directly rather than through the engine: this file is about the
     * VISIBILITY predicate, and going through the router would make it depend on
     * rule resolution as well.
     */
    private function routeTo(int $documentId, int $profileId): void
    {
        $this->pdo->exec(
            'INSERT INTO document_routes (id, tenant_id, document_id, title)
             VALUES (' . $documentId . ', ' . self::TENANT . ', ' . $documentId . ', ' . $this->pdo->quote('R') . ')'
        );
        $this->pdo->exec(
            "INSERT INTO document_route_steps (id, tenant_id, route_id, position, rule_kind, rule_config)
             VALUES ({$documentId}, " . self::TENANT . ", {$documentId}, 1, 'role', '{}')"
        );
        $this->pdo->exec(
            "INSERT INTO document_route_events (id, tenant_id, document_id, route_id, step_id, action)
             VALUES ({$documentId}, " . self::TENANT . ", {$documentId}, {$documentId}, {$documentId}, 'issued')"
        );
        $this->pdo->exec(
            'INSERT INTO document_route_recipients
                 (tenant_id, document_id, route_id, step_id, profile_id, created_by_event_id)
             VALUES (' . self::TENANT . ", {$documentId}, {$documentId}, {$documentId}, {$profileId}, {$documentId})"
        );
    }

    private function grantAt(int $documentId, ?int $profileId): void
    {
        $this->pdo->exec(
            "INSERT INTO resource_role_assignments (tenant_id, resource_type, resource_id, role_id, profile_id, created_at)
             VALUES (" . self::TENANT . ", '" . ResourceTypeRegistry::TYPE_DOCUMENT . "', {$documentId}, 1, "
             . ($profileId === null ? 'NULL' : (string) $profileId) . ', ' . $this->now() . ')'
        );
    }

    /**
     * @return callable(string): bool
     */
    private function holdsNothing(): callable
    {
        return static fn (string $permission): bool => false;
    }

    /**
     * @return callable(string): bool
     */
    private function holds(string $granted): callable
    {
        return static fn (string $permission): bool => $permission === $granted;
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (' . self::TENANT . ', ' . $quote('T') . ') ON CONFLICT DO NOTHING');
        $pdo->exec('INSERT INTO tenants (id, name) VALUES (' . self::OTHER_TENANT . ', ' . $quote('T2') . ') ON CONFLICT DO NOTHING');

        foreach ([self::ME, self::THEM] as $id) {
            $pdo->exec(
                'INSERT INTO profiles (id, password_hash, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote('x') . ', ' . $now . ', ' . $now . ') ON CONFLICT DO NOTHING'
            );
        }

        return $pdo;
    }
}
