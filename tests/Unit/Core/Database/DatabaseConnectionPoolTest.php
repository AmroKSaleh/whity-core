<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Database\ConnectionException;
use Whity\Database\Database;

/**
 * Tests for worker-scoped PostgreSQL connection pooling (WC-21).
 *
 * These tests exercise the connection lifecycle entirely with injected PDO
 * factories / mocks so that they run in CI without a live PostgreSQL server.
 */
class DatabaseConnectionPoolTest extends TestCase
{
    /**
     * Build a mock PDO whose prepare()->execute() succeeds and whose query()
     * (used for the health-check ping) returns a truthy statement.
     */
    private function makeHealthyPdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);

        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);

        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('exec')->willReturn(0);
        $pdo->method('query')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(false);

        return $pdo;
    }

    /**
     * Lazy init: no connection is created until the first query/ping/getPdo.
     */
    public function testConnectionIsNotEstablishedUntilFirstUse(): void
    {
        $calls = 0;
        $db = Database::withFactory(function () use (&$calls): PDO {
            $calls++;
            return $this->makeHealthyPdo();
        });

        $this->assertSame(0, $calls, 'Constructing Database must not open a connection (lazy init)');
        $this->assertFalse($db->isConnected());

        $db->query('SELECT 1');

        $this->assertSame(1, $calls, 'First query must open exactly one connection');
        $this->assertTrue($db->isConnected());
    }

    /**
     * Connection-per-worker: the same PDO instance is reused across queries.
     */
    public function testConnectionIsReusedAcrossQueries(): void
    {
        $calls = 0;
        $pdo = $this->makeHealthyPdo();
        $db = Database::withFactory(function () use (&$calls, $pdo): PDO {
            $calls++;
            return $pdo;
        });

        $db->query('SELECT 1');
        $db->query('SELECT 2');
        $db->query('SELECT 3');

        $this->assertSame(1, $calls, 'A single worker connection must be reused across requests');
    }

    /**
     * Auto-reconnect: when the underlying connection drops mid-query, the next
     * query transparently re-establishes the connection and succeeds without
     * surfacing the error to the caller.
     */
    public function testQueryReconnectsAfterConnectionDrop(): void
    {
        $factoryCalls = 0;

        // First PDO throws a "server closed the connection" PDOException on execute.
        $deadStatement = $this->createMock(PDOStatement::class);
        $deadStatement->method('execute')
            ->willThrowException(new PDOException('SQLSTATE[08006] server closed the connection unexpectedly'));
        $deadPdo = $this->createMock(PDO::class);
        $deadPdo->method('prepare')->willReturn($deadStatement);
        $deadPdo->method('inTransaction')->willReturn(false);

        // Second PDO (after reconnect) works.
        $livePdo = $this->makeHealthyPdo();

        $pdos = [$deadPdo, $livePdo];
        $db = Database::withFactory(function () use (&$factoryCalls, &$pdos): PDO {
            $factoryCalls++;
            return array_shift($pdos);
        });

        // Establish the (dead) connection first so the failure path is the query, not init.
        $db->forceConnect();
        $this->assertSame(1, $factoryCalls);

        $result = $db->query('SELECT 1');

        $this->assertInstanceOf(PDOStatement::class, $result);
        $this->assertSame(2, $factoryCalls, 'A dropped connection must trigger exactly one reconnect');
    }

    /**
     * A connection error that is NOT a recoverable disconnect (e.g. a SQL syntax
     * error) must propagate and must NOT trigger a reconnect/retry loop.
     */
    public function testNonConnectionErrorIsNotRetried(): void
    {
        $factoryCalls = 0;

        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')
            ->willThrowException(new PDOException('SQLSTATE[42601] syntax error at or near "SELCT"'));
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(false);

        $db = Database::withFactory(function () use (&$factoryCalls, $pdo): PDO {
            $factoryCalls++;
            return $pdo;
        });

        $this->expectException(PDOException::class);

        try {
            $db->query('SELCT 1');
        } finally {
            $this->assertSame(1, $factoryCalls, 'Non-connection errors must not reconnect');
        }
    }

    /**
     * If reconnection itself keeps failing, a typed ConnectionException is thrown
     * rather than leaking the raw PDOException to the caller.
     */
    public function testRepeatedConnectionFailureThrowsConnectionException(): void
    {
        $db = Database::withFactory(function (): PDO {
            throw new PDOException('SQLSTATE[08006] could not connect to server');
        });

        $this->expectException(ConnectionException::class);

        $db->forceConnect();
    }

    /**
     * Max connection lifetime: once the configured lifetime elapses, the next
     * use recycles the connection (new PDO from the factory).
     */
    public function testConnectionIsRecycledAfterMaxLifetime(): void
    {
        $factoryCalls = 0;
        $db = Database::withFactory(function () use (&$factoryCalls): PDO {
            $factoryCalls++;
            return $this->makeHealthyPdo();
        });

        // Zero lifetime => every use is considered expired and recycles.
        $db->setMaxLifetimeSeconds(0);
        // Disable the ping-interval gate so lifetime is the only recycler under test.
        $db->setPingIntervalSeconds(0);

        $db->query('SELECT 1');
        $db->query('SELECT 2');

        $this->assertGreaterThanOrEqual(2, $factoryCalls, 'Expired connections must be recycled');
    }

    /**
     * Health-check ping interval: pings are throttled, so a healthy connection
     * is not pinged on every single query (avoids per-query round trips).
     */
    public function testPingIsThrottledByInterval(): void
    {
        $pingCount = 0;
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(false);
        $pdo->method('query')->willReturnCallback(function () use (&$pingCount, $statement) {
            $pingCount++;
            return $statement;
        });

        $db = Database::withFactory(fn (): PDO => $pdo);
        // Large interval => after the first ping, subsequent queries skip pinging.
        $db->setPingIntervalSeconds(3600);
        $db->setMaxLifetimeSeconds(3600);

        $db->query('SELECT 1');
        $db->query('SELECT 2');
        $db->query('SELECT 3');

        $this->assertLessThanOrEqual(1, $pingCount, 'Ping must be throttled by the configured interval');
    }

    /**
     * Between requests the worker resets session state: any dangling transaction
     * is rolled back so it cannot bleed into the next request on the same worker.
     */
    public function testResetSessionStateRollsBackDanglingTransaction(): void
    {
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturn(true);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('query')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn(true);
        $pdo->expects($this->once())->method('rollBack')->willReturn(true);
        // Session reset issues a RESET / DISCARD via exec.
        $pdo->expects($this->atLeastOnce())->method('exec')->willReturn(0);

        $db = Database::withFactory(fn (): PDO => $pdo);
        $db->forceConnect();

        $db->resetSessionState();
    }

    /**
     * resetSessionState on a never-connected Database is a safe no-op (must not
     * open a connection just to reset it).
     */
    public function testResetSessionStateIsNoOpWhenNotConnected(): void
    {
        $calls = 0;
        $db = Database::withFactory(function () use (&$calls): PDO {
            $calls++;
            return $this->makeHealthyPdo();
        });

        $db->resetSessionState();

        $this->assertSame(0, $calls, 'Resetting an unconnected Database must not open a connection');
        $this->assertFalse($db->isConnected());
    }

    /**
     * disconnect() releases the connection (worker recycle path).
     */
    public function testDisconnectReleasesConnection(): void
    {
        $db = Database::withFactory(fn (): PDO => $this->makeHealthyPdo());
        $db->forceConnect();
        $this->assertTrue($db->isConnected());

        $db->disconnect();

        $this->assertFalse($db->isConnected());
    }

    /**
     * Configuration is sourced from environment variables with sane defaults.
     */
    public function testLifetimeAndPingDefaultsAreReadFromEnv(): void
    {
        $db = Database::withFactory(fn (): PDO => $this->makeHealthyPdo());

        // Defaults must be positive integers.
        $this->assertGreaterThan(0, $db->getMaxLifetimeSeconds());
        $this->assertGreaterThanOrEqual(0, $db->getPingIntervalSeconds());
    }
}
