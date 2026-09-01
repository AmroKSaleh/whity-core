<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use InvalidArgumentException;

/**
 * An effect kind was DECLARED in a way the registry will not accept, or an
 * effect produced a plan that cannot be carried out (#1032).
 *
 * The same line {@see InvalidRoutingRuleException} draws: this is a declaration
 * error, raised at boot or at plan time by code, and is not what a person
 * authoring a route sees. An authoring refusal is
 * {@see RoutingRejectedException} and carries a 422.
 *
 * One named constructor per reason, so the reason lives in the type rather than
 * in a string somebody has to match on.
 */
final class InvalidRouteEffectException extends InvalidArgumentException
{
    public static function forSlug(string $slug): self
    {
        return new self(
            "Effect kind slug '{$slug}' is not valid. Slugs must match [a-z][a-z0-9_]* — the "
            . 'grammar every registry in the platform enforces, so no catalogue accepts a name '
            . 'the others would refuse.'
        );
    }

    public static function forReservedSource(string $source): self
    {
        return new self(
            "'{$source}' is reserved for effects core itself ships. A plugin's kinds are "
            . 'namespaced under its own source so it cannot shadow one of core\'s.'
        );
    }

    public static function forDuplicateKey(string $key): self
    {
        return new self(
            "Effect kind '{$key}' is already registered. The second registration is refused "
            . 'rather than silently overwriting the first, which would make which effect runs '
            . 'depend on boot order.'
        );
    }

    public static function forOverlongKey(string $key, int $max): self
    {
        return new self(
            "Effect kind '{$key}' is longer than {$max} characters, which is the width of "
            . 'document_route_step_effects.effect_kind. A key the column cannot hold is one the '
            . 'declaration cannot be saved under.'
        );
    }

    public static function forMalformedEffect(string $key): self
    {
        return new self(
            "Effect kind '{$key}' was declared without a " . RouteEffectInterface::class
            . '. A kind with no implementation is the stored intention migration 112 refused: '
            . 'it would author cleanly and do nothing.'
        );
    }

    public static function forEmptyAudience(string $type): self
    {
        return new self(
            "A '{$type}' effect produced a plan reaching nobody. An effect with nothing to do "
            . 'must return null so the attempt is recorded as skipped with a reason, rather than '
            . 'returning a plan that claims an audience it does not have.'
        );
    }

    public static function forMissingNotificationType(): self
    {
        return new self(
            'A notification plan needs a type: it is what selects the template, so a blank one '
            . 'would deliver an empty message to a real audience.'
        );
    }
}
