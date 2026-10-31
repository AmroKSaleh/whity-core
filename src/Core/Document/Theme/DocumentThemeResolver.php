<?php

declare(strict_types=1);

namespace Whity\Core\Document\Theme;

use Whity\Core\Branding\TenantTheme;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Resolves the theme a document prints with (editor M4d; E.44,
 * DOCUMENT-THEMES.md 3.3). One resolver serves the editor (through the API)
 * and printing, so the page view and the PDF can't drift.
 *
 * Which theme: the document's own `theme.ref`, else the workspace default for
 * its kind (`documents.theme_by_kind`), else the workspace default
 * (`documents.theme`), else Whity's standard theme. A ref that names no theme
 * the workspace has falls back down that list and says so.
 *
 * Its values, each layer winning over the one before:
 *   Whity's standard theme, with the workspace main colour (2.8) as accent
 *   └ the workspace theme (one level; it extends whity.standard)
 *       └ the document's overrides (accent and letterhead only)
 *
 * The accent must read on white (4.5:1); if it doesn't it is darkened and
 * `adjustments` says what changed. `accentOnDark` is derived from it unless a
 * theme sets one (2.8b).
 *
 * A published version freezes the result (`theme_resolved`), and printing it
 * never resolves again.
 */
final class DocumentThemeResolver
{
    public function __construct(
        private readonly SettingsService $settings,
        // Without one, only Whity's standard theme resolves (tests, tools).
        private readonly ?DocumentThemeRepository $themes = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $theme the document's `{ ref, overrides }`
     * @return array{ref: string, version: int, hash: string, tokens: array<string, mixed>, sources: array<string, string>, adjustments: list<array<string, string>>}
     */
    public function resolve(int $tenantId, ?array $theme = null, ?string $kind = null): array
    {
        $settings = $this->settings->effective($tenantId);
        $adjustments = [];

        $candidates = [];
        if (is_string($theme['ref'] ?? null) && $theme['ref'] !== '') {
            $candidates[] = $theme['ref'];
        }
        $byKind = DocumentThemeRef::byKind((string) ($settings[SettingsRegistry::DOCUMENTS_THEME_BY_KIND] ?? ''));
        if ($kind !== null && isset($byKind[$kind])) {
            $candidates[] = $byKind[$kind];
        }
        $candidates[] = (string) ($settings[SettingsRegistry::DOCUMENTS_THEME] ?? '');

        $ref = DocumentThemeRef::STANDARD;
        $row = null;
        foreach ($candidates as $candidate) {
            if ($candidate === DocumentThemeRef::STANDARD) {
                break;
            }
            $key = DocumentThemeRef::tenantKey($candidate);
            $found = $key !== null && $tenantId !== 0 ? $this->themes?->find($tenantId, $key) : null;
            if ($found !== null) {
                $ref = $candidate;
                $row = $found;
                break;
            }
            if ($candidate !== '') {
                $adjustments[] = [
                    'code' => 'theme_missing',
                    'token' => 'ref',
                    'from' => $candidate,
                    'message' => "The theme {$candidate} isn't available here, so the next default is used.",
                ];
            }
        }

        $main = trim((string) ($settings[SettingsRegistry::BRANDING_MAIN_COLOR] ?? ''));
        $hasMain = preg_match('/^#[0-9A-Fa-f]{6}$/', $main) === 1;
        $tokens = ['accent' => $hasMain ? strtolower($main) : TenantTheme::whityBlue()] + DocumentThemeTokens::STANDARD;
        $sources = [
            'accent' => $hasMain ? 'branding.main_color' : DocumentThemeRef::STANDARD,
            'accentUse' => DocumentThemeRef::STANDARD,
            'letterhead.logo' => DocumentThemeRef::STANDARD,
            'letterhead.lines' => DocumentThemeRef::STANDARD,
        ];

        if ($row !== null) {
            if ($row['extends'] !== null && $row['extends'] !== DocumentThemeRef::STANDARD) {
                // Plugin themes come with M7: until then the base is Whity's own.
                $adjustments[] = [
                    'code' => 'base_missing',
                    'token' => 'extends',
                    'from' => (string) $row['extends'],
                    'message' => "The theme {$row['extends']} that {$ref} extends isn't available, so it extends Whity's standard theme.",
                ];
            }
            /** @var array<string, mixed> $own */
            $own = $row['tokens'];
            $tokens = DocumentThemeTokens::merge($tokens, $own);
            $sources = self::mark($sources, $own, $ref);
        }

        $overrides = is_array($theme['overrides'] ?? null) ? $theme['overrides'] : [];
        if ($overrides !== [] && DocumentThemeTokens::errors($overrides, overridesOnly: true) === []) {
            /** @var array<string, mixed> $overrides */
            $overrides = DocumentThemeTokens::normalize($overrides);
            $tokens = DocumentThemeTokens::merge($tokens, $overrides);
            $sources = self::mark($sources, $overrides, 'document');
        }

        $readable = TenantTheme::readableOnWhite((string) $tokens['accent']);
        if ($readable['adjusted']) {
            $adjustments[] = [
                'code' => 'accent_darkened',
                'token' => 'accent',
                'from' => (string) $tokens['accent'],
                'to' => $readable['color'],
                'message' => 'The print colour was darkened to reach 4.5:1 on white.',
            ];
            $tokens['accent'] = $readable['color'];
        }
        // Derived unless a theme set one, and derived again when the document
        // changed the print colour after it: a theme's dark title matches its
        // own accent, not the document's (screen only, never printed).
        if (!is_string($tokens['accentOnDark'] ?? null) || $sources['accent'] === 'document') {
            $tokens['accentOnDark'] = TenantTheme::onDark((string) $tokens['accent']);
            $sources['accentOnDark'] = $sources['accent'];
        }

        $tokens = self::ordered($tokens);
        ksort($sources);
        $version = $row !== null ? (int) $row['version'] : 0;

        return [
            'ref' => $ref,
            'version' => $version,
            'hash' => hash('sha256', json_encode([$ref, $version, $tokens], JSON_THROW_ON_ERROR)),
            'tokens' => $tokens,
            'sources' => $sources,
            'adjustments' => $adjustments,
        ];
    }

    /**
     * @param array<string, string> $sources
     * @param array<string, mixed> $layer
     * @return array<string, string>
     */
    private static function mark(array $sources, array $layer, string $source): array
    {
        foreach ($layer as $key => $value) {
            if ($key === 'letterhead' && is_array($value)) {
                foreach (array_keys($value) as $sub) {
                    $sources["letterhead.{$sub}"] = $source;
                }
                continue;
            }
            $sources[(string) $key] = $source;
        }

        return $sources;
    }

    /**
     * The tokens in a fixed order, so the hash of an unchanged theme never moves.
     *
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private static function ordered(array $tokens): array
    {
        /** @var array<string, mixed> $letterhead */
        $letterhead = is_array($tokens['letterhead'] ?? null) ? $tokens['letterhead'] : [];

        return [
            'accent' => $tokens['accent'],
            'accentOnDark' => $tokens['accentOnDark'],
            'accentUse' => $tokens['accentUse'],
            'letterhead' => [
                'logo' => $letterhead['logo'] ?? 'wide',
                'lines' => array_values((array) ($letterhead['lines'] ?? [])),
            ],
        ];
    }
}
