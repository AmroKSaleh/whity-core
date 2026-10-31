<?php

declare(strict_types=1);

namespace Whity\Mcp\Auth;

use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\ToolSurface;

/**
 * What an MCP token's `scope` grants (WHIT-695, ADR-0006 §3).
 *
 * A scope entry is one of two kinds:
 *
 * - A CAPABILITY — `tools:call`, `resources:read`, `prompts:read` — opens one
 *   family of MCP methods (see {@see self::METHOD_CAPABILITY}). These are the
 *   three the AI-principals screen offers.
 * - A PERMISSION name (`users:read`), or a whole resource (`users:*`). When a
 *   token names any, it reaches only tools, resources and prompts gated by one
 *   of them. Ungated and role-only items are out of reach for such a token.
 *
 * Scopes NARROW and never widen. Every check here is applied on top of the
 * principal's RBAC, never instead of it: a scope naming `users:delete` does
 * nothing for a principal whose role lacks it.
 *
 * Backward compatibility, deliberately:
 * - a token naming no capability keeps every method family. Tokens issued
 *   before enforcement could carry anything, and new ones cannot be issued
 *   that way ({@see self::issuanceErrors()} requires a capability);
 * - a token naming no permission keeps the principal's full RBAC;
 * - session bearers (principal kind `session`) carry no scope at all, so they
 *   keep both.
 *
 * The `tools:`, `resources:` and `prompts:` prefixes are reserved for
 * capabilities ({@see self::FAMILY_PREFIXES}): anything under them is read
 * as the capability, never as a permission.
 *
 * A third kind (WHIT-749, docs/design/MCP-TOOL-SURFACE.md §5.4): a TOOLSET,
 * `toolset:<id>` or `toolset:<prefix>.*`. When a token names any, it reaches
 * only tools in those toolsets, in tools/list, in search and in tools/call. A
 * token naming none keeps every toolset. Like permissions, toolsets narrow and
 * never widen, and the `toolset:` prefix is reserved.
 */
final class McpScope
{
    public const TOOLS_CALL     = 'tools:call';
    public const RESOURCES_READ = 'resources:read';
    public const PROMPTS_READ   = 'prompts:read';

    public const CAPABILITIES = [self::TOOLS_CALL, self::RESOURCES_READ, self::PROMPTS_READ];

    /**
     * The capability each MCP method needs. Methods not listed (initialize,
     * ping, notifications) need none: they reveal nothing and do nothing.
     */
    private const METHOD_CAPABILITY = [
        'tools/list'               => self::TOOLS_CALL,
        'tools/call'               => self::TOOLS_CALL,
        'resources/list'           => self::RESOURCES_READ,
        'resources/templates/list' => self::RESOURCES_READ,
        'resources/read'           => self::RESOURCES_READ,
        'prompts/list'             => self::PROMPTS_READ,
        'prompts/get'              => self::PROMPTS_READ,
    ];

    /**
     * Every entry under one of these prefixes means that family's capability.
     * Scopes went unchecked until WHIT-695, so tokens carry spellings such as
     * `tools:list` (session bearers did) or `prompts:get`; folding them in
     * keeps them from being read as permission names, which would narrow the
     * token to nothing. It also reserves the prefixes: no permission may live
     * under them.
     */
    private const FAMILY_PREFIXES = [
        'tools:'     => self::TOOLS_CALL,
        'resources:' => self::RESOURCES_READ,
        'prompts:'   => self::PROMPTS_READ,
    ];

    public const TOOLSET_PREFIX = 'toolset:';

    /** @var list<string> */
    private readonly array $scope;

    /** @param list<string> $scope */
    public function __construct(array $scope)
    {
        $this->scope = array_values(array_unique(array_map(self::canonical(...), $scope)));
    }

    public static function of(McpPrincipal $principal): self
    {
        return new self(array_values(array_filter($principal->scope, 'is_string')));
    }

    /** Whether the token may call this JSON-RPC method at all. */
    public function allowsMethod(string $method): bool
    {
        $needed = self::METHOD_CAPABILITY[$method] ?? null;
        if ($needed === null) {
            return true;
        }

        $held = array_intersect($this->scope, self::CAPABILITIES);
        return $held === [] || in_array($needed, $held, true);
    }

