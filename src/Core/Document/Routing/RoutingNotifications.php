<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Throwable;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Turns a routing broadcast into notifications (#1054).
 *
 * This class is deliberately thin, and its thinness is the point — the same
 * point {@see DocumentRoutingInboxSource} makes one file over. It is not a
 * delivery channel and it is emphatically not a document-specific mailer: it
 * reads an event the engine already emits, works out who should be told and over
 * which channels, and hands each one to {@see NotificationDispatcher}, which has
 * owned per-channel delivery, retry, templating and per-user preferences since
 * long before routing existed.
 *
 * WHY THIS EXISTS AT ALL RATHER THAN A CALL FROM THE ENGINE
 * ---------------------------------------------------------
 * {@see DocumentRouter} is the system of record for what happened to a document.
 * Its job ends when the trail and the recipient rows are right. A notification is
 * a CONSEQUENCE of that, it can fail without the routing act being wrong, and the
 * next consequence somebody wants — a webhook, a digest, an SMS to a duty phone —
 * must not mean editing the engine again. So the engine broadcasts and this
 * subscribes, which is also what lets a plugin add its own consumer beside this
 * one without core knowing.
 *
 * THE EVENT IT LISTENS TO IS THE SYNCHRONOUS ONE, AND THAT IS NOT AN OVERSIGHT
 * ----------------------------------------------------------------------------
 * #1054 records that routing events "are already published" through
 * `HookManager::dispatchAsync()`, and that only a subscriber was missing. The
 * first half is true and the second is half true, in a way worth writing down
 * because believing it would have produced a feature that looked wired up and did
 * nothing at all:
 *
 *   `dispatchAsync()` PERSISTS an event to `domain_events` and the outbox
 *   (migration 066). IT RUNS NO LISTENERS. And nothing in this codebase drains
 *   the outbox yet — {@see \Whity\Core\Events\DomainEventStore} has the whole
 *   reserve / relay / dead-letter API, and no worker calls it.
 *
 * A listener bound to `document.routed.async` would therefore never have been
 * invoked. So {@see DocumentRouter::broadcast()} now emits BOTH — `document.routed`
 * synchronously for listeners and `document.routed.async` onto the durable spine
 * — which is exactly the pair every other core writer already emits
 * ({@see \Whity\Api\OusApiHandler}, {@see \Whity\Api\RolesApiHandler}). This
 * class binds to the synchronous name.
 *
 * IT RUNS AFTER THE COMMIT. The engine dispatches outside its own transaction,
 * deliberately, so nothing this class does can roll back a routing act that has
 * already succeeded. Every failure here is caught and logged: a person who was
 * not e-mailed still holds their item, and a document that could not be announced
 * was still routed.
 *
 * WHO IS TOLD COMES FROM THE EVENT, NEVER FROM A RE-READ
 * ------------------------------------------------------
 * The payload carries `recipients` — who the act reached, and whether each of
 * them is being ASKED for anything. Re-deriving that here by querying the open
 * recipient rows would be wrong in exactly the case this whole issue is about: a
 * DELIVERY step's rows are already closed by the time any listener runs, so the
 * query would find nobody and would announce nothing to the very people the step
 * existed to tell.
 *
 * THE STEP SAYS WHAT; THE TENANT SAYS HOW
 * ---------------------------------------
 * Which notification TYPE a person gets follows from the step's
 * {@see RouteSatisfaction} — being told and being asked are different messages
 * and must not read alike. Which CHANNELS it goes out on comes from
 * `documents.routing_notification_channels`, resolved per-tenant, then global,
 * then the registry default, and narrowed again per person by
 * {@see \Whity\Core\Notification\NotificationPreferenceResolver} inside the
 * dispatcher. Nothing about a channel appears on a route step, which is what lets
 * an operator move a tenant from in-app to e-mail without re-authoring a single
 * route.
 *
 * Neither type is TRANSACTIONAL, so both can be muted per profile. That is right:
 * a circular is not a password reset. The routing RECORD is unaffected either
 * way — the recipient row still says the person was reached, because being
 * notified and being sent something are different facts, and only one of them is
 * the organisation's record.
 *
 * STATELESS between calls, so it is safe on a long-lived worker.
 */
final class RoutingNotifications
{
    /**
     * "You are holding this, and something is expected of you."
     *
     * Sent to everybody an act opened an OPEN recipient row for — an ordinary
     * circulation step, a decision step, and the predecessor a `returned` handed
     * the document back to.
     */
    public const TYPE_AWAITING = 'document.routing.awaiting';

    /**
     * "This was sent to you. There is nothing to do."
     *
     * Sent to everybody a {@see RouteSatisfaction::DELIVERY} step reached. A
     * SEPARATE type rather than a flag on the first, for two reasons that both
     * bite:
     *
     *  - the words differ, and telling three hundred instructors that a policy
     *    circular "is waiting for you" produces three hundred people looking for
     *    a button that is not there — their item was closed the instant it was
     *    created, and every act on it is a 422;
     *  - preferences are keyed on `(type, channel)`, so two types are what lets a
     *    person keep the notifications that ask them for something and mute the
     *    ones that do not. One type carrying a flag inside `data` could not be
     *    muted separately, because the preference layer never opens `data`.
     */
    public const TYPE_DELIVERED = 'document.routing.delivered';

    public function __construct(
        private readonly NotificationDispatcher $notifications,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * Bind to the two routing events. Both carry the same `recipients` shape, so
     * one handler serves them.
     */
    public function subscribe(HookManager $hooks): void
    {
        $hooks->listen('document.routed', [$this, 'onRoutingEvent']);
        $hooks->listen('document.route_acted', [$this, 'onRoutingEvent']);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function onRoutingEvent(array $data, array $context): array
    {
        try {
            $this->announce($data);
        } catch (Throwable $e) {
            // FAIL-SOFT, for the same reason the engine dispatches after its
            // commit: the routing act has already happened and is already
            // recorded. Letting a notification failure escape would turn a
            // successful forward into a 500 for the person who made it, and they
            // would reasonably conclude the document had not moved.
            error_log('[RoutingNotifications] announcing a routing event failed: ' . $e->getMessage());
        }

        // The payload back unchanged. A hook callback returns what it was given
        // unless it means to modify it, and this one only observes.
        return $data;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function announce(array $payload): void
    {
        $recipients = $payload['recipients'] ?? null;
        if (!is_array($recipients) || $recipients === []) {
            // Every `noted`, and every act that opened nothing — a terminal
            // acknowledgement, a rejection with nowhere to go. Nothing reached
            // anybody, so there is nobody to tell.
            return;
        }

        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return;
        }

        $channels = $this->channels($tenantId);
        if ($channels === []) {
            // The operator turned routing notifications off. Returned BEFORE the
            // dispatcher is called rather than handing it an empty list, because
            // `NotificationDispatcher::resolveChannels()` reads an empty list as
            // "use the defaults" — so passing it through would deliver on
            // `in_app` while reading here as though the setting were honoured.
            return;
        }

        $documentId = (int) ($payload['document_id'] ?? 0);
        $routeId = (int) ($payload['route_id'] ?? 0);
        $title = is_string($payload['title'] ?? null) && trim((string) $payload['title']) !== ''
            ? trim((string) $payload['title'])
            : 'A document';

        foreach ($recipients as $recipient) {
            if (!is_array($recipient) || !isset($recipient['profile_id'])) {
                continue;
            }

            $delivery = RouteSatisfaction::isDelivery($recipient['satisfied_by'] ?? null);

            $this->notifications->dispatch(
                $tenantId,
                (int) $recipient['profile_id'],
                $delivery ? self::TYPE_DELIVERED : self::TYPE_AWAITING,
                [
                    'channels' => $channels,
                    // Inline wording, which the tenant's own
                    // `notification_templates` row for this type overrides
                    // wholesale when it has one — the renderer already resolves
                    // per (tenant, type, channel, locale) and falls back to this
                    // when it does not. Core ships no template for either type on
                    // purpose: an operator's phrasing for a circular is a
                    // decision about their own organisation, and a seeded English
                    // sentence would be the thing they had to discover and undo.
                    'subject' => $delivery
                        ? $title . ' was sent to you'
                        : $title . ' is waiting for you',
                    'body' => $delivery
                        ? $title . ' has been circulated to you for information. There is nothing you '
                            . 'need to do; it stays in your document history if you need it again.'
                        : $title . ' has been routed to you and is waiting in your inbox.',
                    // Enough for a client to open the right thing without a
                    // second request, and nothing that is not already on the wire
                    // elsewhere. `satisfied_by` rides along so a template can vary
                    // on it without core shipping two of everything.
                    'data' => [
                        'document_id' => $documentId,
                        'route_id' => $routeId,
                        'step_id' => isset($recipient['step_id']) ? (int) $recipient['step_id'] : null,
                        'recipient_id' => isset($recipient['recipient_id'])
                            ? (int) $recipient['recipient_id']
                            : null,
                        'satisfied_by' => $delivery ? RouteSatisfaction::DELIVERY : RouteSatisfaction::ACT,
                        'action' => isset($payload['action']) ? (string) $payload['action'] : null,
                    ],
                ]
            );
        }
    }

    /**
     * The channels this tenant offers routing notifications on: per-tenant, then
     * global, then the registry default. Never hardcoded.
     *
     * A stored value that will not parse falls back to the REGISTRY DEFAULT
     * rather than to nothing, and the asymmetry is the point. A mangled setting
     * that silently meant "notify nobody" would be a feature that had quietly
     * stopped working and that nobody would think to report; falling back to
     * `in_app` keeps the record where somebody will see it and notice that the
     * e-mail they expected is missing.
     *
     * An EXPLICIT empty value is different and is honoured — that is an operator
     * saying so, not a value nothing can read.
     *
     * @return list<string>
     */
    private function channels(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $configured = $effective[SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS] ?? null;

        if (is_string($configured)) {
            if (trim($configured) === '') {
                return [];
            }
            $invalid = SettingsRegistry::validate(
                SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS,
                $configured
            );
            if ($invalid === null) {
                return self::split($configured);
            }
        }

        $default = SettingsRegistry::defaults()[SettingsRegistry::DOCUMENTS_ROUTING_NOTIFICATION_CHANNELS] ?? '';

        return is_string($default) ? self::split($default) : [];
    }

    /**
     * @return list<string>
     */
    private static function split(string $value): array
    {
        $out = [];
        foreach (explode(',', $value) as $channel) {
            $channel = trim($channel);
            if ($channel !== '' && !in_array($channel, $out, true)) {
                $out[] = $channel;
            }
        }

        return $out;
    }
}
