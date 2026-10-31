<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * Both production entry points must register the issuing service and the
 * template slots (WHIT-742, DOCUMENT-AUTOMATION.md 3.5).
 *
 * Workspace automations run in `queue:work`, and plugin listeners in
 * `events:relay`. Registered over HTTP only, issuing from a slot would work in
 * a request and throw in the worker, which is exactly where an automation's
 * `issue_document` step runs: the divergence bug class #717 and #724 paid for.
 *
 * public/index.php and BaseCommand::setupKernel() can't run in a unit test,
 * so this pins the wiring by reading their source, as
 * SequenceAllocatorEntryPointWiringTest does.
 */
final class DocumentIssuingEntryPointWiringTest extends TestCase
{
    private const ENTRY_POINTS = ['public/index.php', 'src/Cli/Commands/BaseCommand.php'];

    public function testBothEntryPointsRegisterTheIssuingService(): void
    {
        foreach (self::ENTRY_POINTS as $relative) {
            self::assertMatchesRegularExpression(
                '/register_service\(\s*\\\\?Whity\\\\Core\\\\Document\\\\Issuing\\\\TemplateIssuing::class/',
                $this->read($relative),
                "{$relative} must register TemplateIssuing, or an automation issuing a document works in one process and throws in the other."
            );
        }
    }

    public function testBothEntryPointsRegisterTheSlotsFromTheWorkspacesPlugins(): void
    {
        foreach (self::ENTRY_POINTS as $relative) {
            $source = $this->read($relative);
            self::assertMatchesRegularExpression(
                '/register_service\(\s*\\\\?Whity\\\\Core\\\\Document\\\\Slots\\\\TemplateSlotRegistry::class/',
                $source,
                "{$relative} must register the slot registry."
            );
            self::assertMatchesRegularExpression(
                '/new\s+\\\\?Whity\\\\Core\\\\Document\\\\Slots\\\\TemplateSlotRegistry\(\s*(\$liveManifests|static fn \(int \$tenantId\): array => \$tenantManifestHost->manifestsFor\(\$tenantId\))/',
                $source,
                "{$relative} must read slots from the workspace's live plugins."
            );
        }
    }

    public function testBothEntryPointsServeWorkspacePluginRecordsToTemplates(): void
    {
        foreach (self::ENTRY_POINTS as $relative) {
            self::assertMatchesRegularExpression(
                '/addTenantSource\(\s*static fn \(int \$tenantId\): array => \\\\?Whity\\\\Core\\\\Document\\\\Fields\\\\TenantRecordFieldProvider::forManifests\(/',
                $this->read($relative),
                "{$relative} must add the workspace record types to the field registry, or a template for a workspace plugin's record can't be issued there."
            );
        }
    }

    public function testBothEntryPointsGiveTheLoaderTheDocumentRegistriesBeforeLoading(): void
    {
        foreach (self::ENTRY_POINTS as $relative) {
            self::assertMatchesRegularExpression(
                '/\$pluginLoader->setDocumentRegistries\(\$templateSlotRegistry, \$documentFieldRegistry\);\s*\$pluginLoader->load\(\);/',
                $this->read($relative),
                "{$relative} must hand the loader the slot and field registries before load(), or code plugins' slots never register there."
            );
        }
    }

    public function testBothEntryPointsRegisterTheSdkDocumentsService(): void
    {
        foreach (self::ENTRY_POINTS as $relative) {
            self::assertMatchesRegularExpression(
                '/register_service\(\s*\\\\?Whity\\\\Sdk\\\\Documents\\\\Documents::class,\s*new \\\\?Whity\\\\Core\\\\Document\\\\Issuing\\\\SdkDocuments\(/',
                $this->read($relative),
                "{$relative} must register the SDK's Documents, or a plugin resolving it gets an exception."
            );
        }
    }

    public function testTheWorkerReadsAFreshSetOfPluginsForEachIssue(): void
    {
        // A worker lives for many jobs; a manifest cached from the last job
        // would issue from a plugin the workspace has since switched off.
        self::assertMatchesRegularExpression(
            '/\$tenantManifestHost->resetRequest\(\);\s*return \$tenantManifestHost->manifestsFor\(\$tenantId\);/',
            $this->read('src/Cli/Commands/BaseCommand.php'),
        );
    }

    private function read(string $relative): string
    {
        $source = file_get_contents(__DIR__ . '/../../' . $relative);
        self::assertIsString($source, "Unable to read {$relative}");

        return $source;
    }
}
