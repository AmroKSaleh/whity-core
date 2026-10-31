<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools\Discovery;

use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Text\SearchText;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;
use Whity\Mcp\Tools\ToolSurface;
use Whity\Mcp\Tools\ToolSurfaceRequest;

/**
 * Search and call: two tools in place of the whole catalogue (WHIT-707,
 * WHIT-750, docs/design/MCP-TOOL-SURFACE.md §5.5).
 *
 * Core alone derives over three hundred operations, about 150 KB of
 * definitions a model would pay for before doing anything, and every plugin
 * adds more. Instead a client gets:
 *
 *   search_operations  find operations by what they do; with `operationId`,
 *                      the one operation in full (its arguments, what it
 *                      changes, its agent policy)
 *   call_operation     call one operation with its arguments
 *
 * The catalogue stays whole; it is reached on demand instead of loaded up
 * front, so the cost is the same whether a deployment has 50 operations or 5000.
 *
 * NOTHING HERE DECIDES ACCESS. Search reads the list through
 * {@see ToolsListHandler::listFor()}, the filter tools/list itself applies
 * (exposure, token toolsets and permissions, RBAC), so an operation this caller
 * could not call cannot be found here either. call_operation hands the call to
 * the real {@see ToolsCallHandler}, which re-checks everything and audits it.
 * Calling an operation by its own name with tools/call also works.
 *
 * The WHIT-707 names (`search_tools`, `describe_tool`, `call_tool`) still
 * answer for one minor release, unlisted, then go (owner decision 13).
 */
final class DiscoveryTools
{
    public const SEARCH = 'search_operations';
    public const CALL   = 'call_operation';

    /** WHIT-707 names, callable but unlisted for one minor release. */
    public const LEGACY_SEARCH   = 'search_tools';
    public const LEGACY_DESCRIBE = 'describe_tool';
    public const LEGACY_CALL     = 'call_tool';

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT     = 50;
    private const SUMMARY_CHARS = 200;

    /**
     * Words people use for what an operation does, mapped to the HTTP verb its
     * derived name starts with (`post_api_forms` creates a form).
     */
    private const VERBS = [
        'create' => 'post', 'add' => 'post', 'new' => 'post', 'make' => 'post', 'issue' => 'post', 'send' => 'post',
        'update' => 'patch', 'edit' => 'patch', 'change' => 'patch', 'rename' => 'patch', 'set' => 'put',
        'delete' => 'delete', 'remove' => 'delete',
        'list' => 'get', 'show' => 'get', 'get' => 'get', 'find' => 'get', 'read' => 'get', 'view' => 'get',
    ];

    public function __construct(
        private readonly ToolsListHandler $toolsList,
        private readonly ToolsCallHandler $toolsCall,
        private readonly TokenValidator $tokenValidator,
    ) {}

