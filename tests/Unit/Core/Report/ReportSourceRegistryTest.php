<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Report;

use PHPUnit\Framework\TestCase;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Report\InvalidReportSourceException;
use Whity\Core\Report\ReportColumn;
use Whity\Core\Report\ReportSourceInterface;
use Whity\Core\Report\ReportSourceRegistry;

/**
 * What a report may be run over (#947 item 6).
 *
 * Every refusal below happens at REGISTRATION, which is boot — so a malformed
 * source stops the catalogue being built instead of surfacing later as a report
 * that answers strangely, at which point nobody would look at the registry.
 */
final class ReportSourceRegistryTest extends TestCase
{
    public function testRegistersACoreSourceUnderItsBareKey(): void
    {
        $registry = new ReportSourceRegistry();
        $registry->registerCoreSource('documents', $this->source('documents', 'documents:read'));

        self::assertTrue($registry->has('documents'));
        self::assertSame(['documents'], $registry->keys());
        self::assertNotNull($registry->get('documents'));
    }

    public function testAnUnknownKeyIsNullSoTheCallerCanSayNotFound(): void
    {
        // Null rather than an empty source. An empty report reads as "nothing
        // matched", which sends a caller looking at their own data instead of
        // at the key they mistyped.
        self::assertNull((new ReportSourceRegistry())->get('nope'));
        self::assertFalse((new ReportSourceRegistry())->has('nope'));
    }

    public function testRefusesAKeyOutsideThePlatformsSlugGrammar(): void
    {
        // A key only this catalogue would accept is the start of a second
        // grammar, and the platform already has one.
        $registry = new ReportSourceRegistry();

        $this->expectException(InvalidReportSourceException::class);
        $registry->registerCoreSource('Not-A-Slug', $this->source('x', 'documents:read'));
    }

    public function testRefusesASecondRegistrationOfOneKey(): void
    {
        // Overwriting would make WHICH report runs depend on boot order, and
        // boot order is not something anyone reads.
        $registry = new ReportSourceRegistry();
        $registry->registerCoreSource('documents', $this->source('documents', 'documents:read'));

        $this->expectException(InvalidReportSourceException::class);
        $this->expectExceptionMessage('already registered');
        $registry->registerCoreSource('documents', $this->source('documents', 'documents:read'));
    }

    public function testRefusesASourceThatNamesNoPermission(): void
    {
        // The security property, enforced where it cannot be forgotten. A
        // source with no permission would be readable by everyone the ROUTE
        // admits — and the route's own gate is about spending a render, not
        // about seeing this data.
        $registry = new ReportSourceRegistry();

        $this->expectException(InvalidReportSourceException::class);
        $this->expectExceptionMessage('declares no required permission');
        $registry->registerCoreSource('open', $this->source('open', ''));
    }

    public function testTheCataloguePublishesWhatEachReportRequires(): void
    {
        // Carried so a screen can hide a report the reader could not run, and
        // so an operator can see in one place what each report exposes.
        $registry = new ReportSourceRegistry();
        $registry->registerCoreSource('documents', $this->source('documents', 'documents:read'));

        self::assertSame(
            [[
                'key' => 'documents',
                'label' => 'Documents label',
                'origin' => ReportSourceRegistry::CORE_SOURCE,
                'required_permission' => 'documents:read',
            ]],
            $registry->catalogue('en')
        );
    }

    public function testIsHostWiredSoAnImprovisedInstanceCannotAnswerQuietly(): void
    {
        // An empty registry answers "no such report" for every key, which is
        // indistinguishable from an installation that has none configured. The
        // caller would go and check their own permissions and find nothing
        // wrong with them.
        self::assertInstanceOf(HostWiredService::class, new ReportSourceRegistry());
    }

    private function source(string $key, string $permission): ReportSourceInterface
    {
        return new class ($key, $permission) implements ReportSourceInterface {
            public function __construct(
                private readonly string $key,
                private readonly string $permission,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(string $language): string
            {
                return 'Documents label';
            }

            public function columns(string $language): array
            {
                return [ReportColumn::text('a', 'A')];
            }

            public function rows(
                int $tenantId,
                int $callerId,
                callable $hasPermission,
                callable $reachesOu,
                int $limit,
            ): array {
                return [];
            }

            public function total(
                int $tenantId,
                int $callerId,
                callable $hasPermission,
                callable $reachesOu,
            ): int {
                return 0;
            }

            public function requiredPermission(): string
            {
                return $this->permission;
            }
        };
    }
}
