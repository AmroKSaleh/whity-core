<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

/**
 * One plugin-declared setting, validated and frozen at registration.
 *
 * The counterpart of a single entry in {@see SettingsRegistry}'s static
 * catalogue: it answers the same questions core answers for its own keys — what
 * type is this, what is its default, is this value acceptable, what is the
 * canonical stored form — but carries the answers as DATA rather than as a
 * `match` arm, because the plugin that knows them is not the one compiling core.
 *
 * Every instance is already valid. {@see PluginSettingsRegistry} is the only
 * thing that builds one, and it refuses a declaration outright rather than
 * storing a half-checked definition — including a `default` that fails the
 * definition's own rules, which would otherwise be a key that can never be reset
 * to a value the host will accept.
 *
 * Immutable and side-effect free.
 */
final class SettingDefinition
{
    public const TYPE_STRING = 'string';
    public const TYPE_BOOL = 'bool';
    public const TYPE_INT = 'int';
    public const TYPE_ENUM = 'enum';

    /**
     * The declarable types. Deliberately the SAME vocabulary
     * {@see SettingsRegistry::typeFor()} publishes for core keys, so a client
     * rendering the settings screen switches on one set of type names and cannot
     * tell a plugin key from a core one.
     *
     * `asset` is absent: an asset key's value is a storage reference written by
     * the branding upload endpoints, which are core-only surfaces.
     *
     * @var list<string>
     */
    public const TYPES = [self::TYPE_STRING, self::TYPE_BOOL, self::TYPE_INT, self::TYPE_ENUM];

    /**
     * @param string                $key         The canonical namespaced key (`acme:sync_interval`).
     * @param string                $source      The declaring plugin name, as the loader supplied it.
     * @param string                $bareKey     The key as the plugin declared it.
     * @param string                $type        One of {@see TYPES}.
     * @param string                $default     The fallback value, in canonical stored form.
     * @param list<string>|null     $options     Allowed values for an enum; null otherwise.
     * @param int|null              $min         Inclusive lower bound for an int; null otherwise.
     * @param int|null              $max         Inclusive upper bound for an int; null otherwise.
     * @param int|null              $maxLength   Maximum length in characters for a string.
     * @param string|null           $pattern     Anchorless, delimiter-free regex for a string.
     * @param array<string, string> $labels      locale => display name; never empty.
     * @param string                $description Operator-facing help; may be ''.
     * @param bool                  $globalOnly  Whether a per-tenant override is refused.
     * @param bool                  $adminVisible Whether core's settings screens publish it.
     */
    public function __construct(
        private string $key,
        private string $source,
        private string $bareKey,
        private string $type,
        private string $default,
        private ?array $options,
        private ?int $min,
        private ?int $max,
        private ?int $maxLength,
        private ?string $pattern,
        private array $labels,
        private string $description,
        private bool $globalOnly,
        private bool $adminVisible,
    ) {
    }

    /** The canonical namespaced key, as stored in `app_settings`/`tenant_settings`. */
    public function key(): string
    {
        return $this->key;
    }

    /** The declaring plugin name, as the loader supplied it. */
    public function source(): string
    {
        return $this->source;
    }

    /** The key as the plugin declared it, without the namespace prefix. */
    public function bareKey(): string
    {
        return $this->bareKey;
    }

    /** One of {@see TYPES}. */
    public function type(): string
    {
        return $this->type;
    }

    /** The fallback value, already in canonical stored form. */
    public function defaultValue(): string
    {
        return $this->default;
    }

    /**
     * Allowed values for an enum key, or null when the key is not an enum.
     *
     * @return list<string>|null
     */
    public function options(): ?array
    {
        return $this->options;
    }

    /**
     * Display labels, locale => text. Never empty: a key with no declared label
     * falls back to its bare key, so a screen always has something to render.
     *
     * @return array<string, string>
     */
    public function labels(): array
    {
        return $this->labels;
    }

    /** Operator-facing help, or '' when the plugin declared none. */
    public function description(): string
    {
        return $this->description;
    }

    /** Whether a per-tenant override is refused (operator-level key). */
    public function isGlobalOnly(): bool
    {
        return $this->globalOnly;
    }

