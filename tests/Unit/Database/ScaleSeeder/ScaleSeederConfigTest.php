<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ScaleSeeder;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Database\ScaleSeeder\ScaleSeederConfig;

/**
 * Unit tests for {@see ScaleSeederConfig} (WC-35): defaults, CLI option
 * parsing/validation, and the `--scale` multiplier semantics documented on
 * the class (applies to users-per-tenant / ou-breadth / relations-per-person
 * only, never to tenants / ou-depth).
 */
final class ScaleSeederConfigTest extends TestCase
{
    public function testDefaultsMatchTheDocumentedConstants(): void
    {
        $config = ScaleSeederConfig::fromOptions([]);

        self::assertSame(ScaleSeederConfig::DEFAULT_SEED, $config->seed);
        self::assertSame(ScaleSeederConfig::DEFAULT_TENANTS, $config->tenants);
        self::assertSame(ScaleSeederConfig::DEFAULT_USERS_PER_TENANT, $config->usersPerTenant);
        self::assertSame(ScaleSeederConfig::DEFAULT_OU_DEPTH, $config->ouDepth);
        self::assertSame(ScaleSeederConfig::DEFAULT_OU_BREADTH, $config->ouBreadth);
        self::assertSame(ScaleSeederConfig::DEFAULT_RELATIONS_PER_PERSON, $config->relationsPerPerson);
        self::assertSame(ScaleSeederConfig::DEFAULT_CUSTOM_ROLES_PER_TENANT, $config->customRolesPerTenant);
        self::assertSame(ScaleSeederConfig::DEFAULT_SCALE, $config->scale);
        self::assertSame(ScaleSeederConfig::DEFAULT_BATCH_SIZE, $config->batchSize);
        self::assertFalse($config->dryRun);
        self::assertFalse($config->reset);
    }

    public function testExplicitOptionsOverrideDefaults(): void
    {
        $config = ScaleSeederConfig::fromOptions([
            'seed' => '7',
            'tenants' => '10',
            'users-per-tenant' => '40',
            'ou-depth' => '4',
            'ou-breadth' => '2',
            'relations-per-person' => '2.5',
            'custom-roles-per-tenant' => '3',
            'batch-size' => '50',
        ]);

        self::assertSame(7, $config->seed);
        self::assertSame(10, $config->tenants);
        self::assertSame(40, $config->usersPerTenant);
        self::assertSame(4, $config->ouDepth);
        self::assertSame(2, $config->ouBreadth);
        self::assertSame(2.5, $config->relationsPerPerson);
        self::assertSame(3, $config->customRolesPerTenant);
        self::assertSame(50, $config->batchSize);
    }

    public function testDryRunAndResetFlagsParseFromBareOptions(): void
    {
        $config = ScaleSeederConfig::fromOptions(['dry-run' => true, 'reset' => true]);

        self::assertTrue($config->dryRun);
        self::assertTrue($config->reset);
    }

    public function testScaleMultipliesUsersPerTenantOuBreadthAndRelationsPerPerson(): void
    {
        $config = ScaleSeederConfig::fromOptions([
            'users-per-tenant' => '10',
            'ou-breadth' => '3',
            'relations-per-person' => '2.0',
            'scale' => '2',
        ]);

        self::assertSame(20, $config->usersPerTenant);
        self::assertSame(6, $config->ouBreadth);
        self::assertSame(4.0, $config->relationsPerPerson);
    }

    public function testScaleDoesNotAffectTenantsOrOuDepth(): void
    {
        $config = ScaleSeederConfig::fromOptions([
            'tenants' => '5',
            'ou-depth' => '3',
            'scale' => '10',
        ]);

        self::assertSame(5, $config->tenants);
        self::assertSame(3, $config->ouDepth);
    }

    public function testScaledUsersPerTenantNeverDropsBelowOne(): void
    {
        $config = ScaleSeederConfig::fromOptions([
            'users-per-tenant' => '1',
            'scale' => '0.001',
        ]);

        self::assertSame(1, $config->usersPerTenant);
    }

    public function testNonNumericSeedIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaleSeederConfig::fromOptions(['seed' => 'not-a-number']);
    }

    public function testZeroTenantsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaleSeederConfig::fromOptions(['tenants' => '0']);
    }

    public function testNegativeUsersPerTenantIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaleSeederConfig::fromOptions(['users-per-tenant' => '-5']);
    }

    public function testZeroScaleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaleSeederConfig::fromOptions(['scale' => '0']);
    }

    public function testNegativeRelationsPerPersonIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScaleSeederConfig::fromOptions(['relations-per-person' => '-1']);
    }

    public function testOusPerTenantSumsTheGeometricSeries(): void
    {
        $config = ScaleSeederConfig::make(ouDepth: 3, ouBreadth: 3);

        // 1 root + 3 (level 2) + 9 (level 3) = 13.
        self::assertSame(13, $config->ousPerTenant());
    }

    public function testOusPerTenantWithDepthOneIsJustTheRoot(): void
    {
        $config = ScaleSeederConfig::make(ouDepth: 1, ouBreadth: 5);

        self::assertSame(1, $config->ousPerTenant());
    }

    public function testRelationsPerTenantIsHalfPersonsTimesDensity(): void
    {
        $config = ScaleSeederConfig::make(relationsPerPerson: 2.0);

        self::assertSame(10, $config->relationsPerTenant(10));
    }

    public function testRelationsPerTenantIsZeroBelowTwoPersons(): void
    {
        $config = ScaleSeederConfig::make(relationsPerPerson: 5.0);

        self::assertSame(0, $config->relationsPerTenant(1));
    }
}
