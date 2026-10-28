<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\JsonRpc;

use PHPUnit\Framework\TestCase;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\McpFeatureDisabledException;

/**
 * The Dispatcher records when a dedicated MCP token reaches the server, which is
 * what the connect wizard waits on (WHIT-697).
 */
final class DispatcherTokenUseTest extends TestCase
{
    private const PING = '{"jsonrpc":"2.0","method":"ping","id":1}';

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testRecordsTheJtiOfAnMcpToken(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcher($this->principal('user'), $recorded);

        $dispatcher->handle(self::PING, 'bearer');

        self::assertSame(['jti-app'], $recorded);
    }

    public function testDoesNotRecordASessionBearer(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcher($this->principal('session'), $recorded);

        $dispatcher->handle(self::PING, 'bearer');

        self::assertSame([], $recorded, 'a person using their login token is not an app connection');
    }

    public function testDoesNotRecordAnUnauthenticatedCall(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcher(null, $recorded);

        $response = json_decode($dispatcher->handle(self::PING, 'bad'), true);

        self::assertSame(ErrorCode::UNAUTHENTICATED, $response['error']['code'] ?? null);
        self::assertSame([], $recorded);
    }

    public function testDoesNotRecordWhenTheTenantHasMcpOff(): void
    {
        $recorded = [];
        $dispatcher = $this->dispatcher($this->principal('user'), $recorded, tenantEnabled: false);

        try {
            $dispatcher->handle(self::PING, 'bearer');
            self::fail('expected the tenant gate to refuse the call');
        } catch (McpFeatureDisabledException) {
        }

        self::assertSame([], $recorded, '"connected" must not show for a call the server refused');
    }

    public function testAFailedWriteDoesNotFailTheCall(): void
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn($this->principal('user'));
        $dispatcher = new Dispatcher(
            ['ping' => new PingHandler()],
            $validator,
            recordTokenUse: static function (McpPrincipal $principal): void {
                throw new \RuntimeException('database is down');
            },
        );

        $response = json_decode($dispatcher->handle(self::PING, 'bearer'), true);

        self::assertSame([], $response['result'] ?? null);
    }

    /** @param list<string> $recorded */
    private function dispatcher(?McpPrincipal $principal, array &$recorded, bool $tenantEnabled = true): Dispatcher
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn($principal);

        return new Dispatcher(
            ['ping' => new PingHandler()],
            $validator,
            tenantMcpEnabled: static fn (int $tenantId): bool => $tenantEnabled,
            recordTokenUse: static function (McpPrincipal $principal) use (&$recorded): void {
                $recorded[] = $principal->jti;
            },
        );
    }

    private function principal(string $kind): McpPrincipal
    {
        return new McpPrincipal(5, 5, 2, $kind, ['tools:call'], 'jti-app');
    }
}
