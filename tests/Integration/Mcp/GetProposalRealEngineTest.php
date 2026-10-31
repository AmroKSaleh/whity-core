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
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Sdk\Http\Response;

/**
 * An agent follows what it asked for (WHIT-825): `get_proposal` reads back
 * the calling agent's own proposals and nobody else's, and an agent signed in
 * through Whity ID never gets past what its person may do.
 */
final class GetProposalRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const RANA   = 101;
    private const OMAR   = 202;
    private const BUILDER = 'oauth-agent:builder-for-rana';

    private PDO $pdo;
    private AgentProposalRepository $proposals;
    private DiscoveryTools $discovery;
    private AgentProposalsApiHandler $inbox;
    private McpPrincipal $caller;
    private bool $mayManageForms = true;

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
        $roles->method('hasPermissionForProfile')->willReturnCallback(fn (): bool => $this->mayManageForms);
        $tokens = $this->createMock(TokenValidator::class);
        $tokens->method('validateBearerForMcp')->willReturnCallback(fn (): McpPrincipal => $this->caller);

        $this->caller    = self::agent(self::RANA, self::BUILDER);
        $this->proposals = new AgentProposalRepository($this->pdo);
        $toolsCall       = new ToolsCallHandler($deriver, $router, $roles, $tokens, proposals: $this->proposals);
        $this->inbox     = new AgentProposalsApiHandler($this->proposals, $toolsCall);
        $this->discovery = new DiscoveryTools(
            new ToolsListHandler($deriver, $roles, $tokens),
            $toolsCall,
            $tokens,
            proposals: $this->proposals,
        );
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
        TenantContext::reset();
    }

    public function testAnAgentReadsBackItsProposalAndTheResultOnceApproved(): void
    {
        $id = $this->ask();

        $waiting = $this->get($id);
        self::assertSame('waiting', $waiting['status']);
        self::assertSame(['forms:manage'], $waiting['permissions']);
        self::assertNull($waiting['result']);

        $request       = new Request('POST', '/api/me/agent-proposals', ['Content-Type' => 'application/json'], '');
        $request->user = (object) ['profile_id' => self::RANA];
        self::assertSame(200, $this->inbox->approve($request, ['id' => (string) $id])->getStatusCode());

        $approved = $this->get($id);
        self::assertSame('approved', $approved['status']);
        self::assertSame(['id' => 1], json_decode((string) $approved['result']['content'][0]['text'], true));
    }

    public function testAnExpiredProposalSaysSo(): void
    {
        $id = $this->ask();
        $this->pdo->prepare('UPDATE agent_proposals SET expires_at = :past WHERE id = :id')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 60), ':id' => $id]);

        self::assertSame('expired', $this->get($id)['status']);
    }

    /** @return array<string, array{McpPrincipal}> */
    public static function notTheAsker(): array
    {
        return [
            'another agent of the same person' => [self::agent(self::RANA, 'oauth-agent:someone-else')],
            'the same client for another person' => [self::agent(self::OMAR, self::BUILDER)],
            'the person\'s own client' => [new McpPrincipal(self::RANA, self::RANA, self::TENANT, 'oauth', ['tools:call'], 'oauth:abc')],
            'another workspace' => [new McpPrincipal(self::RANA, self::RANA, 2, AgentScopeGrant::AGENT_KIND, ['tools:call'], self::BUILDER)],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('notTheAsker')]
    public function testNobodyElseCanReadIt(McpPrincipal $other): void
    {
        $id = $this->ask();
        $this->caller = $other;

        $result = $this->discovery->call(['name' => DiscoveryTools::GET_PROPOSAL, 'arguments' => ['id' => $id]], 'bearer');

        self::assertTrue($result['isError']);
        self::assertStringNotContainsString('Leave request', $result['content'][0]['text']);
    }

    public function testAMadeUpIdIsNotFound(): void
    {
        $result = $this->discovery->call(['name' => DiscoveryTools::GET_PROPOSAL, 'arguments' => ['id' => 999]], 'bearer');

        self::assertTrue($result['isError']);
        self::assertSame('No proposal of yours has that id.', $result['content'][0]['text']);
    }

    public function testOnlyAnAgentsListOffersIt(): void
    {
        self::assertContains(DiscoveryTools::GET_PROPOSAL, $this->listed());

        $this->caller = new McpPrincipal(self::RANA, self::RANA, self::TENANT, 'oauth', ['tools:call'], 'oauth:abc');
        self::assertNotContains(DiscoveryTools::GET_PROPOSAL, $this->listed());
    }

    /**
     * The other half of "never more than the person": an agent whose person
     * may not create forms is refused outright. Nothing runs and nothing is
     * put to anyone for approval.
     */
    public function testAnAgentNeverReachesPastItsPerson(): void
    {
        $this->mayManageForms = false;

        try {
            $result = $this->discovery->call(['name' => DiscoveryTools::CALL, 'arguments' => ['operationId' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']]], 'bearer');
            self::assertTrue($result['isError'] ?? false);
        } catch (\Whity\Mcp\JsonRpc\McpException) {
            // Refused before the handler: also fine.
        }

        self::assertSame([], $this->created);
        self::assertSame([], $this->proposals->listFor(self::TENANT, self::RANA));
    }

    private function ask(): int
    {
        $result = $this->discovery->call(['name' => DiscoveryTools::CALL, 'arguments' => ['operationId' => 'post_api_forms', 'arguments' => ['name' => 'Leave request']]], 'bearer');
        self::assertSame([], $this->created, 'an agent\'s write waits for approval');
        return (int) json_decode($result['content'][0]['text'], true)['proposal']['id'];
    }

    /** @return array<string, mixed> */
    private function get(int $id): array
    {
        $result = $this->discovery->call(['name' => DiscoveryTools::GET_PROPOSAL, 'arguments' => ['id' => $id]], 'bearer');
        self::assertArrayNotHasKey('isError', $result);
        return json_decode($result['content'][0]['text'], true)['proposal'];
    }

    /** @return list<string> */
    private function listed(): array
    {
        return array_column(($this->discovery->defaultListHandler())(null, 'bearer')['tools'], 'name');
    }

    private static function agent(int $profileId, string $id): McpPrincipal
    {
        return new McpPrincipal($profileId, $profileId, self::TENANT, AgentScopeGrant::AGENT_KIND, ['tools:call'], $id);
    }
}
