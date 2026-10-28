<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

/**
 * MCP tool annotations: what a client may assume about a tool before calling
 * it (MCP 2025-03-26 `ToolAnnotations`, WHIT-706).
 *
 * Clients use these to run reads without asking and to confirm before a
 * delete. Without them every one of a few hundred tools looks the same, so a
 * careful client asks about all of them and a careless one about none.
 *
 * For a derived tool they come from the HTTP method, which is the one thing a
 * route already says about its effect:
 *
 *   GET            readOnlyHint
 *   PUT            idempotentHint, destructiveHint (it replaces what is there)
 *   DELETE         idempotentHint, destructiveHint
 *   POST, PATCH    not read-only; destructiveHint left UNSET
 *
 * Unset is deliberate. The spec's default for destructiveHint is true, and a
 * POST here is as often an action (trash, revoke, disable two-factor) as a
 * create, so claiming "not destructive" for every POST would tell clients to
 * skip confirmation for exactly the calls that need it. A route that is
 * genuinely additive says so with `'mcp_annotations' => ['destructiveHint' =>
 * false]` in its schema, which also overrides any other derived hint.
 *
 * openWorldHint is false throughout: a tool here acts on this server's own
 * data, never on the open web.
 *
 * These are hints for the client. Nothing here changes what the server
 * enforces: RBAC, scope and the per-call checks still decide every call.
 */
final class ToolAnnotations
{
    /** The boolean hints the spec defines. */
    private const HINTS = ['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'];

    /**
     * Annotations for a tool derived from a route.
     *
     * @param array<string, mixed> $schema The route's schema declaration.
     * @return array<string, bool|string>
     */
    public static function forRoute(string $method, array $schema, string $title): array
    {
        $annotations = ['title' => $title];

        switch (strtoupper($method)) {
            case 'GET':
            case 'HEAD':
                $annotations['readOnlyHint'] = true;
                break;
            case 'PUT':
                $annotations += ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
                break;
            case 'DELETE':
                $annotations += ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true];
                break;
            default:
                $annotations += ['readOnlyHint' => false, 'idempotentHint' => false];
        }
        $annotations['openWorldHint'] = false;

        $declared = self::sanitize($schema['mcp_annotations'] ?? null);

        return $declared === null ? $annotations : array_merge($annotations, $declared);
    }

    /**
     * The well-formed part of declared annotations, or null when there is none.
     *
     * Unknown keys and wrongly typed values are dropped rather than passed on:
     * a client reads these to decide whether to ask the user, so a malformed
     * hint must not reach it looking like a real one.
     *
     * @return array<string, bool|string>|null
     */
    public static function sanitize(mixed $declared): ?array
    {
        if (!is_array($declared)) {
            return null;
        }
        $clean = [];
        if (isset($declared['title']) && is_string($declared['title']) && trim($declared['title']) !== '') {
            $clean['title'] = $declared['title'];
        }
        foreach (self::HINTS as $hint) {
            if (array_key_exists($hint, $declared) && is_bool($declared[$hint])) {
                $clean[$hint] = $declared[$hint];
            }
        }
        return $clean === [] ? null : $clean;
    }
}
