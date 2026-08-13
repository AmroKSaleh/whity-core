<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional MCP prompt contribution point for plugins (SDK v1.9).
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to declare MCP prompt templates it contributes
 * to the host's PromptRegistry. Like the other sibling capability interfaces
 * ({@see PluginFrontendInterface}, {@see PluginRolesInterface}), this is
 * purely additive: plugins that do not implement it load exactly as before.
 *
 * Descriptor shape
 * ----------------
 * Each entry of the returned list is an associative array:
 *
 * - `name` (string, REQUIRED, non-empty): unique kebab-case prompt slug.
 *   Names are de-duplicated across core and all plugins; when two sources
 *   claim the same name the first registration wins and the duplicate is
 *   dropped with a logged warning. Core prompts are always registered before
 *   plugin prompts.
 * - `description` (string, REQUIRED): human-readable summary of what the
 *   prompt does — shown in prompts/list responses.
 * - `arguments` (list, optional): argument descriptors consumed by the
 *   prompt, each `{name: string, description?: string, required?: bool}`.
 * - `messages` (list, optional): static message sequence for this prompt,
 *   each `{role: 'user'|'assistant', content: {type: 'text', text: string}}`.
 * - `requiredRole` (string, optional): if set, only callers holding this
 *   role may retrieve (`prompts/get`) or see (`prompts/list`) this prompt.
 *   Mutually optional with `requiredPermission`; both may be set.
 * - `requiredPermission` (string, optional): same semantics as `requiredRole`
 *   but checked against the caller's permission set.
 *
 * Descriptors with a missing or empty `name` are silently dropped with a
 * logged warning. A `getMcpPrompts()` call that throws is caught, logged, and
 * treated as if the plugin contributed nothing — the plugin itself continues
 * to load normally.
 */
interface PluginMcpInterface
{
    /**
     * @return list<array<string, mixed>> MCP prompt descriptors.
     */
    public function getMcpPrompts(): array;
}
