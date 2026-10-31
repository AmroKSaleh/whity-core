<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Core\Container\HostWiredService;

/**
 * The hand-authored MCP tools plugins contribute (SDK 1.43,
 * {@see \Whity\Sdk\PluginMcpToolsInterface}).
 *
 * The sibling of {@see ToolDeriver}, and deliberately a separate object rather
 * than a branch inside it. The deriver's whole job is reading the route table;
 * a tool that has no route has nothing for it to read, and folding the two
 * together would put a null-route special case through every method that
 * currently assumes a declaration exists.
 *
 * WHO WINS A NAME COLLISION, AND WHY IT IS THE DERIVED TOOL
 * ---------------------------------------------------------
 * A derived tool's name is already published twice over — in the OpenAPI
 * document and in the generated typed clients — so letting an authored tool
 * silently take it would leave two descriptions of one name disagreeing, with
 * nothing reporting the divergence. The authored duplicate is refused and
 * logged.
 *
 * A plugin that genuinely wants the name says so by suppressing derivation for
 * its own routes, which removes the competitor rather than shadowing it.
 *
 * ORDER WITHIN THE AUTHORED SET is first-registration-wins, matching the
 * PromptRegistry's rule for the same reason: two plugins claiming one name is a
 * packaging mistake, and picking a winner by load order at least makes it
 * stable and reportable.
 *
 * HOST-WIRED ({@see HostWiredService}). An improvised, empty instance is
 * indistinguishable from a correct one — "this deployment has no tool-authoring
 * plugin" is a perfectly ordinary state — so a caller handed a fresh registry
 * would find no tool and report none, which is precisely the silent failure the
 * marker exists to prevent.
 */
final class AuthoredToolRegistry implements HostWiredService
{
    /**
     * name => the registered tool.
     *
     * @var array<string, array{
     *     name: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     annotations: ?array<string, bool|string>,
     *     handler: callable,
     *     requiredRole: ?string,
     *     requiredPermission: ?string,
     *     plugin: string,
     *     toolset: string,
     *     visibility: string,
     *     agentPolicy: string,
     *     undo: array{windowSeconds: int, operation: ?string, handler: ?callable}|null
     * }>
     */
    private array $tools = [];

    /**
     * Plugin namespace prefixes that asked for their own routes not to be
     * derived.
     *
     * @var array<string, true>
     */
    private array $suppressedPlugins = [];

    /** @var array<string, array{id: string, title: string, titleAr: string, description: string, split: bool, requiresEntitlement: ?string, plugin: string}> */
    private array $toolsets = [];

    /** @var list<string> */
    private array $warnings = [];

    /** A description is read by a model on every search; longer is cost, not clarity. */
    public const MAX_DESCRIPTION = 500;

