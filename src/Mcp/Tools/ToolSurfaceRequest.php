<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

/**
 * Which tool list a request asks for (WHIT-748, docs/design/MCP-TOOL-SURFACE.md §5.3).
 *
 * Read from the request URL every time and never remembered, so the same
 * answer comes from any worker and for any protocol version (2026-07-28 says
 * the list MUST NOT vary as a side effect of earlier requests).
 *
 *   ?tools=full      every tool the caller may call (today's list)
 *   ?tools=default   the always-on tools plus the discovery tools
 *   ?tools=discover  the discovery tools only
 *   ?toolsets=a,b.*  limits the list and discovery search to those toolsets
 *
 * None of this decides access. `?toolsets=` is the client choosing what to
 * look at; the token's own `toolset:` entries (WHIT-749) are what restrict.
 * A bare `/mcp`, or an unknown `tools=` value, gets {@see self::BARE_MODE}:
 * search and call since WHIT-750 (owner decision 2). `?tools=full` keeps the
 * old full list.
 *
 * The transport passes it to the handlers in `params._meta` under
 * {@see self::META_KEY}, written by the server and never taken from the client.
 */
final class ToolSurfaceRequest
{
    public const MODE_FULL     = 'full';
    public const MODE_DEFAULT  = 'default';
    public const MODE_DISCOVER = 'discover';

    public const MODES = [self::MODE_FULL, self::MODE_DEFAULT, self::MODE_DISCOVER];

    /**
     * What a bare `/mcp` (or an unknown `tools=`) gets. The one switch for the
     * WHIT-750 switch-over: set it back to MODE_FULL to hold the old list.
     */
    public const BARE_MODE = self::MODE_DEFAULT;

    public const META_KEY = 'dev.whity/toolSurface';

    /** @param list<string> $toolsets */
    public function __construct(
        public readonly string $mode = self::BARE_MODE,
        public readonly array $toolsets = [],
    ) {
    }

    /**
     * From a request's query parameters.
     *
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $mode = $query['tools'] ?? null;
        $mode = is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::BARE_MODE;

        $toolsets = [];
        $raw      = $query['toolsets'] ?? null;
        if (is_string($raw)) {
            foreach (explode(',', $raw) as $entry) {
                $entry = trim($entry);
                $id    = str_ends_with($entry, '.*') ? substr($entry, 0, -2) : $entry;
                // An unknown or malformed id is ignored, not an error: it names
                // nothing, so it limits nothing.
                if ($id !== '' && ToolSurface::isToolsetId($id)) {
                    $toolsets[] = $entry;
                }
            }
        }

        return new self($mode, array_values(array_unique($toolsets)));
    }

    /**
     * From a handler's params, where the dispatcher wrote it. Absent means a
     * caller that is not the transport (tests, internal), which gets the bare mode.
     *
     * @param array<string, mixed>|null $params
     */
    public static function fromParams(?array $params): self
    {
        $meta    = is_array($params['_meta'] ?? null) ? $params['_meta'] : [];
        $surface = $meta[self::META_KEY] ?? null;
        if (!is_array($surface)) {
            return new self();
        }
        $mode     = is_string($surface['mode'] ?? null) && in_array($surface['mode'], self::MODES, true) ? $surface['mode'] : self::BARE_MODE;
        $toolsets = array_values(array_filter((array) ($surface['toolsets'] ?? []), 'is_string'));

        return new self($mode, $toolsets);
    }

    /** @return array{mode: string, toolsets: list<string>} */
    public function toArray(): array
    {
        return ['mode' => $this->mode, 'toolsets' => $this->toolsets];
    }

    /** Whether a tool in $toolset is in the selection (`acme.*` is acme and below). An empty selection selects everything. */
    public function selects(?string $toolset): bool
    {
        if ($this->toolsets === []) {
            return true;
        }
        if ($toolset === null || $toolset === '') {
            return false;
        }
        foreach ($this->toolsets as $entry) {
            if ($entry === $toolset) {
                return true;
            }
            if (str_ends_with($entry, '.*') && ToolSurface::isWithin($toolset, substr($entry, 0, -2))) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $tool */
    public function selectsTool(array $tool): bool
    {
        $meta    = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
        $toolset = $meta[ToolSurface::META_TOOLSET] ?? null;

        return $this->selects(is_string($toolset) ? $toolset : null);
    }
}
