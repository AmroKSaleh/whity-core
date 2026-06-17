<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Elmak\ElmakPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Request;

require_once dirname(__DIR__, 2) . '/plugins/Elmak/ElmakPlugin.php';

/**
 * Functional integration test for the Elmak public asset routing.
 */
final class ElmakAssetRoutingTest extends TestCase
{
    private ElmakPlugin $plugin;

    protected function setUp(): void
    {
        $this->plugin = new ElmakPlugin();
    }

    public function testRoutesRegisterCorrectly(): void
    {
        $routes = $this->plugin->getRoutes();
        $assetRoute = null;

        foreach ($routes as $route) {
            if ($route['path'] === '/api/elmak/assets/{path:.+}') {
                $assetRoute = $route;
                break;
            }
        }

        $this->assertNotNull($assetRoute, 'Asset route should be registered');
        $this->assertSame('GET', $assetRoute['method']);
        $this->assertSame([$this->plugin, 'serveAsset'], $assetRoute['handler']);
    }

    public function testServeValidAsset(): void
    {
        $request = new Request('GET', '/api/elmak/assets/test.js');
        $params = ['path' => 'test.js'];

        $response = $this->plugin->serveAsset($request, $params);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Elmak public asset loaded successfully', $response->getBody());
        
        $headers = $response->getHeaders();
        $this->assertArrayHasKey('content-type', $headers);
        $this->assertSame('application/javascript', $headers['content-type']);
        $this->assertArrayHasKey('cache-control', $headers);
        $this->assertSame('public, max-age=31536000', $headers['cache-control']);
    }

    public function testServeNonExistentAssetReturns404(): void
    {
        $request = new Request('GET', '/api/elmak/assets/missing.js');
        $params = ['path' => 'missing.js'];

        $response = $this->plugin->serveAsset($request, $params);

        $this->assertSame(404, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Asset not found', $body['error']);
    }

    public function testServeDirectoryTraversalReturns400(): void
    {
        $request = new Request('GET', '/api/elmak/assets/../ElmakPlugin.php');
        $params = ['path' => '../ElmakPlugin.php'];

        $response = $this->plugin->serveAsset($request, $params);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Invalid asset path', $body['error']);
    }

    public function testServeBackslashTraversalReturns400(): void
    {
        $request = new Request('GET', '/api/elmak/assets/..\\ElmakPlugin.php');
        $params = ['path' => '..\\ElmakPlugin.php'];

        $response = $this->plugin->serveAsset($request, $params);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Invalid asset path', $body['error']);
    }

    public function testServeNullByteTraversalReturns400(): void
    {
        $request = new Request('GET', "/api/elmak/assets/test.js\0.php");
        $params = ['path' => "test.js\0.php"];

        $response = $this->plugin->serveAsset($request, $params);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Invalid asset path', $body['error']);
    }

    public function testServeEmptyPathReturns400(): void
    {
        $request = new Request('GET', '/api/elmak/assets/');
        $params = ['path' => ''];

        $response = $this->plugin->serveAsset($request, $params);

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Asset path is required', $body['error']);
    }
}