    /**
     * Register one descriptor. Returns null on success, or the reason it was
     * refused — the caller logs it against the contributing plugin.
     *
     * Validation is done HERE rather than trusted from the plugin, because a
     * descriptor is plain plugin data: `handler` in particular is called later,
     * on a request, and a non-callable discovered then would surface as a
     * dispatcher crash rather than a load-time warning.
     *
     * @param array<string, mixed> $descriptor
     */
    public function register(array $descriptor, string $pluginKey): ?string
    {
        $name = $descriptor['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            return 'missing or empty `name`';
        }
        $description = $descriptor['description'] ?? null;
        if (!is_string($description) || trim($description) === '') {
            return "tool '{$name}' has a missing or empty `description`";
        }
        $schema = $descriptor['inputSchema'] ?? null;
        if (!is_array($schema)) {
            return "tool '{$name}' has a missing or non-array `inputSchema`";
        }
        $handler = $descriptor['handler'] ?? null;
        if (!is_callable($handler)) {
            return "tool '{$name}' has a missing or non-callable `handler`";
        }
        if (isset($this->tools[$name])) {
            return "tool '{$name}' is already registered by plugin '{$this->tools[$name]['plugin']}' — duplicate dropped";
        }

        $role = $descriptor['requiredRole'] ?? null;
        $permission = $descriptor['requiredPermission'] ?? null;
        $hasRole = is_string($role) && $role !== '';
        $hasPermission = is_string($permission) && $permission !== '';

        // FAIL CLOSED. A derived tool inherits its route's RBAC gate, and a
        // route that declares none is visibly open in the route table and in
        // the route-catalogue CI check. An authored tool has no route, so an
        // omitted permission is visible nowhere at all — it would simply be
        // callable by every authenticated MCP principal, and nothing would say
        // so.
        //
        // `open: true` keeps a genuinely public tool expressible. Without that
        // escape the rule would be worse than this one, not better: authors
        // would mint a dummy permission to satisfy it, and a permission that
        // exists only to be granted to everybody is a lie the catalogue then
        // carries forever.
        if (!$hasRole && !$hasPermission && ($descriptor['open'] ?? false) !== true) {
            return "tool '{$name}' declares neither `requiredRole` nor `requiredPermission`; "
                . 'add one, or set `open: true` to state deliberately that any authenticated caller may invoke it';
        }

        // SDK 1.52 (WHIT-752): name and description budgets, toolset,
        // visibility, agent policy and undo. Refusals here are load-time
        // warnings for the author, never a broken dispatcher later.
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name) !== 1) {
            return "tool '{$name}' has a name outside [A-Za-z0-9_-]{1,64}, which some model APIs refuse";
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            return "tool '{$name}' has a description over " . self::MAX_DESCRIPTION . ' characters';
        }
        $root = CoreToolsets::pluginToolsetIdForClass($pluginKey);
        $slug = str_replace('-', '_', $root);
        if (!str_starts_with($name, $slug . '_')) {
            $this->warnings[] = "tool '{$name}' should start with '{$slug}_'; unprefixed names are deprecated and refused from SDK 2.0";
        }
        $toolset = self::toolset($descriptor['toolset'] ?? null, $root);
        if ($toolset === null) {
            return "tool '{$name}' names a toolset outside its plugin's own ('{$root}')";
        }
        $annotations = ToolAnnotations::sanitize($descriptor['annotations'] ?? null);
        $visibility  = $descriptor['visibility'] ?? ToolSurface::VISIBILITY_DISCOVERABLE;
        if (!in_array($visibility, [ToolSurface::VISIBILITY_ALWAYS_ON, ToolSurface::VISIBILITY_DISCOVERABLE], true)) {
            return "tool '{$name}' has visibility " . json_encode($visibility) . "; use 'always-on' or 'discoverable'";
        }
        if ($visibility === ToolSurface::VISIBILITY_ALWAYS_ON) {
            // Every write goes through search and call (owner decision 4).
            if (($annotations['readOnlyHint'] ?? false) !== true) {
                return "tool '{$name}' is always-on but not read-only; declare annotations.readOnlyHint: true, or make it discoverable";
            }
            // One always-on tool per plugin (owner decision 5). A second is
            // demoted rather than refused, so the tool still works.
            foreach ($this->tools as $other) {
                if ($other['plugin'] === $pluginKey && $other['visibility'] === ToolSurface::VISIBILITY_ALWAYS_ON) {
                    $this->warnings[] = "tool '{$name}' asked to be always-on, but '{$other['name']}' already is; a plugin gets one, so it is discoverable";
                    $visibility = ToolSurface::VISIBILITY_DISCOVERABLE;
                    break;
                }
            }
        }
        $agentPolicy = $descriptor['agentPolicy'] ?? ToolSurface::AGENT_ALLOW;
        if (!in_array($agentPolicy, ToolSurface::AGENT_POLICIES, true)) {
            return "tool '{$name}' has agentPolicy " . json_encode($agentPolicy) . '; use ' . implode(', ', ToolSurface::AGENT_POLICIES);
        }
        $undo = self::undo($descriptor['undo'] ?? null);
        if ($undo === false) {
            return "tool '{$name}' has a malformed undo; use ['windowSeconds' => int > 0, 'operation' => string] or 'handler' => callable";
        }

        $this->tools[$name] = [
            'name'               => $name,
            'description'        => $description,
            'inputSchema'        => $schema,
            // WHIT-706: only what the plugin declares. An authored tool has no
            // HTTP method to derive hints from, and a guessed "read-only" is
            // worse than none: the spec's defaults are the cautious reading.
            'annotations'        => $annotations,
            'handler'            => $handler,
            'requiredRole'       => is_string($role) && $role !== '' ? $role : null,
            'requiredPermission' => is_string($permission) && $permission !== '' ? $permission : null,
            'plugin'             => $pluginKey,
            'toolset'            => $toolset,
            'visibility'         => $visibility,
            'agentPolicy'        => $agentPolicy,
            'undo'               => $undo,
        ];

        return null;
    }

    /**
     * Register a plugin's toolset declarations (SDK 1.52,
     * {@see \Whity\Sdk\PluginMcpToolsetsInterface}). Ids are relative to the
     * plugin's root toolset, which the host prefixes; an empty id is the root.
     * Returns the reasons any were refused, for the caller to log.
     *
     * @param array<mixed> $declarations
     * @return list<string>
     */
    public function registerToolsets(array $declarations, string $pluginKey): array
    {
        $root    = CoreToolsets::pluginToolsetIdForClass($pluginKey);
        $refused = [];
        foreach ($declarations as $declaration) {
            if (!is_array($declaration)) {
                $refused[] = 'a toolset declaration is not an array';
                continue;
            }
            $relative = $declaration['id'] ?? '';
            $id       = is_string($relative) ? ($relative === '' ? $root : $root . '.' . $relative) : '';
            $title    = $declaration['title'] ?? null;
            $summary  = $declaration['description'] ?? null;
            if (!is_string($relative) || !ToolSurface::isToolsetId($id)) {
                $refused[] = 'toolset id ' . json_encode($relative) . ' is malformed';
            } elseif (!is_string($title) || trim($title) === '' || mb_strlen($title) > 40) {
                $refused[] = "toolset '{$id}' needs a title of at most 40 characters";
            } elseif (!is_string($summary) || trim($summary) === '' || mb_strlen($summary) > 160) {
                $refused[] = "toolset '{$id}' needs a one-sentence description of at most 160 characters";
            } else {
                $entitlement = $declaration['requiresEntitlement'] ?? null;
                $titleAr     = $declaration['titleAr'] ?? null;
                $this->toolsets[$id] = [
                    'id'                  => $id,
                    'title'               => $title,
                    'titleAr'             => is_string($titleAr) && $titleAr !== '' ? $titleAr : $title,
                    'description'         => $summary,
                    // A root toolset always gets a split; a sub-toolset only when asked.
                    'split'               => $id === $root || ($declaration['split'] ?? false) === true,
                    'requiresEntitlement' => is_string($entitlement) && $entitlement !== '' ? $entitlement : null,
                    'plugin'              => $pluginKey,
                ];
            }
        }

        return $refused;
    }

    /**
     * Every declared plugin toolset, by id.
     *
     * @return array<string, array{id: string, title: string, titleAr: string, description: string, split: bool, requiresEntitlement: ?string, plugin: string}>
     */
    public function toolsets(): array
    {
        return $this->toolsets;
    }

    /**
     * Load-time notes for plugin authors that refused nothing (deprecated
     * names, demotions). Taken once: the caller logs them.
     *
     * @return list<string>
     */
    public function drainWarnings(): array
    {
        $warnings       = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    /**
     * The toolset a descriptor asks for, under the plugin's root, or null when
     * it names one outside it. Relative (`palettes`) or absolute
     * (`theme-creator.palettes`).
     */
    private static function toolset(mixed $requested, string $root): ?string
    {
        if ($requested === null || $requested === '') {
            return $root;
        }
        if (!is_string($requested)) {
            return null;
        }
        if (ToolSurface::isWithin($requested, $root)) {
            return ToolSurface::isToolsetId($requested) ? $requested : null;
        }
        if (str_contains($requested, '.')) {
            return null;
        }
        $id = $root . '.' . $requested;
        return ToolSurface::isToolsetId($id) ? $id : null;
    }

    /**
     * A descriptor's undo (WHIT-752, design §5.11): null when there is none,
     * false when it is malformed.
     *
     * @return array{windowSeconds: int, operation: ?string, handler: ?callable}|null|false
     */
    private static function undo(mixed $raw): array|null|false
    {
        if ($raw === null) {
            return null;
        }
        if (!is_array($raw) || !is_int($raw['windowSeconds'] ?? null) || $raw['windowSeconds'] <= 0) {
            return false;
        }
        $operation = $raw['operation'] ?? null;
        $handler   = $raw['handler'] ?? null;
        if (!(is_string($operation) && $operation !== '') && !is_callable($handler)) {
            return false;
        }
        return [
            'windowSeconds' => $raw['windowSeconds'],
            'operation'     => is_string($operation) && $operation !== '' ? $operation : null,
            'handler'       => is_callable($handler) ? $handler : null,
        ];
    }

    /** Record that a plugin's own routes must not be derived into tools. */
    public function suppressDerivationFor(string $pluginKey): void
    {
        $this->suppressedPlugins[$pluginKey] = true;
    }

    /**
     * Empty the registry so it can be rebuilt from the current plugin set.
     *
     * The counterpart of `PromptRegistry::reset()`, and needed for the same
     * reason (#952): the registry is memoized off the plugin registry, so a
     * plugin that is disabled or uninstalled at runtime must lose its tools.
     * Without this, a plugin turned OFF would keep its tools listed and
     * CALLABLE — worse than the prompts case it mirrors, because the
     * consequence is an action rather than a listing.
     *
     * Suppressions are cleared too: a suppression belonging to a plugin that
     * is no longer active must not go on hiding derived tools.
     */
    public function reset(): void
    {
        $this->tools = [];
        $this->toolsets = [];
        $this->warnings = [];
        $this->suppressedPlugins = [];
    }

    /** @return list<string> */
    public function suppressedPlugins(): array
    {
        return array_keys($this->suppressedPlugins);
    }

    /**
     * Drop any authored tool whose name a derived tool already claims.
     *
     * Called once the route table is complete, because derivation happens after
     * plugin load — at registration time there is nothing yet to collide with.
     *
     * @param list<string> $derivedNames
     * @return list<string> the names dropped, for the caller to log
     */
    public function dropCollisionsWith(array $derivedNames): array
    {
        $dropped = [];
        foreach ($derivedNames as $name) {
            if (isset($this->tools[$name])) {
                $dropped[] = $name;
                unset($this->tools[$name]);
            }
        }

        return $dropped;
    }

    /**
     * The tool objects for tools/list — schema and copy only, never the
     * handler. A callable is not JSON-encodable and has no business crossing
     * the wire even if it were.
     *
     * @return list<array<string, mixed>>
     */
    public function toolObjects(): array
    {
        $out = [];
        foreach ($this->tools as $tool) {
            $object = [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
            if ($tool['annotations'] !== null) {
                $object['annotations'] = $tool['annotations'];
            }
            // WHIT-747, WHIT-752: the toolset, visibility and agent policy the
            // descriptor declared, as the registry accepted them.
            $object['_meta'] = ToolSurface::meta($tool['toolset'], $tool['visibility'], $tool['agentPolicy']);
            $out[] = $object;
        }

        return $out;
    }

    /**
     * name => access requirements, in the same shape
     * {@see ToolDeriver::buildAccessMap()} returns, so the list and call
     * handlers filter both kinds through one code path.
     *
     * @return array<string, array{requiredRole: ?string, requiredPermission: ?string}>
     */
    public function accessMap(): array
    {
        $out = [];
        foreach ($this->tools as $name => $tool) {
            $out[$name] = [
                'requiredRole'       => $tool['requiredRole'],
                'requiredPermission' => $tool['requiredPermission'],
            ];
        }

        return $out;
    }

    /** The toolset an authored tool belongs to (its plugin's), or null for an unknown name. */
    public function toolsetOf(string $name): ?string
    {
        // The toolset the descriptor declared, as accepted at registration (SDK 1.52).
        return isset($this->tools[$name]) ? $this->tools[$name]['toolset'] : null;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$name]);
    }

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     handler: callable,
     *     requiredRole: ?string,
     *     requiredPermission: ?string,
     *     plugin: string,
     *     toolset: string,
     *     visibility: string,
     *     agentPolicy: string,
     *     undo: array{windowSeconds: int, operation: ?string, handler: ?callable}|null
     * }|null
     */
    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }
}
