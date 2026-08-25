<?php

declare(strict_types=1);

namespace Whity\Core\Document\RouteTemplate;

use RuntimeException;

/**
 * The template GRAPH itself is not acceptable (#1027) — a rule kind nothing
 * registered, a config the rule refuses, an edge naming a node that is not on
 * the canvas, or more steps than the tenant's ceiling allows.
 *
 * All 422s. The caller drew something the engine could never run, and the text is
 * written to be SHOWN beside the node that caused it: it names the position, the
 * kind or the limit, because "invalid template" leaves an author staring at a
 * canvas with no idea which of twelve nodes is wrong.
 *
 * WHY THE TEXT IS A PROPERTY AND NOT JUST `getMessage()`
 * ------------------------------------------------------
 * The reason {@see \Whity\Core\Document\Routing\RoutingRejectedException} and
 * {@see \Whity\Core\Group\GroupRejectedException} both record, unchanged: WC-186
 * forbids interpolating a throwable's message into a client response, and
 * {@see \Tests\Api\ExceptionLeakageTest} enforces that statically over `src/Api`.
 *
 * This class WRAPS third-party text by design. A rule's
 * {@see \Whity\Sdk\Routing\RoutingRuleResolverInterface::validate()} throws an
 * `InvalidArgumentException` whose message the template's author is meant to
 * read, and on a plugin-contributed kind that message comes from the plugin. So
 * two kinds of string are in play — one somebody wrote for a reader, one that is
 * whatever the nearest throw site left behind — and giving the first its own
 * field is the only way to keep them apart. {@see because()} is the only way in.
 */
final class RouteTemplateRejectedException extends RuntimeException
{
    public readonly string $clientMessage;

    private function __construct(string $clientMessage)
    {
        parent::__construct($clientMessage);
        $this->clientMessage = $clientMessage;
    }

    /**
     * The only constructor, so nothing can populate {@see $clientMessage} with
     * text nobody wrote for a client.
     */
    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