    /**
     * Whether core's own settings screens publish this key.
     *
     * Opt-in, and false by default. Those screens are gated on the CORE
     * `settings:*` permissions, so publishing a key there hands it to a
     * population that is not the same as the holders of the plugin's own
     * permissions. A key without this is still stored, resolved and validated
     * identically — it is simply managed on the plugin's own RBAC-gated surface.
     */
    public function isAdminVisible(): bool
    {
        return $this->adminVisible;
    }

    /**
     * Validate a candidate value.
     *
     * Returns null when valid, or a human-readable reason the API layer relays
     * as a field detail — the same contract as
     * {@see SettingsRegistry::validate()}, so a caller cannot tell from the
     * response shape whether it wrote a core key or a plugin one.
     *
     * @param string $value The candidate value (TEXT; the caller stringifies).
     * @return string|null Null when valid; otherwise the failure reason.
     */
    public function validate(string $value): ?string
    {
        return match ($this->type) {
            self::TYPE_BOOL => $this->validateBool($value),
            self::TYPE_INT => $this->validateInt($value),
            self::TYPE_ENUM => $this->validateEnum($value),
            default => $this->validateString($value),
        };
    }

    /**
     * The canonical stored form of a validated value.
     *
     * Only strings are trimmed, mirroring `site_name` — the one core key that
     * normalises — so a caller's incidental whitespace cannot reach storage. The
     * other types are already canonical by the time they validate.
     */
    public function normalize(string $value): string
    {
        return $this->type === self::TYPE_STRING ? trim($value) : $value;
    }

    /**
     * The API descriptor for this key.
     *
     * A superset of {@see SettingsRegistry}'s core descriptor: the same
     * `key`/`type`/`default` (+ `options` for enums) a client already switches
     * on, plus the attribution and presentation a core key does not need because
     * core's own screens hardcode them.
     *
     * @return array{key: string, type: string, default: string, options?: list<string>, source: string, label: array<string, string>, description?: string}
     */
    public function describe(): array
    {
        $descriptor = [
            'key' => $this->key,
            'type' => $this->type,
            'default' => $this->default,
        ];

        if ($this->options !== null) {
            $descriptor['options'] = $this->options;
        }

        // Attribution is not decoration: an operator looking at `acme:mode` on a
        // shared screen needs to know which plugin owns it before changing it,
        // and which plugin to uninstall to make it go away.
        $descriptor['source'] = $this->source;
        $descriptor['label'] = $this->labels;
        if ($this->description !== '') {
            $descriptor['description'] = $this->description;
        }

        return $descriptor;
    }

    private function validateBool(string $value): ?string
    {
        if ($value !== 'true' && $value !== 'false') {
            return "{$this->key} must be 'true' or 'false'.";
        }

        return null;
    }

    private function validateInt(string $value): ?string
    {
        // A leading `-` is accepted so a declaration may use negative bounds;
        // anything else non-numeric is refused rather than silently cast to 0,
        // which is how "off" becomes a real, wrong value.
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            return "{$this->key} must be a whole number.";
        }

        $number = (int) $value;
        if ($this->min !== null && $number < $this->min) {
            return "{$this->key} must be at least {$this->min}.";
        }
        if ($this->max !== null && $number > $this->max) {
            return "{$this->key} must be at most {$this->max}.";
        }

        return null;
    }

    private function validateEnum(string $value): ?string
    {
        $options = $this->options ?? [];
        if (!in_array($value, $options, true)) {
            return "{$this->key} must be one of: " . implode(', ', $options) . '.';
        }

        return null;
    }

    private function validateString(string $value): ?string
    {
        // The TRIMMED value is what gets stored ({@see normalize()}), so both
        // constraints apply to it rather than to the caller's formatting.
        $trimmed = trim($value);

        if ($this->maxLength !== null && mb_strlen($trimmed) > $this->maxLength) {
            return "{$this->key} must be at most {$this->maxLength} characters.";
        }

        if ($this->pattern !== null && preg_match('/' . $this->pattern . '/u', $trimmed) !== 1) {
            return "{$this->key} must match the pattern {$this->pattern}.";
        }

        return null;
    }
}
