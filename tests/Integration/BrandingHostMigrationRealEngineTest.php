<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/** Proves migration 027 adds tenants.branding_host (nullable, unique). */
final class BrandingHostMigrationRealEngineTest extends TestCase
{
    public function testBrandingHostColumnExistsAndAcceptsNull(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, branding_host) VALUES (1, 'acme', NULL)");
        /** @var \PDOStatement $stmt */
        $stmt = $pdo->query("SELECT branding_host FROM tenants WHERE id = 1");
        $host = $stmt->fetchColumn();
        self::assertNull($host);
    }

    public function testBrandingHostIsUnique(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, branding_host) VALUES (1, 'a', 'app.acme.com')");
        $this->expectException(\PDOException::class);
        $pdo->exec("INSERT INTO tenants (id, name, branding_host) VALUES (2, 'b', 'app.acme.com')");
    }
}
