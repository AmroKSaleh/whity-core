<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional AUDITED-EVENT contribution point for plugins (SDK v1.29).
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to declare which of its OWN hook events the host
 * should turn into rows in the platform audit trail. Like the sibling
 * capability interfaces ({@see PluginJobsInterface}, {@see PluginRolesInterface},
 * {@see PluginMcpInterface}, {@see PluginFrontendInterface}), this is purely
 * additive: plugins that do not implement it load exactly as before.
 *
 * Why it exists
 * -------------
 * The host's audit writer subscribes to a HARDCODED map of core event names —
 * `role.created`, `user.updated`, `ou.role_assigned` and a dozen more. A plugin
 * dispatching its own event through the hook manager matched none of them, so
 * nothing a plugin did was ever recorded: an operator opening the one screen
 * that answers "who did what" saw core's mutations and a silence where every
 * plugin-side action had been. The alternatives a plugin was left with were
 * both worse than the gap. Writing to `audit_log` directly means a second
 * writer on the table whose whole design is that it has one — no metadata
 * sanitising, no tenant resolution, and nothing stopping a row that claims to
 * be `user.deleted`. Keeping a private activity table means a second audit
 * surface, in a second place, that nobody is looking at.
 *
 * Namespacing
 * -----------
 * Declare BARE event names. The host stores them under this plugin's own
 * namespace, so a plugin declaring `task.completed` is audited as
 * `acme:task.completed` with a target type of `acme:task`. Both halves are
 * namespaced, and for the same reason: an action of `task.completed` beside a
 * target type of `user` reads, to the operator filtering the trail, as
 * something core did to a core record.
 *
 * The prefix is derived from the plugin NAME the loader supplies, never from
 * anything returned here: a plugin may declare any name it likes, but it cannot
 * declare who said it. Three consequences, all intended:
 *
 *  - two plugins declaring `task.completed` produce DIFFERENT audit actions, so
 *    one plugin's activity is never filed under another's name;
 *  - a plugin cannot produce a bare name, so it cannot SHADOW or FORGE a core
 *    audit action such as `user.deleted` no matter what it declares;
 *  - a declared name carrying the `:` separator is REFUSED, since that would be
 *    the plugin writing its own prefix.
 *
 * Dispatching
 * -----------
 * The host binds its listener to the NAMESPACED event name, so that is the name
 * to dispatch — `Whity\Sdk\Hooks\Events::forPlugin($this->getName(), 'task.completed')`
 * spells it, and is the only supported way to build it. Listening on the bare
 * name instead was considered and rejected: the hook manager tells a listener
 * nothing about WHO dispatched, so with two plugins declaring `task.completed`
 * a single dispatch by one of them would write an audit row for BOTH. An audit
 * trail that records an event which did not happen is worse than one that
 * records nothing, so the trigger is namespaced too and a dispatch can only
 * ever be attributed to the plugin whose prefix it carries.
 *
 * Failure isolation
 * -----------------
 * A {@see getAuditedEvents()} that THROWS, or that returns a malformed
 * declaration, costs that plugin its audit subscriptions (logged, and recorded
 * against the plugin's lifecycle) and costs the host nothing — no other
 * plugin's events and none of core's own are affected. The refusal is
 * WHOLE-declaration, not per entry: a half-subscribed plugin would produce a
 * trail that looks complete and silently omits some of its actions, which is
 * more dangerous than an empty one, because only the empty one is obviously
 * empty.
 *
 * Disabled plugins contribute nothing. The subscriptions are registered with
 * the plugin's other hooks, so disabling it removes them and re-enabling it
 * restores them.
 *
 *     public function getAuditedEvents(): array
 *     {
 *         return [
 *             'task.completed' => ['targetType' => 'task', 'idKey' => 'task_id'],
 *         ];
 *     }
 *
 *     // …wherever the task is actually completed:
 *     $hooks->dispatch(Events::forPlugin($this->getName(), 'task.completed'), [
 *         'task_id'   => $taskId,
 *         'tenant_id' => $tenantId,
 *         'title'     => $title,   // kept as audit metadata
 *     ]);
 */
interface PluginEventsInterface
{
    /**
     * The hook events this plugin wants audited, BARE event name => descriptor.
     *
     * A bare event name is lowercase, starts with a letter, and continues with
     * letters, digits, underscores or dots (`task.completed`, `invoice.sent`) —
     * the shape core's own event names already use. It carries NO colon: that is
     * the namespace separator the host applies.
     *
     * The key is BOTH the event listened for and the audit action recorded, so
     * the two can never disagree. A separate `action` field was considered and
     * left out: core's own map has never used one (all twelve of its entries
     * spell the same string twice), and the only thing the extra freedom buys is
     * the ability to file `task.deleted` under an action that says something
     * else — an audit trail that describes an event other than the one that
     * happened.
     *
     * The descriptor is:
     *
     *  - `targetType` — REQUIRED, the kind of record this event is about
     *    (`task`, `invoice`). Lowercase, starts with a letter, may contain
     *    digits and underscores. The host namespaces it, so `task` is stored as
     *    `acme:task`.
     *  - `idKey` — REQUIRED, the payload key holding the affected record's id
     *    (`task_id`), or explicitly `null` for an event with no single target
     *    (the shape core already uses for a failed login). Required rather than
     *    defaulted to `id`: a default is right for core's payloads and wrong for
     *    most plugin ones, and getting it wrong produces a row that names an
     *    action and points at nothing, while the write still succeeds. An
     *    explicit `null` says "no single target" out loud, so the two cases stay
     *    distinguishable in review.
     *
     * A value that is not numeric under `idKey` records a null target id rather
     * than refusing — the declaration is checked at load time, but a payload is
     * runtime data and auditing must never break the action it records.
     *
     * @return array<string, array{targetType: string, idKey: string|null}>
     */
    public function getAuditedEvents(): array;
}