    /**
     * Whether the token's permission narrowing lets it reach an item gated by
     * $requiredPermission (null for ungated and role-only items). RBAC is a
     * separate check and still applies.
     */
    public function allowsPermission(?string $requiredPermission): bool
    {
        $narrowing = $this->permissionEntries();
        if ($narrowing === []) {
            return true;
        }
        if ($requiredPermission === null || $requiredPermission === '') {
            return false;
        }

        foreach ($narrowing as $entry) {
            if ($entry === $requiredPermission) {
                return true;
            }
            if (str_ends_with($entry, ':*') && str_starts_with($requiredPermission, substr($entry, 0, -1))) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the token's toolset narrowing lets it reach a tool in $toolset
     * (WHIT-749). A tool with no toolset is out of reach for a narrowed token.
     */
    public function allowsToolset(?string $toolset): bool
    {
        $narrowing = $this->toolsetEntries();
        if ($narrowing === []) {
            return true;
        }
        if ($toolset === null || $toolset === '') {
            return false;
        }

        foreach ($narrowing as $entry) {
            if ($entry === $toolset) {
                return true;
            }
            // `acme.*` is acme itself and everything under it.
            if (str_ends_with($entry, '.*')) {
                $root = substr($entry, 0, -2);
                if ($toolset === $root || str_starts_with($toolset, $root . '.')) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * The toolsets the token is narrowed to, as written (`core.forms`,
     * `acme.*`); empty when it names none.
     *
     * @return list<string>
     */
    public function toolsetEntries(): array
    {
        $out = [];
        foreach ($this->scope as $entry) {
            if (str_starts_with($entry, self::TOOLSET_PREFIX)) {
                $out[] = substr($entry, strlen(self::TOOLSET_PREFIX));
            }
        }
        return $out;
    }

    /**
     * Problems with a scope requested at issuance, as messages; empty when it
     * is acceptable. It must name at least one capability, and every other
     * entry must be a known permission or a `resource:*` matching one.
     *
     * @param list<string>          $scope
     * @param list<string>          $knownPermissions
     * @return list<string>
     */
    public static function issuanceErrors(array $scope, array $knownPermissions): array
    {
        $normalized = new self($scope);
        $errors     = [];
        if (array_intersect($normalized->scope, self::CAPABILITIES) === []) {
            $errors[] = 'scope must include at least one of: ' . implode(', ', self::CAPABILITIES);
        }

        foreach ($normalized->toolsetEntries() as $toolset) {
            $error = self::toolsetError($toolset);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        foreach ($normalized->permissionEntries() as $entry) {
            if (str_ends_with($entry, ':*')) {
                $prefix = substr($entry, 0, -1);
                $matches = array_filter($knownPermissions, static fn (string $p): bool => str_starts_with($p, $prefix));
                if ($prefix === ':' || $matches === []) {
                    $errors[] = "unknown permission resource: {$entry}";
                }
            } elseif (!in_array($entry, $knownPermissions, true)) {
                $errors[] = "unknown scope entry: {$entry}";
            }
        }

        return $errors;
    }

    /**
     * Why a requested toolset entry is refused, or null. Core toolsets must be
     * ones core defines; a plugin's can only be checked for shape, because a
     * token may be issued before the plugin is installed.
     */
    private static function toolsetError(string $toolset): ?string
    {
        $id = str_ends_with($toolset, '.*') ? substr($toolset, 0, -2) : $toolset;
        if (!ToolSurface::isToolsetId($id)) {
            return "malformed toolset: toolset:{$toolset}";
        }
        if ($id === 'core') {
            return null;
        }
        if (str_starts_with($id, 'core.')) {
            foreach (array_keys(CoreToolsets::TOOLSETS) as $core) {
                if (ToolSurface::isWithin($id, $core)) {
                    return null;
                }
            }
            return "unknown toolset: toolset:{$toolset}";
        }
        return null;
    }

    private static function canonical(string $entry): string
    {
        foreach (self::FAMILY_PREFIXES as $prefix => $capability) {
            if (str_starts_with($entry, $prefix)) {
                return $capability;
            }
        }
        return $entry;
    }

    /** @return list<string> */
    private function permissionEntries(): array
    {
        return array_values(array_filter(
            array_diff($this->scope, self::CAPABILITIES),
            static fn (string $entry): bool => !str_starts_with($entry, self::TOOLSET_PREFIX),
        ));
    }
}
