<?php

declare(strict_types=1);

namespace HelloWorld;

use HelloWorld\Migrations\CreateHelloGreetingsTable;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginInterface;

/**
 * HelloWorldPlugin
 *
 * A complete, copy-paste reference plugin that accompanies the
 * {@see docs/wiki/Plugin-Development.md} tutorial. It demonstrates every
 * capability surface exposed by {@see PluginInterface}:
 *
 *  - a public route: GET /api/hello
 *  - an admin-only route: GET /api/hello/admin
 *  - permissions declared in the mandated `resource:action` colon notation
 *  - a hook that runs custom logic before a user is created (`user.creating`)
 *  - a migration class registered for the platform migration runner
 *
 * It lives in its own directory (`plugins/HelloWorld/`) so the PluginLoader
 * resolves it under the `HelloWorld` namespace prefix (directory name) and
 * auto-discovers it without any manual registration.
 *
 * Since WC-162 the plugin depends ONLY on the standalone `whity/plugin-sdk`
 * package — never on whity-core — which is what makes it distributable to
 * any Whity-based host application.
 */
final class HelloWorldPlugin implements PluginInterface
{
    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return 'HelloWorld';
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
                'path' => '/api/hello',
                'handler' => [$this, 'hello'],
                'requiredRole' => null,
            ],
            [
                'method' => 'GET',
                'path' => '/api/hello/admin',
                'handler' => [$this, 'adminHello'],
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
            'hello:view',
            'hello:manage',
        ];
    }

    /**
     * @inheritDoc
     */
    public function getHooks(): array
    {
        // Events::USER_CREATING is the real filter hook dispatched by the host
        // immediately before a user row is inserted. Listeners receive the
        // (mutable) user payload and must return it.
        return [
            Events::USER_CREATING => [
                'callback' => [$this, 'onUserCreating'],
                'priority' => 10,
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getMigrations(): array
    {
        return [
            CreateHelloGreetingsTable::class,
        ];
    }

    /**
     * Handle the public greeting route: GET /api/hello.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response A JSON greeting.
     */
    public function hello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello, World!',
            'plugin' => $this->getName(),
            'version' => $this->getVersion(),
        ]);
    }

    /**
     * Handle the admin-only greeting route: GET /api/hello/admin.
     *
     * The router enforces the `requiredRole` of `admin` declared in
     * {@see getRoutes()} before this handler is ever invoked.
     *
     * @param Request $request The incoming HTTP request.
     * @return Response A JSON greeting for administrators.
     */
    public function adminHello(Request $request): Response
    {
        return Response::json([
            'message' => 'Hello, administrator!',
            'plugin' => $this->getName(),
        ]);
    }

    /**
     * Run custom logic before a user is created.
     *
     * Subscribed to the `user.creating` filter hook. The platform passes the
     * pending user payload (`email`, `password`, `role_id`); a filter hook may
     * inspect and mutate it, then MUST return the (possibly modified) array so
     * downstream listeners and the core see the change.
     *
     * Here we normalise the email to lower-case and stamp the payload so the
     * effect is observable, without performing any I/O.
     *
     * @param array<string, mixed> $data    The pending user payload.
     * @param array<string, mixed> $context Execution context (tenant_id, timestamp).
     * @return array<string, mixed> The filtered payload.
     */
    public function onUserCreating(array $data, array $context): array
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = strtolower(trim($data['email']));
        }

        $data['hello_world_greeted'] = true;

        return $data;
    }
}
