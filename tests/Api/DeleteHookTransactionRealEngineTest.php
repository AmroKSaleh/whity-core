<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\RecordingHookManager;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\OusApiHandler;
use Whity\Api\RolesApiHandler;
use Whity\Api\TenantsApiHandler;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * WC-713 — entity deletion and its cleanup hooks must be ONE atomic unit.
 *
 * The defect these tests pin (issue #713 item 4): `OusApiHandler::delete()`,
 * `TenantsApiHandler::delete()` and `RolesApiHandler::delete()` ran
 *
 *     dispatch('*.deleting')  →  DELETE (autocommit!)  →  dispatch('*.deleted')
 *
 * with no surrounding transaction. So a `*.deleted` listener ran against a row
 * that was already COMMITTED gone, and if it threw, the generic
 * `catch (\Exception)` returned 500 *after* the delete had landed — the caller
 * saw a failure, the row was gone anyway, and a listener had no way to veto or
 * undo. Core's own tables shrug this off because they carry real
 * `ON DELETE CASCADE` foreign keys; PLUGIN-owned tables have no FK to
 * `tenants`/`organizational_units`/`roles`, so this hook is the only cleanup
 * mechanism they have, and "best effort" meant orphaned rows.
 *
 * Every test here drives the handlers with a REAL hook manager and REAL
 * listeners rather than the `createMock(HookManager::class)` the sibling
 * *RealEngineTest classes use — stubbing `dispatch()` away is exactly what let
 * this ordering bug live undetected in a suite that already covered these
 * delete paths heavily.
 *
 * `plugin_owned_rows` stands in for a plugin's private store: no FK, no
 * cascade, cleaned up only by a `*.deleted` listener. That is the shape the
 * issue describes.
 */
final class DeleteHookTransactionRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== OUs ====================

    /**
     * The core regression. A `ou.deleted` listener throws (a plugin's cleanup
     * failed). Before the fix the OU row was already committed gone and only the
     * 500 remained; now the whole unit rolls back and the OU survives, so the
     * caller's error response and the database finally agree with each other.
     */
    public function testThrowingOuDeletedListenerRollsTheDeleteBack(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('ou.deleted', static function (array $data): array {
            throw new \RuntimeException('cleanup exploded');
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(
            1,
            $this->countRow('organizational_units', 14),
            'A throwing ou.deleted listener must roll the DELETE back, not leave it committed.'
        );
    }

    /**
     * The other half of atomicity: a plugin's own cleanup writes are undone too.
     * Listener A (priority 10) deletes the plugin's rows; listener B (priority
     * 20) then fails. Neither the OU nor the plugin rows may disappear — a
     * half-applied cleanup is precisely the orphaning this fix exists to prevent,
     * and no FK exists that could have caught it.
     */
    public function testPluginCleanupInOuDeletedIsRolledBackWithTheDelete(): void
    {
        $this->seedPluginRows('ou', 14, 3);

        $hooks = $this->hooks();
        $hooks->listen('ou.deleted', function (array $data): array {
            $stmt = $this->pdo->prepare(
                "DELETE FROM plugin_owned_rows WHERE owner_type = 'ou' AND owner_id = ?"
            );
            $stmt->execute([(int) $data['id']]);

            return $data;
        }, 10);
        $hooks->listen('ou.deleted', static function (array $data): array {
            throw new \RuntimeException('second listener exploded');
        }, 20);

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $this->countRow('organizational_units', 14), 'The OU must survive.');
        $this->assertSame(
            3,
            $this->countPluginRows('ou', 14),
            "The first listener's cleanup must roll back together with the delete."
        );
    }

    /**
     * The veto path: a plugin refuses the deletion from `ou.deleting`. The caller
     * gets 409 (not 500, and not a silently-succeeded delete) with the plugin's
     * own reason echoed under `details`, and the OU is untouched.
     */
    public function testOuDeletingVetoReturns409AndKeepsTheRow(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('ou.deleting', static function (array $data): array {
            throw HookVetoException::forEvent('ou.deleting', 'Devices are still assigned here.');
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->decode($response->getBody());
        $this->assertSame('Cannot delete organizational unit: blocked by an installed plugin', $body['error']);
        $this->assertSame('Devices are still assigned here.', $body['details']['reason']);
        $this->assertSame(1, $this->countRow('organizational_units', 14));
    }

    /**
     * A veto raised from the POST-delete hook is honoured just as strongly: the
     * row is already deleted *within* the transaction, so the only correct answer
     * is to roll back and report 409 rather than commit an orphaning delete.
     */
    public function testOuDeletedVetoRollsBackAndReturns409(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('ou.deleted', static function (array $data): array {
            throw HookVetoException::forEvent('ou.deleted', 'Archive job could not run.');
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('Archive job could not run.', $this->decode($response->getBody())['details']['reason']);
        $this->assertSame(1, $this->countRow('organizational_units', 14));
    }

    /**
     * `ou.deleting` is the READ point — the row must still be visible there,
     * which is what makes an informed veto possible at all — and gone by
     * `ou.deleted`. Pinning both halves keeps the documented SDK contract honest.
     */
    public function testDeletingSeesTheRowAndDeletedDoesNot(): void
    {
        $seen = [];

        $hooks = $this->hooks();
        $hooks->listen('ou.deleting', function (array $data) use (&$seen): array {
            $seen['deleting'] = $this->countRow('organizational_units', (int) $data['id']);

            return $data;
        });
        $hooks->listen('ou.deleted', function (array $data) use (&$seen): array {
            $seen['deleted'] = $this->countRow('organizational_units', (int) $data['id']);

            return $data;
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(1, $seen['deleting'] ?? null, 'ou.deleting must still see the row it may veto.');
        $this->assertSame(0, $seen['deleted'] ?? null, 'ou.deleted runs after the DELETE, inside the transaction.');
        $this->assertSame(0, $this->countRow('organizational_units', 14), 'The successful delete must commit.');
    }

    /**
     * The happy path still commits, in the documented order, and the async
     * variant fires exactly once — AFTER the transaction closed, so the durable
     * event spine can never announce a deletion that was rolled back.
     */
    public function testSuccessfulDeleteCommitsAndDispatchesAsyncAfterCommit(): void
    {
        $hooks = $this->hooks();

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(['ou.deleting', 'ou.deleted'], $hooks->dispatched);
        $this->assertSame(['ou.deleted.async'], $hooks->asyncEvents());
        $this->assertFalse(
            $hooks->async[0]['inTransaction'],
            'ou.deleted.async must be dispatched only after the delete transaction has committed.'
        );
        $this->assertSame(0, $this->countRow('organizational_units', 14));
    }

    /**
     * The converse: a rolled-back delete must NOT emit its async event. An outbox
     * row announcing a deletion that never happened would have a relay worker
     * acting on an OU that still exists.
     */
    public function testRolledBackDeleteDoesNotDispatchTheAsyncEvent(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('ou.deleted', static function (array $data): array {
            throw new \RuntimeException('cleanup exploded');
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame([], $hooks->asyncEvents(), 'No durable event may announce a deletion that rolled back.');
        $this->assertSame(1, $this->countRow('organizational_units', 14));
    }

    // ==================== Tenants ====================

    /**
     * Same guarantee on the tenant path, where the stakes are highest: a tenant
     * delete cascades across 32 core FK constraints, and a plugin's tenant-scoped
     * tables have none of them.
     */
    public function testThrowingTenantDeletedListenerRollsTheDeleteBack(): void
    {
        $this->seedPluginRows('tenant', 2, 4);

        $hooks = $this->hooks();
        $hooks->listen('tenant.deleted', static function (array $data): array {
            throw new \RuntimeException('tenant cleanup exploded');
        });

        MockRequestFactory::setTestTenant(0);
        $response = $this->tenants($hooks)->delete(new Request('DELETE', '/api/tenants/2'), ['id' => 2]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $this->countRow('tenants', 2), 'A failed tenant.deleted listener must roll the delete back.');
        $this->assertSame(4, $this->countPluginRows('tenant', 2));
        $this->assertSame([], $hooks->asyncEvents());
    }

    public function testTenantDeletingVetoReturns409AndKeepsTheRow(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('tenant.deleting', static function (array $data): array {
            throw HookVetoException::forEvent('tenant.deleting', 'An export is still running for this workspace.');
        });

        MockRequestFactory::setTestTenant(0);
        $response = $this->tenants($hooks)->delete(new Request('DELETE', '/api/tenants/2'), ['id' => 2]);

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->decode($response->getBody());
        $this->assertSame('Cannot delete tenant: blocked by an installed plugin', $body['error']);
        $this->assertSame('An export is still running for this workspace.', $body['details']['reason']);
        $this->assertSame(1, $this->countRow('tenants', 2));
    }

    public function testSuccessfulTenantDeleteStillCommits(): void
    {
        $hooks = $this->hooks();

        MockRequestFactory::setTestTenant(0);
        $response = $this->tenants($hooks)->delete(new Request('DELETE', '/api/tenants/2'), ['id' => 2]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->countRow('tenants', 2));
        $this->assertSame(['tenant.deleting', 'tenant.deleted'], $hooks->dispatched);
        $this->assertSame(['tenant.deleted.async'], $hooks->asyncEvents());
    }

    // ==================== Roles ====================

    /**
     * The role path deletes from TWO tables (`role_permissions`, then `roles`).
     * Before the fix those were separate autocommitted statements, so a failure
     * between them stripped a role's grants but left the role itself — a silent
     * privilege change. The transaction makes them atomic with each other AND
     * with the hook.
     */
    public function testThrowingRoleDeletedListenerRollsBackBothDeletes(): void
    {
        $grantsBefore = $this->countRolePermissions(100);
        $this->assertGreaterThan(0, $grantsBefore, 'The fixture must grant the role at least one permission.');

        $hooks = $this->hooks();
        $hooks->listen('role.deleted', static function (array $data): array {
            throw new \RuntimeException('role cleanup exploded');
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->roles($hooks)->delete(new Request('DELETE', '/api/roles/100'), ['id' => 100]);

        $this->assertSame(500, $response->getStatusCode());
        $this->assertSame(1, $this->countRow('roles', 100), 'The role must survive.');
        $this->assertSame(
            $grantsBefore,
            $this->countRolePermissions(100),
            'Its permission grants must survive too — the two DELETEs are one unit.'
        );
    }

    public function testRoleDeletingVetoReturns409AndKeepsTheRole(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('role.deleting', static function (array $data): array {
            throw HookVetoException::forEvent('role.deleting', 'This role is referenced by a workflow.');
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->roles($hooks)->delete(new Request('DELETE', '/api/roles/100'), ['id' => 100]);

        $this->assertSame(409, $response->getStatusCode());
        $body = $this->decode($response->getBody());
        $this->assertSame('Cannot delete role: blocked by an installed plugin', $body['error']);
        $this->assertSame('This role is referenced by a workflow.', $body['details']['reason']);
        $this->assertSame(1, $this->countRow('roles', 100));
        $this->assertSame(1, $this->countRolePermissions(100));
    }

    public function testSuccessfulRoleDeleteStillCommits(): void
    {
        $hooks = $this->hooks();

        MockRequestFactory::setTestTenant(1);
        $response = $this->roles($hooks)->delete(new Request('DELETE', '/api/roles/100'), ['id' => 100]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $this->countRow('roles', 100));
        $this->assertSame(0, $this->countRolePermissions(100));
        $this->assertSame(['role.deleting', 'role.deleted'], $hooks->dispatched);
        $this->assertSame(['role.deleted.async'], $hooks->asyncEvents());
    }

    // ==================== Veto reason normalisation ====================

    /**
     * The veto reason is plugin-authored text that reaches the client, so it is
     * normalised on the way out: control characters collapsed to spaces, runs of
     * whitespace squeezed, trimmed.
     */
    public function testVetoReasonIsNormalisedBeforeItReachesTheClient(): void
    {
        $hooks = $this->hooks();
        $hooks->listen('ou.deleting', static function (array $data): array {
            throw HookVetoException::forEvent('ou.deleting', "  line one\n\tline two  ");
        });

        MockRequestFactory::setTestTenant(1);
        $response = $this->ous($hooks)->delete(new Request('DELETE', '/api/ous/14'), ['id' => 14]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame('line one line two', $this->decode($response->getBody())['details']['reason']);
    }

    public function testEmptyVetoReasonFallsBackToTheGenericText(): void
    {
        $this->assertSame(
            HookVetoException::DEFAULT_REASON,
            HookVetoException::forEvent('ou.deleting', "   \n  ")->reason()
        );
    }

    /**
     * Arabic is a first-class content language here (RTL is a cross-cutting
     * product requirement), so the length cap must count CODE POINTS, not bytes —
     * a byte-wise cut would hand the client a broken UTF-8 sequence.
     */
    public function testLongVetoReasonIsCappedOnACodePointBoundary(): void
    {
        $capped = HookVetoException::forEvent('ou.deleting', str_repeat('م', 400))->reason();

        $this->assertSame(HookVetoException::REASON_MAX_LENGTH, mb_strlen($capped));
        $this->assertTrue(mb_check_encoding($capped, 'UTF-8'), 'The cap must not split a code point.');
    }

    public function testVetoExceptionCarriesItsEventName(): void
    {
        $this->assertSame('ou.deleting', HookVetoException::forEvent('ou.deleting', 'no')->eventName());
    }

    // ==================== Helpers ====================

    private function hooks(): RecordingHookManager
    {
        return new RecordingHookManager($this->pdo);
    }

    private function ous(RecordingHookManager $hooks): OusApiHandler
    {
        return new OusApiHandler($this->pdo, $hooks);
    }

    private function tenants(RecordingHookManager $hooks): TenantsApiHandler
    {
        return new TenantsApiHandler($this->pdo, $hooks);
    }

    private function roles(RecordingHookManager $hooks): RolesApiHandler
    {
        return new RolesApiHandler($this->pdo, $hooks);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function countRow(string $table, int $id): int
    {
        // Table names are literals from this test only — never request input.
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id = ?");
        $stmt->execute([$id]);

        return (int) $stmt->fetchColumn();
    }

    private function countRolePermissions(int $roleId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM role_permissions WHERE role_id = ?');
        $stmt->execute([$roleId]);

        return (int) $stmt->fetchColumn();
    }

    private function countPluginRows(string $ownerType, int $ownerId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM plugin_owned_rows WHERE owner_type = ? AND owner_id = ?'
        );
        $stmt->execute([$ownerType, $ownerId]);

        return (int) $stmt->fetchColumn();
    }

    private function seedPluginRows(string $ownerType, int $ownerId, int $count): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO plugin_owned_rows (owner_type, owner_id, payload) VALUES (?, ?, ?)'
        );
        for ($i = 0; $i < $count; $i++) {
            $stmt->execute([$ownerType, $ownerId, 'row-' . $i]);
        }
    }

    /**
     * Schema + fixtures:
     *  - tenants 1 and 2 (2 is the delete target; 0 is the protected system tenant);
     *  - OU 14 "Sales" in tenant 1 — a childless, memberless root, so the
     *    handler's own child/member guards never short-circuit the hook path;
     *  - role 100 owned by tenant 1, unassigned, with exactly one permission grant;
     *  - `plugin_owned_rows`, standing in for a plugin's private table: no FK to
     *    anything, so nothing but a hook can ever clean it up.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at)
            VALUES (14, 1, NULL, 'Sales', 'sales', '', datetime('now'))
        ");

        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at)
            VALUES (100, 'tenant-a-role', 'Tenant A role', 1, datetime('now'))
        ");

        // One real permission grant, so the role delete has something to cascade
        // and the "both DELETEs roll back together" assertion has teeth.
        $lookup = $pdo->prepare('SELECT id FROM permissions ORDER BY id LIMIT 1');
        $lookup->execute();
        $permissionId = (int) $lookup->fetchColumn();

        $grant = $pdo->prepare(
            "INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, datetime('now'))"
        );
        $grant->execute([100, $permissionId]);

        $pdo->exec('
            CREATE TABLE plugin_owned_rows (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_type VARCHAR(32) NOT NULL,
                owner_id INTEGER NOT NULL,
                payload TEXT NOT NULL
            )
        ');

        return $pdo;
    }
}
