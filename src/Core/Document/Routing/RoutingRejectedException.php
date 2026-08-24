<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * The routing request itself is not acceptable (#947 item 3) — a step naming a
 * rule kind nothing registered, a config the rule refuses, a forward from the
 * last step, a return from the first, or a ceiling exceeded.
 *
 * All 422s. The caller asked for something the engine will not attempt, and the
 * text is written to be SHOWN: it names the step, the kind or the limit,
 * because "invalid route" leaves an author guessing at which of five steps was
 * wrong and at numbers that are tenant-configurable and therefore unknowable
 * from the outside.
 *
 * WHY THE TEXT IS A PROPERTY AND NOT JUST `getMessage()`
 * ------------------------------------------------------
 * The same reason {@see \Whity\Core\Document\Render\DocumentRenderRejectedException}
 * records, and it matters more here. WC-186 forbids interpolating a throwable's
 * message into a client response, and {@see \Tests\Api\ExceptionLeakageTest}
 * enforces it statically over `src/Api`.
 *
 * This class WRAPS third-party text by design: a rule's
 * {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface::validate()} throws an
 * `InvalidArgumentException` whose message the route's author is meant to read,
 * and that message comes from a plugin. So there are two kinds of string in
 * play — one an author wrote for a reader, one that is whatever the nearest
 * throw site left there — and the only way to keep them apart is to give the
 * first its own field. {@see because()} is the only way in; the Throwable
 * message carries the same text purely so a log line is legible, and a cause
 * attached later changes `getMessage()` while leaving {@see $clientMessage}
 * alone.
 *
 * Note what this deliberately does NOT wrap: a resolver throwing during
 * {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface::resolve()}. That is a
 * plugin failing at run time rather than telling an author something, so its
 * message is logged and the caller is told the step could not be resolved,
 * without the text.
 */
final class RoutingRejectedException extends \RuntimeException
{
    /** Text written to be shown to the caller, never a wrapped internal error. */
    public readonly string $clientMessage;

    private function __construct(string $clientMessage)
    {
        parent::__construct($clientMessage);
        $this->clientMessage = $clientMessage;
    }

    /**
     * Reject a routing request, naming the reason in words the caller can act on.
     *
     * The named constructor is the only way in, so there is no path that fills
     * {@see $clientMessage} with text nobody wrote for a client.
     */
    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
