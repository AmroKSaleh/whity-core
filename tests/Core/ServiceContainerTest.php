<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * WC-712: the \Whity\app() service container is the ONLY handle a plugin has on
 * host collaborators, so its failure modes are part of the plugin contract.
 *
 * Three properties matter here:
 *
 *  1. An unwired service must fail CLOSED, with a CATCHABLE error. The container
 *     used to fall back to a bare `new $class()`, which for anything taking
 *     constructor arguments — i.e. every non-trivial service, and notably every
 *     security-relevant one — raised an \ArgumentCountError. That is an \Error,
 *     not an \Exception, so a plugin guarding its lookup the documented way
 *     (`catch (\Exception)`) never caught it and the request died with a 500.
 *
 *  2. The fallback must never IMPROVISE a security service. An auto-built
 *     RoleChecker pointed at a different database handle, an empty permission
 *     registry or no delegation resolver would answer authorization questions
 *     differently from the middleware — exactly the divergence the resolver
 *     contract exists to remove.
 *
 *  3. And it must not improvise a STATEFUL one either — the hole (2) left open.
 *     "Concrete, no REQUIRED arguments" is a rule about constructor shape, and
 *     PermissionRegistry (concrete, one OPTIONAL argument) fitted it perfectly:
 *     an unregistered lookup returned a fresh, EMPTY permission catalogue, so a
 *     plugin's `exists('its:permission')` answered false for something it had
 *     just declared, and it failed closed with nothing thrown and nothing
 *     logged. Emptiness is a legitimate registry state, so the caller could not
 *     tell. Two guards now close it: the {@see HostWiredService} marker, and a
 *     fallback narrowed to constructors with NO parameters at all.
 */
