<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WC-226: GET /api/frontend/features validates plugin `screen:'blocks'`
 * features through the SDK {@see \Whity\Sdk\Frontend\Blocks\BlockValidator}
 * before they reach a renderer, and fails CLOSED on an invalid tree.
 *
 * Acceptance focus:
 *  - a VALID `screen:'blocks'` feature is served WITH its `blocks` tree intact,
 *    and is STILL permission-filtered (a caller lacking its requiredPermission
 *    never sees it);
 *  - an INVALID `screen:'blocks'` feature (unknown block type, or a non-array
 *    `blocks`) is OMITTED — the endpoint still returns 200 with the OTHER valid
 *    features, never a 500, and the response carries no raw validator/error text;
 *  - the omit reason is logged structured + secret-free (plugin/feature id +
 *    validator errors) via the injected logger, never returned to the client;
 *  - existing crud/custom/action features are unaffected.
 *
 * The handler sources features from {@see PluginLoader::getFrontendFeatures()}.
 * The loader's own on-disk normalization path now admits `screen:'blocks'`
 * features (WC-228), and the handler re-validates the tree as a defence-in-depth
 * gate; this slice injects already-shaped loader descriptors via a tiny
 * PluginLoader subclass — exactly the contract the handler consumes — to keep the
 * test focused on the handler's OWN block validation (including the malformed
 * trees the loader would itself reject, exercised here at the handler boundary).
 * RoleChecker is mocked per the sibling FrontendFeatures handler test so each
 * caller's permission set is precise.
 */
final class FrontendFeaturesBlocksTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->router = new Router('');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== valid blocks feature ====================

    public function testValidBlocksFeatureIsServedWithItsBlocksIntact(): void
    {
        TenantContext::setTenantId(1);

        $blocks = [
            [
                'type' => 'section',
                'title' => 'Overview',
                'children' => [
                    ['type' => 'heading', 'level' => 2, 'text' => 'Welcome'],
                    ['type' => 'text', 'value' => 'Hello from a plugin block screen.'],
                ],
            ],
        ];

        $handler = $this->handlerFor(
            [$this->blocksFeature('plugin-dashboard', 'demo:read', $blocks)],
            ['demo:read']
        );

        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');

        $this->assertArrayHasKey('plugin-dashboard', $byId, 'A valid blocks feature must be served');
        $this->assertSame('blocks', $byId['plugin-dashboard']['screen']);
        $this->assertSame($blocks, $byId['plugin-dashboard']['blocks'], 'The validated blocks tree must pass through intact');
        // resource/action are not applicable to a blocks screen.
        $this->assertNull($byId['plugin-dashboard']['resource']);
        $this->assertNull($byId['plugin-dashboard']['action']);
    }

    public function testValidBlocksFeatureIsStillPermissionFiltered(): void
    {
        TenantContext::setTenantId(1);

        $blocks = [['type' => 'heading', 'level' => 1, 'text' => 'Secret']];

        // The caller does NOT hold demo:read, so even a perfectly valid blocks
        // feature must be filtered out — validation is in ADDITION to, never
        // instead of, the per-caller permission filter.
        $handler = $this->handlerFor(
            [$this->blocksFeature('plugin-dashboard', 'demo:read', $blocks)],
            [] // no permissions granted
        );

        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => []], json_decode($response->getBody(), true));
    }

    // ==================== invalid blocks feature (fail-closed) ====================

    public function testInvalidBlocksFeatureIsOmittedAndOthersStillServed(): void
    {
        TenantContext::setTenantId(1);

        $logger = new CapturingLogger();

        $invalidBlocks = [['type' => 'wormhole', 'warp' => 9]]; // unknown block type
        $validBlocks = [['type' => 'text', 'value' => 'I am valid.']];

        $handler = $this->handlerFor(
            [
                $this->blocksFeature('bad-screen', 'demo:read', $invalidBlocks),
                $this->blocksFeature('good-screen', 'demo:read', $validBlocks),
                // A crud feature is unaffected by block validation.
                [
                    'id' => 'demo-widgets',
                    'plugin' => 'Demo',
                    'label' => 'Widgets',
                    'icon' => null,
                    'group' => 'plugins',
                    'order' => 5,
                    'screen' => 'crud',
                    'resource' => ['basePath' => '/api/demo/widgets', 'titleField' => null],
                    'action' => null,
                    'requiredPermission' => 'demo:read',
                ],
            ],
            ['demo:read'],
            $logger
        );

        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode(), 'An invalid blocks feature must never produce a 500');
        $body = $response->getBody();
        $ids = array_column(json_decode($body, true)['data'], 'id');

        $this->assertNotContains('bad-screen', $ids, 'An invalid blocks feature must be omitted (fail-closed)');
        $this->assertContains('good-screen', $ids, 'A valid blocks feature alongside it is still served');
        $this->assertContains('demo-widgets', $ids, 'A crud feature is unaffected by block validation');

        // No raw validator/error text leaks to the client.
        $this->assertStringNotContainsString('wormhole', $body, 'Raw validator errors must not leak to the client');
        $this->assertStringNotContainsString('unknown block type', $body);

        // The omit reason is logged, structured and secret-free: it names the
        // offending feature id and carries the validator errors for operators.
        $this->assertNotSame([], $logger->records, 'The omitted feature must be logged');
        $logged = implode("\n", array_map(
            static fn (array $r): string => $r['message'] . ' ' . json_encode($r['context']),
            $logger->records
        ));
        $this->assertStringContainsString('bad-screen', $logged, 'The log must name the dropped feature id');
        $this->assertStringContainsString('wormhole', $logged, 'The log must carry the validator error for operators');
    }

    public function testNonArrayBlocksFeatureIsOmitted(): void
    {
        TenantContext::setTenantId(1);

        // screen:'blocks' but `blocks` is not an array → fail-closed omit.
        $feature = [
            'id' => 'broken-blocks',
            'plugin' => 'Demo',
            'label' => 'Broken',
            'icon' => null,
            'group' => 'plugins',
            'order' => 1,
            'screen' => 'blocks',
            'resource' => null,
            'action' => null,
            'requiredPermission' => 'demo:read',
            'blocks' => 'not-an-array',
        ];

        $valid = $this->blocksFeature('ok-blocks', 'demo:read', [['type' => 'divider']]);

        $handler = $this->handlerFor([$feature, $valid], ['demo:read']);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $ids = array_column(json_decode($response->getBody(), true)['data'], 'id');

        $this->assertNotContains('broken-blocks', $ids, 'A blocks feature with a non-array blocks must be omitted');
        $this->assertContains('ok-blocks', $ids);
    }

    public function testMissingBlocksKeyForBlocksScreenIsOmitted(): void
    {
        TenantContext::setTenantId(1);

        // screen:'blocks' with NO `blocks` key at all → fail-closed omit.
        $feature = [
            'id' => 'no-blocks-key',
            'plugin' => 'Demo',
            'label' => 'No blocks',
            'icon' => null,
            'group' => 'plugins',
            'order' => 1,
            'screen' => 'blocks',
            'resource' => null,
            'action' => null,
            'requiredPermission' => 'demo:read',
        ];

        $handler = $this->handlerFor([$feature], ['demo:read']);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => []], json_decode($response->getBody(), true));
    }

    // ==================== other screens unaffected ====================

    public function testCustomAndCrudFeaturesAreUnaffected(): void
    {
        TenantContext::setTenantId(1);

        $features = [
            [
                'id' => 'demo-widgets',
                'plugin' => 'Demo',
                'label' => 'Widgets',
                'icon' => 'box',
                'group' => 'plugins',
                'order' => 7,
                'screen' => 'crud',
                'resource' => ['basePath' => '/api/demo/widgets', 'titleField' => 'name'],
                'action' => null,
                'requiredPermission' => 'demo:read',
            ],
            [
                'id' => 'demo-console',
                'plugin' => 'Demo',
                'label' => 'Console',
                'icon' => null,
                'group' => 'plugins',
                'order' => 100,
                'screen' => 'custom',
                'resource' => null,
                'action' => null,
                'requiredPermission' => 'demo:read',
            ],
        ];

        $handler = $this->handlerFor($features, ['demo:read']);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');

        $this->assertArrayHasKey('demo-widgets', $byId);
        $this->assertArrayHasKey('demo-console', $byId);
        $this->assertSame('crud', $byId['demo-widgets']['screen']);
        $this->assertSame('custom', $byId['demo-console']['screen']);
        // Non-blocks features must not gain a `blocks` key.
        $this->assertArrayNotHasKey('blocks', $byId['demo-widgets']);
        $this->assertArrayNotHasKey('blocks', $byId['demo-console']);
    }

    // ==================== helpers ====================

    /**
     * A normalized loader-shaped `screen:'blocks'` descriptor.
     *
     * @param list<array<string, mixed>> $blocks
     * @return array<string, mixed>
     */
    private function blocksFeature(string $id, string $permission, array $blocks): array
    {
        return [
            'id' => $id,
            'plugin' => 'Demo',
            'label' => ucfirst($id),
            'icon' => null,
            'group' => 'plugins',
            'order' => 50,
            'screen' => 'blocks',
            'resource' => null,
            'action' => null,
            'requiredPermission' => $permission,
            'blocks' => $blocks,
        ];
    }

    /**
     * Build a handler whose PluginLoader returns exactly the given descriptors
     * and whose RoleChecker grants exactly the given permissions.
     *
     * @param list<array<string, mixed>> $features
     * @param array<int, string> $granted
     */
    private function handlerFor(array $features, array $granted, ?CapturingLogger $logger = null): FrontendFeaturesApiHandler
    {
        $loader = new class ($features) extends PluginLoader {
            /** @param list<array<string, mixed>> $features */
            public function __construct(private array $features)
            {
                parent::__construct(
                    sys_get_temp_dir() . '/whity_blocks_' . uniqid(),
                    new Router(''),
                    new PermissionRegistry(),
                    new HookManager()
                );
            }

            public function getFrontendFeatures(): array
            {
                return $this->features;
            }
        };

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')
            ->willReturnCallback(
                static fn (int $userId, string $permission, int $tenantId): bool => in_array($permission, $granted, true)
            );

        return new FrontendFeaturesApiHandler($loader, $roleChecker, $this->router, $logger);
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['user_id' => $userId];

        return $request;
    }
}

/**
 * In-memory PSR-3 logger that captures records so a test can assert the
 * structured, secret-free omit reason without it ever reaching a client.
 */
final class CapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param mixed $level
     * @param array<string, mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
