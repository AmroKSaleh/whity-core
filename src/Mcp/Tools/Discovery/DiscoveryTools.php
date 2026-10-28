<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools\Discovery;

use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\JsonRpc\ErrorCode;
use Whity\Mcp\JsonRpc\McpException;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Tools\ToolsCallHandler;
use Whity\Mcp\Tools\ToolsListHandler;

/**
 * Discovery mode: three tools in place of the whole catalogue (WHIT-707).
 *
 * Core alone derives over three hundred tools, about 22k tokens of definitions
 * an agent pays for before it does anything, and several clients cap or degrade
 * well below that (Cursor is widely reported to drop everything past 40). In
 * discovery mode `tools/list` answers with three tools instead:
 *
 *   search_tools   find tools by what they do
 *   describe_tool  the full input schema of one tool
 *   call_tool      call one tool by name
 *
 * The catalogue stays whole; it is reached on demand instead of loaded up
 * front, so the cost stays the same whether a deployment has 50 tools or 5000.
 *
 * NOTHING HERE DECIDES ACCESS. search_tools and describe_tool read the list
 * through {@see ToolsListHandler::listFor()}, the filter tools/list itself
 * applies (exposure, scope, RBAC), so a tool this caller could not see in the
 * full list cannot be found here either. call_tool hands the call to the real
 * {@see ToolsCallHandler}, which re-checks everything and audits it. Calling a
 * real tool by its own name also works, for a client that remembers one.
 *
 * Chosen per request by the transport (`/mcp?tools=discover`), with its own
 * Dispatcher, so no mode flag lives in worker-global state.
 */
final class DiscoveryTools
{
    public const SEARCH   = 'search_tools';
    public const DESCRIBE = 'describe_tool';
    public const CALL     = 'call_tool';

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT     = 50;

    public function __construct(
        private readonly ToolsListHandler $toolsList,
        private readonly ToolsCallHandler $toolsCall,
        private readonly TokenValidator $tokenValidator,
    ) {}

    /** The tools/list handler for discovery mode. */
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

