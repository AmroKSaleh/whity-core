<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Whity\Api\HealthApiHandler;
use Whity\Core\Request;
use Whity\Database\Database;

/**
 * Tests for the WC-4 health monitoring endpoint handler.
 */
class HealthApiHandlerTest extends TestCase
{
    /**
     * Healthy database: GET /api/health returns 200 with the full contract shape
     * and db_connected = true.
     */
    public function testHealthyDatabaseReturns200WithFullShape(): void
    {
        $db = Database::withFactory(fn (): PDO => $this->healthyPdo());
        $handler = new HealthApiHandler($db);

        $response = $handler->handle(new Request('GET', '/api/health'));

        $this->assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);

        // Exact contract: status, version, workers_active, memory_usage_mb,
        // uptime_seconds, db_connected.
        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('version', $body);
        $this->assertArrayHasKey('workers_active', $body);
        $this->assertArrayHasKey('memory_usage_mb', $body);
        $this->assertArrayHasKey('uptime_seconds', $body);
        $this->assertArrayHasKey('db_connected', $body);

        $this->assertSame('ok', $body['status']);
        // WC-172: operators read a deployment's running version remotely.
        $this->assertSame(\Whity\Core\CoreVersion::VERSION, $body['version']);
        $this->assertTrue($body['db_connected']);
        $this->assertIsInt($body['workers_active']);
        $this->assertGreaterThanOrEqual(1, $body['workers_active']);
        // memory_usage_mb is a float in PHP, but JSON normalises whole values
        // (e.g. 16.0) back to int on decode, so assert it is numeric.
        $this->assertIsNumeric($body['memory_usage_mb']);
        $this->assertGreaterThan(0, $body['memory_usage_mb']);
        $this->assertIsInt($body['uptime_seconds']);
        $this->assertGreaterThanOrEqual(0, $body['uptime_seconds']);
    }

    /**
     * Unreachable database: GET /api/health returns 503 degraded with
     * db_connected = false, and the connection failure never surfaces as an
     * exception to the caller.
     */
    public function testUnreachableDatabaseReturns503Degraded(): void
    {
        // A factory that always fails to connect makes the wrapper throw a typed
        // ConnectionException on query, which the handler catches and maps to a
        // degraded response.
        $db = Database::withFactory(static function (): PDO {
            throw new PDOException('SQLSTATE[08006] could not connect to server');
        });
        $handler = new HealthApiHandler($db);

        $response = $handler->handle(new Request('GET', '/api/health'));

        $this->assertSame(503, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertSame('degraded', $body['status']);
        $this->assertFalse($body['db_connected']);

        // Degraded responses must still expose the full shape (no leaked details).
        // WC-172: version stays readable even while the database is down — that
        // is exactly when an operator checks which build is misbehaving.
        $this->assertArrayHasKey('version', $body);
        $this->assertSame(\Whity\Core\CoreVersion::VERSION, $body['version']);
        $this->assertArrayHasKey('workers_active', $body);
        $this->assertArrayHasKey('memory_usage_mb', $body);
        $this->assertArrayHasKey('uptime_seconds', $body);
        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $response->getBody());
        $this->assertStringNotContainsStringIgnoringCase('PDO', $response->getBody());
    }

    /**
     * workers_active reflects the configured FRANKENPHP_WORKERS env value.
     */
    public function testWorkersActiveReflectsConfiguredEnv(): void
    {
        $previous = $_ENV['FRANKENPHP_WORKERS'] ?? null;
        $_ENV['FRANKENPHP_WORKERS'] = '8';

        try {
            $db = Database::withFactory(fn (): PDO => $this->healthyPdo());
            $handler = new HealthApiHandler($db);

            $response = $handler->handle(new Request('GET', '/api/health'));
            $body = json_decode($response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertSame(8, $body['workers_active']);
        } finally {
            if ($previous === null) {
                unset($_ENV['FRANKENPHP_WORKERS']);
            } else {
                $_ENV['FRANKENPHP_WORKERS'] = $previous;
            }
        }
    }

    /**
     * workers_active falls back to 1 when FRANKENPHP_WORKERS is unset/invalid.
     */
    public function testWorkersActiveDefaultsToOneWhenUnset(): void
    {
        $previous = $_ENV['FRANKENPHP_WORKERS'] ?? null;
        unset($_ENV['FRANKENPHP_WORKERS']);
        $previousServer = $_SERVER['FRANKENPHP_WORKERS'] ?? null;
        unset($_SERVER['FRANKENPHP_WORKERS']);

        try {
            $db = Database::withFactory(fn (): PDO => $this->healthyPdo());
            $handler = new HealthApiHandler($db);

            $response = $handler->handle(new Request('GET', '/api/health'));
            $body = json_decode($response->getBody(), true);
            $this->assertIsArray($body);
            $this->assertSame(1, $body['workers_active']);
        } finally {
            if ($previous !== null) {
                $_ENV['FRANKENPHP_WORKERS'] = $previous;
            }
            if ($previousServer !== null) {
                $_SERVER['FRANKENPHP_WORKERS'] = $previousServer;
            }
        }
    }

    /**
     * uptime_seconds is derived from the injected boot timestamp.
     */
    public function testUptimeIsDerivedFromBootTimestamp(): void
    {
        $db = Database::withFactory(fn (): PDO => $this->healthyPdo());
        $handler = new HealthApiHandler($db, time() - 42);

        $response = $handler->handle(new Request('GET', '/api/health'));
        $body = json_decode($response->getBody(), true);
        $this->assertIsArray($body);
        $this->assertGreaterThanOrEqual(42, $body['uptime_seconds']);
    }

    /**
     * Build an in-memory SQLite PDO that answers the SELECT 1 connectivity ping.
     */
    private function healthyPdo(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    }
}
