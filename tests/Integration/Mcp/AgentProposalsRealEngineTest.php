<?php

declare(strict_types=1);

namespace Tests\Integration\Mcp;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AgentProposalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Agent\AgentProposalRepository;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\AgentScopeGrant;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\OpenAPI\CoreApiSchemas;
use Whity\Sdk\Http\Response;

/**
 * "Ask first" made real (WHIT-784; register 7.5a, 7.5b; owner decisions 10
 * and 12): an agent's Ask-first call is stored, not run; the person it acts
 * for approves, edits or declines; approval runs it once, re-checked.
 */
final class AgentProposalsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OMAR   = 202;

    private PDO $pdo;
    private AgentProposalRepository $proposals;
    private ToolsCallHandler $toolsCall;
    private AgentProposalsApiHandler $inbox;
    private bool $ranaMayManageForms = true;

    /** @var list<array<string, mixed>> */
    private array $created = [];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'example-bakery')");
        foreach ([self::RANA => 'Rana', self::OMAR => 'Omar'] as $id => $name) {
            $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                                 VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)")->execute([$id, $name]);
        }
        SchemaFromMigrations::syncSequences($this->pdo);

        $router = new Router('');
        $router->registerUnversioned('POST', '/api/forms', function (Request $request): Response {
            $this->created[] = (array) json_decode($request->getBody(), true);
            return Response::json(['id' => count($this->created)], 201);
        }, null, null, 'forms:manage');
        $deriver = new ToolDeriver([[
            'method' => 'POST', 'path' => '/api/forms', 'requiredPermission' => 'forms:manage',
            'schema' => ['summary' => 'Create a form', 'tags' => ['forms'], 'request' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']]],
        ]], [], $router, static fn (string $m): null => null);

        $roles = $this->createMock(RoleChecker::class);
        $roles->method('hasPermissionForProfile')->willReturnCallback(fn (): bool => $this->ranaMayManageForms);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturn(
            new McpPrincipal(self::RANA, self::RANA, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], 'jti-billing-assistant'),
        );

        $this->proposals = new AgentProposalRepository($this->pdo);
        $this->toolsCall = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: $this->proposals);
        $this->inbox     = new AgentProposalsApiHandler($this->proposals, $this->toolsCall);
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAnAskFirstCallIsStoredNotRun(): void
    {
        $result = ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']], 'bearer');

        self::assertSame([], $this->created, 'nothing ran');
        $proposal = json_decode($result['content'][0]['text'], true)['proposal'];
        self::assertSame('waiting', $proposal['status']);
        self::assertSame(['forms:manage'], $proposal['permissions']);

        $stored = $this->stored($proposal['id'], self::TENANT);
        self::assertSame(self::RANA, $stored['on_behalf_of_profile_id']);
        self::assertSame('jti-billing-assistant', $stored['agent_principal_id']);
        self::assertSame(['name' => 'Leave request'], $stored['arguments']);
    }

    public function testTheInboxNamesTheAgentByItsCredential(): void
    {
        $this->pdo->prepare("INSERT INTO mcp_tokens (jti, profile_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
                             VALUES ('jti-billing-assistant', ?, ?, 'Billing assistant', 'ai-agent', '[]', '2099-01-01 00:00:00', CURRENT_TIMESTAMP)")
            ->execute([self::RANA, self::TENANT]);
        ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']], 'bearer');

        $listed = json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'][0];

        self::assertSame(['name' => 'Billing assistant'], $listed['agent']);
        self::assertArrayNotHasKey('agent_principal_id', $listed, 'the credential id stays server-side');
    }

    public function testAnAgentWhoseCredentialIsGoneHasNoName(): void
    {
        ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']], 'bearer');

        self::assertSame(['name' => null], json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'][0]['agent']);
    }

    public function testAnInvalidCallIsNeverPutToAPerson(): void
    {
        $result = ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => []], 'bearer');

        self::assertTrue($result['isError']);
        self::assertSame([], $this->proposals->listFor(self::TENANT, self::RANA));
    }

    public function testApprovalRunsItOnceAsTheirDelegate(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        $first  = $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id]);
        $second = $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id]);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(409, $second->getStatusCode(), 'a second approval runs nothing');
        self::assertSame([['name' => 'Leave request']], $this->created);
        self::assertSame('approved', $this->stored($id, self::TENANT)['status']);
        self::assertNotNull($this->stored($id, self::TENANT)['result']);
    }

    public function testApprovalCanEditTheArguments(): void
    {
        $id = $this->ask(['name' => 'Leave reqest']);

        $this->inbox->approve($this->as(self::RANA, '{"arguments":{"name":"Leave request"}}'), ['id' => (string) $id]);

        self::assertSame([['name' => 'Leave request']], $this->created);
    }

    public function testDecliningRunsNothing(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        self::assertSame(200, $this->inbox->decline($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame(409, $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame([], $this->created);
    }

    public function testOnlyTheGrantingPersonDecides(): void
    {
        $id = $this->ask(['name' => 'Leave request']);

        self::assertSame(404, $this->inbox->approve($this->as(self::OMAR), ['id' => (string) $id])->getStatusCode());
        self::assertSame(404, $this->inbox->decline($this->as(self::OMAR), ['id' => (string) $id])->getStatusCode());
        self::assertSame([], json_decode($this->inbox->list($this->as(self::OMAR))->getBody(), true)['data']);
        self::assertSame([], $this->created);
    }

    public function testAnExpiredProposalCanNotBeApproved(): void
    {
        $id = $this->ask(['name' => 'Leave request']);
        $this->pdo->exec("UPDATE agent_proposals SET expires_at = '2000-01-01 00:00:00' WHERE id = {$id}");

        self::assertSame(409, $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame('expired', json_decode($this->inbox->list($this->as(self::RANA))->getBody(), true)['data'][0]['status']);
        self::assertSame([], $this->created);
    }

    public function testApprovalChecksAgainAndRecordsARefusal(): void
    {
        $id = $this->ask(['name' => 'Leave request']);
        $this->ranaMayManageForms = false;

        self::assertSame(200, $this->inbox->approve($this->as(self::RANA), ['id' => (string) $id])->getStatusCode());
        self::assertSame([], $this->created, 'the person no longer may, so nothing ran');
        self::assertTrue($this->stored($id, self::TENANT)['result']['isError']);
    }

    public function testAgentsCanNotReachTheApprovalRoutes(): void
    {
        ToolDeriver::clearCache();
        $deriver = new ToolDeriver(CoreApiSchemas::routes(), CoreApiSchemas::components(), null, static fn (string $m): null => null);
        $names   = array_column($deriver->deriveTools(), 'name');

        foreach (['get_api_me_agent_proposals', 'post_api_me_agent_proposals_id_approve', 'post_api_me_agent_proposals_id_decline'] as $name) {
            self::assertContains($name, $names);
            self::assertFalse($deriver->isExposed($name), "{$name} must not be on the MCP surface");
        }
    }

    /** @return array<string, mixed> */
    private function stored(int $id, int $tenantId): array
    {
        $proposal = $this->proposals->find($id, $tenantId);
        self::assertIsArray($proposal);
        return $proposal;
    }

    /** @param array<string, mixed> $arguments */
    private function ask(array $arguments): int
    {
        $result = ($this->toolsCall)(['name' => 'post_api_forms', 'arguments' => $arguments], 'bearer');
        return (int) json_decode($result['content'][0]['text'], true)['proposal']['id'];
    }

    private function as(int $profileId, string $body = ''): Request
    {
        $request       = new Request('POST', '/api/me/agent-proposals', ['Content-Type' => 'application/json'], $body);
        $request->user = (object) ['profile_id' => $profileId];
        return $request;
    }
}
