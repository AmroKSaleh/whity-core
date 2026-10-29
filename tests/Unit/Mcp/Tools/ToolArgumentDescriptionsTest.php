<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\TestCase;
use Whity\Mcp\McpExposure;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * CI gate (WHIT-711): every argument of every core MCP tool carries a
 * description, or is listed in KNOWN_UNDOCUMENTED.
 *
 * An agent picks a tool from its description and fills it in from the
 * argument descriptions; a bare `scope` or `data` leaves it guessing, and the
 * guess costs a failed call and a retry at best. Path parameters are described
 * by ToolDeriver itself, so what this catches is a body or query field
 * declared in CoreApiSchemas without a `description`.
 *
 * KNOWN_UNDOCUMENTED is the backlog as it stood when the gate was added. It
 * only shrinks: a new route cannot join it without someone editing this file
 * on purpose, and an entry that has since been described fails the test until
 * it is removed, so the list never claims a gap that is no longer there.
 */
final class ToolArgumentDescriptionsTest extends TestCase
{
    /**
     * Tool name => arguments that still have no description.
     *
     * Describe the field in CoreApiSchemas (`'description' => '...'` in the
     * literal, or `+ ['description' => '...']` after a helper) and delete it
     * here.
     *
     * @var array<string, list<string>>
     */
    private const KNOWN_UNDOCUMENTED = [
        'post_api_ou_types' => ['key', 'label', 'sort_order'],
        'patch_api_ou_types_id' => ['label', 'sort_order'],
        'post_api_time_window_types' => ['key', 'label', 'parent_type_id'],
        'patch_api_time_window_types_id' => ['label', 'parent_type_id'],
        'post_api_time_windows' => ['window_type_id', 'key', 'label', 'starts_on', 'ends_on', 'parent_window_id'],
        'patch_api_time_windows_id' => ['label', 'starts_on', 'ends_on', 'parent_window_id'],
        'post_api_time_windows_id_close' => ['reason', 'cascade'],
        'post_api_time_windows_id_reopen' => ['reason'],
        'put_api_notification_settings_channel' => ['transport', 'from_address', 'from_name', 'reply_to', 'config', 'enabled'],
        'put_api_notification_settings_channel_credentials' => ['credentials'],
        'post_api_deployments_apply' => ['version', 'source_path'],
        'get_api_plugins_store_catalog' => ['store_url', 'q'],
        'post_api_persons' => ['displayName', 'birthDate', 'deceased', 'notes'],
        'patch_api_persons_id' => ['displayName', 'birthDate', 'deceased', 'notes'],
        'post_api_relations' => ['from', 'to', 'relationshipTypeId'],
        'patch_api_settings' => ['settings'],
        'patch_api_settings_global' => ['settings'],
        'post_api_languages' => ['code', 'name', 'direction', 'enabled'],
        'patch_api_languages_id' => ['name', 'direction', 'enabled'],
        'post_api_translations' => ['language_code', 'domain', 'key', 'translation'],
        'patch_api_translations_id' => ['translation'],
        'put_api_tenants_id_branding_host' => ['host'],
        'post_api_identity_providers' => ['provider_key', 'display_name', 'client_id', 'issuer', 'client_secret', 'discovery_url', 'scopes', 'domain', 'enabled'],
        'patch_api_identity_providers_id' => ['provider_key', 'display_name', 'client_id', 'issuer', 'client_secret', 'discovery_url', 'scopes', 'domain', 'enabled'],
        'post_api_email_domains' => ['domain', 'default_role_id', 'auto_provision'],
        'patch_api_email_domains_id' => ['default_role_id', 'auto_provision'],
        'patch_api_tenants_id_entitlements' => ['entitlements'],
        'put_api_storage_config' => ['driver', 'endpoint', 'region', 'bucket', 'access_key', 'secret', 'path_style', 'public_base_url'],
        'post_api_promotions' => ['name', 'code', 'percent_off', 'amount_off', 'currency', 'starts_at', 'ends_at', 'max_redemptions', 'max_redemptions_per_tenant', 'plan_ids'],
        'post_api_affiliates' => ['code', 'name', 'commission_bp', 'window_months', 'email', 'profile_id', 'promotion_id'],
        'patch_api_affiliates_id' => ['commission_bp', 'window_months', 'promotion_id', 'is_active'],
        'post_api_affiliates_id_payouts' => ['currency'],
        'patch_api_affiliate_payouts_id' => ['reference'],
        'post_api_plans_id_prices' => ['currency', 'unit_amount', 'billing_period', 'is_per_seat', 'is_per_device'],
        'post_api_plans' => ['plan_key', 'name', 'description', 'is_active', 'sort_order'],
        'patch_api_plans_id' => ['name', 'description', 'is_active', 'sort_order'],
        'post_api_plans_id_move_subscribers' => ['to_plan_id'],
        'put_api_plans_id_entitlements' => ['entitlements'],
        'post_api_tenants_id_plan' => ['plan_id'],
        'post_api_billing_invoices_id_pay' => ['provider', 'return_url'],
        'put_api_tenants_id_subscription' => ['plan_id', 'status', 'enforcement_mode', 'current_period_end', 'grace_until', 'external_ref'],
        'post_api_document_blocks' => ['name', 'data', 'scope', 'required_permission', 'owner_ou_id'],
        'patch_api_document_blocks_id' => ['name', 'data', 'scope', 'required_permission', 'owner_ou_id'],
        'post_api_user_groups_preview' => ['rule_kind', 'rule_config'],
        'post_api_user_groups' => ['name', 'description', 'rule_kind', 'rule_config'],
        'patch_api_user_groups_id' => ['name', 'description', 'rule_kind', 'rule_config'],
        'post_api_document_route_templates' => ['name', 'description'],
        'put_api_document_route_templates_id_graph' => ['steps', 'edges'],
        'patch_api_document_route_templates_id' => ['name', 'description'],
        'post_api_document_collections' => ['name'],
        'patch_api_document_collections_id' => ['name'],
        'post_api_2fa_policies' => ['scope_type', 'scope_id', 'grace_period_days'],
        'patch_api_2fa_policies_id' => ['grace_period_days'],
        'post_api_tag_groups' => ['key', 'display_name'],
        'patch_api_tag_groups_id' => ['key', 'display_name'],
        'post_api_tags' => ['group_id', 'name'],
        'patch_api_tags_id' => ['name'],
        'post_api_entity_tags' => ['entity_type', 'entity_id', 'tag_id'],
        'delete_api_entity_tags' => ['entity_type', 'entity_id', 'tag_id'],
        'post_api_2fa_recovery_force_reset' => ['profile_id'],
        'post_api_data_types_type_bulk' => ['action'],
        'post_api_resource_role_grants' => ['resource_type', 'resource_id', 'role_id', 'profile_id'],
        'post_api_licensing_devices' => ['serial_numbers'],
        'post_api_licensing_codes' => ['licensed_device_id', 'max_redemptions', 'expires_at'],
        'post_api_billing_checkout' => ['plan_key', 'billing_period'],
        'post_api_billing_quantity' => ['quantity'],
    ];

    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testEveryToolArgumentIsDescribed(): void
    {
        $missing = [];
        foreach ($this->undocumented() as $tool => $arguments) {
            $known = self::KNOWN_UNDOCUMENTED[$tool] ?? [];
            foreach (array_diff($arguments, $known) as $argument) {
                $missing[] = "{$tool}.{$argument}";
            }
        }

        self::assertSame([], $missing, "These MCP tool arguments have no description.\n"
            . "Add one in CoreApiSchemas so an agent knows what to send:\n  "
            . implode("\n  ", $missing));
    }

