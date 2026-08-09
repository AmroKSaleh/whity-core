<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * WC-712: the \Whity\app() service container is the ONLY handle a plugin has on
 * host collaborators, so its failure modes are part of the plugin contract.
 *
 * Two properties matter here:
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
 *     contract exists to remove. Auto-instantiation therefore stays limited to
 *     concrete, argument-free classes.
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

            public function hasRole(int $profileId, int $tenantId, string $role): bool
            {
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
     * An OPTIONAL-argument constructor is still safely buildable, so the bound
     * is "required parameters", not "any parameters".
     */
    public function testClassWithOnlyOptionalArgumentsIsAutoInstantiated(): void
    {
        $this->forget(ContainerFixtureOptionalArgs::class);

        $this->assertInstanceOf(
            ContainerFixtureOptionalArgs::class,
            \Whity\app(ContainerFixtureOptionalArgs::class)
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

/** Fixture: optional-only constructor arguments — still safely buildable. */
final class ContainerFixtureOptionalArgs
{
    public function __construct(public readonly ?string $dependency = null)
    {
    }
}

/** Fixture: abstract — not instantiable, so it must fail closed. */
abstract class ContainerFixtureAbstract
{
}
