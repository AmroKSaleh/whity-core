<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

/**
 * The vocabulary of the MCP tool surface (WHIT-747, docs/design/MCP-TOOL-SURFACE.md
 * §5.2, §5.8, §5.9): visibility, agent policy, the `_meta` keys they travel
 * under, and how a route schema's `mcp` key declares them.
 *
 * None of it decides access. Visibility says where an operation is shown
 * (listed directly, or reached through search), and agent policy is a ceiling
 * applied to agents only (§5.11, WHIT-753).
 */
final class ToolSurface
{
    public const VISIBILITY_ALWAYS_ON    = 'always-on';
    public const VISIBILITY_DISCOVERABLE = 'discoverable';
    public const VISIBILITY_OFF          = 'off';

    public const VISIBILITIES = [self::VISIBILITY_ALWAYS_ON, self::VISIBILITY_DISCOVERABLE, self::VISIBILITY_OFF];

    public const AGENT_ALLOW        = 'allow';
    public const AGENT_PROPOSE      = 'propose';
    public const AGENT_SUGGEST_ONLY = 'suggest-only';
    public const AGENT_REFUSE       = 'refuse';

    public const AGENT_POLICIES = [self::AGENT_ALLOW, self::AGENT_PROPOSE, self::AGENT_SUGGEST_ONLY, self::AGENT_REFUSE];

    public const META_TOOLSET      = 'dev.whity/toolset';
    public const META_VISIBILITY   = 'dev.whity/visibility';
    public const META_AGENT_POLICY = 'dev.whity/agentPolicy';

    /**
     * What a route schema's `mcp` key declares. `'mcp' => true` is the original
     * opt-in for an ungated route and means `['expose' => true]`. Values of the
     * wrong type or outside the vocabulary are dropped, not guessed at: a
     * dropped value falls back to the default, which is the cautious reading.
     *
     * @param array<string, mixed> $schema
     * @return array{expose: ?bool, visibility: ?string, toolset: ?string, agentPolicy: ?string}
     */
    public static function routeOptions(array $schema): array
    {
        $mcp = $schema['mcp'] ?? null;
        if ($mcp === true) {
            return ['expose' => true, 'visibility' => null, 'toolset' => null, 'agentPolicy' => null];
        }
        if (!is_array($mcp)) {
            return ['expose' => null, 'visibility' => null, 'toolset' => null, 'agentPolicy' => null];
        }

        $visibility = $mcp['visibility'] ?? null;
        $policy     = $mcp['agentPolicy'] ?? null;
        $toolset    = $mcp['toolset'] ?? null;

        return [
            'expose'      => is_bool($mcp['expose'] ?? null) ? $mcp['expose'] : null,
            'visibility'  => is_string($visibility) && in_array($visibility, self::VISIBILITIES, true) ? $visibility : null,
            'toolset'     => is_string($toolset) && self::isToolsetId($toolset) ? $toolset : null,
            'agentPolicy' => is_string($policy) && in_array($policy, self::AGENT_POLICIES, true) ? $policy : null,
        ];
    }

    /** Dot-separated segments of `[a-z0-9_-]`. */
    public static function isToolsetId(string $id): bool
    {
        return preg_match('/^[a-z0-9_-]+(\.[a-z0-9_-]+)*$/', $id) === 1;
    }

    /** Whether $id is $root itself or one of its sub-toolsets. */
    public static function isWithin(string $id, string $root): bool
    {
        return $id === $root || str_starts_with($id, $root . '.');
    }

    /** @return array<string, string> */
    public static function meta(string $toolset, string $visibility, string $agentPolicy): array
    {
        return [
            self::META_TOOLSET      => $toolset,
            self::META_VISIBILITY   => $visibility,
            self::META_AGENT_POLICY => $agentPolicy,
        ];
    }
}
