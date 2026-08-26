<?php

declare(strict_types=1);

namespace Tests\Unit\Core\TimeWindow;

use PHPUnit\Framework\TestCase;
use Whity\Core\Container\HostWiredService;
use Whity\Core\TimeWindow\InvalidWindowTypeException;
use Whity\Core\TimeWindow\WindowTypeRegistry;

/**
 * The declarable TIME-WINDOW TYPE catalogue (#1070).
 *
 * Every illustrative vocabulary here comes from two unrelated domains — an
 * agricultural one and an industrial one — and neither is the vocabulary that
 * motivated the feature. That is deliberate: a test whose fixtures read as one
 * canonical case teaches the next reader that the case is canonical, and the
 * entire point of a declared vocabulary is that there is no canonical one.
 */
final class WindowTypeRegistryTest extends TestCase
{
    public function testCoreDeclaresNoWindowTypesAtAll(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->registerCoreWindowTypes();

        self::assertSame([], $registry->all());
        self::assertSame(
            [],
            WindowTypeRegistry::coreWindowTypes(),
            'Core shipping a period vocabulary would be the core enumeration this design rejects: '
            . 'a white-label platform cannot know how a given tenant slices time.'
        );
    }

    public function testAPluginsSlugsAreStampedWithItsOwnNamespace(): void
    {
        $registry = new WindowTypeRegistry();
        $keys = $registry->register('Acme Farms', [
            'crop_year' => ['label' => 'Crop year'],
        ]);

        self::assertSame(['acme_farms:crop_year'], $keys);
        self::assertTrue($registry->has('acme_farms:crop_year'));
        self::assertFalse(
            $registry->has('crop_year'),
            'A plugin must never be able to mint a BARE key — the unprefixed namespace belongs to '
            . "core and to the tenant's own vocabulary."
        );
    }

    public function testTwoPluginsMayDeclareTheSameSlugWithoutColliding(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Acme Farms', ['season' => ['label' => 'Growing season']]);
        $registry->register('Borden Kilns', ['season' => ['label' => 'Firing season']]);

        self::assertSame('Growing season', $registry->get('acme_farms:season')?->label());
        self::assertSame('Firing season', $registry->get('borden_kilns:season')?->label());
    }