final class ServiceContainerTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $savedServices = [];

    protected function setUp(): void
    {
        $this->savedServices = $this->services();
    }

    protected function tearDown(): void
    {
        $GLOBALS['whity_services'] = $this->savedServices;
    }

    public function testRegisteredInstanceIsReturnedByIdentity(): void
    {
        $service = new ContainerFixtureZeroArg();
        \Whity\register_service(ContainerFixtureZeroArg::class, $service);

        $this->assertSame($service, \Whity\app(ContainerFixtureZeroArg::class));
    }

    /**
     * An INTERFACE key resolves to whatever the host registered under it. This
     * is how a plugin reaches PermissionResolver without knowing (or being able
     * to construct) the host's concrete implementation.
     */
    public function testInterfaceKeyResolvesToTheRegisteredImplementation(): void
    {
        $fake = new class implements PermissionResolver {
            public function hasPermission(
                int $profileId,
                int $tenantId,
                string $permission,
                ?string $resourceType = null,
                ?int $resourceId = null
            ): bool {
                return false;
            }

            public function hasRole(
                int $profileId,
                int $tenantId,
                string $role,
                ?string $resourceType = null,
                ?int $resourceId = null
            ): bool {
                return false;
            }

            /** @return list<string> */
            public function effectivePermissions(
                int $profileId,
                int $tenantId,
                ?string $resourceType = null,
                ?int $resourceId = null
            ): array {
                return [];
            }
        };

        \Whity\register_service(PermissionResolver::class, $fake);

        $this->assertSame($fake, \Whity\app(PermissionResolver::class));
    }

    /**
     * The pre-existing convenience — a concrete, argument-free class is still
     * auto-instantiated — must keep working.
     */
    public function testConcreteArgumentFreeClassIsStillAutoInstantiated(): void
    {
        $this->forget(ContainerFixtureZeroArg::class);

        $this->assertInstanceOf(
            ContainerFixtureZeroArg::class,
            \Whity\app(ContainerFixtureZeroArg::class)
        );
    }

    /**
     * The regression: a class with REQUIRED constructor arguments must raise the
     * documented, catchable \RuntimeException — not an \ArgumentCountError that
     * escapes `catch (\Exception)` and 500s the whole request.
     */
    public function testUnwiredServiceWithRequiredArgumentsThrowsRuntimeExceptionNotError(): void
    {
        $this->forget(ContainerFixtureRequiresArgs::class);

        $caught = null;
        $message = '';
        try {
            \Whity\app(ContainerFixtureRequiresArgs::class);
        } catch (\Exception $e) {
            // Deliberately catching \Exception, exactly as a plugin would.
            $caught = $e;
            $message = $e->getMessage();
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $caught,
            'An unwired service with constructor arguments must be catchable as an \Exception.'
        );
        $this->assertStringContainsString(ContainerFixtureRequiresArgs::class, $message);
    }

    /**
     * The bound is now "no parameters AT ALL", not "no REQUIRED parameters".
     *
     * An optional constructor parameter is a COLLABORATOR the host passes — a
     * HookManager, a logger, an ownership registry. Dropping it does not yield
     * "the same object, simpler"; it yields a silently deaf one that dispatches
     * no events and validates against nothing. This is the exact shape
     * PermissionRegistry has, and the exact shape that let the container hand
     * out an empty permission catalogue.
     */
    public function testClassWithOnlyOptionalArgumentsFailsClosed(): void
    {
        $this->forget(ContainerFixtureOptionalArgs::class);

        $caught = null;
        try {
            \Whity\app(ContainerFixtureOptionalArgs::class);
        } catch (\Exception $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $caught,
            'A class with an optional constructor collaborator must not be improvised by the '
            . 'container; an unwired lookup must throw, catchably.'
        );
        $this->assertStringContainsString(ContainerFixtureOptionalArgs::class, $caught->getMessage());
    }

    /**
     * The marker is independent of constructor shape: this fixture takes NO
     * constructor arguments at all, so every parameter-counting rule would wave
     * it through. It must still fail closed — that is the whole point of an
     * explicit opt-out (JobRegistry, TransportRegistry and PromptRegistry all
     * have exactly this shape).
     */
    public function testHostWiredServiceIsNeverAutoInstantiatedEvenWithNoConstructorArguments(): void
    {
        $this->forget(ContainerFixtureHostWired::class);

        $caught = null;
        try {
            \Whity\app(ContainerFixtureHostWired::class);
        } catch (\Exception $e) {
            $caught = $e;
        }

        $this->assertInstanceOf(
            \RuntimeException::class,
            $caught,
            'A HostWiredService must never be auto-instantiated, whatever its constructor '
            . 'looks like.'
        );
        $this->assertStringContainsString(ContainerFixtureHostWired::class, $caught->getMessage());
        $this->assertStringContainsString(
            HostWiredService::class,
            $caught->getMessage(),
            'The message must name the marker so the reader learns WHY it refused, not just that '
            . 'it did.'
        );
    }

    /**
     * The production regression, pinned on the real class: an unregistered
     * PermissionRegistry must THROW, not resolve to an empty catalogue.
     *
     * Reproduced on a live boot: the container returned a registry whose
     * exists('plugin_store:manage') was false although the plugin had declared
     * the permission and PluginLoader had accepted it. No error, no warning,
     * nothing to diagnose — the caller simply concluded the permission did not
     * exist.
     */
    public function testStatefulRegistryIsNeverSilentlyAutoInstantiatedEmpty(): void
    {
        $this->forget(PermissionRegistry::class);

        $resolved = null;
        $caught = null;
        try {
            $resolved = \Whity\app(PermissionRegistry::class);
        } catch (\Exception $e) {
            $caught = $e;
        }

        $this->assertNull(
            $resolved,
            'An unregistered PermissionRegistry must never resolve. Returning an empty one is '
            . 'worse than failing: every plugin permission reads as unregistered and the caller '
            . 'fails closed with nothing to diagnose.'
        );
        $this->assertInstanceOf(\RuntimeException::class, $caught);
    }

    /**
     * The other half of the same property: once the host DOES register it, the
     * container must hand back that exact populated instance — not a copy, and
     * not something rebuilt.
     */
    public function testRegisteredPermissionRegistryResolvesToTheSamePopulatedInstance(): void
    {
        $registry = new PermissionRegistry(new HookManager());
        $registry->registerCorePermissions();
        $registry->register('demo_catalog', ['demo_catalog:view', 'demo_catalog:manage']);

        \Whity\register_service(PermissionRegistry::class, $registry);

        $resolved = \Whity\app(PermissionRegistry::class);

        $this->assertSame($registry, $resolved, 'Identity, not equality: the loader fills ONE object.');
        $this->assertTrue(
            $resolved->exists('demo_catalog:view'),
            'A permission the loader registered must be visible through the container.'
        );
    }

    /**
     * An interface that was never registered fails closed. A container that
     * quietly produced *something* for a permission resolver would be far worse
     * than one that refuses.
     */
    public function testUnregisteredInterfaceFailsClosed(): void
    {
        $this->forget(PermissionResolver::class);

        $this->expectException(\RuntimeException::class);
        \Whity\app(PermissionResolver::class);
    }

    public function testAbstractClassFailsClosed(): void
    {
        $this->forget(ContainerFixtureAbstract::class);

        $this->expectException(\RuntimeException::class);
        \Whity\app(ContainerFixtureAbstract::class);
    }

    public function testCompletelyUnknownClassFailsClosed(): void
    {
        $this->expectException(\RuntimeException::class);
        \Whity\app('Definitely\\Not\\A\\Real\\Service');
    }

    /**
     * @return array<string, mixed>
     */
    private function services(): array
    {
        /** @var array<string, mixed> $services */
        $services = $GLOBALS['whity_services'] ?? [];

        return $services;
    }

    private function forget(string $key): void
    {
        $services = $this->services();
        unset($services[$key]);
        $GLOBALS['whity_services'] = $services;
    }
}

/** Fixture: concrete and argument-free — the only shape the fallback may build. */
final class ContainerFixtureZeroArg
{
}

/** Fixture: required constructor argument — must fail closed, catchably. */
final class ContainerFixtureRequiresArgs
{
    public function __construct(public readonly string $dependency)
    {
    }
}

/** Fixture: optional-only constructor arguments — the shape that slipped through. */
final class ContainerFixtureOptionalArgs
{
    public function __construct(public readonly ?string $dependency = null)
    {
    }
}

/**
 * Fixture: argument-free but self-declared host-wired — the shape no
 * constructor-counting rule can catch.
 */
final class ContainerFixtureHostWired implements HostWiredService
{
}

/** Fixture: abstract — not instantiable, so it must fail closed. */
abstract class ContainerFixtureAbstract
{
}
