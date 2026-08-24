<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;

/**
 * Raised when a routing-rule declaration does not conform to the rules
 * {@see RoutingRuleRegistry} enforces (#947 item 3).
 *
 * Mirrors {@see \Whity\Core\Ou\InvalidOuTypeException} and
 * {@see \Whity\Core\RBAC\InvalidResourceTypeException} deliberately: the three
 * registries apply the same namespacing rule, so they refuse the same kinds of
 * malformed declaration with the same wording, and a plugin author who has seen
 * one message recognises the next.
 *
 * This is about DECLARING a kind. A config that a registered rule rejects at
 * authoring time is {@see RoutingRejectedException}, which reaches the person
 * composing the route rather than the plugin author.
 */
class InvalidRoutingRuleException extends InvalidArgumentException
{
    /**
     * A bare slug that failed format validation.
     */
    public static function forSlug(string $slug): self
    {
        return new self(
            "Invalid routing rule kind '{$slug}': expected a bare lowercase slug "
            . '(letters, digits, underscores; no colon — the host applies the namespace)'
        );
    }

    /**
     * A caller other than core claimed the reserved `core` source, which would
     * let it mint UNPREFIXED kinds and shadow `role` or `role_below_actor`.
     */
    public static function forReservedSource(string $source): self
    {
        return new self(
            "Source '{$source}' is reserved for core routing rules; plugins are "
            . 'namespaced under their own plugin name'
        );
    }

    /**
     * A source name from which no usable namespace prefix could be derived, so
     * its kinds would be stored unprefixed and could shadow core's.
     */
    public static function forSource(string $source): self
    {
        return new self(
            "Source '{$source}' yields no usable namespace prefix; a routing rule "
            . 'source must start with a letter'
        );
    }

    /**
     * A declared value that is not a resolver.
     *
     * Refused at declaration rather than discovered when a step is reached: a
     * route authored against a kind whose "resolver" is a string would validate,
     * store, and then fail for the first recipient — days later, to somebody who
     * did not write the plugin.
     */
    public static function forMalformedResolver(string $key): self
    {
        return new self(
            "Routing rule '{$key}' must be declared as an instance of "
            . \Whity\Sdk\Routing\RoutingRuleResolverInterface::class
        );
    }

    /**
     * A kind already registered by this or another source.
     */
    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "Routing rule '{$key}' is already registered; a source may declare each slug once"
        );
    }

    /**
     * A slug that is well-formed but too long for `document_route_steps.rule_kind`
     * (migration 112).
     *
     * A kind wider than the column can be registered and never STORED — a rule
     * that exists and no route can name. Rejecting it at declaration turns that
     * into one logged warning when the plugin loads, which is the same trade
     * {@see \Whity\Core\Queue\JobRegistry::MAX_NAME_LENGTH} makes.
     */
    public static function forOverlongKey(string $key, int $max): self
    {
        return new self(
            "Routing rule '{$key}' is longer than {$max} characters and could never be "
            . 'stored on a route step'
        );
    }
}