    /** The tools/list handler for `?tools=discover`: the two tools only. */
    public function listHandler(): MethodHandler
    {
        return new class implements MethodHandler {
            /** @param array<string, mixed>|null $params */
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return ['tools' => DiscoveryTools::definitions()];
            }
        };
    }

    /**
     * The tools/list handler for `?tools=default` (WHIT-748) and, from
     * WHIT-750, the bare `/mcp`: the always-on tools this caller may use, then
     * search and call for everything else.
     */
    public function defaultListHandler(): MethodHandler
    {
        $self = $this;
        return new class ($self) implements MethodHandler {
            public function __construct(private readonly DiscoveryTools $discovery) {}

            /** @param array<string, mixed>|null $params */
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return ['tools' => [...$this->discovery->alwaysOnTools($params, $bearerToken), ...DiscoveryTools::definitions()]];
            }
        };
    }

    /**
     * The always-on tools the caller may use, in the stable order, within the
     * request's `?toolsets=`. Plugin tools are not listed here yet: a plugin's
     * always-on tool shows only once a tenant admin enables it (WHIT-754).
     * Until then they are found through search like any other plugin tool.
     *
     * @param array<string, mixed>|null $params
     * @return list<array<string, mixed>>
     */
    public function alwaysOnTools(?array $params, ?string $bearerToken): array
    {
        $principal = $bearerToken !== null ? $this->tokenValidator->validateBearerForMcp($bearerToken) : null;
        if ($principal === null) {
            return [];
        }
        $surface = ToolSurfaceRequest::fromParams($params);

        return array_values(array_filter(
            $this->toolsList->listFor($principal, TenantContext::getTenantId()),
            static function (array $tool) use ($surface): bool {
                $meta = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
                return ($meta[ToolSurface::META_VISIBILITY] ?? null) === ToolSurface::VISIBILITY_ALWAYS_ON
                    && CoreToolsets::isAlwaysOn((string) ($tool['name'] ?? ''))
                    && $surface->selectsTool($tool);
            },
        ));
    }

    /** The tools/call handler for the search-and-call modes. */
    public function callHandler(): MethodHandler
    {
        $self = $this;
        return new class ($self) implements MethodHandler {
            public function __construct(private readonly DiscoveryTools $discovery) {}

            /** @param array<string, mixed>|null $params */
            public function __invoke(?array $params, ?string $bearerToken): mixed
            {
                return $this->discovery->call($params, $bearerToken);
            }
        };
    }

    /**
     * The two tool definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name'        => self::SEARCH,
                'description' => 'Find what you can do here. Search with a few words ("list users", "create invoice", '
                    . '"إجازة"); an empty query lists the areas. Pass operationId to get one operation in full: '
                    . 'its arguments, whether it changes data, and whether it needs a person to approve it. '
                    . 'Then use call_operation.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query'       => ['type' => 'string', 'description' => 'Words describing what you want to do. Empty: the areas you can search.'],
                        'toolset'     => ['type' => 'string', 'description' => 'Search one area only, by its id from an empty query (core.forms).'],
                        'operationId' => ['type' => 'string', 'description' => 'Return this one operation in full instead of searching.'],
                        'limit'       => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Most results (default ' . self::DEFAULT_LIMIT . ').'],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['title' => 'Search operations', 'readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name'        => self::CALL,
                'description' => 'Call one operation with its arguments, as search_operations described them. '
                    . 'Read it first: an operation that changes or deletes data does so for real.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'operationId' => ['type' => 'string', 'description' => 'The operation to call.'],
                        'arguments'   => ['type' => 'object', 'description' => 'Its arguments.', 'additionalProperties' => true],
                    ],
                    'required'             => ['operationId'],
                    'additionalProperties' => false,
                ],
                // Whatever it calls may write or delete, so it keeps the
                // spec's cautious default for destructiveHint.
                'annotations' => ['title' => 'Call an operation', 'readOnlyHint' => false, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $params
     * @throws McpException
     */
    public function call(?array $params, ?string $bearerToken): mixed
    {
        $name = $params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new McpException(ErrorCode::INVALID_PARAMS, 'Missing required parameter: name');
        }
        /** @var array<string, mixed> $arguments */
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $surface   = ToolSurfaceRequest::fromParams($params);

        return match ($name) {
            self::SEARCH          => $this->search($arguments, $bearerToken, $surface),
            self::CALL            => $this->callThrough(self::str($arguments, 'operationId'), $arguments, $bearerToken),
            // WHIT-707 names, for clients that remember them.
            self::LEGACY_SEARCH   => $this->search(['query' => $arguments['query'] ?? '', 'limit' => $arguments['limit'] ?? null], $bearerToken, $surface),
            self::LEGACY_DESCRIBE => $this->search(['operationId' => self::str($arguments, 'name')], $bearerToken, $surface),
            self::LEGACY_CALL     => $this->callThrough(self::str($arguments, 'name'), $arguments, $bearerToken),
            // A real operation called by its own name: the real handler decides.
            default               => ($this->toolsCall)($params, $bearerToken),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function search(array $arguments, ?string $bearerToken, ToolSurfaceRequest $surface): array
    {
        $tools = $this->visibleTools($bearerToken, $surface);

        $operationId = self::str($arguments, 'operationId');
        if ($operationId !== '') {
            return $this->describe($operationId, $tools);
        }

        $toolset = self::str($arguments, 'toolset');
        if ($toolset !== '') {
            $tools = array_values(array_filter($tools, static fn (array $t): bool => ToolSurface::isWithin(self::toolsetOf($t), $toolset)));
        }

        $query = self::str($arguments, 'query');
        $limit = is_int($arguments['limit'] ?? null) ? max(1, min(self::MAX_LIMIT, $arguments['limit'])) : self::DEFAULT_LIMIT;
        $terms = self::terms($query);
        $mine  = self::asksAboutSelf($query);

        if ($terms === [] && !$mine && $toolset === '') {
            return self::result(['toolsets' => self::tableOfContents($tools)]);
        }

        $scored = [];
        foreach ($tools as $tool) {
            $score = $terms === [] ? 1 : self::score($tool, $terms);
            if ($score > 0 && $mine) {
                // "my notifications", "who am I": the caller's own operations
                // are the ones under /api/me.
                $score += in_array('me', self::nameWords($tool), true) ? 8 : 0;
            }
            if ($score > 0) {
                $scored[] = [$score, $tool];
            }
        }
        // Equal scores: when the query names no action, reads before writes,
        // then the shorter name, which is the more general operation
        // (`get_api_documents` before `get_api_documents_id_routes`).
        $asksForAction = array_intersect($terms, array_keys(self::VERBS)) !== [];
        $rank = static function (array $entry) use ($asksForAction): array {
            $name = (string) $entry[1]['name'];
            return [-$entry[0], $asksForAction || str_starts_with($name, 'get_') ? 0 : 1, strlen($name), $name];
        };
        usort($scored, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));

        return self::result([
            'matches' => array_map(static fn (array $entry): array => self::summary($entry[1]), array_slice($scored, 0, $limit)),
            'total'   => count($scored),
            'hint'    => count($scored) > $limit ? 'More operations match; narrow the query, pick a toolset, or raise limit.' : null,
        ]);
    }

    /**
     * One operation in full, or a tool error. Unknown and hidden read the same:
     * there is nothing here for this caller.
     *
     * @param list<array<string, mixed>> $tools
     * @return array<string, mixed>
     */
    private function describe(string $operationId, array $tools): array
    {
        foreach ($tools as $tool) {
            if ($tool['name'] === $operationId) {
                $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
                $meta        = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
                return self::result([
                    'operationId' => $tool['name'],
                    'title'       => $annotations['title'] ?? null,
                    'description' => $tool['description'] ?? '',
                    'inputSchema' => $tool['inputSchema'] ?? ['type' => 'object'],
                    'readOnly'    => self::readOnly($tool),
                    'destructive' => self::destructive($tool),
                    'toolset'     => $meta[ToolSurface::META_TOOLSET] ?? null,
                    'agentPolicy' => $meta[ToolSurface::META_AGENT_POLICY] ?? ToolSurface::AGENT_ALLOW,
                ]);
            }
        }
        return self::error("No operation '{$operationId}' is available. Use search_operations to find one.");
    }

    /** @param array<string, mixed> $arguments */
    private function callThrough(string $operationId, array $arguments, ?string $bearerToken): mixed
    {
        if ($operationId === '') {
            return self::error('call_operation needs the operationId to call; find one with search_operations.');
        }
        if (in_array($operationId, [self::SEARCH, self::CALL, self::LEGACY_SEARCH, self::LEGACY_DESCRIBE, self::LEGACY_CALL], true)) {
            return self::error("{$operationId} is one of the search tools; call it directly.");
        }
        $toolArguments = is_array($arguments['arguments'] ?? null) ? $arguments['arguments'] : [];

        try {
            return ($this->toolsCall)(['name' => $operationId, 'arguments' => $toolArguments], $bearerToken);
        } catch (McpException $e) {
            // At the top level these are protocol errors. Here the operation is
            // an argument the model chose, so it is the model's to fix, and the
            // reason has to reach it (WHIT-710).
            if ($e->errorCode === ErrorCode::METHOD_NOT_FOUND) {
                return self::error("No operation '{$operationId}' is available. Use search_operations to find one.");
            }
            if ($e->errorCode === ErrorCode::FORBIDDEN) {
                return self::error("Not allowed to call '{$operationId}': " . $e->getMessage());
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private function visibleTools(?string $bearerToken, ToolSurfaceRequest $surface): array
    {
        $principal = $bearerToken !== null ? $this->tokenValidator->validateBearerForMcp($bearerToken) : null;
        if ($principal === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'Unauthenticated');
        }
        $tools = $this->toolsList->listFor($principal, TenantContext::getTenantId());

        // WHIT-748: `?toolsets=` limits what search looks at.
        return array_values(array_filter($tools, static fn (array $tool): bool => $surface->selectsTool($tool)));
    }

    /**
     * The areas the caller can search, with how many operations each holds:
     * what a model reads before it knows what to ask for.
     *
     * @param list<array<string, mixed>> $tools
     * @return list<array{id: string, title: string, titleAr: string, description: ?string, operations: int}>
     */
    private static function tableOfContents(array $tools): array
    {
        $counts = [];
        foreach ($tools as $tool) {
            $id          = self::toolsetOf($tool);
            $counts[$id] = ($counts[$id] ?? 0) + 1;
        }
        ksort($counts);

        $out = [];
        foreach ($counts as $id => $count) {
            $core  = CoreToolsets::TOOLSETS[$id] ?? null;
            $out[] = [
                'id'          => $id,
                'title'       => $core['title'] ?? $id,
                'titleAr'     => $core['titleAr'] ?? $id,
                'description' => $core['description'] ?? null,
                'operations'  => $count,
            ];
        }
        return $out;
    }

    /**
     * What a search match carries: enough to choose, not enough to call. The
     * schema comes from a second search with `operationId`, so results stay small.
     *
     * @param array<string, mixed> $tool
     * @return array<string, mixed>
     */
    private static function summary(array $tool): array
    {
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        $meta        = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
        $description = (string) ($tool['description'] ?? '');
        if (mb_strlen($description) > self::SUMMARY_CHARS) {
            $description = rtrim(mb_substr($description, 0, self::SUMMARY_CHARS - 1)) . '…';
        }

        return [
            'operationId' => $tool['name'],
            'title'       => $annotations['title'] ?? null,
            'summary'     => $description,
            'toolset'     => $meta[ToolSurface::META_TOOLSET] ?? null,
            'readOnly'    => self::readOnly($tool),
            'destructive' => self::destructive($tool),
            'agentPolicy' => $meta[ToolSurface::META_AGENT_POLICY] ?? ToolSurface::AGENT_ALLOW,
        ];
    }

    /** Filler that names nothing an operation does. */
    private const STOP_WORDS = ['the', 'an', 'of', 'for', 'to', 'all', 'is', 'are', 'what', 'who', 'am', 'with', 'in', 'on', 'items', 'waiting', 'please', 'any', 'some'];

    /** Words that mean the caller themselves. */
    private const SELF_WORDS = ['my', 'me', 'i', 'mine', 'myself'];

    /** Whether the query is about the caller (`my inbox`, `who am I`). */
    private static function asksAboutSelf(string $query): bool
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', SearchText::fold($query)) ?: [];
        return array_intersect($words, self::SELF_WORDS) !== [];
    }

    /**
     * A tool name's words, singular: `get_api_documents_id` → get, api, document, id.
     *
     * @param array<string, mixed> $tool
     * @return list<string>
     */
    private static function nameWords(array $tool): array
    {
        return array_map(self::singular(...), explode('_', strtolower((string) ($tool['name'] ?? ''))));
    }

    private static function singular(string $word): string
    {
        return mb_strlen($word) > 3 && str_ends_with($word, 's') && !str_ends_with($word, 'ss') ? mb_substr($word, 0, -1) : $word;
    }

    /**
     * The query as folded terms (Arabic letters and marks folded, lower case),
     * with a crude English singular: "users" should find `get_api_users_id`.
     * A near miss here costs one more search, not a wrong answer.
     *
     * @return list<string>
     */
    private static function terms(string $query): array
    {
        return array_values(array_map(
            self::singular(...),
            array_filter(
                preg_split('/[^\p{L}\p{N}]+/u', SearchText::fold($query)) ?: [],
                static fn (string $term): bool => mb_strlen($term) >= 2
                    && !in_array($term, self::STOP_WORDS, true)
                    && !in_array($term, self::SELF_WORDS, true),
            ),
        ));
    }

    /**
     * How well a tool matches: a term in the name counts most, then the title
     * and toolset, then the description and argument names. Every term has to
     * match somewhere: "delete user" must not rank a tool that merely mentions
     * users above one that deletes them. A verb word ("create") also matches the
     * HTTP verb a derived name starts with (`post_`).
     *
     * @param array<string, mixed> $tool
     * @param list<string>         $terms
     */
    private static function score(array $tool, array $terms): int
    {
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        $name        = SearchText::fold(str_replace('_', ' ', (string) ($tool['name'] ?? '')));
        $verb        = strstr($name . ' ', ' ', true);
        $title       = SearchText::fold((string) ($annotations['title'] ?? ''));
        $toolset     = self::toolsetOf($tool);
        $core        = CoreToolsets::TOOLSETS[$toolset] ?? null;
        $area        = SearchText::fold($toolset . ' ' . ($core['title'] ?? '') . ' ' . ($core['titleAr'] ?? ''));
        $description = SearchText::fold((string) ($tool['description'] ?? ''));
        $schema      = is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [];
        $arguments   = SearchText::fold(str_replace('_', ' ', implode(' ', array_keys(is_array($schema['properties'] ?? null) ? $schema['properties'] : []))));

        $words = self::nameWords($tool);

        $score = 0;
        foreach ($terms as $term) {
            // A whole word of the name counts most; a word starting with the
            // same five letters ("invite", "invitations") or a substring less.
            $inName = in_array($term, $words, true) ? 4 : (str_contains($name, $term) ? 2 : 0);
            if ($inName === 0 && mb_strlen($term) >= 5) {
                $stem = mb_substr($term, 0, 5);
                foreach ($words as $word) {
                    if (str_starts_with($word, $stem)) {
                        $inName = 2;
                        break;
                    }
                }
            }
            $hit = $inName
                + ((self::VERBS[$term] ?? null) === $verb ? 3 : 0)
                + (str_contains($title, $term) ? 2 : 0)
                + (str_contains($area, $term) ? 2 : 0)
                + (str_contains($description, $term) ? 1 : 0)
                + (str_contains($arguments, $term) ? 1 : 0);
            if ($hit === 0) {
                return 0;
            }
            $score += $hit;
        }
        return $score;
    }

    /** @param array<string, mixed> $tool */
    private static function toolsetOf(array $tool): string
    {
        $meta = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
        return (string) ($meta[ToolSurface::META_TOOLSET] ?? '');
    }

    /** @param array<string, mixed> $tool */
    private static function readOnly(array $tool): bool
    {
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        return ($annotations['readOnlyHint'] ?? false) === true;
    }

    /** @param array<string, mixed> $tool */
    private static function destructive(array $tool): bool
    {
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        return !self::readOnly($tool) && ($annotations['destructiveHint'] ?? true) === true;
    }

    /** @param array<string, mixed> $arguments */
    private static function str(array $arguments, string $key): string
    {
        return is_string($arguments[$key] ?? null) ? trim($arguments[$key]) : '';
    }

    /** @return array{content: list<array{type: string, text: string}>} */
    private static function result(mixed $data): array
    {
        return ['content' => [[
            'type' => 'text',
            'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]]];
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: true} */
    private static function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
