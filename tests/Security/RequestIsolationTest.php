<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;
use Whity\Core\Router;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;

class RequestIsolationTest extends TestCase
{
    private HttpKernel $kernel;
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $jwtParser = $this->createMock(JwtParser::class);
        $roleChecker = $this->createMock(RoleChecker::class);
        $rbacMiddleware = new RbacMiddleware($jwtParser, $roleChecker);
        $this->kernel = new HttpKernel($this->router, $rbacMiddleware);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Test case for global variable isolation ($GLOBALS)
     */
    public function testGlobalVariablesAreIsolated(): void
    {
        // Register handler for request A that sets a global variable
        $this->router->register('GET', '/request-a', function(Request $request): Response {
            $GLOBALS['user_id'] = 42;
            $GLOBALS['custom_request_state'] = 'active';
            return Response::json(['status' => 'ok']);
        });

        // Register handler for request B that asserts the global variables are NOT present
        $this->router->register('GET', '/request-b', function(Request $request): Response {
            $userIdExists = isset($GLOBALS['user_id']);
            $customStateExists = isset($GLOBALS['custom_request_state']);
            return Response::json([
                'user_id_exists' => $userIdExists,
                'custom_state_exists' => $customStateExists
            ]);
        });

        // Execute Request A
        $requestA = new Request('GET', '/request-a');
        $responseA = $this->kernel->handle($requestA);
        $this->assertSame(200, $responseA->getStatusCode());

        // Verify globals were set during Request A (they are cleared in finally block of handle(), so they should be unset immediately after handle() completes)
        $this->assertArrayNotHasKey('user_id', $GLOBALS);
        $this->assertArrayNotHasKey('custom_request_state', $GLOBALS);

        // Execute Request B
        $requestB = new Request('GET', '/request-b');
        $responseB = $this->kernel->handle($requestB);
        $this->assertSame(200, $responseB->getStatusCode());

        $data = json_decode($responseB->getBody(), true);
        $this->assertFalse($data['user_id_exists']);
        $this->assertFalse($data['custom_state_exists']);
    }

    /**
     * Test case for namespaced register_shutdown_function isolation
     */
    public function testShutdownFunctionsAreExecutedAndIsolated(): void
    {
        $executionCount = 0;
        $passedArg = null;

        // Register handler for request A that registers a shutdown function
        // Note: we are in Tests\Security namespace, so register_shutdown_function is shadowed
        $this->router->register('GET', '/request-a', function(Request $request) use (&$executionCount, &$passedArg): Response {
            register_shutdown_function(function(string $arg) use (&$executionCount, &$passedArg) {
                $executionCount++;
                $passedArg = $arg;
            }, 'hello-world');

            return Response::json(['status' => 'ok']);
        });

        // Register handler for request B
        $this->router->register('GET', '/request-b', function(Request $request): Response {
            return Response::json(['status' => 'ok']);
        });

        // Execute Request A
        $requestA = new Request('GET', '/request-a');
        $responseA = $this->kernel->handle($requestA);
        $this->assertSame(200, $responseA->getStatusCode());

        // Verify shutdown function ran at the end of Request A
        $this->assertSame(1, $executionCount);
        $this->assertSame('hello-world', $passedArg);

        // Execute Request B
        $requestB = new Request('GET', '/request-b');
        $responseB = $this->kernel->handle($requestB);
        $this->assertSame(200, $responseB->getStatusCode());

        // Verify shutdown function from Request A did NOT run again
        $this->assertSame(1, $executionCount);
    }

    /**
     * Test case for Core classes static properties reset
     */
    public function testStaticPropertiesAreReset(): void
    {
        // Assert initial state is clean
        $this->assertNull(TenantContext::getTenantId());
        $this->assertFalse(TenantContext::hasTenant());

        // Register handler for request A that sets static state
        $this->router->register('GET', '/request-a', function(Request $request): Response {
            TenantContext::setTenantId(123);
            return Response::json(['status' => 'ok']);
        });

        // Register handler for request B that asserts static state is clean
        $this->router->register('GET', '/request-b', function(Request $request): Response {
            return Response::json([
                'tenant_id' => TenantContext::getTenantId(),
                'has_tenant' => TenantContext::hasTenant()
            ]);
        });

        // Execute Request A
        $requestA = new Request('GET', '/request-a');
        $responseA = $this->kernel->handle($requestA);
        $this->assertSame(200, $responseA->getStatusCode());

        // Execute Request B
        $requestB = new Request('GET', '/request-b');
        $responseB = $this->kernel->handle($requestB);
        $this->assertSame(200, $responseB->getStatusCode());

        $data = json_decode($responseB->getBody(), true);
        $this->assertNull($data['tenant_id']);
        $this->assertFalse($data['has_tenant']);
    }

    /**
     * Test case for session and superglobal isolation
     */
    public function testSessionAndSuperglobalsAreIsolated(): void
    {
        // Register handler for request A that populates superglobals and starts session
        $this->router->register('POST', '/request-a', function(Request $request): Response {
            $_GET['param'] = 'leak';
            $_POST['data'] = 'leak';
            $_COOKIE['session'] = 'leak';
            $_FILES['file'] = ['leak'];
            $_REQUEST['req'] = 'leak';

            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
            $_SESSION['user'] = 'leak';

            return Response::json(['status' => 'ok']);
        });

        // Register handler for request B that asserts superglobals and session are clean
        $this->router->register('GET', '/request-b', function(Request $request): Response {
            $sessionActive = session_status() === PHP_SESSION_ACTIVE;
            $sessionVal = isset($_SESSION['user']) ? $_SESSION['user'] : null;

            return Response::json([
                'get' => $_GET,
                'post' => $_POST,
                'cookie' => $_COOKIE,
                'files' => $_FILES,
                'request' => $_REQUEST,
                'session_active' => $sessionActive,
                'session_val' => $sessionVal
            ]);
        });

        // Execute Request A
        $requestA = new Request('POST', '/request-a');
        $responseA = $this->kernel->handle($requestA);
        $this->assertSame(200, $responseA->getStatusCode());

        // Execute Request B
        $requestB = new Request('GET', '/request-b');
        $responseB = $this->kernel->handle($requestB);
        $this->assertSame(200, $responseB->getStatusCode());

        $data = json_decode($responseB->getBody(), true);
        $this->assertEmpty($data['get']);
        $this->assertEmpty($data['post']);
        $this->assertEmpty($data['cookie']);
        $this->assertEmpty($data['files']);
        $this->assertEmpty($data['request']);
        $this->assertFalse($data['session_active']);
        $this->assertNull($data['session_val']);
    }
}
