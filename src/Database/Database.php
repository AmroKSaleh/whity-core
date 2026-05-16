<?php

namespace Whity\Database;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Database connection wrapper
 *
 * Manages PostgreSQL connections with prepared statements and exception handling.
 * Uses environment variables for connection configuration.
 */
class Database
{
    private PDO $pdo;

    /**
     * Constructor
     *
     * @param string $dsn Database DSN (e.g., "pgsql:host=localhost;port=5432;dbname=whity_core")
     * @param string $user Database user
     * @param string $password Database password
     */
    public function __construct(string $dsn, string $user, string $password)
    {
        $this->pdo = new PDO($dsn, $user, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /**
     * Execute a prepared statement with parameters
     *
     * @param string $sql SQL statement with named placeholders
     * @param array $params Named parameters array
     * @return PDOStatement Executed statement
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    /**
     * Execute raw SQL statement
     *
     * @param string $sql SQL statement
     * @return int Number of affected rows
     */
    public function exec(string $sql): int
    {
        return $this->pdo->exec($sql);
    }

    /**
     * Get the underlying PDO instance
     *
     * @return PDO PDO instance
     */
    public function getPdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Create a database connection using environment variables
     *
     * Required environment variables:
     * - DB_HOST: Database host (default: localhost)
     * - DB_PORT: Database port (default: 5432)
     * - DB_NAME: Database name (default: whity_core)
     * - DB_USER: Database user (required)
     * - DB_PASSWORD: Database password (required)
     *
     * @return self Database connection instance
     * @throws RuntimeException if required environment variables are missing
     */
    public static function connect(): self
    {
        // Validate required environment variables
        $required = ['DB_USER', 'DB_PASSWORD'];
        foreach ($required as $var) {
            if (empty($_ENV[$var] ?? null)) {
                throw new RuntimeException("Missing required environment variable: {$var}");
            }
        }

        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? 5432;
        $dbName = $_ENV['DB_NAME'] ?? 'whity_core';
        $user = $_ENV['DB_USER'];
        $password = $_ENV['DB_PASSWORD'];

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";

        return new self($dsn, $user, $password);
    }
}
