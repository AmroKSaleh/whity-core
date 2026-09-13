<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional declaration of the LIMITS a plugin sells.
 *
 * A plugin MAY implement this — in addition to {@see PluginInterface} — to add
 * its own entries to the platform's catalogue of sellable limits. A course
 * plugin sells "max students"; an exam plugin sells "exams per month". Those
 * then appear in the same tier editor as storage and seats, are priced by the
 * same people, and are resolved by the same three layers (a workspace's own
 * override, then its tier, then the baseline declared here).
 *
 * Without this, a plugin wanting to sell a limit has to build its own pricing,
 * its own per-tenant storage and its own editor — a second, worse copy of a
 * mechanism the platform already has, which the operator then has to configure
 * in two places.
 *
 * Like {@see PluginRolesInterface} and {@see PluginFrontendInterface}, this is a
 * capability side-interface: implementing it is optional and omitting it affects
 * nothing.
 *
 * ── The rules, and why each one refuses rather than warns ──────────────────
 *
 * NAMESPACED TO THE PLUGIN. Keys must read `<plugin>.<name>`, lowercase, where
 * `<plugin>` is this plugin's own name in lowercase — `courses.students.max`
 * for a plugin called `Courses`. A key outside that namespace is refused, and a
 * key core already owns is refused outright: core names are not plugin-ownable,
 * exactly as with permissions and routes. Two plugins quietly disagreeing about
 * what one key means is not a conflict anybody would find by reading.
 *
 * DECLARED AT LOAD, NEVER LATER. The host reads this once while loading, and
 * closes the catalogue afterwards. That is not ceremony: the catalogue is
 * process-level state and a production host runs several worker processes, so a
 * limit registered during a request would exist in one of them and not the
 * others — and a workspace's access would depend on which worker answered,
 * which reads as flakiness rather than as a bug.
 *
 * WITHDRAWN ON UNINSTALL — not on disable. Uninstalling removes these from the
 * catalogue, so a limit nothing can enforce is never left on a pricing screen.
 * DISABLING deliberately leaves them: re-enabling a plugin does not run this
 * declaration again, so withdrawing on disable would leave an ACTIVE plugin
 * whose limits had silently vanished, with its gates reading "unlimited" until
 * the next restart. A running feature with no limits is worse than a stopped
 * feature with stale ones.
 *
 * Values a tier already holds against these keys are KEPT either way, so a
 * reinstall restores the pricing rather than making somebody rebuild it. An
 * undeclared key gates nothing in the meantime.
 *
 * ── Example ────────────────────────────────────────────────────────────────
 *
 * ```php
 * public function getEntitlements(): array
 * {
 *     return [
 *         // A standing cap: how many may EXIST at once.
 *         [
 *             'key'         => 'courses.students.max',
 *             'type'        => 'int',
 *             'default'     => '-1',
 *             'description' => 'Maximum students this workspace may enrol.',
 *         ],
 *         // A meter: how many may be USED inside a window that resets.
 *         [
 *             'key'         => 'courses.exams.per_month',
 *             'type'        => 'int',
 *             'default'     => '-1',
 *             'description' => 'Exams this workspace may run each calendar month.',
 *             'period'      => 'month',
 *         ],
 *         // A flag.
 *         [
 *             'key'         => 'courses.transcripts',
 *             'type'        => 'bool',
 *             'default'     => 'false',
 *             'description' => 'Allow this workspace to issue transcripts.',
 *         ],
 *     ];
 * }
 * ```
 *
 * ── Enforcing them is still yours ──────────────────────────────────────────
 *
 * Declaring a limit puts it in the catalogue and makes it priceable; it does
 * NOT make anything check it. The plugin reads its own limits where the action
 * happens — `EntitlementService::limit()` for a cap, `MeterService::consume()`
 * for a meter — exactly as core does for its own. A declared limit nothing
 * checks is a line on a pricing screen that gates nothing, which is worse than
 * not selling it, because somebody is paying.
 */
interface PluginEntitlementsInterface
{
    /**
     * The limits this plugin sells.
     *
     * ARRAYS RATHER THAN OBJECTS, deliberately: a plugin declares what it sells
     * without importing a core value object, so the SDK contract stays a shape
     * rather than a class the host must keep binary-compatible across versions.
     *
     * Keys per entry:
     *   - `key`         string, `<plugin>.<name>`, lowercase. Required.
     *   - `type`        'bool' or 'int'. Required.
     *   - `default`     the baseline grant as TEXT — 'true'/'false', a number,
     *                   or '-1' for unlimited. Required, and validated against
     *                   the declared type.
     *   - `description` what it sells, written for whoever prices it. Required;
     *                   an unlabelled row is one they cannot price.
     *   - `period`      optional: 'day', 'week' or 'month' to make it a METER,
     *                   consumed inside a window that resets on the calendar.
     *                   Absent means a standing cap or a flag.
     *
     * A malformed entry is refused with the reason and the plugin still loads —
     * its other declarations are unaffected — because one bad row must not cost
     * an operator the whole plugin.
     *
     * @return list<array{key: string, type: string, default: string, description: string, period?: string}>
     */
    public function getEntitlements(): array;
}
