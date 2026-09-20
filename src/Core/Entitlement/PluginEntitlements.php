<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * The limits plugins have added to the catalogue, and the rules they obey.
 *
 * ── Why plugin limits live beside the core ones rather than inside them ─────
 *
 * A vertical product sells things core has never heard of. A course plugin
 * sells "max students"; an exam plugin sells "exams per month". Those have to
 * appear in the same tier editor as storage and seats, be priced by the same
 * people, and be enforced by the same resolution — otherwise a plugin's limits
 * are a second, worse copy of this whole mechanism.
 *
 * But they cannot live in {@see EntitlementRegistry}'s consts, because a plugin
 * contributes at boot and a const is fixed at parse time. So the registry reads
 * BOTH: its own constants, which no plugin can touch, and this, which plugins
 * fill in. Core keys stay a compile-time contract; plugin keys are runtime data.
 *
 * ── Worker safety, which is the trap here ──────────────────────────────────
 *
 * This is process-level static state and there are eight FrankenPHP workers.
 * That is safe ONLY because registration happens during plugin load at BOOT, so
 * every worker builds the identical catalogue before serving anything. It would
 * stop being safe the moment something registered lazily inside a request: one
 * worker would know a limit and seven would not, and a tenant's access would
 * depend on which worker answered — a bug that reads as flakiness. Hence
 * {@see seal()}: once the host has finished loading plugins it closes the
 * catalogue, and a late registration raises instead of half-applying.
 *
 * ── Ownership ──────────────────────────────────────────────────────────────
 *
 * A plugin may only declare keys under its own namespace, and may never shadow
 * a core key. This mirrors the rule the plugin loader already applies to
 * permissions and routes — core names are not plugin-ownable — and exists for
 * the same reason: two plugins, or a plugin and core, silently disagreeing
 * about what one key means is not a conflict anybody would find by reading.
 */
final class PluginEntitlements
{
    /**
     * Declared limits, keyed by entitlement key.
     *
     * @var array<string, EntitlementDefinition>
     */
    private static array $definitions = [];

    /** Closed once the host has loaded every plugin. See the class note. */
    private static bool $sealed = false;

    /**
     * Add one plugin-declared limit.
     *
     * IDEMPOTENT FOR AN IDENTICAL RE-DECLARATION, because a plugin can be loaded
     * more than once in a process during tests and tooling, and failing there
     * would make the catalogue depend on load order. A DIFFERENT definition for
     * the same key is always an error — that is two plugins disagreeing, or one
     * plugin changed without its data migrating, and silently keeping either one
     * prices a feature that the other half of the code does not enforce.
     *
     * @throws EntitlementValidationException When the key is malformed, not
     *         under the plugin's namespace, shadows a core key, collides with
     *         another plugin, or arrives after the catalogue was sealed.
     */
    public static function register(EntitlementDefinition $definition): void
    {
        $owner = $definition->owner;
        if ($owner === null || $owner === '') {
            throw new EntitlementValidationException(
                $definition->key,
                'A plugin-declared entitlement must name the plugin that owns it.'
            );
        }

        if (self::$sealed) {
            throw new EntitlementValidationException(
                $definition->key,
                "Plugin '{$owner}' declared an entitlement after plugin loading finished. "
                . 'Declare it from the plugin\'s entitlements method, which runs at boot — '
                . 'registering later would leave other workers without it.'
            );
        }

        self::assertWellFormed($definition, $owner);

        $existing = self::$definitions[$definition->key] ?? null;
        if ($existing !== null) {
            if (self::isSameDeclaration($existing, $definition)) {
                return;
            }

            throw new EntitlementValidationException(
                $definition->key,
                "Entitlement '{$definition->key}' is already declared by '{$existing->owner}' "
                . "with a different definition; '{$owner}' cannot redeclare it."
            );
        }

        self::$definitions[$definition->key] = $definition;
    }

    /**
     * Every plugin-declared limit, in declaration order.
     *
     * @return array<string, EntitlementDefinition>
     */
    public static function all(): array
    {
        return self::$definitions;
    }

    public static function get(string $key): ?EntitlementDefinition
    {
        return self::$definitions[$key] ?? null;
    }

