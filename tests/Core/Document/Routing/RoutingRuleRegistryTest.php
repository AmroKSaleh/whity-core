<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Document\Routing\InvalidRoutingRuleException;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * {@see RoutingRuleRegistry} — the catalogue of declarable routing rule kinds
 * (#947 item 3).
 *
 * The property worth protecting is the NAMESPACE GUARANTEE, and it matters more
 * here than in the other twelve registries: a rule kind decides WHO RECEIVES a
 * document, so a plugin able to mint a bare kind could shadow `role` and
 * silently redirect every circulation on the instance that already named it.
 */
final class RoutingRuleRegistryTest extends TestCase
{
    public function testCoreOwnsExactlyFourKindsAndAllAreBare(): void
    {
        $registry = $this->withCoreRules();

        self::assertTrue($registry->has(RoutingRuleRegistry::KIND_ROLE));
        self::assertTrue($registry->has(RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR));
        self::assertTrue($registry->has(RoutingRuleRegistry::KIND_EXPLICIT));
        self::assertTrue($registry->has(RoutingRuleRegistry::KIND_GROUP));
        self::assertSame(
            ['explicit', 'group', 'role', 'role_below_actor'],
            array_column($registry->catalogue(), 'kind')
        );

        foreach ($registry->catalogue() as $entry) {
            self::assertStringNotContainsString(
                ':',
                $entry['kind'],
                "core's kinds are bare — the unprefixed namespace is core's, which is what makes a "
                . 'stored step naming `role` un-shadowable'
            );
            self::assertSame('core', $entry['source']);
            self::assertNotSame('', $entry['label'], 'a kind a person must pick from needs a label');
        }
    }

    /**
     * The audience catalogue is the ROUTING catalogue minus `group` (#999).
     *
     * This is the assertion that makes a group-of-groups impossible rather than
     * merely discouraged. `group` is registered — a route step may name it — and
     * it must never appear in the list a group's own definition is chosen from,
     * because it is the only thing standing between the design and a reference
     * cycle nothing detects.
     */
    public function testTheAudienceCatalogueExcludesTheGroupKindItself(): void
    {
        $registry = $this->withCoreRules();

        $audience = array_column($registry->audienceCatalogue(), 'kind');

        self::assertSame(['explicit', 'role', 'role_below_actor'], $audience);
        self::assertNotContains(
            RoutingRuleRegistry::KIND_GROUP,
            $audience,
            'a user group must not be definable as another user group — nesting is prevented '
            . 'structurally here, which is why no cycle detection exists anywhere in the group code'
        );
        self::assertNull(
            $registry->audienceResolver(RoutingRuleRegistry::KIND_GROUP),
            'the accessor agrees with the catalogue'
        );
        self::assertNotNull(
            $registry->get(RoutingRuleRegistry::KIND_GROUP),
            'and `group` is still perfectly usable as a route step'
        );
    }

    /**
     * A plugin kind that implements only the ROUTING interface is offered to
     * routing and withheld from groups (#999).
     *
     * The honest outcome rather than an error: a rule that reads the document it
     * is being routed with cannot answer a question that has no document in it.
     */
    public function testARoutingOnlyPluginKindIsNotOfferedAsAGroupDefinition(): void
    {
        $registry = $this->withCoreRules();
        $registry->register('Acme Widgets', ['committee' => $this->stubResolver()]);

        self::assertContains('acme_widgets:committee', array_column($registry->catalogue(), 'kind'));
        self::assertNotContains(
            'acme_widgets:committee',
            array_column($registry->audienceCatalogue(), 'kind')
        );
        self::assertNull($registry->audienceResolver('acme_widgets:committee'));
    }

    public function testAPluginKindIsStampedWithThePluginsOwnNamespace(): void
    {
        $registry = $this->withCoreRules();

        $registered = $registry->register('Acme Widgets', ['committee' => $this->stubResolver()]);

        self::assertSame(['acme_widgets:committee'], $registered);
        self::assertTrue($registry->has('acme_widgets:committee'));
        self::assertSame('Acme Widgets', $registry->sourceOf('acme_widgets:committee'));
        self::assertFalse(
            $registry->has('committee'),
            'the bare slug must NOT be registered — a plugin cannot mint a bare kind'
        );
    }

    public function testTwoPluginsMayDeclareTheSameSlugWithoutColliding(): void
    {
        $registry = $this->withCoreRules();

        $registry->register('acme', ['committee' => $this->stubResolver()]);
        $registry->register('globex', ['committee' => $this->stubResolver()]);

        self::assertTrue($registry->has('acme:committee'));
        self::assertTrue($registry->has('globex:committee'));
        self::assertNotSame(
            $registry->get('acme:committee'),
            $registry->get('globex:committee'),
            'each plugin resolves its OWN steps; neither can resolve the other\'s'
        );
    }

    public function testAPluginCannotClaimTheReservedCoreSource(): void
    {
        $registry = $this->withCoreRules();

        $this->expectException(InvalidRoutingRuleException::class);
        $this->expectExceptionMessageMatches('/reserved/');
        $registry->register('core', ['sneaky' => $this->stubResolver()]);
    }

    public function testAPluginCannotShadowACoreKind(): void
    {
        $registry = $this->withCoreRules();

        // Declaring `role` gets you `acme:role`, which is a different kind — so
        // a step already stored naming `role` keeps meaning core's.
        $registered = $registry->register('acme', ['role' => $this->stubResolver()]);

        self::assertSame(['acme:role'], $registered);
        self::assertInstanceOf(
            RoleRuleResolver::class,
            $registry->get(RoutingRuleRegistry::KIND_ROLE),
            'core\'s `role` must still be core\'s'
        );
    }

    /**
     * @dataProvider malformedSlugs
     */
    public function testAMalformedSlugIsRefused(string $slug): void
    {
        $registry = $this->withCoreRules();

        $this->expectException(InvalidRoutingRuleException::class);
        $registry->register('acme', [$slug => $this->stubResolver()]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedSlugs(): array
    {
        return [
            // A colon would let a declaration choose its own namespace, which is
            // the whole thing the loader-stamped prefix prevents.
            'contains a colon' => ['other:committee'],
            'starts with a digit' => ['2committee'],
            'uppercase' => ['Committee'],
            'hyphenated' => ['sub-committee'],
            'empty' => [''],
        ];
    }

    public function testAValueThatIsNotAResolverIsRefusedAtDeclarationTime(): void
    {
        $registry = $this->withCoreRules();

        $this->expectException(InvalidRoutingRuleException::class);
        // Refused HERE rather than discovered when a step is reached: a route
        // authored against this would validate, store, and fail days later for
        // somebody who did not write the plugin.
        /** @phpstan-ignore-next-line deliberately the wrong type */
        $registry->register('acme', ['committee' => 'not a resolver']);
    }

    public function testASlugTooLongForTheColumnIsRefused(): void
    {
        $registry = $this->withCoreRules();

        $this->expectException(InvalidRoutingRuleException::class);
        $registry->register('acme', [str_repeat('a', RoutingRuleRegistry::KEY_MAX_LENGTH + 1) => $this->stubResolver()]);
    }

    public function testAPrefixedKeyThatOverflowsTheColumnIsRefusedEvenWithALegalSlug(): void
    {
        $registry = $this->withCoreRules();

        // A legal slug under a very long plugin name: the prefix is added by the
        // HOST, so this is only reachable here and not by validating the slug.
        $longSource = str_repeat('p', RoutingRuleRegistry::KEY_MAX_LENGTH);

        $this->expectException(InvalidRoutingRuleException::class);
        $this->expectExceptionMessageMatches('/longer than/');
        $registry->register($longSource, ['committee' => $this->stubResolver()]);
    }

    public function testADuplicateKindIsRefusedRatherThanSilentlyReplaced(): void
    {
        $registry = $this->withCoreRules();
        $registry->register('acme', ['committee' => $this->stubResolver()]);

        $this->expectException(InvalidRoutingRuleException::class);
        $registry->register('acme', ['committee' => $this->stubResolver()]);
    }

    public function testAnUnknownKindIsNullRatherThanAnError(): void
    {
        $registry = $this->withCoreRules();

        // Null is a REAL answer: a step naming an uninstalled plugin's kind is a
        // state migration 112 deliberately allows, and the CALLER turns the null
        // into a failure that names the kind.
        self::assertNull($registry->get('acme:committee'));
        self::assertFalse($registry->has('acme:committee'));
        self::assertNull($registry->sourceOf('acme:committee'));
    }

    public function testRegisteringCoreRulesTwiceIsANoOp(): void
    {
        $registry = $this->withCoreRules();
        $before = $registry->catalogue();

        self::applyCoreRules($registry);

        self::assertSame($before, $registry->catalogue(), 'bootstrap-safe: a second call changes nothing');
    }

    public function testTheRegistryDeclaresItselfHostWired(): void
    {
        // An improvised empty instance would report every kind as unknown —
        // including core's own two — and "this kind is not registered" is an
        // ordinary answer for an uninstalled plugin, so nothing would look wrong.
        self::assertInstanceOf(HostWiredService::class, new RoutingRuleRegistry());
    }

    public function testCanonicalKeyIsTheOneSpellingOfTheNamespacingRule(): void
    {
        self::assertSame('role', RoutingRuleRegistry::canonicalKey('core', 'role'));
        self::assertSame('acme:committee', RoutingRuleRegistry::canonicalKey('acme', 'committee'));
        // Callers hold a slug and a source and ask; they never concatenate, or a
        // change to the rule silently orphans every step already authored.
        self::assertSame(
            'acme_widgets:committee',
            RoutingRuleRegistry::canonicalKey('Acme Widgets', 'committee')
        );
    }

    /**
     * @dataProvider kindValidity
     */
    public function testIsValidKindMatchesWhatTheColumnMustHold(string $kind, bool $valid): void
    {
        self::assertSame($valid, RoutingRuleRegistry::isValidKind($kind));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function kindValidity(): array
    {
        return [
            'bare core kind' => ['role', true],
            'bare with underscore' => ['role_below_actor', true],
            'namespaced' => ['acme:committee', true],
            'two colons' => ['acme:sub:committee', false],
            'trailing colon' => ['acme:', false],
            'leading colon' => [':committee', false],
            'uppercase' => ['Role', false],
        ];
    }

    private function withCoreRules(): RoutingRuleRegistry
    {
        $registry = new RoutingRuleRegistry();
        self::applyCoreRules($registry);

        return $registry;
    }

    /**
     * Register core's four kinds on a registry.
     *
     * Core's resolvers query, so they take a PDO. An in-memory handle is enough:
     * nothing here resolves, only registers.
     *
     * The `group` resolver needs a group resolver which needs THIS REGISTRY —
     * the cycle public/index.php breaks with a closure. Reproduced here rather
     * than stubbed, because a stub would let this suite agree with a wiring
     * production does not have.
     */
    private static function applyCoreRules(RoutingRuleRegistry $registry): void
    {
        $pdo = new PDO('sqlite::memory:');

        $registry->registerCoreRoutingRules(
            new RoleRuleResolver($pdo),
            new RoleBelowActorRuleResolver($pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $pdo,
                new UserGroupRepository($pdo),
                static fn (): RoutingRuleRegistry => $registry
            ))
        );
    }

    private function stubResolver(): RoutingRuleResolverInterface
    {
        return new class implements RoutingRuleResolverInterface {
            public function label(): string
            {
                return 'Stub';
            }

            public function validate(array $config): void
            {
            }

            /**
             * @return list<ResolvedRecipient>
             */
            public function resolve(RoutingRuleContext $context): array
            {
                return [];
            }
        };
    }
}