    /** The tools/call handler for discovery mode. */
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
     * The three tool definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'name'        => self::SEARCH,
                'description' => 'Find the tools you can use here by what they do. Search with a few words '
                    . '("list users", "create invoice", "notifications"), then call describe_tool for the '
                    . 'one you need. Returns each match\'s name, title, description and whether it only reads.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Words describing what you want to do. Empty lists everything, a page at a time.'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Most results to return (default ' . self::DEFAULT_LIMIT . ').'],
                    ],
                ],
                'annotations' => ['title' => 'Search tools', 'readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name'        => self::DESCRIBE,
                'description' => 'Get everything needed to call one tool: its description, the arguments it '
                    . 'takes (JSON Schema) and whether it reads, changes or deletes data. Use a name from search_tools.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => ['name' => ['type' => 'string', 'description' => 'The tool name, as search_tools returned it.']],
                    'required'   => ['name'],
                ],
                'annotations' => ['title' => 'Describe a tool', 'readOnlyHint' => true, 'openWorldHint' => false],
            ],
            [
                'name'        => self::CALL,
                'description' => 'Call one tool by name with its arguments, as describe_tool described them. '
                    . 'Check describe_tool first: a tool that deletes or changes data does so for real.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => [
                        'name'      => ['type' => 'string', 'description' => 'The tool to call.'],
                        'arguments' => ['type' => 'object', 'description' => 'Its arguments.', 'additionalProperties' => true],
                    ],
                    'required'   => ['name'],
                ],
                // Whatever it calls may write or delete, so it keeps the
                // spec's cautious default for destructiveHint.
                'annotations' => ['title' => 'Call a tool', 'readOnlyHint' => false, 'openWorldHint' => false],
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

        return match ($name) {
            self::SEARCH   => $this->search($arguments, $bearerToken),
            self::DESCRIBE => $this->describe($arguments, $bearerToken),
            self::CALL     => $this->callThrough($arguments, $bearerToken),
            // A real tool called by its own name: the real handler decides.
            default        => ($this->toolsCall)($params, $bearerToken),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function search(array $arguments, ?string $bearerToken): array
    {
        $query = is_string($arguments['query'] ?? null) ? $arguments['query'] : '';
        $limit = is_int($arguments['limit'] ?? null) ? max(1, min(self::MAX_LIMIT, $arguments['limit'])) : self::DEFAULT_LIMIT;

        $terms = array_values(array_map(
            // "users" should find get_api_user: match on a plain English
            // singular. Crude on purpose; a near miss here costs one more search.
            static fn (string $term): string => mb_strlen($term) > 3 && str_ends_with($term, 's') ? mb_substr($term, 0, -1) : $term,
            array_filter(
                preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query)) ?: [],
                static fn (string $term): bool => mb_strlen($term) >= 2,
            ),
        ));

        $scored = [];
        foreach ($this->visibleTools($bearerToken) as $tool) {
            $score = $terms === [] ? 1 : self::score($tool, $terms);
            if ($score > 0) {
                $scored[] = [$score, $tool];
            }
        }
        usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]['name']] <=> [$a[0], $b[1]['name']]);

        $matches = array_map(static function (array $entry): array {
            $tool = $entry[1];
            $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
            return [
                'name'        => $tool['name'],
                'title'       => $annotations['title'] ?? null,
                'description' => $tool['description'] ?? '',
                'readOnly'    => ($annotations['readOnlyHint'] ?? false) === true,
                'destructive' => ($annotations['readOnlyHint'] ?? false) !== true && ($annotations['destructiveHint'] ?? true) === true,
            ];
        }, array_slice($scored, 0, $limit));

        return self::result([
            'matches' => $matches,
            'total'   => count($scored),
            'hint'    => count($scored) > $limit ? 'More tools match; narrow the query or raise limit.' : null,
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function describe(array $arguments, ?string $bearerToken): array
    {
        $name = is_string($arguments['name'] ?? null) ? $arguments['name'] : '';
        foreach ($this->visibleTools($bearerToken) as $tool) {
            if ($tool['name'] === $name) {
                return self::result($tool);
            }
        }
        // Unknown and hidden read the same: there is nothing here for this caller.
        return self::error("No tool named '{$name}' is available. Use search_tools to find one.");
    }

    /** @param array<string, mixed> $arguments */
    private function callThrough(array $arguments, ?string $bearerToken): mixed
    {
        $name = $arguments['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return self::error('call_tool needs the name of the tool to call.');
        }
        if (in_array($name, [self::SEARCH, self::DESCRIBE, self::CALL], true)) {
            return self::error("{$name} is one of the discovery tools; call it directly.");
        }
        $toolArguments = is_array($arguments['arguments'] ?? null) ? $arguments['arguments'] : [];

        try {
            return ($this->toolsCall)(['name' => $name, 'arguments' => $toolArguments], $bearerToken);
        } catch (McpException $e) {
            // At the top level an unknown tool is a protocol error. Here the name
            // is an argument the model chose, so it is the model's to fix, and
            // the error has to reach it (WHIT-710).
            if ($e->errorCode === ErrorCode::METHOD_NOT_FOUND) {
                return self::error("No tool named '{$name}' is available. Use search_tools to find one.");
            }
            throw $e;
        }
    }

    /** @return list<array<string, mixed>> */
    private function visibleTools(?string $bearerToken): array
    {
        $principal = $bearerToken !== null ? $this->tokenValidator->validateBearerForMcp($bearerToken) : null;
        if ($principal === null) {
            throw new McpException(ErrorCode::UNAUTHENTICATED, 'Unauthenticated');
        }
        return $this->toolsList->listFor($principal, TenantContext::getTenantId());
    }

    /**
     * How well a tool matches the query: a term in the name counts most, then
     * the title, then the description.
     *
     * @param array<string, mixed> $tool
     * @param list<string>         $terms
     */
    private static function score(array $tool, array $terms): int
    {
        $annotations = is_array($tool['annotations'] ?? null) ? $tool['annotations'] : [];
        $name        = mb_strtolower(str_replace('_', ' ', (string) ($tool['name'] ?? '')));
        $title       = mb_strtolower((string) ($annotations['title'] ?? ''));
        $description = mb_strtolower((string) ($tool['description'] ?? ''));

        $score = 0;
        foreach ($terms as $term) {
            $hit = (str_contains($name, $term) ? 3 : 0)
                + (str_contains($title, $term) ? 2 : 0)
                + (str_contains($description, $term) ? 1 : 0);
            if ($hit === 0) {
                // Every term has to match somewhere: "delete user" must not
                // rank a tool that merely mentions users above one that deletes them.
                return 0;
            }
            $score += $hit;
        }
        return $score;
    }

    /** @return array{content: list<array{type: string, text: string}>} */
    private static function result(mixed $data): array
    {
        return ['content' => [[
            'type' => 'text',
            'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]]];
    }

    /** @return array{content: list<array{type: string, text: string}>, isError: true} */
    private static function error(string $message): array
    {
        return ['content' => [['type' => 'text', 'text' => $message]], 'isError' => true];
    }
}
