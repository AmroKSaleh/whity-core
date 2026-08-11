<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use Throwable;
use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;

/**
 * The catalogue of PLUGIN-DECLARED setting keys (#713 item 1).
 *
 * What this closes
 * ----------------
 * {@see SettingsRegistry} already does everything a plugin needs — discovery,
 * typing, per-key defaults, validation, normalisation, option enums — and had no
 * way for a plugin to contribute to it. The predictable result was that every
 * plugin needing configuration rebuilt the settings layer badly, most often as a
 * private table shaped exactly like `tenant_settings` (`tenant_id`,
 * `setting_key`, `value`) with no declared keys and no validation, where a typo
 * writes a new invisible row instead of failing. Core itself worked around the
 * closed catalogue locally: {@see \Whity\Core\Observability\SettingKeys} is a
 * second constants class that exists only to alias back into this one.
 *
 * This registry is the contribution point. Plugin keys are stored in core's OWN
 * `app_settings` / `tenant_settings` tables and resolve through core's OWN chain,
 * so there is one settings store, one registry, and one place a value can be.
 *
 * Instance, not static — and why the core catalogue stays static
 * -------------------------------------------------------------
 * Process-level statics are per FrankenPHP worker: a registration performed while
 * serving one request is invisible to the other seven. That hazard produced the
 * stale-permission bug in PR #701 and, later, empty registries handed out by
 * {@see \Whity\app()} in PR #727. In a settings layer it would be quieter and
 * worse — a key missing from one worker's catalogue does not throw, it reads as
 * "unknown setting", so the same request succeeds or 422s depending on which
 * worker answered.
 *
 * So the mutable half is an INSTANCE resolved from the container, rebuilt per
 * boot from the plugins actually loaded. That also means unloading a plugin
 * removes its keys with no unregister API — the property
 * {@see \Whity\Core\RBAC\PermissionRegistry} already relies on.
 *
 * The static const catalogue in {@see SettingsRegistry} is left exactly as it
 * is. It is not mutable state, it is a compile-time constant identical in all
 * eight workers, and roughly 330 call sites across three dozen files read it
 * directly. Turning it into a mutable static would reintroduce the #701 hazard;
 * turning it into an instance would rewrite every one of those call sites for no
 * behavioural gain. Core's contribution stays a constant, plugin contributions
 * live here, and {@see SettingsCatalog} is the one place the two are unioned.
 *
 * Attribution comes from the loader
 * ---------------------------------
 * `$source` is the plugin NAME the loader supplies from `$plugin->getName()`,
 * never anything the plugin returned. Keys are namespaced under it, so two
 * plugins may both declare `mode` and neither can shadow a core key — the same
 * rule {@see ResourceTypeRegistry} applies, and deliberately the same separator,
 * so `acme:` means one thing across the install.
 */
class PluginSettingsRegistry implements HostWiredService
{
    /**
     * Separates a plugin's namespace from its key: `acme:sync_interval`.
     *
     * A colon, matching {@see ResourceTypeRegistry} and the data-type catalogue.
     * Core keys stay BARE (`site_name`, `mail.smtp.host`) — they are the
     * reserved, unprefixed namespace, and they are already written that way in
     * `app_settings`/`tenant_settings`, so namespacing plugins rewrites nothing
     * that already exists.
     *
     * It is also what makes a collision structurally impossible: no core key
     * contains a colon and every plugin key contains exactly one.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * The width of `setting_key` in BOTH settings tables (migrations 024/025).
     *
     * A canonical key longer than the column cannot be stored at all, so it is
     * refused at declaration time — one logged warning when the plugin loads,
     * instead of a key that appears on the admin screen and 500s on every write.
     */
    public const MAX_KEY_LENGTH = 100;

    /**
     * A bare key: lowercase, starts with a letter, letters/digits/underscores,
     * optionally grouped with dots.
     *
     * Dots are allowed because core's own keys use them (`mail.smtp.host`,
     * `documents.render_max_rows`) and a plugin with more than two settings wants
     * the same grouping. Colons are not, since that is the separator the host
     * applies — a key containing one could write its own prefix.
     */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * Field names a declaration may carry. Anything else is refused rather than
     * ignored: a silently dropped `maxlength` (for `max_length`) is a constraint
     * the plugin author believes is enforced and is not.
     *
     * @var list<string>
     */
    private const KNOWN_FIELDS = [
        'type', 'default', 'options', 'min', 'max', 'max_length', 'pattern',
        'label', 'description', 'global_only', 'admin',
    ];

