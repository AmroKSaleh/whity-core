<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Print\LetterheadLogo;
use Whity\Core\Document\Print\PrintLabels;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Qr\DocumentQrScanRepository;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrTokenRepository;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceClient;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Document\Theme\DocumentThemeRepository;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Http\FrontendUrls;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Store\DatabaseSharedStore;
use Whity\Storage\StorageDriverFactory;
use Whity\Storage\TenantRoutingStorageDriver;
use Whity\Storage\TenantStorageConfigRepository;
use Whity\Storage\TenantStorageResolver;

/**
 * The issuing service outside a request (WHIT-742, DOCUMENT-AUTOMATION.md
 * 3.5): `queue:work` and `events:relay` run automations and plugin jobs that
 * issue documents, so they need the same {@see TemplateIssuing} the REST route
 * uses, built from the same parts `public/index.php` wires: the render
 * service, per-tenant storage, the meters, the QR code, the letterhead and
 * the workspace's themes.
 *
 * Built only from the environment and the connection, as the CLI kernel has
 * no request. A template's visibility is checked exactly as the editor checks
 * it ({@see DocumentAccessPolicy} with the actor's units).
 */
final class WorkerDocumentIssuing
{
    private function __construct()
    {
    }

    /** @param array<string, mixed> $env */
    public static function build(
        PDO $pdo,
        SettingsService $settings,
        HookManager $hooks,
        RoleChecker $roleChecker,
        ResourceTypeRegistry $resourceTypes,
        DocumentFieldRegistry $fields,
        DocumentEmbedRegistry $embeds,
        TemplateSlotRegistry $slots,
        array $env,
        string $storageRoot,
    ): TemplateIssuing {
        $sources = new DocumentSourceRepository($pdo);
        $policy = new DocumentAccessPolicy();
        $reach = new OuReachResolver($pdo, new ResourceRoleAssignmentRepository($pdo, $resourceTypes));

        return new TemplateIssuing(
            // Built on the first issue: a command that never issues (migrate,
            // a backup) must not need the render service, storage or the key.
            static fn (): DocumentIssuing => self::issuing($pdo, $settings, $hooks, $fields, $embeds, $env, $storageRoot),
            $fields,
            $settings,
            static function (int $id, int $tenantId, int $profileId) use ($sources, $policy, $reach, $roleChecker): ?array {
                $row = $sources->findById($id, $tenantId);
                if ($row === null || !$policy->canView(
                    $row,
                    $profileId,
                    ScopedPermissionSet::forProfile($roleChecker, $profileId, $tenantId),
                    $reach->reachFor($tenantId, $profileId),
                )) {
                    return null;
                }

                return $row;
            },
            $slots,
        );
    }

    /** @param array<string, mixed> $env */
    private static function issuing(
        PDO $pdo,
        SettingsService $settings,
        HookManager $hooks,
        DocumentFieldRegistry $fields,
        DocumentEmbedRegistry $embeds,
        array $env,
        string $storageRoot,
    ): DocumentIssuing {
        $entitlements = new EntitlementService(new TenantEntitlementRepository($pdo));
        $defaultStorage = StorageDriverFactory::fromSettings($settings, $env, $storageRoot);
        $storage = new TenantRoutingStorageDriver($defaultStorage, new TenantStorageResolver(
            $defaultStorage,
            new TenantStorageConfigRepository($pdo),
            $entitlements,
            EncryptedSecretStore::fromEnv($env),
        ));

        $documents = new DocumentRepository($pdo);
        $artifacts = new DocumentArtifactRepository($pdo);
        $urls = FrontendUrls::fromEnv();

        return new DocumentIssuing(
            new SourceDocumentPrinter(
                $fields,
                $embeds,
                $settings,
                new DocumentSourceFileStore($storage, $pdo),
                new PrintLabels(),
                new LetterheadLogo($settings, $storage),
                new DocumentThemeRepository($pdo),
            ),
            new DocumentSourceVersionRepository($pdo),
            new DocumentIssuer($pdo, $documents, $artifacts, new DocumentArtifactStore($storage)),
            $settings,
            new FlowDocumentRenderer($settings, new RenderServiceClient(
                (string) ($env['RENDER_SERVICE_URL'] ?? 'http://render:8130'),
                (string) ($env['RENDER_SHARED_SECRET'] ?? ''),
                (int) ($env['RENDER_TIMEOUT_SECONDS'] ?? 30),
            )),
            new DocumentQrService(
                $pdo,
                new DocumentQrTokenRepository($pdo),
                new DocumentQrScanRepository($pdo),
                $urls->base(),
                $urls->link(FrontendUrls::DOCUMENT_VERIFY),
            ),
            new MeterService(new DatabaseSharedStore($pdo), $entitlements, $settings),
            $hooks,
            $documents,
            $artifacts,
        );
    }
}
