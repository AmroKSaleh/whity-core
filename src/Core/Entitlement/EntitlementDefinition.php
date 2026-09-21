<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

/**
 * One sellable limit: what it is called, what kind of thing it is, and who owns it.
 *
 * WHY A VALUE OBJECT AND NOT THREE MORE CONST ARRAYS. The registry kept its
 * catalogue as parallel `DEFAULTS`/`TYPES`/`DESCRIPTIONS` maps, which is fine
 * while the catalogue is a compile-time constant. It stopped being one: plugins
 * declare their own limits now — a course plugin sells "max students", an exam
 * plugin sells "exams per month" — and a plugin contributes its entry at boot,
 * not at edit time. Three maps that must be kept key-identical by hand is a
 * shape that survives being written once and not being extended.
 *
 * ── The two kinds, and the third that is really the second ──────────────────
 *
 * `bool` is a feature flag: granted or not. `int` is a cap: how many of a thing
 * may EXIST at once — members, devices, students. Both are answered by looking
 * at the world as it is.
 *
 * A METER is an `int` too, and deliberately not a third type: its VALUE is still
 * just a number. What makes it different is {@see $period} — it counts what has
 * been CONSUMED inside a window that resets on the calendar, so "5 documents a
 * day" is spent by rendering and comes back at midnight whether or not anything
 * was deleted. A cap asks how many exist; a meter asks how many were used since
 * the window opened. Modelling that as a type would have forced every validate/
 * cast/normalize branch to grow a copy of the int branch.
 *
 * TWO PERIODS ON ONE RESOURCE ARE TWO DEFINITIONS, not one definition with a
 * list. "5 a day and 50 a month" is exactly `documents.render.per_day` and
 * `documents.render.per_month`, each priced independently, each shown to
 * marketing as its own row, and each enforced by the same code path. A single
 * definition carrying several windows would need its own value syntax, its own
 * editor, and its own comparison rules for the downgrade guard.
 */
final class EntitlementDefinition
{
    /** A cap or flag: answered by the state of the world, with no window. */
    public const NO_PERIOD = null;

    /** The windows a meter may reset on. Calendar-aligned, never rolling. */
    public const PERIOD_DAY = 'day';
    public const PERIOD_WEEK = 'week';
    public const PERIOD_MONTH = 'month';

    public const PERIODS = [self::PERIOD_DAY, self::PERIOD_WEEK, self::PERIOD_MONTH];

    public const TYPE_BOOL = 'bool';
    public const TYPE_INT = 'int';

    public function __construct(
        public readonly string $key,
        /** 'bool' or 'int'. A meter is an int with a {@see $period}. */
        public readonly string $type,
        /**
         * The baseline grant, as TEXT — what a tenant gets when neither their
         * own overrides nor their tier says anything.
         *
         * THE FREE TIER LIVES HERE, which is why it is part of the declaration
         * rather than a row somewhere. A deployment that sells nothing still has
         * to answer "may this tenant do that", and the answer has to exist
         * before any plan does.
         */
        public readonly string $default,
        /** Shown to whoever is pricing this. Written for marketing, not for us. */
        public readonly string $description,
        /**
         * Null for a flag or a standing cap; one of {@see PERIODS} for a meter.
         *
         * DELIBERATELY TYPED `?string` RATHER THAN A UNION OF THE THREE. A
         * plugin's declaration is DATA arriving at runtime, so the value can be
         * anything a plugin author types; narrowing it here would let static
         * analysis promise a guarantee the boundary cannot keep, and would make
         * the runtime check that actually enforces it look redundant. That check
         * lives in {@see PluginEntitlements::register()} and refuses an unknown
         * window with the reason.
         */
        public readonly ?string $period = self::NO_PERIOD,
        /**
         * Which plugin declared this, or null for core.
         *
         * CARRIED SO IT CAN BE WITHDRAWN. A plugin's limits leave when the
         * plugin does, and without an owner there is no way to tell which
         * entries to drop — leaving a priced feature in the catalogue that
         * nothing enforces, which is worse than not selling it.
         */
        public readonly ?string $owner = null,
    ) {
    }

    /** Whether this is consumed inside a window rather than held. */
    public function isMetered(): bool
    {
        return $this->period !== null;
    }

    public function isPluginOwned(): bool
    {
        return $this->owner !== null;
    }

    /**
     * Whether `$candidate` grants at least as much as `$current`.
     *
     * THE DOWNGRADE GUARD'S ONE PIECE OF ARITHMETIC, and the reason it is not
     * simply `>=`: for an int, -1 means UNLIMITED, so it is the most permissive
     * value there is while also being the smallest number. A guard comparing
     * naively would read "unlimited → 5" as an increase and let somebody quietly
     * cap every tenant on the tier.
     *
     * @throws \InvalidArgumentException When either value is not valid for this
     *         definition — a caller comparing unvalidated input.
     */
    public function grantsAtLeast(string $candidate, string $current): bool
    {
        if ($this->type === self::TYPE_BOOL) {
            // Losing a flag is the reduction; gaining or keeping it is not.
            return EntitlementRegistry::cast($this->key, $candidate)
                || !EntitlementRegistry::cast($this->key, $current);
        }

        $new = (int) EntitlementRegistry::cast($this->key, $candidate);
        $old = (int) EntitlementRegistry::cast($this->key, $current);

        if ($new === EntitlementRegistry::UNLIMITED) {
            return true;
        }
        if ($old === EntitlementRegistry::UNLIMITED) {
            // Anything finite is less than unlimited, including a large number.
            return false;
        }

        return $new >= $old;
    }
}
