<?php

declare(strict_types=1);

namespace Whity\Database\ScaleSeeder;

/**
 * Parsed, validated parameters for the scale-seeder CLI (WC-35).
 *
 * Every knob defaults to a small, sane value; per the project's "no hardcoded
 * values" convention nothing here is a magic constant buried inside
 * {@see ScaleSeeder} itself — every default lives in exactly ONE place (the
 * `DEFAULT_*` constants below) and every value is overridable via a CLI flag
 * (see {@see \Whity\Cli\Commands\ScaleSeedCommand}).
 *
 * Scale-multiplier semantics: `--scale` multiplies the PER-TENANT volume
 * knobs — users-per-tenant, OU breadth, and relation density — because those
 * are continuous "how much" dials. It deliberately does NOT multiply
 * `--tenants` or `--ou-depth`: those are discrete/structural choices (how
 * many separate environments, how many hierarchy levels) that an operator
 * should set directly rather than have silently multiplied, since OU node
 * count already grows exponentially with depth and an accidental tenant
 * explosion is much harder to undo than a deeper user/OU tree.
 */
final class ScaleSeederConfig
{
    public const DEFAULT_SEED = 42;
    public const DEFAULT_TENANTS = 5;
    public const DEFAULT_USERS_PER_TENANT = 25;
    public const DEFAULT_OU_DEPTH = 3;
    public const DEFAULT_OU_BREADTH = 3;
    public const DEFAULT_RELATIONS_PER_PERSON = 1.5;
    public const DEFAULT_CUSTOM_ROLES_PER_TENANT = 2;
    public const DEFAULT_SCALE = 1.0;
    public const DEFAULT_BATCH_SIZE = 200;

    /** Environment variable that supplies the shared scale-seeded-user password (InitialPassword pattern). */
    public const PASSWORD_ENV_VAR = 'SCALE_SEED_PASSWORD';

    private function __construct(
        public readonly int $seed,
        public readonly int $tenants,
        public readonly int $usersPerTenant,
        public readonly int $ouDepth,
        public readonly int $ouBreadth,
        public readonly float $relationsPerPerson,
        public readonly int $customRolesPerTenant,
        public readonly float $scale,
        public readonly int $batchSize,
        public readonly bool $dryRun,
        public readonly bool $reset,
    ) {
    }

    /**
     * Build from a parsed CLI options map (`--key=value` / bare `--flag`).
     *
     * @param array<string, string|bool> $options
     * @throws \InvalidArgumentException On a malformed/out-of-range value.
     */
    public static function fromOptions(array $options): self
    {
        $seed = self::intOption($options, 'seed', self::DEFAULT_SEED);
        $tenants = self::positiveIntOption($options, 'tenants', self::DEFAULT_TENANTS);
        $baseUsersPerTenant = self::positiveIntOption(
            $options,
            'users-per-tenant',
            self::DEFAULT_USERS_PER_TENANT
        );
        $ouDepth = self::positiveIntOption($options, 'ou-depth', self::DEFAULT_OU_DEPTH);
        $baseOuBreadth = self::positiveIntOption($options, 'ou-breadth', self::DEFAULT_OU_BREADTH);
        $baseRelationsPerPerson = self::nonNegativeFloatOption(
            $options,
            'relations-per-person',
            self::DEFAULT_RELATIONS_PER_PERSON
        );
        $customRolesPerTenant = self::nonNegativeIntOption(
            $options,
            'custom-roles-per-tenant',
            self::DEFAULT_CUSTOM_ROLES_PER_TENANT
        );
        $scale = self::positiveFloatOption($options, 'scale', self::DEFAULT_SCALE);
        $batchSize = self::positiveIntOption($options, 'batch-size', self::DEFAULT_BATCH_SIZE);
        $dryRun = self::flagOption($options, 'dry-run');
        $reset = self::flagOption($options, 'reset');

        // Apply the overall scale multiplier to the per-tenant volume knobs only
        // (see class docblock).
        $usersPerTenant = max(1, (int) round($baseUsersPerTenant * $scale));
        $ouBreadth = max(1, (int) round($baseOuBreadth * $scale));
        $relationsPerPerson = max(0.0, $baseRelationsPerPerson * $scale);

        return new self(
            seed: $seed,
            tenants: $tenants,
            usersPerTenant: $usersPerTenant,
            ouDepth: $ouDepth,
            ouBreadth: $ouBreadth,
            relationsPerPerson: $relationsPerPerson,
            customRolesPerTenant: $customRolesPerTenant,
            scale: $scale,
            batchSize: $batchSize,
            dryRun: $dryRun,
            reset: $reset,
        );
    }

