<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PHPUnit\Framework\TestCase;
use Whity\Core\Entitlement\EntitlementDefinition;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\PluginEntitlements;
use Whity\Core\PluginEntitlementRegistrar;
use Whity\Sdk\PluginEntitlementsInterface;
use Whity\Sdk\PluginInterface;

/**
 * A plugin selling its own limits, through the host's own registrar.
 *
 * {@see \Tests\Unit\Core\Entitlement\PluginEntitlementsTest} covers the registry's
 * rules — namespacing, collisions, sealing. This covers the LAYER ABOVE: a
 * plugin returns plain arrays from an SDK interface, and the host turns them
 * into catalogue entries or refuses them with a reason.
 *
 * THE PART THAT EARNS ITS KEEP is what happens to a malformed entry. Refusing
 * the whole plugin would turn a typo in one description into an outage of a
 * feature a customer is using; accepting it silently would put a row in the
 * tier editor that gates nothing. Neither is acceptable, so the entry is
 * dropped, the reason is logged, and every other declaration still lands.
 */
final class PluginDeclaredEntitlementsTest extends TestCase
{
    protected function setUp(): void
    {
        PluginEntitlements::reset();
    }

    protected function tearDown(): void
    {
        PluginEntitlements::reset();
    }

    public function testADeclaredCapReachesTheCatalogue(): void
    {
        $added = $this->register([[
            'key' => 'courses.students.max',
            'type' => 'int',
            'default' => '500',
            'description' => 'Maximum students this workspace may enrol.',
        ]]);

        self::assertSame(1, $added);
        self::assertTrue(EntitlementRegistry::isKnown('courses.students.max'));
        self::assertSame('500', EntitlementRegistry::defaultFor('courses.students.max'));
        self::assertNull(EntitlementRegistry::periodFor('courses.students.max'));
    }

    public function testADeclaredMeterCarriesItsWindow(): void
    {
        $this->register([[
            'key' => 'courses.exams.per_month',
            'type' => 'int',
            'default' => '-1',
            'description' => 'Exams each calendar month.',
            'period' => 'month',
        ]]);

        self::assertSame(
            EntitlementDefinition::PERIOD_MONTH,
            EntitlementRegistry::periodFor('courses.exams.per_month')
        );
    }

    /**
     * THE OWNER IS THE HOST'S WORD, NOT THE PLUGIN'S. A plugin cannot claim
     * another's namespace by naming it in its own declaration — which is the
     * one thing the namespacing rule exists to stop, and would be trivially
     * defeated if the registrar took `owner` from the entry.
     */
    public function testAPluginCannotClaimAnotherNamespaceByDeclaringIt(): void
    {
        $added = $this->register([[
            'key' => 'exams.attempts.max',
            'type' => 'int',
            'default' => '3',
            'description' => 'Trying to declare under somebody else\'s namespace.',
        ]]);

        self::assertSame(0, $added);
        self::assertFalse(EntitlementRegistry::isKnown('exams.attempts.max'));
    }

    // ── One bad row must not cost the plugin ────────────────────────────────

    /**
     * @dataProvider malformedEntries
     *
     * @param array<string, mixed> $bad
     */
    public function testAMalformedEntryIsDroppedAndTheRestStillLand(array $bad): void
    {
        $added = $this->register([
            $bad,
            [
                'key' => 'courses.students.max',
                'type' => 'int',
                'default' => '500',
                'description' => 'A perfectly good declaration after a bad one.',
            ],
        ]);

        self::assertSame(1, $added, 'The good entry must still be registered.');
        self::assertTrue(EntitlementRegistry::isKnown('courses.students.max'));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedEntries(): array
    {
        return [
            'missing key' => [[
                'type' => 'int', 'default' => '1', 'description' => 'No key.',
            ]],
            'missing type' => [[
                'key' => 'courses.a', 'default' => '1', 'description' => 'No type.',
            ]],
            'missing description' => [[
                'key' => 'courses.b', 'type' => 'int', 'default' => '1',
            ]],
            'non-string key' => [[
                'key' => 42, 'type' => 'int', 'default' => '1', 'description' => 'Numeric key.',
            ]],
            'non-string period' => [[
                'key' => 'courses.c', 'type' => 'int', 'default' => '1',
                'description' => 'Period is not a string.', 'period' => 7,
            ]],
            'unknown period' => [[
                'key' => 'courses.d', 'type' => 'int', 'default' => '1',
                'description' => 'A window nothing can close.', 'period' => 'fortnight',
            ]],
            'default its own type rejects' => [[
                'key' => 'courses.e', 'type' => 'int', 'default' => 'lots',
                'description' => 'A default that is not a number.',
            ]],
            'shadows a core key' => [[
                'key' => 'members.max', 'type' => 'int', 'default' => '5',
                'description' => 'Redefining what core already owns.',
            ]],
        ];
    }

    /** An entry that is not an array at all does not fatal the load. */
    public function testANonArrayEntryIsSurvived(): void
    {
        $plugin = new class implements PluginInterface, PluginEntitlementsInterface {
            public function getName(): string
            {
                return 'Courses';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            /** @return array<int|string, mixed> */
            public function getRoutes(): array
            {
                return [];
            }

            /** @return array<int|string, mixed> */
            public function getPermissions(): array
            {
                return [];
            }

            /** @return array<int|string, mixed> */
            public function getHooks(): array
            {
                return [];
            }

            /** @return array<int|string, mixed> */
            public function getMigrations(): array
            {
                return [];
            }

            /** @return list<array{key: string, type: string, default: string, description: string, period?: string}> */
            public function getEntitlements(): array
            {
                // Deliberately wrong, and typed as correct — the shape a plugin
                // author gets wrong is exactly the one the contract promised.
                /** @phpstan-ignore-next-line */
                return ['not-an-array'];
            }
        };

        $added = (new PluginEntitlementRegistrar())->register($plugin, 'Courses');

        self::assertSame(0, $added);
    }

    /**
     * @param list<mixed> $entries
     */
    private function register(array $entries): int
    {
        $plugin = new class ($entries) implements PluginInterface, PluginEntitlementsInterface {
            /** @param list<mixed> $entries */
            public function __construct(private readonly array $entries)
            {
            }

            public function getName(): string
            {
                return 'Courses';
            }

            public function getVersion(): string
            {
                return '1.0.0';
            }

            /** @return array<int|string, mixed> */
            public function getRoutes(): array
            {
                return [];
            }

            /** @return array<int|string, mixed> */
            public function getPermissions(): array
            {
                return [];
            }

            /** @return array<int|string, mixed> */
            public function getHooks(): array
            {
                return [];
            }

            /** @return array<int|string, mixed> */
            public function getMigrations(): array
            {
                return [];
            }

            /** @return list<array{key: string, type: string, default: string, description: string, period?: string}> */
            public function getEntitlements(): array
            {
                // `list<mixed>` widens to the declared shape without complaint,
                // which is the point of this fixture: it feeds the registrar the
                // malformed entries a real plugin author would, while the
                // contract still claims they are well-formed.
                return $this->entries;
            }
        };

        return (new PluginEntitlementRegistrar())->register($plugin, 'Courses');
    }
}
