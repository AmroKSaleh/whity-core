<?php

declare(strict_types=1);

namespace Whity\Core\Audit;

use Whity\Sdk\PluginNamespace;

/**
 * Turns one plugin's {@see \Whity\Sdk\PluginEventsInterface} declaration into
 * the canonical, namespaced event map {@see AuditLogger::subscribeFromSource()}
 * binds listeners for.
 *
 * The whole point is attribution. A plugin declares BARE names and this class
 * stamps the prefix from the SOURCE — the plugin name the loader holds, never
 * anything the plugin returned — exactly as
 * {@see \Whity\Core\Queue\JobRegistry::registerFromSource()} does for job
 * handlers. A plugin may declare any event it likes; it cannot declare who said
 * it, so it can neither claim another plugin's activity nor mint a bare name and
 * write a row that reads as core's.
 *
 * Pure and side-effect-free by design: it validates and renames, and nothing
 * else. The subscription belongs to {@see AuditLogger} because only the logger
 * can route a hook payload through its own tenant resolution and metadata
 * sanitising, and the point of this seam is that a plugin's events go through
 * exactly the same path core's do rather than a second one written beside it.
 */
final class PluginAuditEvents
{
    /**
     * The width of `audit_log.action` (migration 016).
     *
     * A canonical action wider than the column would be truncated or refused at
     * write time — an event declared audited that is silently never recorded.
     * Rejecting it at declaration time turns that into one logged warning when
     * the plugin loads.
     */
    public const MAX_ACTION_LENGTH = 100;

    /** The width of `audit_log.target_type` (migration 016). Same reasoning. */
    public const MAX_TARGET_TYPE_LENGTH = 100;

    /**
     * A declared event name: lowercase, starts with a letter, dot-separated
     * segments allowed because core's own event names already have that shape.
     *
     * Deliberately has NO colon — that is the separator the host applies, and a
     * declaration containing one would be a plugin writing its own prefix.
     */
    private const EVENT_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /**
     * A declared target type: one lowercase segment, matching core's own
     * `role` / `user` / `tenant` / `ou`. No dots and no colon — a target type is
     * an entity kind, not a path, and the flat shape keeps a namespaced
     * `acme:task` readable at a glance.
     */
    private const TARGET_TYPE_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Validate and namespace one plugin's declaration.
     *
     * Every entry is checked before ANY is returned. A half-accepted plugin
     * would produce an audit trail that looks complete and silently omits the
     * events that did not make it — the exact failure this seam exists to
     * remove — so a malformed declaration costs the plugin all of its events
     * rather than an arbitrary subset.
     *
     * Both halves of the record are namespaced. The action becomes
     * `acme:task.completed` and the target type `acme:task`, because an action
     * a filter can attribute beside a target type of `user` still reads as
     * something core did to a core record.
     *
     * @param string               $source      The plugin name, supplied by the loader.
     * @param array<mixed, mixed>  $declaration The plugin's raw {@see \Whity\Sdk\PluginEventsInterface::getAuditedEvents()} return.
     *
     * @return array<string, array{targetType: string, idKey: string|null}>
     *         Canonical event name (which is also the audit action) => descriptor.
     *
     * @throws InvalidPluginAuditEventException If the source is unusable or any
     *                                          entry is malformed.
     */
    public static function fromDeclaration(string $source, array $declaration): array
    {
        $prefix = PluginNamespace::slug($source);
        if ($prefix === null) {
            throw InvalidPluginAuditEventException::forSource($source);
        }

        $canonical = [];

        foreach ($declaration as $event => $descriptor) {
            // PHP surfaces a numeric-string key as an int; normalise before the
            // pattern check so `['1' => …]` is refused as a bad name rather
            // than crashing the match.
            $event = (string) $event;
            if (preg_match(self::EVENT_PATTERN, $event) !== 1) {
                throw InvalidPluginAuditEventException::forEventName($event);
            }

            if (!is_array($descriptor)) {
                throw InvalidPluginAuditEventException::forDescriptor($event, $descriptor);
            }

            $targetType = $descriptor['targetType'] ?? null;
            if (!is_string($targetType) || preg_match(self::TARGET_TYPE_PATTERN, $targetType) !== 1) {
                throw InvalidPluginAuditEventException::forTargetType($event, $targetType);
            }

            // Presence is checked separately from the value: an ABSENT idKey is
            // an author who did not think about the target, an explicit null is
            // one who did and said there isn't one. Only the second is accepted.
            if (!array_key_exists('idKey', $descriptor)) {
                throw InvalidPluginAuditEventException::forIdKey($event, null);
            }
            $idKey = $descriptor['idKey'];
            if ($idKey !== null && (!is_string($idKey) || $idKey === '')) {
                throw InvalidPluginAuditEventException::forIdKey($event, $idKey);
            }

            $action = $prefix . PluginNamespace::SEPARATOR . $event;
            if (strlen($action) > self::MAX_ACTION_LENGTH) {
                throw InvalidPluginAuditEventException::forOversizedName($action, self::MAX_ACTION_LENGTH);
            }

            $namespacedTarget = $prefix . PluginNamespace::SEPARATOR . $targetType;
            if (strlen($namespacedTarget) > self::MAX_TARGET_TYPE_LENGTH) {
                throw InvalidPluginAuditEventException::forOversizedName(
                    $namespacedTarget,
                    self::MAX_TARGET_TYPE_LENGTH
                );
            }

            $canonical[$action] = [
                'targetType' => $namespacedTarget,
                'idKey' => $idKey,
            ];
        }

        return $canonical;
    }
}
