<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * SDK identity (v1.12).
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
 * WC-247).
 * Breaking changes require a new major version.
 */
final class Sdk
{
    /** The SDK contract version shipped by this package. */
    public const VERSION = '1.13.0';

    /**
     * Static identity only — never instantiated.
     */
    private function __construct()
    {
    }
}