    public function testKnownUndocumentedHoldsOnlyRealGaps(): void
    {
        $undocumented = $this->undocumented();

        $stale = [];
        foreach (self::KNOWN_UNDOCUMENTED as $tool => $arguments) {
            foreach (array_diff($arguments, $undocumented[$tool] ?? []) as $argument) {
                $stale[] = "{$tool}.{$argument}";
            }
        }

        self::assertSame([], $stale, "These are described now (or gone); remove them from KNOWN_UNDOCUMENTED:\n  "
            . implode("\n  ", $stale));
    }

    /**
     * Tool name => its arguments without a description, over the tools an
     * agent is actually offered.
     *
     * @return array<string, list<string>>
     */
    private function undocumented(): array
    {
        $routes = array_values(array_filter(
            CoreApiSchemas::routes(),
            static fn (array $route): bool => McpExposure::exposes($route),
        ));
        $deriver = new ToolDeriver($routes, CoreApiSchemas::components(), null, static function (): void {
        });

        $undocumented = [];
        foreach ($deriver->deriveTools() as $tool) {
            foreach ($tool['inputSchema']['properties'] ?? [] as $name => $schema) {
                $description = is_array($schema) ? ($schema['description'] ?? null) : null;
                if (!is_string($description) || trim($description) === '') {
                    $undocumented[$tool['name']][] = (string) $name;
                }
            }
        }

        return $undocumented;
    }
}
