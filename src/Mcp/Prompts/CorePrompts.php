<?php

declare(strict_types=1);

namespace Whity\Mcp\Prompts;

use Whity\Core\RBAC\CorePermissions;

/**
 * Registers the curated built-in MCP prompts (WC-7755fc38).
 *
 * These prompts expose guided agent workflows for common platform tasks:
 * onboarding, RBAC auditing, relation graph exploration, and per-user
 * permission summaries. Each prompt is permission-aware — protected prompts
 * are filtered from prompts/list and enforced at prompts/get time.
 *
 * Plugin-contributed prompts are registered separately via PluginMcpInterface
 * (WC-7abb732f).
 */
final class CorePrompts
{
    public static function register(PromptRegistry $registry): void
    {
        // 1. Onboarding walkthrough — open to all authenticated users.
        $registry->register(new Prompt(
            name: 'onboarding-walkthrough',
            description: 'Guide a tenant administrator through initial platform setup — verify users, assign roles, and confirm RBAC is correctly configured.',
            arguments: [
                new PromptArgument('tenant_name', 'Name of the tenant being set up (used for context)', false),
            ],
            messages: [
                new PromptMessage(
                    'user',
                    'I need help setting up {{tenant_name}} on the platform. ' .
                    'Please start by checking the current list of users and their assigned roles, ' .
                    'then walk me through any missing configuration steps to make this tenant fully operational.',
                ),
            ],
        ));

        // 2. Role audit — requires admin role.
        $registry->register(new Prompt(
            name: 'role-audit',
            description: 'Audit the RBAC configuration — list all roles and their permission sets, review user assignments, and flag over-privileged or misconfigured accounts.',
            arguments: [
                new PromptArgument('role_name', 'Specific role to focus the audit on (optional — omit to audit all roles)', false),
            ],
            messages: [
                new PromptMessage(
                    'user',
                    'Conduct a thorough RBAC audit for this tenant. ' .
                    'Role focus: [{{role_name}}]. ' .
                    'List all roles and their assigned permissions, then check every user\'s role assignments. ' .
                    'Flag any accounts that appear over-privileged or any role definitions that violate least-privilege.',
                ),
            ],
            requiredRole: 'admin',
        ));

        // 3. Relation query — requires relations:read permission.
        $registry->register(new Prompt(
            name: 'relation-query',
            description: 'Explore and map person and user relations within the tenant — useful for auditing family or organisational relation graphs.',
            arguments: [
                new PromptArgument('user_id', 'User ID to centre the relation map on (optional — omit to map all relations)', false),
            ],
            messages: [
                new PromptMessage(
                    'user',
                    'Map the relation graph for this tenant. ' .
                    'Starting point — user ID: [{{user_id}}]. ' .
                    'List all persons and their relation edges, then flag any orphaned person records ' .
                    'or broken relation links that are missing their target.',
                ),
            ],
            requiredPermission: CorePermissions::RELATIONS_READ,
        ));

        // 4. Permission summary — requires users:read permission.
        $registry->register(new Prompt(
            name: 'permission-summary',
            description: "Summarise a specific user's effective permissions — their direct roles, any delegated grants, and notable access combinations.",
            arguments: [
                new PromptArgument('user_id', 'ID of the user to inspect', true),
            ],
            messages: [
                new PromptMessage(
                    'user',
                    'Provide a complete permission summary for user {{user_id}}. ' .
                    'Include their assigned roles, all effective permissions (accounting for any delegations), ' .
                    'and highlight if they hold admin-level access or any unusual privilege combinations.',
                ),
            ],
            requiredPermission: CorePermissions::USERS_READ,
        ));
    }
}
