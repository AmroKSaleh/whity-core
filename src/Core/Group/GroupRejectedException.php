<?php

declare(strict_types=1);

namespace Whity\Core\Group;

/**
 * The group request itself is not acceptable (#999) — a rule kind nothing
 * registered, a kind that cannot answer without a document, a config the rule
 * refuses, or a group id that no longer exists.
 *
 * All 422s. The caller asked for something the resolver will not attempt, and
 * the text is written to be SHOWN: it names the kind, the group or the limit,
 * because "invalid group" leaves an author guessing at which of their choices
 * was wrong.
 *
 * WHY THE TEXT IS A PROPERTY AND NOT JUST `getMessage()`
 * ------------------------------------------------------
 * The reason {@see \Whity\Core\Document\Routing\RoutingRejectedException} records,
 * unchanged: WC-186 forbids interpolating a throwable's message into a client
 * response, and {@see \Tests\Api\ExceptionLeakageTest} enforces that statically
 * over `src/Api`.
 *
 * This class WRAPS third-party text by design. A rule's
 * {@see \Whity\Sdk\Audience\AudienceRuleResolverInterface::validate()} throws an
 * `InvalidArgumentException` whose message the group's author is meant to read,
 * and on a plugin-contributed kind that message comes from the plugin. So two
 * kinds of string are in play — one somebody wrote for a reader, one that is
 * whatever the nearest throw site left behind — and giving the first its own
 * field is the only way to keep them apart. {@see because()} is the only way in.
 *
 * What this deliberately does NOT wrap: a resolver throwing during
 * `resolve()`. That is plugin code failing at run time rather than telling an
 * author something, so its message is logged and the caller is told which rule
 * could not be resolved, without the text.
 *
 * A SEPARATE CLASS FROM THE ROUTING ONE, AND WHY
 * ----------------------------------------------
 * The two are the same shape, which is an argument for sharing and a stronger
 * argument against. `RoutingRejectedException` is caught by
 * {@see \Whity\Api\DocumentRoutingApiHandler} and turned into a 422 about a
 * ROUTE; a group failure surfacing there would be reported as a bad routing
 * step, and a routing failure surfacing on the groups API would be reported as a
 * bad group. Two catch sites that must not confuse each other's failures need
 * two types, and the duplication is thirty lines that will never diverge because
 * neither has any behaviour.
 */
final class GroupRejectedException extends \RuntimeException
{
    /** Text written to be shown to the caller, never a wrapped internal error. */
    public readonly string $clientMessage;

    private function __construct(string $clientMessage)
    {
        parent::__construct($clientMessage);
        $this->clientMessage = $clientMessage;
    }

    /**
     * Reject a group request, naming the reason in words the caller can act on.
     *
     * The named constructor is the only way in, so there is no path that fills
     * {@see $clientMessage} with text nobody wrote for a client.
     */
    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
