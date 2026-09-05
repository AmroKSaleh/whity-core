<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional HAND-AUTHORED MCP tool contribution point for plugins (SDK v1.43).
 *
 * A plugin MAY implement this interface — in addition to {@see PluginInterface}
 * — to declare MCP tools it writes itself, rather than tools derived from its
 * route declarations. Purely additive: plugins that do not implement it load
 * exactly as before, and their routes are derived exactly as before.
 *
 * WHY BOTH KINDS EXIST
 * --------------------
 * Derived tools expose an API SURFACE. The host generates one per route with a
 * schema, at near-zero authoring cost, and the tool list stays honest as routes
 * change — the right default, and it remains the default.
 *
 * Hand-authored tools expose a WORKFLOW surface. Fewer tools, each carrying
 * domain semantics, instructions and guardrails that no route signature
 * implies: "close the period and report what it refused" is one tool with one
 * description, not four endpoints a model must sequence correctly by reading
 * their operationIds.
 *
 * Neither subsumes the other. A tool derived from a route cannot describe a
 * multi-step process, and a hand-authored tool cannot stay in step with a route
 * table automatically. A platform hosting third-party plugins wants both.
 *
 * Descriptor shape
 * ----------------
 * Each entry of the returned list is an associative array:
 *
 * - `name` (string, REQUIRED, non-empty): the tool name the model calls.
 *   De-duplicated across derived tools, core, and all plugins. DERIVED TOOLS
 *   WIN a collision — a route-backed tool is the one the OpenAPI document and
 *   the typed clients already describe, so silently shadowing it would make two
 *   published surfaces disagree. The authored duplicate is dropped with a
 *   logged warning; see {@see self::suppressesDerivedMcpTools()} for the
 *   deliberate way to take the name.
 * - `description` (string, REQUIRED): what the tool does, in the terms the
 *   model needs. This is the whole reason for authoring by hand — say what the
 *   workflow is FOR and what it will refuse, not what the endpoint is called.
 * - `inputSchema` (array, REQUIRED): a JSON Schema object describing the
 *   arguments. Validated by the host before the handler runs, with the same
 *   validator derived tools use, so a handler never sees arguments of the
 *   wrong shape.
 * - `handler` (callable, REQUIRED): `fn(array $arguments): mixed`. Return a
 *   value the host will encode as the tool result. THROWING IS SAFE — the host
 *   catches, logs, and answers the caller with an internal-error result rather
 *   than letting one plugin's tool break the dispatcher.
 * - `requiredRole` (string, optional): only callers holding this role may see
 *   (`tools/list`) or invoke (`tools/call`) the tool.
 * - `requiredPermission` (string, optional): the same, checked against the
 *   caller's permission set. Both may be set; both are checked.
 *
 * - `open` (bool, optional): state that any authenticated MCP principal may
 *   invoke this tool.
 *
 * A DESCRIPTOR MUST DECLARE ITS AUDIENCE. `requiredRole`, `requiredPermission`
 * or `open: true` — a tool declaring none of the three is REFUSED at load.
 *
 * A derived tool inherits its route's RBAC gate, and a route that declares no
 * permission is visibly open in the route table and in the route-catalogue CI
 * check. An authored tool has no route, so an omitted permission would be
 * visible nowhere: callable by every authenticated principal, with nothing
 * saying so. `open: true` is therefore a sentence somebody wrote, not a
 * default anybody fell into.
 *
 * Descriptors with a missing or empty `name`, a missing `description`, a
 * non-array `inputSchema` or a non-callable `handler` are dropped with a
 * logged warning. A `getMcpTools()` call that throws is caught, logged, and
 * treated as if the plugin contributed nothing — the plugin itself continues
 * to load normally, exactly as with {@see PluginMcpInterface}.
 */
interface PluginMcpToolsInterface
{
    /**
     * @return list<array<string, mixed>> Hand-authored MCP tool descriptors.
     */
    public function getMcpTools(): array;

    /**
     * Whether to STOP deriving tools from this plugin's own routes.
     *
     * Return true when the authored tools are the intended surface and the
     * route-derived ones would duplicate them — the common case for a plugin
     * that wraps several of its own endpoints in one workflow tool, where
     * publishing both leaves a model to guess which to call.
     *
     * Scoped to the plugin's OWN routes. A plugin cannot suppress derivation
     * for core routes or for another plugin's, which is why this is a boolean
     * here rather than a list of names to remove.
     *
     * Returning true is also how an authored tool may legitimately TAKE the
     * name of one of the plugin's own derived tools: with derivation off there
     * is no collision to lose. Say so deliberately rather than relying on
     * shadowing.
     */
    public function suppressesDerivedMcpTools(): bool;
}
