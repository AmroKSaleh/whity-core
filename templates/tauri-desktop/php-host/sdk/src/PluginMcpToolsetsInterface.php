<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional: name and describe the toolsets a plugin's MCP tools live in
 * (SDK 1.52, WHIT-752, docs/design/MCP-TOOL-SURFACE.md §5.1 and §5.7).
 *
 * Every MCP tool belongs to one toolset. A plugin's tools are in its ROOT
 * toolset, named after the plugin (`Plugins\PeriodClose` → `period-close`),
 * unless a tool names a sub-toolset (`'toolset' => 'locks'` →
 * `period-close.locks`). Toolsets organise search results, let a token be
 * limited to some of them (`toolset:period-close.*`), and give a plugin its
 * own MCP endpoint (a split). They never grant access: that stays with each
 * tool's `requiredPermission`.
 *
 * Declaring them is optional. An undeclared toolset is shown with the
 * plugin's name. Declare one to give people and models a better title and a
 * one-line description, which `search_operations` shows before any tool.
 *
 * Each entry:
 *
 * - `id` (string): relative to the plugin's root; empty for the root itself.
 *   The host adds the prefix, so a plugin can't declare a toolset under
 *   another plugin's name or core's.
 * - `title` (string, at most 40 characters) and `titleAr` (Arabic, optional
 *   but expected: every visible string has an English and an Arabic label).
 * - `description` (string, one sentence, at most 160 characters).
 * - `split` (bool, optional): give this sub-toolset its own MCP endpoint. A
 *   root toolset always has one.
 * - `requiresEntitlement` (string, optional): an entitlement key a tenant
 *   needs for these tools to exist for it at all, for paid features.
 *
 * Refused entries are logged against the plugin and skipped; the plugin
 * still loads.
 */
interface PluginMcpToolsetsInterface
{
    /**
     * @return list<array{id: string, title: string, titleAr?: string, description: string, split?: bool, requiresEntitlement?: string}>
     */
    public function getMcpToolsets(): array;
}
