<?php

declare(strict_types=1);

namespace Tests\Core\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Audit\InvalidPluginAuditEventException;
use Whity\Core\Audit\PluginAuditEvents;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Hooks\Events;

/**
 * Real-engine (in-memory SQLite) tests for the PLUGIN half of the audit trail
 * ({@see \Whity\Sdk\PluginEventsInterface}, SDK 1.29).
 *
 * The invariant under test is the one that makes it safe to let untrusted code
 * write into the trail at all: a plugin may declare any event it likes, but it
 * cannot declare WHO said it. The prefix on both the action and the target type
 * comes from the source the loader supplies, so two plugins declaring
 * `task.completed` produce two different actions, and no declaration — however
 * crafted — can produce the bare `user.deleted` that core writes.
 *
 * The rows themselves are asserted against a genuine SQL engine rather than a
 * spy, because the guarantee being made to an operator is about what is IN the
 * table: the right tenant, the right target, and no secret that core's own path
 * would have stripped.
 */
final class PluginAuditEventsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    // ==================== Namespacing ====================

    public function testADeclaredEventIsAuditedUnderThePluginNamespace(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);
        $logger->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'task_id'],
        ]);

        TenantContext::setTenantId(4);
        AuditContext::set(9, '203.0.113.5');

        $hooks->dispatch('tasker:task.completed', [
            'task_id' => 100,
            'tenant_id' => 4,
            'title' => 'Ship the audit seam',
        ]);

        $row = $this->onlyRow();
        $this->assertSame('tasker:task.completed', $row['action']);
        // The TARGET TYPE is namespaced too: `task` beside core's `user`/`role`
        // would read, to an operator filtering the trail, as a core record.
        $this->assertSame('tasker:task', $row['target_type']);
        $this->assertSame('100', (string) $row['target_id']);
        $this->assertSame('4', (string) $row['tenant_id']);
        $this->assertSame('9', (string) $row['actor_user_id']);
        $this->assertSame('203.0.113.5', $row['ip_address']);
    }

    public function testTheDeclaredNameIsNeverBoundOrRecordedBare(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'task_id'],
        ]);

        // Dispatching the BARE name matches nothing: the host listens on the
        // namespaced one, which is what makes an audit row attributable.
        $hooks->dispatch('task.completed', ['task_id' => 1, 'tenant_id' => 1]);

        $this->assertSame([], $this->allRows());
    }

    public function testTwoPluginsDeclaringTheSameNameProduceDifferentActions(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);
        $logger->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);
        $logger->subscribeFromSource($hooks, 'Planner', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);

        $hooks->dispatch('tasker:task.completed', ['id' => 1, 'tenant_id' => 1]);

        // Exactly ONE row. Had the host listened on the bare name it could not
        // tell which plugin dispatched — the hook manager does not say — and
        // BOTH plugins would have been credited with an event only one of them
        // had. A trail that records something which did not happen is worse
        // than one that records nothing.
        $rows = $this->allRows();
        $this->assertCount(1, $rows);
        $this->assertSame('tasker:task.completed', $rows[0]['action']);
    }

    public function testAPluginCannotShadowOrForgeACoreAuditAction(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);
        // Core's own subscriptions are live, exactly as they are in the host.
        $logger->subscribe($hooks);
        $logger->subscribeFromSource($hooks, 'Evil', [
            'user.deleted' => ['targetType' => 'user', 'idKey' => 'id'],
        ]);

        TenantContext::setTenantId(1);
        $hooks->dispatch('evil:user.deleted', ['id' => 42, 'tenant_id' => 1]);

        $row = $this->onlyRow();
        $this->assertSame('evil:user.deleted', $row['action'], 'a plugin can never produce the bare core action');
        $this->assertSame('evil:user', $row['target_type'], 'nor claim a core target type');
    }

    public function testCoreEventsAreUnaffectedByAPluginDeclaringTheirNames(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);
        $logger->subscribe($hooks);
        $logger->subscribeFromSource($hooks, 'Evil', [
            'user.deleted' => ['targetType' => 'user', 'idKey' => 'id'],
        ]);

        TenantContext::setTenantId(1);
        $hooks->dispatch('user.deleted', ['id' => 42, 'tenant_id' => 1]);

        // The real core event still records once, under its own bare action —
        // the plugin's identically-named declaration neither replaced it nor
        // added a second row beside it.
        $row = $this->onlyRow();
        $this->assertSame('user.deleted', $row['action']);
        $this->assertSame('user', $row['target_type']);
    }

    public function testTheEventNameToDispatchIsTheOneTheSdkHelperBuilds(): void
    {
        // The plugin-side spelling and the host-side binding must be the same
        // string, or a plugin ships with a trail that is quietly empty.
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Acme\\Widgets\\Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);

        $hooks->dispatch(Events::forPlugin('Acme\\Widgets\\Tasker', 'task.completed'), [
            'id' => 5,
            'tenant_id' => 1,
        ]);

        $this->assertSame('tasker:task.completed', $this->onlyRow()['action']);
    }

    // ==================== The record itself ====================

    public function testTenantResolutionIsCoresOwnOrder(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);

        // 1. The payload's tenant wins over the ambient context: the row being
        //    audited is the authority on which tenant owns it.
        TenantContext::setTenantId(2);
        $hooks->dispatch('tasker:task.completed', ['id' => 1, 'tenant_id' => 3]);
        // 2. Falling back to the hook context when the payload is silent.
        $hooks->dispatch('tasker:task.completed', ['id' => 2]);
        // 3. …and to the system tenant when nothing resolves at all.
        TenantContext::reset();
        $hooks->dispatch('tasker:task.completed', ['id' => 3]);

        $rows = $this->allRows();
        $this->assertSame('3', (string) $rows[0]['tenant_id']);
        $this->assertSame('2', (string) $rows[1]['tenant_id']);
        $this->assertSame('0', (string) $rows[2]['tenant_id']);
    }

    public function testMetadataIsSanitizedAndDoesNotRepeatTheTargetId(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'task_id'],
        ]);

        TenantContext::setTenantId(1);
        $hooks->dispatch('tasker:task.completed', [
            'task_id' => 7,
            'tenant_id' => 1,
            'title' => 'Renew the certificate',
            'api_token' => 'must-never-be-stored',
        ]);

        $metadata = json_decode($this->onlyRow()['metadata'], true);
        $this->assertSame(['title' => 'Renew the certificate'], $metadata);
        $this->assertArrayNotHasKey(
            'api_token',
            $metadata,
            'a plugin payload goes through the SAME secret filter core payloads do'
        );
    }

    public function testANonNumericOrMissingIdRecordsANullTargetRatherThanFailing(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'task_id'],
        ]);

        TenantContext::setTenantId(1);
        // The declaration is checked at load time; a PAYLOAD is runtime data,
        // and auditing must never break the action it records.
        $hooks->dispatch('tasker:task.completed', ['task_id' => 'not-an-id']);
        $hooks->dispatch('tasker:task.completed', []);

        $rows = $this->allRows();
        $this->assertCount(2, $rows);
        $this->assertNull($rows[0]['target_id']);
        $this->assertNull($rows[1]['target_id']);
    }

    public function testAnExplicitlyTargetlessEventIsRecordedWithNoTargetId(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'board.recalculated' => ['targetType' => 'board', 'idKey' => null],
        ]);

        TenantContext::setTenantId(1);
        $hooks->dispatch('tasker:board.recalculated', ['duration_ms' => 12]);

        $row = $this->onlyRow();
        $this->assertSame('tasker:board.recalculated', $row['action']);
        $this->assertNull($row['target_id']);
        $this->assertSame(['duration_ms' => 12], json_decode($row['metadata'], true));
    }

    public function testTheAuditListenerThreadsTheFilterPayloadThroughUnchanged(): void
    {
        $hooks = new HookManager();
        (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);

        TenantContext::setTenantId(1);
        $payload = ['id' => 1, 'tenant_id' => 1, 'title' => 'x'];
        $result = $hooks->dispatch('tasker:task.completed', $payload);

        $this->assertSame($payload, $result, 'auditing must never disturb the plugin filter chain');
    }

    // ==================== Refusals ====================

    public function testADeclaredNameCarryingTheSeparatorIsRefused(): void
    {
        // That is the plugin writing its own prefix — the one thing namespacing
        // exists to take out of its hands.
        $this->expectException(InvalidPluginAuditEventException::class);
        $this->expectExceptionMessageMatches('/no colon/');

        PluginAuditEvents::fromDeclaration('Tasker', [
            'core:user.deleted' => ['targetType' => 'user', 'idKey' => 'id'],
        ]);
    }

    public function testAMalformedDeclarationIsRefusedWholeAndSubscribesNothing(): void
    {
        $hooks = new HookManager();
        $logger = new AuditLogger($this->pdo);

        try {
            $logger->subscribeFromSource($hooks, 'Tasker', [
                'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
                'task.reopened' => 'not a descriptor',
            ]);
            $this->fail('a malformed entry must refuse the whole declaration');
        } catch (InvalidPluginAuditEventException) {
            // expected
        }

        TenantContext::setTenantId(1);
        $hooks->dispatch('tasker:task.completed', ['id' => 1]);

        // The well-formed entry goes with the bad one. A half-subscribed plugin
        // ships a trail that LOOKS complete and silently omits some of its
        // actions, which is harder to notice than no trail at all.
        $this->assertSame([], $this->allRows());
    }

    /**
     * @return array<string, array{0: array<mixed, mixed>, 1: string}>
     */
    public static function malformedDeclarations(): array
    {
        return [
            'name carrying the separator' => [['a:b' => ['targetType' => 't', 'idKey' => 'id']], 'no colon'],
            'uppercase name' => [['Task.Completed' => ['targetType' => 't', 'idKey' => 'id']], 'no colon'],
            'name starting with a digit' => [['1task' => ['targetType' => 't', 'idKey' => 'id']], 'no colon'],
            'empty name' => [['' => ['targetType' => 't', 'idKey' => 'id']], 'no colon'],
            'descriptor is not an array' => [['task.done' => 'task'], 'must be declared as an array'],
            'missing targetType' => [['task.done' => ['idKey' => 'id']], 'invalid targetType'],
            'targetType carrying the separator' => [['task.done' => ['targetType' => 'core:user', 'idKey' => 'id']], 'invalid targetType'],
            'targetType with a dot' => [['task.done' => ['targetType' => 'a.b', 'idKey' => 'id']], 'invalid targetType'],
            'missing idKey' => [['task.done' => ['targetType' => 'task']], 'must declare an idKey'],
            'empty idKey' => [['task.done' => ['targetType' => 'task', 'idKey' => '']], 'must declare an idKey'],
            'non-string idKey' => [['task.done' => ['targetType' => 'task', 'idKey' => 7]], 'must declare an idKey'],
        ];
    }

    /**
     * @param array<mixed, mixed> $declaration
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedDeclarations')]
    public function testMalformedDeclarationsAreRefusedWithAReasonTheAuthorCanActOn(array $declaration, string $expected): void
    {
        $this->expectException(InvalidPluginAuditEventException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($expected, '/') . '/');

        PluginAuditEvents::fromDeclaration('Tasker', $declaration);
    }

    public function testASourceWithNoUsableSlugIsRefused(): void
    {
        // Refused rather than reduced to something usable: an unprefixed action
        // is indistinguishable from one of core's.
        $this->expectException(InvalidPluginAuditEventException::class);
        $this->expectExceptionMessageMatches('/no usable namespace prefix/');

        PluginAuditEvents::fromDeclaration('!!!', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);
    }

    public function testANameTooWideForTheColumnIsRefusedAtDeclarationTime(): void
    {
        // Registered-but-unwritable is the worst outcome: the plugin believes
        // it is audited, the column truncates or the write fails, and the
        // failure is a swallowed log line under a fail-soft writer.
        $this->expectException(InvalidPluginAuditEventException::class);
        $this->expectExceptionMessageMatches('/could never be written to audit_log/');

        PluginAuditEvents::fromDeclaration('Tasker', [
            str_repeat('a', PluginAuditEvents::MAX_ACTION_LENGTH) => ['targetType' => 'task', 'idKey' => 'id'],
        ]);
    }

    public function testAnOversizedTargetTypeIsRefusedToo(): void
    {
        $this->expectException(InvalidPluginAuditEventException::class);
        $this->expectExceptionMessageMatches('/could never be written to audit_log/');

        PluginAuditEvents::fromDeclaration('Tasker', [
            'task.completed' => [
                'targetType' => str_repeat('a', PluginAuditEvents::MAX_TARGET_TYPE_LENGTH),
                'idKey' => 'id',
            ],
        ]);
    }

    public function testAnEmptyDeclarationIsAcceptedAndSubscribesNothing(): void
    {
        $hooks = new HookManager();

        $registered = (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', []);

        $this->assertSame([], $registered, 'declaring no events is a legitimate state, not an error');
    }

    public function testSubscribeFromSourceReturnsItsListenersSoTheCallerCanRemoveThem(): void
    {
        // The loader tracks these beside the plugin's own hooks; without the
        // handles back, disabling a plugin could not stop auditing it.
        $hooks = new HookManager();
        $registered = (new AuditLogger($this->pdo))->subscribeFromSource($hooks, 'Tasker', [
            'task.completed' => ['targetType' => 'task', 'idKey' => 'id'],
        ]);

        $this->assertCount(1, $registered);
        $this->assertSame('tasker:task.completed', $registered[0]['event']);

        $this->assertTrue($hooks->removeListener($registered[0]['event'], $registered[0]['callback']));

        TenantContext::setTenantId(1);
        $hooks->dispatch('tasker:task.completed', ['id' => 1]);
        $this->assertSame([], $this->allRows());
    }

    // ==================== Helpers ====================

    /**
     * @return array<string, mixed>
     */
    private function onlyRow(): array
    {
        $rows = $this->allRows();
        $this->assertCount(1, $rows, 'Expected exactly one audit row.');
        return $rows[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRows(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM audit_log ORDER BY id');
        $this->assertNotFalse($stmt);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        // audit_log.tenant_id carries an FK to tenants (enforced on real PG).
        // Without these rows the fail-soft writer silently drops the row.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES
            (1, 't1'), (2, 't2'), (3, 't3'), (4, 't4')");
        return $pdo;
    }
}
