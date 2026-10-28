<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Mcp\Auth\OAuth\ProtectedResource;
use Whity\Mcp\JsonRpc\Dispatcher;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\Lifecycle\PingHandler;
use Whity\Mcp\Transport\McpTransportHandler;

/**
 * OAuth Protected Resource Metadata and the 401 challenge (RFC 9728, MCP
 * authorization; WHIT-709). Expected values are the RFC's field names and the
 * spec's header format, written out.
 */
final class ProtectedResourceTest extends TestCase
{
    private const RESOURCE = 'https://api.acme.test/mcp';
    private const ISSUER   = 'https://auth.whity.test';

    public function testMetadataNamesTheResourceAndWhereToSignIn(): void
    {
        $response = (new ProtectedResource(self::ISSUER, self::RESOURCE))->metadata(new Request('GET', '/.well-known/oauth-protected-resource/mcp'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame([
            'resource'                 => self::RESOURCE,
            'authorization_servers'    => [self::ISSUER],
            'scopes_supported'         => ['tools:call', 'resources:read', 'prompts:read'],
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'Whity',
        ], json_decode($response->getBody(), true));
        self::assertStringContainsString('public', self::header($response, 'Cache-Control') ?? '');
    }

    public function testTheMetadataUrlInsertsTheWellKnownPathBeforeTheResourcePath(): void
    {
        self::assertSame(
            'https://api.acme.test/.well-known/oauth-protected-resource/mcp',
            (new ProtectedResource(self::ISSUER, self::RESOURCE))->metadataUrl(),
        );
        self::assertSame(
            'http://localhost:8080/.well-known/oauth-protected-resource/mcp',
            (new ProtectedResource(self::ISSUER, 'http://localhost:8080/mcp'))->metadataUrl(),
        );
    }

    public function testTheChallengePointsAtTheMetadata(): void
    {
        self::assertSame(
            'Bearer resource_metadata="https://api.acme.test/.well-known/oauth-protected-resource/mcp", scope="tools:call resources:read prompts:read"',
            (new ProtectedResource(self::ISSUER, self::RESOURCE))->challenge(),
        );
    }

    public function testOffUntilBothTheIssuerAndTheResourceAreKnown(): void
    {
        foreach ([[null, self::RESOURCE], [self::ISSUER, null], [null, null]] as [$issuer, $resource]) {
            $resourceServer = new ProtectedResource($issuer, $resource);
            self::assertFalse($resourceServer->enabled());
            self::assertNull($resourceServer->metadataUrl());
            self::assertSame('Bearer', $resourceServer->challenge());
            self::assertSame(404, $resourceServer->metadata(new Request('GET', '/'))->getStatusCode());
        }
    }

    public function testFromEnvTrimsTheIssuerAndTreatsEmptyAsOff(): void
    {
        $_ENV['MCP_OAUTH_ISSUER'] = ' https://auth.whity.test/ ';
        try {
            self::assertTrue(ProtectedResource::fromEnv(self::RESOURCE)->enabled());
            self::assertStringContainsString('"authorization_servers":["https://auth.whity.test"]', ProtectedResource::fromEnv(self::RESOURCE)->metadata(new Request('GET', '/'))->getBody());
            $_ENV['MCP_OAUTH_ISSUER'] = '';
            self::assertFalse(ProtectedResource::fromEnv(self::RESOURCE)->enabled());
        } finally {
            unset($_ENV['MCP_OAUTH_ISSUER']);
        }
    }

    public function testAnUnauthenticatedCallIs401WithTheChallenge(): void
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn(null);
        $transport = new McpTransportHandler(
            new Dispatcher(['ping' => new PingHandler()], $validator),
            protectedResource: new ProtectedResource(self::ISSUER, self::RESOURCE),
        );

        $response = $transport->handlePost(new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame((new ProtectedResource(self::ISSUER, self::RESOURCE))->challenge(), self::header($response, 'WWW-Authenticate'));
        self::assertSame(ErrorCode::UNAUTHENTICATED, json_decode($response->getBody(), true)['error']['code']);
    }

    public function testWithoutOAuthTheChallengeIsBare(): void
    {
        $validator = $this->createMock(TokenValidator::class);
        $validator->method('validateBearerForMcp')->willReturn(null);
        $transport = new McpTransportHandler(new Dispatcher(['ping' => new PingHandler()], $validator));

        $response = $transport->handlePost(new Request('POST', '/mcp', ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1,"method":"ping"}'));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer', self::header($response, 'WWW-Authenticate'));
    }

    /** A response header by name, case-insensitively, as HTTP reads it. */
    private static function header(\Whity\Sdk\Http\Response $response, string $name): ?string
    {
        foreach ($response->getHeaders() as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }
        return null;
    }
}
