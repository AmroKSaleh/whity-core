<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Http\HttpKernel;
use Whity\Http\Middleware\RbacMiddleware;
use Whity\Auth\JwtParser;

/**
 * WC-206: GET /api/version endpoint.
 *
 * The version endpoint is unversioned (always at /api/version regardless of the
 * API version prefix) and returns the current version, the full supported set,
 * and the default version. It requires no authentication.
 */
class VersionEndpointTest extends TestCase
{
    /**
     * The /api/version route is registered at the exact path (no prefix) and
     * returns the expected JSON payload.
     */
    public function testVersionEndpointReturnsExpectedPayload(): void
    {
        $router = new Router('/v1');
        $router->registerUnversioned(
            'GET',
            '/api/version',
            static function () use ($router): Response {
                $prefix  = ltrim($router->getVersionPrefix(), '/'); // 'v1'
                $version = ltrim($prefix, 'v');                     // '1'
                return new Response(
                    200,
                    (string) json_encode([
                        'version'   => $version,
                        'supported' => [$version],
                        'default'   => $version,
                    ], JSON_THROW_ON_ERROR),
                    ['Content-Type' => 'application/json']
                );
            }
        );

        $match = $router->match(new Request('GET', '/api/version'));
        $this->assertNotNull($match, 'GET /api/version must match');

        $response = ($match['handler'])(new Request('GET', '/api/version'), []);

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('1', $body['version']);
        $this->assertSame(['1'], $body['supported']);
        $this->assertSame('1', $body['default']);
    }

    /**
     * /api/version must NOT match at the versioned path /api/v1/version.
     */
    public function testVersionEndpointNotAccessibleAtVersionedPath(): void
    {
        $router = new Router('/v1');
        $router->registerUnversioned(
            'GET',
            '/api/version',
            static fn (): Response => new Response(200, '{}')
        );

        $this->assertNull(
            $router->match(new Request('GET', '/api/v1/version')),
            'GET /api/v1/version must not match — the endpoint is unversioned'
        );
    }

    /**
     * /api/health and /api/version are both accessible; /api/v1/health is not
     * — infrastructure probes stay at their permanent unversioned paths.
     */
    public function testHealthAndVersionAreUnversionedAndCoexist(): void
    {
        $router = new Router('/v1');
        $router->registerUnversioned('GET', '/api/health', static fn (): Response => new Response(200, '{}'));
        $router->registerUnversioned('GET', '/api/version', static fn (): Response => new Response(200, '{}'));

        $this->assertNotNull($router->match(new Request('GET', '/api/health')));
        $this->assertNotNull($router->match(new Request('GET', '/api/version')));
        $this->assertNull($router->match(new Request('GET', '/api/v1/health')));
        $this->assertNull($router->match(new Request('GET', '/api/v1/version')));
    }
}
