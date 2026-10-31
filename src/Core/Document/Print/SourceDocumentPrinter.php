<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Storage\StorageException;

/**
 * What an editor template prints with, for one set of field values: the
 * printer that reads records, charts and images for the person asking, and
 * the flowing payload with the tenant's letterhead and print colour.
 *
 * Shared by the editor's own render and label routes and by issuing a
 * document (`POST /api/documents`, a correction), so all of them print a
 * template the same way.
 */
final class SourceDocumentPrinter
{
    private PrintLabels $printLabels;

    public function __construct(
        private readonly DocumentFieldRegistry $fields,
        private readonly DocumentEmbedRegistry $embeds,
        private readonly SettingsService $settings,
        private readonly ?DocumentSourceFileStore $files = null,
        ?PrintLabels $printLabels = null,
        // The tenant's logo, for the PDF's letterhead (6.2, WHIT-734).
        private readonly ?LetterheadLogo $logo = null,
    ) {
        $this->printLabels = $printLabels ?? new PrintLabels();
    }

    /**
     * @param callable(string): bool $hasPermission
     * @param array<string, ?string> $values field path => value
     */
    public function printer(int $tenantId, int $callerId, callable $hasPermission, int $sourceId, array $values, ?string $recordType): WhityDocumentPrinter
    {
        return new WhityDocumentPrinter(
            $values,
            $this->fields->requiredPaths($tenantId, $recordType),
            function (array $ref) use ($tenantId, $callerId, $hasPermission): ?array {
                return $this->embeds->recordProvider($ref['type'])?->resolve($tenantId, $callerId, $hasPermission, $ref['id']);
            },
            function (string $key) use ($tenantId, $callerId, $hasPermission): ?array {
                return $this->embeds->chartSource($key)?->load($tenantId, $callerId, $hasPermission, []);
            },
            fn (string $fileId): ?string => $this->imageData($tenantId, $sourceId, $fileId),
            $this->printLabels,
        );
    }

    /**
     * The flowing payload for one document.
     *
     * @param array{title: string, lang: string, page: mixed, blocks: mixed} $doc
     * @param array<string, ?string>                                        $values
     * @return array<string, mixed>
     */
    public function payload(WhityDocumentPrinter $printer, int $tenantId, array $doc, ?string $recordLabel, array $values): array
    {
        $page = is_array($doc['page']) ? $doc['page'] : [];

        return $printer->payload(
            ['title' => $doc['title'], 'lang' => $doc['lang'], 'page' => $page, 'blocks' => $doc['blocks']],
            ($page['letterhead'] ?? true) === false ? null : $this->printedLetterhead($tenantId),
            $recordLabel !== null && $recordLabel !== '' ? $recordLabel : null,
            $values['today'] ?? null,
            $this->accent($tenantId),
        );
    }

    /** The tenant's print colour (documents.print_color), when it is a hex colour. */
    public function accent(int $tenantId): ?string
    {
        $value = trim((string) ($this->settings->effective($tenantId)[SettingsRegistry::DOCUMENTS_PRINT_COLOR] ?? ''));

        return preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1 ? $value : null;
    }

    /**
     * The tenant's letterhead as it invoices: its name, address and tax number
     * (stage 6). The logo is the editor's to show; Whity Render prints the name.
     *
     * @return array{name: string, lines: list<string>}
     */
    public function letterhead(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $name = trim((string) ($effective[SettingsRegistry::BILLING_SELLER_NAME] ?? ''));
        if ($name === '') {
            $name = trim((string) ($effective[SettingsRegistry::SITE_NAME] ?? ''));
        }
        $lines = [];
        $address = trim((string) ($effective[SettingsRegistry::BILLING_SELLER_ADDRESS] ?? ''));
        if ($address !== '') {
            $lines[] = preg_replace('/\s*\R\s*/', ', ', $address) ?? $address;
        }
        $tax = trim((string) ($effective[SettingsRegistry::BILLING_SELLER_TAX_ID] ?? ''));
        if ($tax !== '') {
            $lines[] = $tax;
        }

        return ['name' => $name, 'lines' => $lines];
    }

    /**
     * The letterhead as it prints: the same name and lines, plus the tenant's
     * logo (6.2) when it has one Whity Render can draw. The editor shows the
     * logo itself.
     *
     * @return array{name: string, lines: list<string>, logo: string|null}
     */
    public function printedLetterhead(int $tenantId): array
    {
        return $this->letterhead($tenantId) + ['logo' => $this->logo?->dataUri($tenantId)];
    }

    private function imageData(int $tenantId, int $sourceId, string $fileId): ?string
    {
        if ($this->files === null || !ctype_digit($fileId)) {
            return null;
        }
        $file = $this->files->find((int) $fileId, $sourceId, $tenantId);
        if ($file === null || !in_array($file['content_type'], ['image/png', 'image/jpeg'], true)) {
            return null;
        }
        try {
            return 'data:' . $file['content_type'] . ';base64,' . base64_encode($this->files->bytes((string) $file['storage_key']));
        } catch (StorageException) {
            return null;
        }
    }
}
