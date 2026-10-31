<?php

declare(strict_types=1);

namespace Tests\Integration\Audit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\Actor;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Events\DomainEventStore;
use Whity\Mcp\Auth\McpPrincipal;

/**
 * Every write records its actor: a person, an agent for a person, or the
 * system (WHIT-753, migration 169), on the audit log and on domain events.
 */
final class ActorRecordingRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Example Bakery')");
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        AuditContext::reset();
    }

    public function testAPersonActingThemselves(): void
    {
        AuditContext::set(7, '10.0.0.1');
        (new AuditLogger($this->pdo))->record('thing.done', ['tenant_id' => 1]);

        self::assertSame(['7', 'person', null, null], $this->lastAudit());
    }

    public function testAnAgentActingForAPerson(): void
    {
        AuditContext::setActor((new McpPrincipal(7, 7, 1, McpPrincipal::KIND_AGENT, ['tools:call'], 'jti-agent'))->actor());
        (new AuditLogger($this->pdo))->record('thing.done', ['tenant_id' => 1]);
        (new DomainEventStore($this->pdo))->append(1, 'thing.done', [], ['actor_user_id' => 7]);

        self::assertSame(['7', 'agent', 'jti-agent', '7'], $this->lastAudit());
        self::assertSame(['7', 'agent', 'jti-agent', '7'], $this->lastEvent());
    }

    public function testAPersonsOwnClientIsThePerson(): void
    {
        AuditContext::setActor((new McpPrincipal(7, 7, 1, 'user', ['tools:call'], 'jti-person'))->actor());
        (new AuditLogger($this->pdo))->record('thing.done', ['tenant_id' => 1]);

        self::assertSame(['7', 'person', null, null], $this->lastAudit());
    }

    public function testNoActorIsTheSystem(): void
    {
        (new AuditLogger($this->pdo))->record('job.ran', ['tenant_id' => 1]);
        (new DomainEventStore($this->pdo))->append(1, 'job.ran', []);

        self::assertSame([null, 'system', null, null], $this->lastAudit());
        self::assertSame([null, 'system', null, null], $this->lastEvent());
    }

    public function testARowNamingAnotherActorIsThatPersonNotTheAgent(): void
    {
        AuditContext::setActor(Actor::agent('jti-agent', 7));
        (new AuditLogger($this->pdo))->record('user.created', ['tenant_id' => 1, 'actor_user_id' => 9]);

        self::assertSame(['9', 'person', null, null], $this->lastAudit());
    }

    /** @return list<string|null> */
    private function lastAudit(): array
    {
        return $this->row('SELECT actor_user_id, actor_kind, agent_principal_id, on_behalf_of_profile_id FROM audit_log ORDER BY id DESC LIMIT 1');
    }

    /** @return list<string|null> */
    private function lastEvent(): array
    {
        return $this->row('SELECT actor_user_id, actor_kind, agent_principal_id, on_behalf_of_profile_id FROM domain_events ORDER BY created_at DESC, id DESC LIMIT 1');
    }

    /** @return list<string|null> */
    private function row(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_NUM);
        self::assertIsArray($row);
        return array_map(static fn (mixed $v): ?string => $v === null ? null : (string) $v, $row);
    }
}
