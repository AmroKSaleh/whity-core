<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Cli\Commands\BaseCommand;
use Whity\Core\Router;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;

/**
 * WC-162 regression: BaseCommand::callApi() returns whatever the kernel
 * produces — including the kernel's own 404/405 and the middlewares' 401/403,
 * which since the SDK extraction are \Whity\Sdk\Http\Response instances. A
 * core-subclass return hint on callApi() turned every CLI error path into a
 * TypeError (caught live by review; invisible to the suite because the CLI
 * command tests mock callApi itself).
 */
final class BaseCommandCallApiTest extends TestCase
{
    public function testCallApiReturnsKernelErrorResponsesWithoutTypeError(): void
    {
        $command = new class extends BaseCommand {
            public function execute(array $argv): int
            {
                return 0;
            }

            /**
             * @return \Whity\Sdk\Http\Response
             */
            public function call(string $method, string $path): object
            {
                return $this->callApi($method, $path);
            }
        };

        // Wire a minimal real kernel (no DB): one GET route, so a POST to the
        // same path exercises the kernel's 405 path — an SDK-base Response.
        $router = new Router('');
        $router->register('GET', '/api/tenants', static fn ($req) => \Whity\Core\Response::json([]));

        $jwtParser = new JwtParser('cli-test-secret-padded-for-hs256-min-32-byte-key');
        $kernel = new HttpKernel($router, new RbacMiddleware($jwtParser, $this->createMock(RoleChecker::class)));

        $kernelProp = new \ReflectionProperty(BaseCommand::class, 'kernel');
        $kernelProp->setValue($command, $kernel);
        $tokenProp = new \ReflectionProperty(BaseCommand::class, 'token');
        $tokenProp->setValue($command, 'irrelevant');

        $response = $command->call('POST', '/api/tenants');

        $this->assertSame(405, $response->getStatusCode(), 'The CLI must surface the kernel 405, not a TypeError');
        $this->assertStringContainsString('Method Not Allowed', $response->getBody());
    }
}
