<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * SDK identity (v1.21).
 *
 * {@see self::VERSION} is the version a host application evaluates plugin
 * SDK-constraints against ({@see PluginRequirementsInterface::getSdkConstraint()}).
 * It MUST match the `version` field in the package's composer.json — the host
 * test suite pins the two together so they cannot drift.
 *
 * Versioning policy (additive): new capabilities land in minor versions —
 * 1.0 (contract extraction) → 1.1 (requirements declaration, this class) →
 * 1.2 (frontend feature descriptor, {@see PluginFrontendInterface}, plus
 * host-enforced route-level `requiredPermission`) → 1.3 (tenant-isolation
 * conformance kit: {@see \Whity\Sdk\Tenant\TenantPredicateScanner},
 * {@see \Whity\Sdk\Tenant\MigrationTenantColumnLinter}, and the shared
 * {@see \Whity\Sdk\Testing\TenantIsolationConformanceTestCase} a plugin
 * extends to prove its tenant tables and queries are scoped) →
 * 1.4 (host CORE-version constraint declaration,
 * {@see PluginRequirementsInterface::getCoreConstraint()}, gated against the
 * host's core version independently of the SDK gate) →
 * 1.5 (multipart upload shapes: {@see \Whity\Sdk\Http\UploadedFile} and the
 * additive {@see \Whity\Sdk\Http\Request::getUploadedFiles()} upload bag, plus
 * the host-side {@see \Whity\Sdk\Http\MultipartParser}, WC-217) →
 * 1.6 (server-driven plugin-UI block contract: the platform-neutral
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract} whitelist and the
 * {@see \Whity\Sdk\Frontend\Blocks\BlockValidator}, plus the new
 * `screen: 'blocks'` frontend-feature value, WC-225) →
 * 1.7 (data-bound block types: `dataTable`, `dataStat`, `dataList` leaves with
 * the new `apiPath` prop-rule kind — strict relative API path validation, WC-229) →
 * 1.8 (interactive block types: `form` container, 9 input leaves — `textInput`,
 * `textArea`, `numberInput`, `select`, `checkbox`, `slider`, `dateInput`,
 * `fileInput`, `colorInput` — plus `submitButton` and `actionButton`; new
 * `inputName`/`selectOptions`/`submitSpec` prop-rule kinds; form-ancestor
 * enforcement and per-form duplicate-name detection in the validator, WC-233) →
 * 1.9 (MCP prompt contribution point: {@see PluginMcpInterface}, WC-7abb732f) →
 * 1.10 (`chart` data-bound block type — bar/line/area/pie backed by the same
 * `apiPath`-owned `source` trust boundary as `dataTable`/`dataStat`/`dataList`,
 * plus the new `chartSeriesList` prop-rule kind for its semantic
 * `{key, label, color: 1..5}` series set, WC-240) →
 * 1.11 (`dataTable`/`dataList` inline client-side sort/filter/pagination:
 * `dataTable.columns` upgraded to the new `dataColumnList` prop-rule kind
 * (adds optional per-column `sortable`/`filterable` booleans), plus a shared
 * optional `pageSize` on both leaves — purely additive, applies only to rows
 * already fetched from the block's single verified `source`, WC-241) →
 * 1.12 (optional theme-override contribution point:
 * {@see PluginThemeInterface}, letting a plugin contribute design-token CSS
 * variable overrides the host applies at render time. Same ownership model
 * as data-bound block sources — the declared route must be one this plugin
 * actually registered — and the host independently revalidates every
 * returned key/value before it ever reaches a `<style>` tag, WC-242) →
 * 1.13 (`screen: 'embed'` frontend-feature value — the host iframes a
 * plugin's own RBAC-protected GET route with zero host-application edits,
 * WC-246 — plus real multipart file uploads for `screen: 'action'` fields,
 * WC-247) →
 * 1.14 (notification transport contract: the pluggable
 * {@see \Whity\Sdk\Notification\NotificationTransport} channel contract plus
 * the {@see \Whity\Sdk\Notification\NotificationMessage} and
 * {@see \Whity\Sdk\Notification\SendResult} value objects — one contract for
 * email/SMS/in-app/push delivery. Core ships the transport registry + a
 * null/log transport; plugins contribute real ones, WC-notifications) →
 * 1.15 (hook VETO contract: {@see \Whity\Sdk\Hooks\HookVetoException}, the one
 * Throwable the host's per-plugin error boundary lets through. Paired with the
 * host now running `*.deleting` → DELETE → `*.deleted` inside one transaction
 * for tenants/OUs/roles, this lets a plugin refuse a deletion — or fail its own
 * cleanup — and have the deletion rolled back rather than silently committed,
 * WC-713) →
 * 1.16 (read-only permission resolution:
 * {@see \Whity\Sdk\Rbac\PermissionResolver}, the host-registered contract a
 * plugin resolves from the service container to ask the SAME authorization
 * question the host's RBAC middleware answers — membership gating, OU-ancestor
 * inheritance, role hierarchy, live delegations, catalogue validation — instead
 * of re-deriving it in hand-written SQL and drifting from what is actually
 * enforced, WC-712) →
 * 1.17 (resource-scoped resolution: `$resourceType`/`$resourceId` on
 * {@see \Whity\Sdk\Rbac\PermissionResolver}, so a plugin can ask "may this
 * caller act on THIS record?" — additive, omitting them preserves the previous
 * tenant-wide answer exactly, WC-712 §2) →
 * 1.18 (plugin-declared resource types:
 * {@see \Whity\Sdk\Rbac\PluginResourceTypesInterface}, the optional declaration
 * that lets a plugin address a role grant at one of its own records instead of
 * keeping a private grant table, WC-712 §2) →
 * 1.19 (status-page health probes: {@see \Whity\Sdk\Health\PluginHealthProbesInterface},
 * {@see \Whity\Sdk\Health\HealthProbeDefinition} and
 * {@see \Whity\Sdk\Health\ProbeResult} — a plugin contributes a probe for a
 * dependency it owns and the host samples and publishes it beside its own
 * database/queue/scheduler/render probes, instead of the plugin inventing a
 * second status surface nobody watches. Host-namespaced under the plugin name,
 * so probes cannot collide or shadow a core one) →
 * 1.20 (plugin-owned data types — Door 2 of the schema-driven admin surface:
 * {@see \Whity\Sdk\Tenant\PluginTablesInterface} declares WHICH tables a plugin
 * owns (the host stamps WHO owns them, from the plugin name the loader holds),
 * {@see \Whity\Sdk\DataType\PluginDataTypesInterface} declares a record's
 * lifecycle and its reference graph as DATA, and
 * {@see \Whity\Sdk\DataType\DataTypeGuard} exposes the host's own guard
 * evaluation so a plugin's custom delete route enforces through the same path
 * the generated one does. Trashed and retired are kept distinct: reversible-and
 * -pending-removal versus permanent-and-closed-to-new-references, WC-723) ->
 * 1.21 (plugin-declared SETTINGS: {@see \Whity\Sdk\Settings\PluginSettingsInterface},
 * by which a plugin contributes typed, validated, defaulted configuration keys to
 * the HOST'S own settings store instead of rebuilding one as a private table with
 * no declared keys and no validation. Host-namespaced under the plugin name the
 * loader supplies, resolved through the same per-tenant ?? global ?? default chain
 * as a core key, and published on the host's own settings screens only on an
 * explicit `admin => true` opt-in — those screens are gated on core permissions,
 * which are not the plugin's. Secret-shaped declarations are REFUSED rather than
 * downgraded to a readable string, #713 item 1).
 * Breaking changes require a new major version.
 */
final class Sdk
{
    /** The SDK contract version shipped by this package. */
    public const VERSION = '1.21.0';

    /**
     * Static identity only — never instantiated.
     */
    private function __construct()
    {
    }
}
