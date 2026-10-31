<?php

declare(strict_types=1);

namespace Whity\Mcp\Protocol;

use Whity\Core\CoreVersion;

/**
 * The MCP protocol versions this server speaks, and what each one changes about
 * a response (WHIT-708).
 *
 * Two eras, served side by side on the same endpoint:
 *
 *  - LEGACY (2025-03-26, 2025-06-18, 2025-11-25): an `initialize` handshake
 *    negotiates the version; later requests carry it in the
 *    `MCP-Protocol-Version` header (2025-06-18 on), or carry nothing at all
 *    (2025-03-26).
 *  - MODERN (2026-07-28): no handshake and no session. Every request names its
 *    version in `params._meta`, mirrored in HTTP headers the server checks.
 *
 * The server was already stateless -- no session ids, no standing GET stream --
 * so both eras need no per-connection memory: the version is read from each
 * request and written into its `params._meta`, where every handler can see it.
 *
 * NOT IMPLEMENTED for modern clients: `subscriptions/listen`. It holds a
 * response stream open indefinitely, and under FrankenPHP an open stream pins
 * one of a handful of workers; the same reason GET /mcp answers 405. Modern
 * clients are therefore not told lists announce changes, and lean on the
 * `ttlMs` freshness hint instead.
 */
final class McpProtocol
{
    public const MODERN = '2026-07-28';

    /** Newest first: the version `initialize` falls back to when it cannot match. */
    public const LEGACY = ['2025-11-25', '2025-06-18', '2025-03-26'];

    /** What a request that names no version at all is taken to speak. */
    public const ASSUMED = '2025-03-26';

    public const META_VERSION      = 'io.modelcontextprotocol/protocolVersion';
    public const META_SERVER_INFO  = 'io.modelcontextprotocol/serverInfo';

    /** How long a client may reuse a list before asking again, absent change notifications. */
    public const LIST_TTL_MS     = 60_000;
    public const DISCOVER_TTL_MS = 3_600_000;

    /** Results that carry the 2026 `CacheableResult` fields. */
    private const CACHEABLE = ['tools/list', 'prompts/list', 'resources/list', 'resources/read', 'resources/templates/list'];

    /** @return list<string> Every version served, newest first. */
    public static function supported(): array
    {
        return [self::MODERN, ...self::LEGACY];
    }

    public static function isSupported(string $version): bool
    {
        return in_array($version, self::supported(), true);
    }

    public static function isModern(string $version): bool
    {
        return $version === self::MODERN;
    }

    /** Whether $version is $minimum or later. Version strings are dates, so they order as strings. */
    public static function atLeast(string $version, string $minimum): bool
    {
        return strcmp($version, $minimum) >= 0;
    }

    /**
     * The version an `initialize` answers with: the client's own when this
     * server speaks it, else the newest legacy version (the spec's rule; the
     * client decides whether it can work with that).
     */
    public static function negotiate(mixed $requested): string
    {
        return is_string($requested) && in_array($requested, self::LEGACY, true) ? $requested : self::LEGACY[0];
    }

    /**
     * The version a dispatched request speaks, as written into its params by
     * the Dispatcher.
     *
     * @param array<string, mixed>|null $params
     */
    public static function versionOf(?array $params): string
    {
        $meta = $params['_meta'] ?? null;
        $version = is_array($meta) ? ($meta[self::META_VERSION] ?? null) : null;
        return is_string($version) && self::isSupported($version) ? $version : self::ASSUMED;
    }

    /** @return array{name: string, version: string} */
    public static function serverInfo(): array
    {
        return ['name' => 'whity-core', 'version' => CoreVersion::VERSION];
    }

    /**
     * Shape a handler's result for the version the request speaks.
     *
     *  - 2025-06-18 on: a tool's annotation title is also its top-level
     *    `title`, and a tools/call result whose text is a JSON object (any JSON
     *    value from 2026) also carries it as `structuredContent`. No
     *    `outputSchema` is declared: a declared schema obliges every result to
     *    conform, and the route response schemas are not yet checked against
     *    what handlers return (WHIT-120), so a client could reject correct data.
     *  - 2026-07-28: `resultType`, the server's identity in `_meta`, and cache
     *    hints on list and read results (`private`: they depend on the caller).
     */
    public static function adaptResult(string $method, mixed $result, string $version): mixed
    {
        if (!is_array($result)) {
            return $result;
        }

        if (self::atLeast($version, '2025-06-18')) {
            if ($method === 'tools/list' && is_array($result['tools'] ?? null)) {
                $result['tools'] = array_map(self::withTopLevelTitle(...), $result['tools']);
            }
            if ($method === 'tools/call') {
                $result = self::withStructuredContent($result, self::isModern($version));
            }
        } elseif ($method === 'tools/list' && is_array($result['tools'] ?? null)) {
            // A tool's `_meta` (toolset, visibility, agent policy; WHIT-747) is a
            // 2025-06-18 field. Left out for the oldest version, whose schema
            // has no place for it.
            $result['tools'] = array_map(
                static function (mixed $tool): mixed {
                    if (is_array($tool)) {
                        unset($tool['_meta']);
                    }
                    return $tool;
                },
                $result['tools'],
            );
        }

        if (self::isModern($version)) {
            $result['resultType'] = 'complete';
            $meta = is_array($result['_meta'] ?? null) ? $result['_meta'] : [];
            $result['_meta'] = $meta + [self::META_SERVER_INFO => self::serverInfo()];
            if (in_array($method, self::CACHEABLE, true)) {
                $result['ttlMs']      = self::LIST_TTL_MS;
                $result['cacheScope'] = 'private';
            }
        }

        return $result;
    }

    /**
     * @param mixed $tool
     * @return mixed
     */
    private static function withTopLevelTitle(mixed $tool): mixed
    {
        if (!is_array($tool) || isset($tool['title'])) {
            return $tool;
        }
        $title = is_array($tool['annotations'] ?? null) ? ($tool['annotations']['title'] ?? null) : null;
        if (is_string($title) && $title !== '') {
            $tool['title'] = $title;
        }
        return $tool;
    }

    /**
     * @param array<array-key, mixed> $result
     * @return array<array-key, mixed>
     */
    private static function withStructuredContent(array $result, bool $anyJson): array
    {
        if (array_key_exists('structuredContent', $result) || ($result['isError'] ?? false) === true) {
            return $result;
        }
        $content = $result['content'] ?? null;
        $text = is_array($content) && count($content) === 1 && is_array($content[0] ?? null)
            && ($content[0]['type'] ?? null) === 'text' ? ($content[0]['text'] ?? null) : null;
        if (!is_string($text) || $text === '') {
            return $result;
        }
        try {
            $decoded = json_decode($text, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $result;
        }
        // Before 2026 structuredContent must be a JSON object. Decoded as
        // stdClass so `{}` and `[]` stay distinguishable.
        if ($decoded instanceof \stdClass || $anyJson) {
            $result['structuredContent'] = $decoded;
        }
        return $result;
    }
}