    /**
     * Drop everything a plugin declared — called when it is deactivated.
     *
     * WITHDRAWN, NOT ORPHANED. A tier may still hold a VALUE for a key that has
     * just gone away, and that is deliberately left alone rather than deleted:
     * the plugin is usually coming back (an upgrade, a restart), and deleting
     * the pricing on deactivation would quietly reset every tier's configuration
     * for that feature. Resolution ignores values whose key is not declared, so
     * an undeclared limit gates nothing while it is gone.
     */
    public static function forget(string $owner): void
    {
        foreach (self::$definitions as $key => $definition) {
            if ($definition->owner === $owner) {
                unset(self::$definitions[$key]);
            }
        }
    }

    /**
     * Close the catalogue. Called by the host once plugins have loaded.
     *
     * Deliberately NOT automatic on first read: the host knows when loading is
     * done, and inferring it from "somebody asked a question" would seal the
     * catalogue during whichever request happened to come first.
     */
    public static function seal(): void
    {
        self::$sealed = true;
    }

    /** Reopen and empty the catalogue. For the host's own reload path, and tests. */
    public static function reset(): void
    {
        self::$definitions = [];
        self::$sealed = false;
    }

    /**
     * A plugin's keys must be `<namespace>.<something>`, lowercase, dotted.
     *
     * The namespace is compared case-insensitively against the plugin's own
     * name so `Elmak` declares `elmak.students.max`. Anything else is refused
     * with the reason, because a silently-ignored declaration is a feature that
     * appears in no tier editor and gates nothing.
     */
    private static function assertWellFormed(EntitlementDefinition $definition, string $owner): void
    {
        $key = $definition->key;

        if (EntitlementRegistry::isCoreKey($key)) {
            throw new EntitlementValidationException(
                $key,
                "'{$key}' is a core entitlement; core names are not plugin-ownable."
            );
        }

        if (preg_match('/^[a-z0-9]+(?:[a-z0-9_-]*)\.[a-z0-9._-]+$/', $key) !== 1) {
            throw new EntitlementValidationException(
                $key,
                "Entitlement keys must look like '<plugin>.<name>' in lowercase; got '{$key}'."
            );
        }

        $namespace = substr($key, 0, (int) strpos($key, '.'));
        $expected = strtolower($owner);
        if ($namespace !== $expected) {
            throw new EntitlementValidationException(
                $key,
                "Plugin '{$owner}' must declare entitlements under '{$expected}.'; got '{$key}'."
            );
        }

        if (!in_array($definition->type, [EntitlementDefinition::TYPE_BOOL, EntitlementDefinition::TYPE_INT], true)) {
            throw new EntitlementValidationException(
                $key,
                "Unknown entitlement type '{$definition->type}'; expected bool or int."
            );
        }

        if ($definition->period !== null && !in_array($definition->period, EntitlementDefinition::PERIODS, true)) {
            throw new EntitlementValidationException(
                $key,
                "Unknown meter period '{$definition->period}'; expected one of: "
                . implode(', ', EntitlementDefinition::PERIODS) . '.'
            );
        }

        if ($definition->period !== null && $definition->type !== EntitlementDefinition::TYPE_INT) {
            throw new EntitlementValidationException(
                $key,
                'A metered entitlement counts consumption, so it must be an int.'
            );
        }

        if ($definition->description === '') {
            // Marketing prices this from a list. An unlabelled row is one they
            // cannot price, so it is refused at declaration rather than shown
            // to them as a blank.
            throw new EntitlementValidationException($key, 'An entitlement must describe what it sells.');
        }

        // Validated against its own declaration so a plugin cannot ship a
        // default its own type would reject — which would make every tenant's
        // resolution throw on a key nobody had set.
        $reason = EntitlementRegistry::validateAgainst($definition, $definition->default);
        if ($reason !== null) {
            throw new EntitlementValidationException($key, "Default is not valid: {$reason}");
        }
    }

    private static function isSameDeclaration(EntitlementDefinition $a, EntitlementDefinition $b): bool
    {
        return $a->type === $b->type
            && $a->default === $b->default
            && $a->period === $b->period
            && $a->owner === $b->owner
            && $a->description === $b->description;
    }
}
