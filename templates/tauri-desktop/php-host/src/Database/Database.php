<?php

declare(strict_types=1);

namespace Whity\Database;

/**
 * Single-connection SQLite shim of production's Whity\Database\Database.
 *
 * SAME FQCN as production so plugin code (e.g.
 * DemoCatalogPlugin::resolvePdo()) resolves it via \Whity\app() unmodified.
 * Production's real class manages a worker-scoped Postgres connection pool
 * (lazy connect, health-ping, reconnect-retry, max-lifetime recycle); this
 * offline host has exactly one local SQLite file and one connection for the
 * lifetime of the process, so none of that machinery applies.
 */
final class Database
{
    public function __construct(private readonly SqliteCompatPdo $pdo)
    {
    }

    public function getPdo(): \PDO
    {
        return $this->pdo;
    }
}
