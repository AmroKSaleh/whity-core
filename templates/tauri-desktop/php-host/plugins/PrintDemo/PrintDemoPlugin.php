<?php

declare(strict_types=1);

namespace PrintDemo;

use Whity\Native\NativeBridgeClient;
use Whity\Native\NativeBridgeUnavailableException;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

/**
 * The only new "real" plugin in this proof of concept: DemoCatalog has
 * nothing print-worthy and must not be modified, so this tiny plugin exists
 * purely to demonstrate a whity plugin calling back into Rust for native
 * hardware through NativeBridgeClient.
 */
final class PrintDemoPlugin implements PluginInterface
{
    public function getName(): string
    {
        return 'PrintDemo';
    }

    public function getVersion(): string
    {
        return '0.1.0';
    }

    public function getPermissions(): array
    {
        return [];
    }

    public function getHooks(): array
    {
        return [];
    }

    public function getMigrations(): array
    {
        return [];
    }

    public function getRoutes(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => '/api/print-demo/print',
                'handler' => [$this, 'print'],
                'requiredRole' => null,
            ],
        ];
    }

    public function print(Request $request, array $params = []): Response
    {
        $body = json_decode($request->getBody(), true);
        $text = is_array($body) && is_string($body['text'] ?? null) ? $body['text'] : '';
        if ($text === '') {
            return Response::error('text is required', 400);
        }

        try {
            $printer = \Whity\app(NativeBridgeClient::class)->print($text);

            return Response::json(['printer' => $printer]);
        } catch (NativeBridgeUnavailableException $e) {
            return Response::error('Native bridge unavailable: ' . $e->getMessage(), 503);
        }
    }
}
