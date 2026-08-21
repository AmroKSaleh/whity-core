<?php

declare(strict_types=1);

namespace Whity\Core\Db;

/**
 * The one way to read a SQL boolean into a PHP bool (#891).
 *
 * WHAT WAS ACTUALLY WRONG
 * -----------------------
 * Not, as the folklore in this repo had it, that `(bool)` casts were silently
 * inverting on PostgreSQL. That claim was MEASURED on the PHP this platform
 * ships (8.4, `dunglas/frankenphp:1-php8.4`) and is false — see the table
 * below. What was true is that twelve private `toBool()`/`dbTruthy()` copies
 * had accumulated across `src/`, implementing THREE mutually-incompatible
 * answers to the same question, and the answer a given column got depended on
 * which file happened to read it.
 *
 * WHAT pdo_pgsql ACTUALLY RETURNS FOR `BOOLEAN` (PHP 8.4.24, measured, across
 * `query()` and `prepare()`, with `ATTR_EMULATE_PREPARES` both ways):
 *
 *     ATTR_STRINGIFY_FETCHES = false (the driver default) …… bool(true) / bool(false)
 *     ATTR_STRINGIFY_FETCHES = true  ……………………………………………… string('1') / string('0')
 *
 * Note what is NOT in that list: `'t'` / `'f'`. Every comment in this codebase
 * asserting PostgreSQL hands back `'t'`/`'f'` — and the `(bool) 'f' === true`
 * inversion those comments warn about — describes a driver generation we do not
 * run. A bare `(bool)` cast on a `BOOLEAN` column is, today, correct under BOTH
 * settings of `STRINGIFY_FETCHES`. So this class is not an incident fix.
 *
 * WHY IT EXISTS ANYWAY
 * --------------------
 * Because "correct today, by a driver default nobody chose" is not the same as
 * correct, and because the three competing semantics DID disagree on inputs
 * that reach them:
 *
 *   - the dominant form (8 copies) is a case-insensitive, trimmed DENY-list:
 *     everything except `'' 0 f false no` is true;
 *   - three copies use `in_array((string) $v, ['1','t','true'], true)` — a
 *     CASE-SENSITIVE allow-list, so it answers FALSE for `'TRUE'`;
 *   - two copies compare against a hand-written identity set, same blind spot.
 *
 * There is one representation that genuinely defeats a bare `(bool)` cast, and
 * it is reachable: a boolean projected as text (`SELECT flag::text`) returns
 * `'false'`, and `(bool) 'false'` is TRUE. The strict allow-lists handle it;
 * a bare cast does not. That, plus `'t'`/`'f'` remaining the correct answer for
 * any older driver, is why the canonical form accepts every representation
 * rather than the one the current driver happens to emit.
 *
 * THE CANONICAL SEMANTIC is the deny-list, because it is what 8 of the 12
 * copies already did — so unifying on it changed nothing at those sites — and
 * because it is case- and whitespace-insensitive, which the allow-lists were
 * not. It differs from the allow-lists ONLY on values a `BOOLEAN` column cannot
 * produce (`'2'`, `'yes'`, `'maybe'`), and every migrated call site was checked
 * to read a real `BOOLEAN` column.
 *
 * Accepts, correctly, all of: `true`/`false`, `1`/`0`, `'1'`/`'0'`, `'t'`/`'f'`,
 * `'true'`/`'false'` in any case, `''`, and `null`.
 *
 * @see \Whity\Core\Db\DbBoolScanner  the CI guard that keeps new bare casts out
 */
final class DbBool
{
    /**
     * String forms that mean FALSE, lower-cased and trimmed before comparison.
     *
     * A deny-list rather than an allow-list: see the class docblock. `''` is
     * listed because PHP casts `false` to the empty string, and because some
     * drivers render a NULL boolean that way.
     */
    private const FALSE_STRINGS = ['', '0', 'f', 'false', 'no'];

    /**
     * Coerce a value read from a SQL boolean column into a PHP bool.
     *
     * NULL is false: a nullable boolean that was never set is not "true", and
     * every call site that wants to distinguish "unset" from "false" has to
     * check for null BEFORE calling this — which is visible at the call site,
     * where the decision belongs.
     */
    public static function of(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if ($value === null) {
            return false;
        }
        if (is_float($value)) {
            return $value !== 0.0;
        }
        if (is_array($value) || is_object($value)) {
            // Not a scalar the drivers ever hand back for a boolean column.
            // Refuse rather than guess — `(string) [...]` is "Array", which the
            // deny-list would happily call TRUE.
            throw new \InvalidArgumentException(
                'DbBool::of() expects a scalar or null, got ' . get_debug_type($value) . '.'
            );
        }

        return !in_array(strtolower(trim((string) $value)), self::FALSE_STRINGS, true);
    }
}
