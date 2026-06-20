<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;

final class HostResolverTest extends TestCase
{
    private function resolver(\PDO $pdo): HostResolver
    {
        return new HostResolver(new TenantHostRepository($pdo), 'whity.app');
    }

    private function seed(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO tenants (id, name, slug, branding_host) VALUES (1, 'Acme', 'acme', NULL)");
        $pdo->exec("INSERT INTO tenants (id, name, slug, branding_host) VALUES (2, 'Beta', 'beta', 'app.beta.com')");
    }

    public function testCustomHostWins(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->seed($pdo);
        self::assertSame(2, $this->resolver($pdo)->resolveTenantIdByHost('app.beta.com'));
    }

    public function testSlugSubdomain(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->seed($pdo);
        self::assertSame(1, $this->resolver($pdo)->resolveTenantIdByHost('acme.whity.app'));
    }

    public function testCaseInsensitiveAndPortStripped(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->seed($pdo);
        self::assertSame(1, $this->resolver($pdo)->resolveTenantIdByHost('ACME.whity.app:443'));
    }

    public function testBareBaseDomainAndWwwAndUnknownReturnNull(): void
    {
        $pdo = SchemaFromMigrations::make(true);
        $this->seed($pdo);
        $r = $this->resolver($pdo);
        self::assertNull($r->resolveTenantIdByHost('whity.app'));
        self::assertNull($r->resolveTenantIdByHost('www.whity.app'));
        self::assertNull($r->resolveTenantIdByHost('nope.whity.app'));
        self::assertNull($r->resolveTenantIdByHost(''));
    }
}
