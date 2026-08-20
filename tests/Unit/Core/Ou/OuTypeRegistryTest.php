<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Ou;

use PHPUnit\Framework\TestCase;
use Whity\Core\Ou\InvalidOuTypeException;
use Whity\Core\Ou\OuTypeRegistry;

/**
 * The OU-type catalogue's governance rules (#822).
 *
 * The value of this registry is entirely in what it REFUSES, so that is what is
 * pinned here: a plugin cannot mint a bare key, cannot claim the `core` source,
 * and cannot choose its own namespace, because those are the three ways a
 * plugin could otherwise squat on a name a tenant's own vocabulary depends on.
 */
final class OuTypeRegistryTest extends TestCase
{
    public function testPluginTypesAreNamespacedUnderTheSourceTheLoaderSupplies(): void
    {
        $registry = new OuTypeRegistry();
        $keys = $registry->register('AcmeClinics', ['clinic' => ['label' => 'Clinic']]);

        $this->assertSame(['acmeclinics:clinic'], $keys);
        $this->assertTrue($registry->has('acmeclinics:clinic'));
        $this->assertFalse(
            $registry->has('clinic'),
            'A plugin must never produce a BARE key — the unprefixed namespace belongs to core '
            . "and to the tenant's own vocabulary."
        );
    }

    /**
     * The collision guarantee: two plugins declaring the same slug get different
     * keys, so neither can adopt into or shadow the other's rows.
     */
    public function testTwoPluginsMayDeclareTheSameSlugWithoutColliding(): void
    {
        $registry = new OuTypeRegistry();
        $registry->register('AcmeClinics', ['clinic' => []]);
        $registry->register('BetaHealth', ['clinic' => []]);

        $this->assertTrue($registry->has('acmeclinics:clinic'));
        $this->assertTrue($registry->has('betahealth:clinic'));
        $this->assertCount(2, $registry->all());
    }

    public function testCoreSourceIsReservedAgainstPlugins(): void
    {
        $registry = new OuTypeRegistry();

        $this->expectException(InvalidOuTypeException::class);
        $registry->register(OuTypeRegistry::CORE_SOURCE, ['faculty' => []]);
    }

    /**
     * `tenant` is the provenance a row carries when nothing declared it. A plugin
     * registering under it would make declared and undeclared keys
     * indistinguishable to an operator deciding what is safe to rename.
     */
    public function testTenantSourceIsReservedAgainstPlugins(): void
    {
        $registry = new OuTypeRegistry();

        $this->expectException(InvalidOuTypeException::class);
        $registry->register(OuTypeRegistry::TENANT_SOURCE, ['faculty' => []]);
    }

    public function testASlugContainingTheNamespaceSeparatorIsRefused(): void
    {
        $registry = new OuTypeRegistry();

        $this->expectException(InvalidOuTypeException::class);
        // Declaring `other:clinic` would be choosing a namespace, which is the
        // one thing the loader-stamped prefix exists to prevent.
        $registry->register('AcmeClinics', ['other:clinic' => []]);
    }

    public function testASourceWithNoUsableSlugIsRefused(): void
    {
        $registry = new OuTypeRegistry();

        $this->expectException(InvalidOuTypeException::class);
        $registry->register('###', ['clinic' => []]);
    }

    /**
     * Per-type validation: a malformed declaration costs the plugin that ONE
     * type, not the whole batch, matching DataTypeRegistry.
     */
    public function testAMalformedDeclarationDoesNotDiscardTypesAlreadyValidated(): void
    {
        $registry = new OuTypeRegistry();

        try {
            $registry->register('AcmeClinics', [
                'clinic' => ['label' => 'Clinic'],
                'ward' => ['sort_order' => 'soon'],
            ]);
            $this->fail('An invalid sort_order must raise.');
        } catch (InvalidOuTypeException) {
            // expected
        }

        $this->assertTrue($registry->has('acmeclinics:clinic'));
        $this->assertFalse($registry->has('acmeclinics:ward'));
    }

    public function testDeclaredDefaultsAreExposedAndLabelFallsBackToTheSlug(): void
    {
        $registry = new OuTypeRegistry();
        $registry->register('AcmeClinics', [
            'clinic' => ['label' => 'Clinic', 'sort_order' => 30],
            'ward' => [],
        ]);

        $clinic = $registry->get('acmeclinics:clinic');
        $this->assertNotNull($clinic);
        $this->assertSame('Clinic', $clinic->label());
        $this->assertSame(30, $clinic->sortOrder());
        $this->assertSame('AcmeClinics', $clinic->source());

        $ward = $registry->get('acmeclinics:ward');
        $this->assertNotNull($ward);
        $this->assertSame('ward', $ward->label(), 'An undeclared label falls back to the bare slug.');
        $this->assertNull(
            $ward->sortOrder(),
            'An undeclared rank stays null so the adopting tenant appends it, rather than '
            . 'collapsing to 0 and jumping to the front of that tenant\'s vocabulary.'
        );
    }

    /**
     * Core declares no vocabulary, and that is the design: a white-label platform
     * cannot know whether a tenant's second level is a faculty or a region, and
     * shipping one would be the core enum #822 exists to reject. The BARE
     * namespace is still reserved so a plugin cannot occupy it.
     */
    public function testCoreShipsNoOpinionatedVocabulary(): void
    {
        $registry = new OuTypeRegistry();
        $registry->registerCoreOuTypes();

        $this->assertSame([], $registry->all());
    }

    public function testCanonicalKeyMatchesWhatRegistrationStores(): void
    {
        $registry = new OuTypeRegistry();
        $registry->register('Acme\\Widgets\\Plugin', ['zone' => []]);

        $this->assertTrue(
            $registry->has(OuTypeRegistry::canonicalKey('Acme\\Widgets\\Plugin', 'zone')),
            'A plugin spelling its own key through canonicalKey() must reach the row it registered.'
        );
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    public static function keyShapes(): array
    {
        return [
            ['faculty', true],
            ['acme:clinic', true],
            ['catalog_item', true],
            ['Faculty', false],
            ['1faculty', false],
            ['acme:', false],
            [':clinic', false],
            ['acme:beta:clinic', false],
            ['faculty-2', false],
            ['', false],
        ];
    }

    /**
     * @dataProvider keyShapes
     */
    public function testKeyShapeValidation(string $key, bool $valid): void
    {
        $this->assertSame($valid, OuTypeRegistry::isValidKey($key));
    }

    /**
     * `none` is how `?type=` asks for the untyped units, so a tenant must not be
     * able to author a real type that answers to it. A PLUGIN still may — its
     * key becomes `acme:none`, which is unambiguous.
     */
    public function testTheUntypedSentinelIsNotTenantAuthorableButIsPluginDeclarable(): void
    {
        $this->assertFalse(OuTypeRegistry::isTenantAuthorable(OuTypeRegistry::UNTYPED));
        $this->assertFalse(
            OuTypeRegistry::isTenantAuthorable('acme:clinic'),
            'A prefixed key is an ATTRIBUTION; a tenant writing one by hand would be claiming a '
            . 'plugin said so.'
        );
        $this->assertTrue(OuTypeRegistry::isTenantAuthorable('faculty'));

        $registry = new OuTypeRegistry();
        $registry->register('Acme', [OuTypeRegistry::UNTYPED => []]);
        $this->assertTrue($registry->has('acme:none'));
    }
}
