<?php

declare(strict_types=1);

namespace Tests\Core\Container;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Core\Container\HostWiredService;

/**
 * Convention guard: a registry that CARRIES STATE must declare itself
 * host-wired, so the container can never improvise an empty one.
 *
 * The bug this exists to prevent was not a missing `register_service()` call —
 * it was that the missing call had no consequence anyone could see.
 * PermissionRegistry was concrete with one optional constructor argument, so
 * `\Whity\app()` built a fresh empty catalogue and every plugin permission read
 * as unregistered, silently. The registration was then added; but the NEXT
 * stateful registry someone adds and forgets to register would fail exactly the
 * same way, and a test that only pins today's five registries would not notice.
 *
 * So the rule is stated as a property instead: every instantiable `*Registry`
 * in `src/` that holds instance state implements {@see HostWiredService}. State
 * is the discriminator because state is what makes emptiness ambiguous — a
 * const-only catalogue (SettingsRegistry, EntitlementRegistry) is fully defined
 * by its own source, so an improvised instance is not merely plausible, it is
 * CORRECT, and there is nothing to protect.
 *
 * Scope note: `sdk/` is deliberately not scanned. The SDK depends on nothing but
 * PHP (that is its contract — it is vendored into plugins), so it cannot
 * reference a core interface. Its one registry,
 * {@see \Whity\Sdk\Tenant\TenantTableRegistry}, is a value object built from
 * data its caller passes, never resolved from the host container.
 */
final class HostWiredRegistryConventionTest extends TestCase
{
    public function testEveryStatefulRegistryInSrcDeclaresItselfHostWired(): void
    {
        $offenders = [];
        $checked = 0;

        foreach ($this->registryClasses() as $class) {
            $reflection = new ReflectionClass($class);

            // Not buildable by the container at all, so it can never be improvised.
            if (!$reflection->isInstantiable()) {
                continue;
            }

            if (!$this->holdsInstanceState($reflection)) {
                continue;
            }

            $checked++;

            if (!$reflection->implementsInterface(HostWiredService::class)) {
                $offenders[] = $class;
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'The scan found no stateful registries at all — it has stopped testing anything.'
        );

        self::assertSame(
            [],
            $offenders,
            "These registries hold state the host fills at boot but do not implement "
            . HostWiredService::class . ", so \\Whity\\app() will hand a caller a silently EMPTY "
            . "one instead of failing:\n  - " . implode("\n  - ", $offenders)
            . "\n\nImplement the marker AND register the populated instance in BOTH "
            . 'public/index.php and BaseCommand::setupKernel().'
        );
    }

    /**
     * The marker is worthless if nothing carries it: pin the registries that
     * produced, or could reproduce, the silent-empty failure.
     */
    public function testTheRegistriesThatCausedTheFailureCarryTheMarker(): void
    {
        $expected = [
            \Whity\Core\RBAC\PermissionRegistry::class,
            \Whity\Core\RBAC\ResourceTypeRegistry::class,
            \Whity\Core\Health\HealthProbeRegistry::class,
            \Whity\Core\Tenant\TableOwnershipRegistry::class,
            \Whity\Core\DataType\DataTypeRegistry::class,
            // #713 item 1. The settings pair is the worst case for the silent-
            // empty failure, which is why it is pinned rather than left to the
            // scan above: SettingsCatalog is `final` with a single OPTIONAL
            // constructor argument, so \Whity\app() would happily improvise a
            // core-only one — and a core-only catalogue does not throw. It
            // answers "unknown setting" for every key a plugin declared, which
            // reads as a typo in the plugin rather than a missing registration.
            \Whity\Core\Settings\PluginSettingsRegistry::class,
            \Whity\Core\Settings\SettingsCatalog::class,
            \Whity\Core\Queue\JobRegistry::class,
            \Whity\Core\Notification\TransportRegistry::class,
            \Whity\Mcp\Prompts\PromptRegistry::class,
        ];

        foreach ($expected as $class) {
            self::assertTrue(
                (new ReflectionClass($class))->implementsInterface(HostWiredService::class),
                "{$class} is filled at boot and empty by default, so it must be marked "
                . HostWiredService::class . '.'
            );
        }
    }

    /**
     * A const-only catalogue is fully described by its own source. Improvising
     * one is harmless, and refusing to would be pointless strictness, so the
     * convention must NOT drag them in.
     */
    public function testStatelessCatalogueRegistriesAreDeliberatelyNotMarked(): void
    {
        foreach ([\Whity\Core\Settings\SettingsRegistry::class, \Whity\Core\Entitlement\EntitlementRegistry::class] as $class) {
            $reflection = new ReflectionClass($class);

            self::assertFalse(
                $this->holdsInstanceState($reflection),
                "{$class} is expected to be a const-only catalogue; if it has grown instance "
                . 'state it now needs the host-wired marker.'
            );
        }
    }

    /**
     * @param ReflectionClass<object> $reflection
     */
    private function holdsInstanceState(ReflectionClass $reflection): bool
    {
        foreach ($reflection->getProperties() as $property) {
            if (!$property->isStatic()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<class-string>
     */
    private function registryClasses(): array
    {
        $root = dirname(__DIR__, 3) . '/src';
        $files = new \RegexIterator(
            new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)),
            '/Registry\.php$/'
        );

        $classes = [];
        foreach ($files as $file) {
            $source = file_get_contents((string) $file);
            if ($source === false) {
                continue;
            }

            if (
                preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1
                || preg_match('/^(?:final\s+|abstract\s+)*class\s+(\w+)/m', $source, $cls) !== 1
            ) {
                continue;
            }

            $fqcn = trim($ns[1]) . '\\' . $cls[1];
            if (class_exists($fqcn)) {
                $classes[] = $fqcn;
            }
        }

        sort($classes);

        return $classes;
    }
}
