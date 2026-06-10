<?php

declare(strict_types=1);

namespace Whity\Plugins;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

/**
 * ExamplePlugin
 *
 * A sample reference implementation of the SDK plugin contract
 * ({@see PluginInterface}, WC-162). Declares routes, custom permissions, and
 * hooks to demonstrate plugin integration capabilities — depending only on
 * `whity/plugin-sdk`, never on whity-core.
 */
class ExamplePlugin implements PluginInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'ExamplePlugin';
    }

    /**
     * @inheritDoc
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * @inheritDoc
     */
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/example/hello',
                'handler' => [$this, 'hello'],
                'requiredRole' => null,
            ],
            [
                'method' => 'POST',
                'path' => '/api/example/secure',
                'handler' => [$this, 'secureAction'],
                'requiredRole' => 'admin',
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getPermissions(): array
    {
        // Permissions use the mandated `resource:action` colon notation,
        // validated against /^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/ by the RBAC layer.
        return [
            'example:view',
            'example:admin',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getHooks(): array
    {
        return [
            'user.login' => [$this, 'onUserLogin'],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getMigrations(): array
    {
        return [];
    }

    /**
     * Handle the public hello route
     *
     * @param Request $request The incoming HTTP request
     * @return Response The HTTP response
     */
    public function hello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello from ExamplePlugin!',
            'plugin' => $this->getName(),
            'version' => $this->getVersion(),
        ]);
    }

    /**
     * Handle the secure admin route
     *
     * @param Request $request The incoming HTTP request
     * @return Response The HTTP response
     */
    public function secureAction(Request $request): Response
    {
        return Response::json([
            'message' => 'Secure content accessed successfully.',
        ]);
    }

    /**
     * Hook listener for user.login event
     *
     * @param array<string, mixed> $data Hook input data
     * @param array<string, mixed> $context Hook execution context
     * @return array<string, mixed> Modified output data
     */
    public function onUserLogin(array $data, array $context): array
    {
        $data['example_plugin_notified'] = true;
        return $data;
    }
}
