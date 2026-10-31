<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Theme\DocumentThemeRepository;
use Whity\Core\Document\Theme\DocumentThemeResolver;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Storage\StorageException;

/**
 * What an editor template prints with, for one set of field values: the
 * printer that reads records, charts and images for the person asking, and
 * the flowing payload with the tenant's letterhead in the document's theme
 * (editor M4d: its print colour, logo and letterhead lines).
 *
 * Shared by the editor's own render and label routes and by issuing a
 * document (`POST /api/documents`, a correction), so all of them print a
 * template the same way.
 */
final class SourceDocumentPrinter
{
    private PrintLabels $printLabels;

    private DocumentThemeResolver $themes;

    public function __construct(
        private readonly DocumentFieldRegistry $fields,
        private readonly DocumentEmbedRegistry $embeds,
        private readonly SettingsService $settings,
        private readonly ?DocumentSourceFileStore $files = null,
        ?PrintLabels $printLabels = null,
        // The tenant's logo, for the PDF's letterhead (6.2, WHIT-734).
        private readonly ?LetterheadLogo $logo = null,
        // The workspace's themes (editor M4d). Without them, documents print
        // in Whity's standard theme with the workspace main colour.
        ?DocumentThemeRepository $themes = null,
    ) {
        $this->printLabels = $printLabels ?? new PrintLabels();
        $this->themes = new DocumentThemeResolver($settings, $themes);
    }

    /**
     * The theme a document prints with now: its own `{ ref, overrides }`, else
     * the workspace defaults. A published version prints with the theme frozen
     * when it was published instead ({@see self::versionTheme()}).
     *
     * @return array{ref: string, version: int, hash: string, tokens: array<string, mixed>, sources: array<string, string>, adjustments: list<array<string, string>>}
     */
    public function theme(int $tenantId, mixed $documentTheme, ?string $kind): array
    {
        /** @var array<string, mixed>|null $own */
        $own = is_array($documentTheme) ? $documentTheme : null;

        return $this->themes->resolve($tenantId, $own, $kind);
    }

    /**
     * The theme a published version prints with: the one frozen at publish, so
     * a theme changed since reaches it only when it is published again. A
     * version published before themes existed has none, and resolves now.
     *
     * @param array<string, mixed> $version a document_source_versions row
     * @return array<string, mixed>
     */
    public function versionTheme(int $tenantId, array $version, ?string $kind): array
    {
        $frozen = $version['theme_resolved'] ?? null;
        if (is_array($frozen) && is_array($frozen['tokens'] ?? null)) {
            return $frozen;
        }

        return $this->theme($tenantId, $version['theme'] ?? null, $kind);
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
     * @param array<string, mixed>                                          $theme  a resolved theme
     * @return array<string, mixed>
     */
    public function payload(WhityDocumentPrinter $printer, int $tenantId, array $doc, ?string $recordLabel, array $values, array $theme): array
    {
        $page = is_array($doc['page']) ? $doc['page'] : [];
        /** @var array<string, mixed> $tokens */
        $tokens = is_array($theme['tokens'] ?? null) ? $theme['tokens'] : [];

        return $printer->payload(
            ['title' => $doc['title'], 'lang' => $doc['lang'], 'page' => $page, 'blocks' => $doc['blocks']],
            ($page['letterhead'] ?? true) === false ? null : $this->printedLetterhead($tenantId, $tokens),
            $recordLabel !== null && $recordLabel !== '' ? $recordLabel : null,
            $values['today'] ?? null,
            $tokens,
        );
    }

    /**
     * The tenant's letterhead lines as it invoices (stage 6), each by its
     * theme name: `address` and `taxId` from the seller settings. `phone` and
     * `email` have no source yet (WHIT-788 adds the company's own keys), so a
     * theme that lists them prints nothing for them.
     *
     * @return array{name: string, parts: array{address: ?string, taxId: ?string, phone: ?string, email: ?string}}
     */
    public function letterheadParts(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);
        $name = trim((string) ($effective[SettingsRegistry::BILLING_SELLER_NAME] ?? ''));
        if ($name === '') {
            $name = trim((string) ($effective[SettingsRegistry::SITE_NAME] ?? ''));
        }
        $address = trim((string) ($effective[SettingsRegistry::BILLING_SELLER_ADDRESS] ?? ''));
        $tax = trim((string) ($effective[SettingsRegistry::BILLING_SELLER_TAX_ID] ?? ''));

        return ['name' => $name, 'parts' => [
            'address' => $address !== '' ? (preg_replace('/\s*\R\s*/', ', ', $address) ?? $address) : null,
            'taxId' => $tax !== '' ? $tax : null,
            'phone' => null,
            'email' => null,
        ]];
    }

    /**
     * The tenant's letterhead: its name and the lines the theme lists, in its
     * order (default: address, then tax number). The editor shows the logo
     * itself.
     *
     * @param array<string, mixed> $tokens a resolved theme's tokens
     * @return array{name: string, lines: list<string>}
     */
    public function letterhead(int $tenantId, array $tokens = []): array
    {
        $letterhead = $this->letterheadParts($tenantId);
        $wanted = is_array($tokens['letterhead'] ?? null) ? ($tokens['letterhead']['lines'] ?? null) : null;
        $lines = [];
        foreach (is_array($wanted) ? $wanted : ['address', 'taxId'] as $part) {
            $value = is_string($part) ? ($letterhead['parts'][$part] ?? null) : null;
            if (is_string($value) && $value !== '') {
                $lines[] = $value;
            }
        }

        return ['name' => $letterhead['name'], 'lines' => $lines];
    }

    /**
     * The letterhead as it prints: the same name and lines, plus the logo the
     * theme chooses (6.2) when the tenant has one Whity Render can draw.
     *
     * @param array<string, mixed> $tokens a resolved theme's tokens
     * @return array{name: string, lines: list<string>, logo: string|null}
     */
    public function printedLetterhead(int $tenantId, array $tokens = []): array
    {
        $logo = is_array($tokens['letterhead'] ?? null) ? ($tokens['letterhead']['logo'] ?? 'wide') : 'wide';

        return $this->letterhead($tenantId, $tokens) + ['logo' => $this->logo?->dataUri($tenantId, is_string($logo) ? $logo : 'wide')];
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
