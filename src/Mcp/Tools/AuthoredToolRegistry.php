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
     *     handler: callable,
     *     requiredRole: ?string,
     *     requiredPermission: ?string,
     *     plugin: string
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

        $this->tools[$name] = [
            'name'               => $name,
            'description'        => $description,
            'inputSchema'        => $schema,
            'handler'            => $handler,
            'requiredRole'       => is_string($role) && $role !== '' ? $role : null,
            'requiredPermission' => is_string($permission) && $permission !== '' ? $permission : null,
            'plugin'             => $pluginKey,
        ];

        return null;
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
            $out[] = [
                'name'        => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
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
     *     plugin: string
     * }|null
     */
    public function get(string $name): ?array
    {
        return $this->tools[$name] ?? null;
    }
}