    public function testDeclaredNestingIsNamespacedToo(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Borden Kilns', [
            'kiln_campaign' => ['label' => 'Kiln campaign'],
            'firing_run' => ['label' => 'Firing run', 'parent' => 'kiln_campaign'],
        ]);

        self::assertNull(
            $registry->get('borden_kilns:kiln_campaign')?->parentKey(),
            'A top-level period nests inside nothing.'
        );
        self::assertSame(
            'borden_kilns:kiln_campaign',
            $registry->get('borden_kilns:firing_run')?->parentKey(),
            'The parent is stamped with the same prefix, because a plugin may only nest inside its '
            . 'own vocabulary and the prefix is the host to apply.'
        );
    }

    public function testAParentDeclaredLaterInTheSameBatchIsLegal(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Acme Farms', [
            'growing_season' => ['parent' => 'crop_year'],
            'crop_year' => [],
        ]);

        self::assertSame('acme_farms:crop_year', $registry->get('acme_farms:growing_season')?->parentKey());
    }

    public function testNestingInsideASlugTheSourceDoesNotDeclareIsRefused(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);
        $this->expectExceptionMessageMatches('/does not declare/');

        $registry->register('Acme Farms', [
            'growing_season' => ['parent' => 'kiln_campaign'],
        ]);
    }

    public function testAPluginMayNotNestInsideAnotherSourcesType(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Borden Kilns', ['kiln_campaign' => []]);

        try {
            $registry->register('Acme Farms', [
                'growing_season' => ['parent' => 'kiln_campaign'],
            ]);
            self::fail('A cross-source parent must be refused.');
        } catch (InvalidWindowTypeException) {
            // Expected: Acme does not own Borden's type and cannot know whether a
            // given tenant adopted it.
        }

        self::assertFalse($registry->has('acme_farms:growing_season'));
    }

    public function testANestingCycleIsRefused(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);
        $this->expectExceptionMessageMatches('/nests inside itself/');

        $registry->register('Acme Farms', [
            'crop_year' => ['parent' => 'growing_season'],
            'growing_season' => ['parent' => 'crop_year'],
        ]);
    }

    public function testASelfParentIsACycle(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);

        $registry->register('Acme Farms', ['crop_year' => ['parent' => 'crop_year']]);
    }

    /**
     * The batch is all-or-nothing, which is the one place this departs from the
     * OU-type catalogue. Nesting makes the declarations interdependent, so a
     * partial store would leave parents pointing at nothing.
     */
    public function testOneBadDeclarationDiscardsTheWholeBatch(): void
    {
        $registry = new WindowTypeRegistry();

        try {
            $registry->register('Acme Farms', [
                'crop_year' => ['label' => 'Crop year'],
                'Growing_Season' => ['label' => 'Growing season'],
            ]);
            self::fail('A malformed slug must be refused.');
        } catch (InvalidWindowTypeException) {
            // Expected.
        }

        self::assertSame(
            [],
            $registry->all(),
            'The valid sibling must not survive: with nesting, half a hierarchy is worse than none.'
        );
    }

    public function testTheReservedSourcesAreRefused(): void
    {
        $registry = new WindowTypeRegistry();

        foreach ([WindowTypeRegistry::CORE_SOURCE, WindowTypeRegistry::TENANT_SOURCE] as $source) {
            try {
                $registry->register($source, ['crop_year' => []]);
                self::fail("Source '{$source}' must be reserved.");
            } catch (InvalidWindowTypeException $e) {
                self::assertMatchesRegularExpression('/reserved/', $e->getMessage());
            }
        }
    }

    public function testASourceYieldingNoPrefixIsRefused(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);
        $this->expectExceptionMessageMatches('/no usable namespace prefix/');

        $registry->register('!!!', ['crop_year' => []]);
    }

    public function testADuplicateKeyIsRefusedRatherThanSilentlyReplacing(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Acme Farms', ['crop_year' => ['label' => 'Crop year']]);

        try {
            $registry->register('Acme Farms', ['crop_year' => ['label' => 'Something else']]);
            self::fail('A duplicate key must be refused.');
        } catch (InvalidWindowTypeException $e) {
            self::assertMatchesRegularExpression('/already registered/', $e->getMessage());
        }

        self::assertSame('Crop year', $registry->get('acme_farms:crop_year')?->label());
    }

    public function testALegalSlugUnderAnOverlongPluginNameIsRefused(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);

        $registry->register(str_repeat('a', 200), ['crop_year' => []]);
    }

    public function testAMissingLabelFallsBackToTheBareSlug(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Acme Farms', ['crop_year' => []]);

        $definition = $registry->get('acme_farms:crop_year');
        self::assertNotNull($definition);
        self::assertSame('crop_year', $definition->label());
        self::assertSame('crop_year', $definition->slug());
        self::assertSame('Acme Farms', $definition->source());
    }

    public function testAnEmptyLabelIsRefusedRatherThanDefaulted(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);
        $this->expectExceptionMessageMatches('/label/');

        $registry->register('Acme Farms', ['crop_year' => ['label' => '   ']]);
    }

    public function testANamespacedParentIsRefusedBecauseThePrefixIsTheHostsToApply(): void
    {
        $registry = new WindowTypeRegistry();

        $this->expectException(InvalidWindowTypeException::class);
        $this->expectExceptionMessageMatches('/parent/');

        $registry->register('Acme Farms', [
            'crop_year' => [],
            'growing_season' => ['parent' => 'acme_farms:crop_year'],
        ]);
    }

    public function testAnUnknownKeyIsARealAnswerRatherThanAnError(): void
    {
        $registry = new WindowTypeRegistry();

        self::assertNull($registry->get('crop_year'));
        self::assertFalse($registry->has('crop_year'));
    }

    public function testGetBySourceReturnsOnlyThatSourcesDeclarations(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Acme Farms', ['crop_year' => [], 'growing_season' => ['parent' => 'crop_year']]);
        $registry->register('Borden Kilns', ['kiln_campaign' => []]);

        self::assertCount(2, $registry->getBySource('Acme Farms'));
        self::assertCount(1, $registry->getBySource('Borden Kilns'));
        self::assertSame([], $registry->getBySource('Nobody'));
    }

    public function testRegisteringCoreTypesTwiceIsANoOp(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->registerCoreWindowTypes();
        $registry->registerCoreWindowTypes();

        self::assertSame([], $registry->all());
    }

    public function testTheRegistryDeclaresItselfHostWired(): void
    {
        self::assertInstanceOf(
            HostWiredService::class,
            new WindowTypeRegistry(),
            'An improvised empty catalogue answers "that key does not exist" for every plugin '
            . 'contribution, which reads as a typo in the plugin rather than a missing registration.'
        );
    }

    /**
     * @dataProvider malformedSlugProvider
     */
    public function testMalformedSlugsAreRefused(string $slug): void
    {
        self::assertFalse(WindowTypeRegistry::isValidSlug($slug));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedSlugProvider(): array
    {
        return [
            'namespaced' => ['acme:crop_year'],
            'leading digit' => ['1st_season'],
            'uppercase' => ['CropYear'],
            'hyphenated' => ['crop-year'],
            'empty' => [''],
            'leading underscore' => ['_crop_year'],
        ];
    }

    /**
     * @dataProvider keyValidityProvider
     */
    public function testKeyValidity(string $key, bool $valid): void
    {
        self::assertSame($valid, WindowTypeRegistry::isValidKey($key));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function keyValidityProvider(): array
    {
        return [
            'bare' => ['crop_year', true],
            'namespaced' => ['acme_farms:crop_year', true],
            'two colons' => ['a:b:c', false],
            'trailing colon' => ['crop_year:', false],
            'leading colon' => [':crop_year', false],
            'uppercase' => ['Crop_Year', false],
        ];
    }

    public function testOnlyBareNonSentinelKeysAreTenantAuthorable(): void
    {
        self::assertTrue(WindowTypeRegistry::isTenantAuthorable('crop_year'));
        self::assertFalse(
            WindowTypeRegistry::isTenantAuthorable('acme_farms:crop_year'),
            'A prefixed key is an ATTRIBUTION: writing it by hand claims a plugin said so.'
        );
        self::assertFalse(WindowTypeRegistry::isTenantAuthorable(WindowTypeRegistry::UNTYPED));
    }

    public function testCanonicalKeyIsSpelledInOnePlace(): void
    {
        self::assertSame(
            'crop_year',
            WindowTypeRegistry::canonicalKey(WindowTypeRegistry::CORE_SOURCE, 'crop_year')
        );
        self::assertSame(
            'acme_farms:crop_year',
            WindowTypeRegistry::canonicalKey('Acme Farms', 'crop_year')
        );
    }

    public function testCatalogueRepresentationCarriesTheNesting(): void
    {
        $registry = new WindowTypeRegistry();
        $registry->register('Acme Farms', [
            'crop_year' => ['label' => 'Crop year'],
            'growing_season' => ['label' => 'Growing season', 'parent' => 'crop_year'],
        ]);

        self::assertSame([
            'key' => 'acme_farms:growing_season',
            'source' => 'Acme Farms',
            'label' => 'Growing season',
            'parent_key' => 'acme_farms:crop_year',
        ], $registry->get('acme_farms:growing_season')?->toArray());
    }
}