    /**
     * Field names that name a credential. Present ONLY to refuse them loudly —
     * see {@see InvalidSettingDeclarationException::forSecret()}.
     *
     * @var list<string>
     */
    private const SECRET_FIELDS = ['secret', 'encrypted', 'write_only'];

    /**
     * Registered definitions, keyed by canonical namespaced key.
     *
     * @var array<string, SettingDefinition>
     */
    private array $definitions = [];

    /**
     * Canonical keys grouped by source, in declaration order.
     *
     * @var array<string, list<string>>
     */
    private array $keysBySource = [];

    private ?HookManager $hookManager;

    /**
     * @param HookManager|null $hookManager Announces registrations on the durable spine.
     */
    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Register a source's declared settings.
     *
     * Each setting is validated and stored INDEPENDENTLY: one malformed
     * declaration is rejected on its own and does not discard the source's other
     * keys — a plugin with six settings and one typo keeps the five that are
     * fine. A setting is never partially stored.
     *
     * @param string                              $source       Plugin name supplied by the loader.
     * @param array<string, array<string, mixed>> $declarations Bare key => declaration.
     * @return list<string> The canonical keys actually registered.
     *
     * @throws InvalidSettingDeclarationException On the FIRST invalid declaration,
     *                                            so the loader can log it against
     *                                            the plugin. Settings validated
     *                                            before it are already stored.
     */
    public function register(string $source, array $declarations): array
    {
        $prefix = SourceSlug::from($source);
        if ($prefix === null) {
            throw InvalidSettingDeclarationException::forSource($source);
        }

        $registered = [];
        foreach ($declarations as $bareKey => $declaration) {
            $definition = $this->build($source, $prefix, (string) $bareKey, $declaration);

            $this->definitions[$definition->key()] = $definition;
            $this->keysBySource[$source] = [
                ...($this->keysBySource[$source] ?? []),
                $definition->key(),
            ];
            $registered[] = $definition->key();
        }

        $this->dispatch($source, $registered);

        return $registered;
    }

