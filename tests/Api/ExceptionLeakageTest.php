<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\AdminApiHandler;
use Whity\Api\TwoFactorHandler;
use Whity\Api\UsersApiHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * WC-186 — no API error response may leak raw internal exception text.
 *
 * Two complementary guarantees are pinned here:
 *
 *  1. Behavioural: representative handlers are driven so the underlying data
 *     access throws an exception carrying a recognisable secret marker. The
 *     resulting 500 response body must be the stable, generic client message and
 *     must NOT contain the thrown exception's raw text (DB errors, table names,
 *     stack details). Server-side diagnosability is preserved separately via
 *     error_log / the structured logger and is out of scope for the client body.
 *
 *  2. Static (regression guard): no handler under src/Api may build a client
 *     response that interpolates $e->getMessage() (or any *->getMessage()) into
 *     Response::error(...). Server-side error_log/logger calls are unaffected, so
 *     this slice can never silently regress.
 */
final class ExceptionLeakageTest extends TestCase
{
    /**
     * A unique, unmistakable fragment that the forced exceptions carry. If it
     * ever appears in a client response body the leak has re-opened. Public so the
     * throwing-PDO anonymous class can reference it.
     */
    public const SECRET = 'SQLSTATE[42P01] relation "users" does not exist :: SECRET-LEAK-MARKER';

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== Behavioural: the body must be generic ====================

    /**
     * AdminApiHandler::stats — the site named in the ticket. When the underlying
     * PDO throws, the client sees the generic "Failed to fetch system stats" and
     * never the raw DB error.
     */
    public function testAdminStatsDoesNotLeakExceptionMessage(): void
    {
        MockRequestFactory::setTestTenant(1);

        $database = Database::withFactory(fn (): PDO => $this->throwingPdo());
        $handler = new AdminApiHandler($database, sys_get_temp_dir());

        $response = $handler->stats(new Request('GET', '/api/admin/stats', []));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertLeakFree($response->getBody(), 'Failed to fetch system stats');
    }

    /**
     * UsersApiHandler::list — when the user query throws, the client sees the
     * generic "Failed to fetch users" and never the raw DB error.
     */
    public function testUsersListDoesNotLeakExceptionMessage(): void
    {
        MockRequestFactory::setTestTenant(1);

        $hooks = $this->createMock(HookManager::class);
        $handler = new UsersApiHandler($this->throwingPdo(), $hooks);

        $response = $handler->list(new Request('GET', '/api/users', []));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertLeakFree($response->getBody(), 'Failed to fetch users');
    }

    /**
     * TwoFactorHandler::setup — a token-authenticated request whose user lookup
     * throws must surface the generic "Failed to setup 2FA", not the raw error.
     */
    public function testTwoFactorSetupDoesNotLeakExceptionMessage(): void
    {
        $tokenValidator = $this->createMock(TokenValidator::class);
        $tokenValidator->method('validateAccessToken')->willReturn(['profile_id' => 1]);

        $handler = new TwoFactorHandler(
            $this->throwingPdo(),
            $this->createMock(TotpService::class),
            $this->createMock(BackupCodesService::class),
            $tokenValidator
        );

        $response = $handler->setup(new Request('POST', '/api/auth/2fa/setup', []));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertLeakFree($response->getBody(), 'Failed to setup 2FA');
    }

    // ==================== Static completeness guard ====================

    /**
     * No file under src/Api may interpolate an exception message into a client
     * response. This greps every handler for `Response::error( ... getMessage() )`
     * across single or multiple lines and fails listing each offending file:line.
     *
     * It deliberately keys on `Response::error(` so legitimate SERVER-SIDE logging
     * (`error_log('...' . $e->getMessage())`, `$this->logger->log(... $e->getMessage())`)
     * and internal string matching (`strpos($e->getMessage(), ...)`) never trip it.
     */
    public function testNoHandlerLeaksExceptionTextIntoResponse(): void
    {
        $apiDir = dirname(__DIR__, 2) . '/src/Api';
        $this->assertDirectoryExists($apiDir);

        $offenders = [];

        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($apiDir, \FilesystemIterator::SKIP_DOTS)
            ),
            '/\.php$/'
        );

        foreach ($files as $file) {
            /** @var \SplFileInfo $file */
            $path = $file->getPathname();
            $source = (string) file_get_contents($path);

            // Match a Response::error( ... ) call whose argument list contains a
            // getMessage() call, even when wrapped across lines. The argument body
            // is non-greedy and forbids an inner ')' so we don't run past the call.
            if (
                preg_match_all(
                    '/Response::error\((?:[^()]|\([^()]*\))*?getMessage\(\)/s',
                    $source,
                    $matches,
                    PREG_OFFSET_CAPTURE
                ) > 0
            ) {
                foreach ($matches[0] as [$snippet, $offset]) {
                    $line = substr_count($source, "\n", 0, (int) $offset) + 1;
                    $offenders[] = sprintf('%s:%d', $path, $line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These sites leak raw exception text into a client response (WC-186):\n"
            . implode("\n", $offenders)
        );
    }

    // ==================== Helpers ====================

    /**
     * Assert a 500 body carries ONLY the generic message and not the leaked text.
     */
    private function assertLeakFree(string $body, string $expectedGenericMessage): void
    {
        $this->assertStringNotContainsString(
            'SECRET-LEAK-MARKER',
            $body,
            'The raw exception text must never reach the client.'
        );
        $this->assertStringNotContainsString('SQLSTATE', $body, 'No DB error code may reach the client.');

        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame($expectedGenericMessage, $decoded['error'] ?? null);
    }

    /**
     * A PDO whose every query/prepare throws an exception carrying the secret
     * marker — the seam used to force the handlers' catch blocks.
     */
    private function throwingPdo(): PDO
    {
        return new class ('sqlite::memory:') extends PDO {
            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                throw new PDOException(ExceptionLeakageTest::SECRET);
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                throw new PDOException(ExceptionLeakageTest::SECRET);
            }
        };
    }
}