    /** Build directly from typed values (tests, programmatic callers). */
    public static function make(
        int $seed = self::DEFAULT_SEED,
        int $tenants = self::DEFAULT_TENANTS,
        int $usersPerTenant = self::DEFAULT_USERS_PER_TENANT,
        int $ouDepth = self::DEFAULT_OU_DEPTH,
        int $ouBreadth = self::DEFAULT_OU_BREADTH,
        float $relationsPerPerson = self::DEFAULT_RELATIONS_PER_PERSON,
        int $customRolesPerTenant = self::DEFAULT_CUSTOM_ROLES_PER_TENANT,
        float $scale = self::DEFAULT_SCALE,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        bool $dryRun = false,
        bool $reset = false,
    ): self {
        $usersPerTenant = max(1, (int) round($usersPerTenant * $scale));
        $ouBreadth = max(1, (int) round($ouBreadth * $scale));
        $relationsPerPerson = max(0.0, $relationsPerPerson * $scale);

        return new self(
            seed: $seed,
            tenants: max(1, $tenants),
            usersPerTenant: $usersPerTenant,
            ouDepth: max(1, $ouDepth),
            ouBreadth: $ouBreadth,
            relationsPerPerson: $relationsPerPerson,
            customRolesPerTenant: max(0, $customRolesPerTenant),
            scale: $scale,
            batchSize: max(1, $batchSize),
            dryRun: $dryRun,
            reset: $reset,
        );
    }

    /**
     * Total organizational_units per tenant: 1 root + breadth children per
     * node at each subsequent level, for `ouDepth` levels total.
     */
    public function ousPerTenant(): int
    {
        $total = 0;
        $levelCount = 1;
        for ($level = 1; $level <= $this->ouDepth; $level++) {
            $total += $levelCount;
            $levelCount *= $this->ouBreadth;
        }

        return $total;
    }

    /** Estimated relation edges per tenant given the person count and target average degree. */
    public function relationsPerTenant(int $personsInTenant): int
    {
        if ($personsInTenant < 2 || $this->relationsPerPerson <= 0.0) {
            return 0;
        }

        return (int) round(($personsInTenant * $this->relationsPerPerson) / 2);
    }

    // ── Option parsing helpers ──────────────────────────────────────────────

    /** @param array<string, string|bool> $options */
    private static function intOption(array $options, string $key, int $default): int
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $raw = $options[$key];
        if (!is_string($raw) || !is_numeric($raw) || (int) $raw != $raw) {
            throw new \InvalidArgumentException("--{$key} must be an integer, got: " . self::describe($raw));
        }

        return (int) $raw;
    }

    /** @param array<string, string|bool> $options */
    private static function positiveIntOption(array $options, string $key, int $default): int
    {
        $value = self::intOption($options, $key, $default);
        if ($value < 1) {
            throw new \InvalidArgumentException("--{$key} must be >= 1, got: {$value}");
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    private static function nonNegativeIntOption(array $options, string $key, int $default): int
    {
        $value = self::intOption($options, $key, $default);
        if ($value < 0) {
            throw new \InvalidArgumentException("--{$key} must be >= 0, got: {$value}");
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    private static function positiveFloatOption(array $options, string $key, float $default): float
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $raw = $options[$key];
        if (!is_string($raw) || !is_numeric($raw)) {
            throw new \InvalidArgumentException("--{$key} must be a number, got: " . self::describe($raw));
        }
        $value = (float) $raw;
        if ($value <= 0.0) {
            throw new \InvalidArgumentException("--{$key} must be > 0, got: {$value}");
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    private static function nonNegativeFloatOption(array $options, string $key, float $default): float
    {
        if (!array_key_exists($key, $options)) {
            return $default;
        }
        $raw = $options[$key];
        if (!is_string($raw) || !is_numeric($raw)) {
            throw new \InvalidArgumentException("--{$key} must be a number, got: " . self::describe($raw));
        }
        $value = (float) $raw;
        if ($value < 0.0) {
            throw new \InvalidArgumentException("--{$key} must be >= 0, got: {$value}");
        }

        return $value;
    }

    /** @param array<string, string|bool> $options */
    private static function flagOption(array $options, string $key): bool
    {
        if (!array_key_exists($key, $options)) {
            return false;
        }
        $raw = $options[$key];

        return $raw === true || $raw === '1' || $raw === 'true';
    }

    private static function describe(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : gettype($value);
    }
}