    /** Whether a canonical key is registered by any source. */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->definitions);
    }

    /**
     * The definition registered under a canonical key, or null when unknown.
     */
    public function get(string $key): ?SettingDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * Every registered canonical key, in registration order.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * Every registered definition, keyed by canonical key.
     *
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * The canonical keys registered by one source.
     *
     * @return list<string>
     */
    public function keysBySource(string $source): array
    {
        return array_values($this->keysBySource[$source] ?? []);
    }

    /**
     * The canonical key a source's bare key resolves to.
     *
     * Callers use this rather than concatenating the prefix by hand, so the
     * namespacing rule lives in one place and a change to it cannot silently
     * orphan every value a plugin has already written.
     */
    public static function canonicalKey(string $source, string $bareKey): string
    {
        $prefix = SourceSlug::from($source);

        return $prefix === null ? $bareKey : $prefix . self::NAMESPACE_SEPARATOR . $bareKey;
    }

    /**
     * Validate one declaration and build its definition.
     *
     * @param string $source      The raw plugin name (kept for attribution).
     * @param string $prefix      Its normalised namespace prefix.
     * @param string $bareKey     The key the plugin declared.
     * @param mixed  $declaration The raw declaration.
     *
     * @throws InvalidSettingDeclarationException
     */
    private function build(string $source, string $prefix, string $bareKey, mixed $declaration): SettingDefinition
    {
        if (preg_match(self::KEY_PATTERN, $bareKey) !== 1) {
            throw InvalidSettingDeclarationException::forKey($bareKey);
        }

        $key = $prefix . self::NAMESPACE_SEPARATOR . $bareKey;

        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw InvalidSettingDeclarationException::forOversizedKey($key);
        }
        // Belt and braces against a future core key that happens to carry a
        // colon: the shape rule above already makes this unreachable today.
        if (SettingsRegistry::isKnown($key)) {
            throw InvalidSettingDeclarationException::forCoreKey($key);
        }
        if ($this->has($key)) {
            throw InvalidSettingDeclarationException::forDuplicateKey($key);
        }
        if (!is_array($declaration)) {
            throw InvalidSettingDeclarationException::forMalformedDeclaration($key);
        }

        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $declaration)) {
                throw InvalidSettingDeclarationException::forSecret($key);
            }
        }
        foreach (array_keys($declaration) as $field) {
            if (!in_array((string) $field, self::KNOWN_FIELDS, true)) {
                throw InvalidSettingDeclarationException::forField(
                    $key,
                    (string) $field,
                    'is not a recognised declaration field; expected one of '
                    . implode(', ', self::KNOWN_FIELDS)
                );
            }
        }

        $type = $declaration['type'] ?? null;
        if (!is_string($type) || !in_array($type, SettingDefinition::TYPES, true)) {
            throw InvalidSettingDeclarationException::forType($key, is_string($type) ? $type : '');
        }

        $options = self::options($key, $type, $declaration['options'] ?? null);
        [$min, $max] = self::bounds($key, $type, $declaration);
        $maxLength = self::maxLength($key, $type, $declaration['max_length'] ?? null);
        $pattern = self::pattern($key, $type, $declaration['pattern'] ?? null);

        $definition = new SettingDefinition(
            $key,
            $source,
            $bareKey,
            $type,
            self::defaultValue($key, $declaration),
            $options,
            $min,
            $max,
            $maxLength,
            $pattern,
            self::labels($declaration['label'] ?? null, $bareKey),
            self::description($key, $declaration['description'] ?? null),
            self::flag($key, 'global_only', $declaration['global_only'] ?? false),
            self::flag($key, 'admin', $declaration['admin'] ?? false),
        );

        // The declaration must satisfy ITSELF. A default outside its own enum or
        // bounds is a key whose unset state is invalid — it would resolve to a
        // value the very next write is refused for.
        $reason = $definition->validate($definition->defaultValue());
        if ($reason !== null) {
            throw InvalidSettingDeclarationException::forInvalidDefault($key, $reason);
        }

        return $definition;
    }

    /**
     * Coerce the declared default into its canonical stored form.
     *
     * Booleans and integers are accepted and stringified because that is how a
     * plugin author naturally writes them (`'default' => false`, not
     * `'default' => 'false'`), and PHP's own `(string) false` is `''` — a silent
     * wrong answer this coercion exists to prevent.
     *
     * @param array<string, mixed> $declaration
     *
     * @throws InvalidSettingDeclarationException
     */
    private static function defaultValue(string $key, array $declaration): string
    {
        if (!array_key_exists('default', $declaration)) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'default',
                'is required — it is what the key resolves to when neither a '
                . 'per-tenant override nor a global default is stored'
            );
        }

        $raw = $declaration['default'];

        return match (true) {
            is_string($raw) => $raw,
            is_bool($raw) => $raw ? 'true' : 'false',
            is_int($raw) => (string) $raw,
            default => throw InvalidSettingDeclarationException::forField(
                $key,
                'default',
                'must be a string, bool or int (values are stored as TEXT)'
            ),
        };
    }

    /**
     * @param mixed $raw The raw `options` value.
     * @return list<string>|null
     *
     * @throws InvalidSettingDeclarationException
     */
    private static function options(string $key, string $type, mixed $raw): ?array
    {
        if ($type !== SettingDefinition::TYPE_ENUM) {
            if ($raw !== null) {
                throw InvalidSettingDeclarationException::forField(
                    $key,
                    'options',
                    "is only meaningful for type 'enum'"
                );
            }

            return null;
        }

        if (!is_array($raw) || $raw === []) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'options',
                "is required for type 'enum' and must be a non-empty list of allowed values"
            );
        }

        $out = [];
        foreach ($raw as $option) {
            if (!is_string($option) || $option === '') {
                throw InvalidSettingDeclarationException::forField(
                    $key,
                    'options',
                    'must contain only non-empty strings'
                );
            }
            $out[] = $option;
        }

        return array_values(array_unique($out));
    }

    /**
     * @param array<string, mixed> $declaration
     * @return array{0: int|null, 1: int|null}
     *
     * @throws InvalidSettingDeclarationException
     */
    private static function bounds(string $key, string $type, array $declaration): array
    {
        $min = self::intField($key, $type, 'min', $declaration['min'] ?? null);
        $max = self::intField($key, $type, 'max', $declaration['max'] ?? null);

        if ($min !== null && $max !== null && $min > $max) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'min',
                "must not exceed 'max'"
            );
        }

        return [$min, $max];
    }

    /**
     * @throws InvalidSettingDeclarationException
     */
    private static function intField(string $key, string $type, string $field, mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if ($type !== SettingDefinition::TYPE_INT) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                $field,
                "is only meaningful for type 'int'"
            );
        }
        if (!is_int($raw)) {
            throw InvalidSettingDeclarationException::forField($key, $field, 'must be an integer');
        }

        return $raw;
    }

    /**
     * @throws InvalidSettingDeclarationException
     */
    private static function maxLength(string $key, string $type, mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if ($type !== SettingDefinition::TYPE_STRING) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'max_length',
                "is only meaningful for type 'string'"
            );
        }
        if (!is_int($raw) || $raw < 1) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'max_length',
                'must be a positive integer'
            );
        }

        return $raw;
    }

    /**
     * Validate the optional string pattern.
     *
     * Delimiters are supplied by the host, never by the declaration: a plugin
     * passing `/^a$/i` would otherwise choose its own modifiers, and `e` was a
     * modifier that executed code. The pattern is compiled here — against the
     * exact delimiters {@see SettingDefinition} will use — so a syntactically
     * broken one is a load-time warning rather than a `preg_match()` notice on
     * every settings write.
     *
     * @throws InvalidSettingDeclarationException
     */
    private static function pattern(string $key, string $type, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if ($type !== SettingDefinition::TYPE_STRING) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'pattern',
                "is only meaningful for type 'string'"
            );
        }
        if (!is_string($raw) || $raw === '') {
            throw InvalidSettingDeclarationException::forField($key, 'pattern', 'must be a non-empty string');
        }
        if (str_contains($raw, '/')) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'pattern',
                'must not contain a delimiter — declare the bare expression '
                . "('^[a-z]+$'), and the host supplies the delimiters and modifiers"
            );
        }

        $compiles = @preg_match('/' . $raw . '/u', '');
        if ($compiles === false) {
            throw InvalidSettingDeclarationException::forField(
                $key,
                'pattern',
                'is not a valid regular expression'
            );
        }

        return $raw;
    }

    /**
     * Normalise the label map, falling back to the bare key so a setting always
     * has something a screen can render.
     *
     * @return array<string, string>
     */
    private static function labels(mixed $raw, string $bareKey): array
    {
        if (!is_array($raw)) {
            return ['en' => $bareKey];
        }

        $out = [];
        foreach ($raw as $locale => $text) {
            if (is_string($locale) && is_string($text) && trim($text) !== '') {
                $out[strtolower($locale)] = $text;
            }
        }

        return $out === [] ? ['en' => $bareKey] : $out;
    }

    /**
     * @throws InvalidSettingDeclarationException
     */
    private static function description(string $key, mixed $raw): string
    {
        if ($raw === null) {
            return '';
        }
        if (!is_string($raw)) {
            throw InvalidSettingDeclarationException::forField($key, 'description', 'must be a string');
        }

        return trim($raw);
    }

    /**
     * @throws InvalidSettingDeclarationException
     */
    private static function flag(string $key, string $field, mixed $raw): bool
    {
        if (!is_bool($raw)) {
            throw InvalidSettingDeclarationException::forField($key, $field, 'must be a boolean');
        }

        return $raw;
    }

    /**
     * Announce a registration on the durable event spine.
     *
     * A listener throwing must not take the catalogue down with it: the settings
     * are already stored by the time this runs, and a failed announcement is
     * strictly less bad than a deployment whose plugin configuration silently
     * does not exist.
     *
     * @param list<string> $keys
     */
    private function dispatch(string $source, array $keys): void
    {
        if ($keys === []) {
            return;
        }

        try {
            $this->hookManager?->dispatch('settings.declared', [
                'source' => $source,
                'settings' => $keys,
            ]);
        } catch (Throwable) {
            // Best effort by design — see above.
        }
    }
}
