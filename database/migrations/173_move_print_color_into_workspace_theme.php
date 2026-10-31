<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * The workspace print colour becomes the workspace's document theme (editor
 * M4d; DOCUMENT-THEMES.md 4.1, no-legacy rule).
 *
 * `documents.print_color` was the one document look a workspace could set. A
 * theme now carries it (`tokens.accent`), so for every workspace whose print
 * colour is set — its own override, or the global default it inherits — this
 * creates the workspace theme `workspace` with that accent and makes it the
 * workspace default (`documents.theme = tenant.workspace`). A workspace with
 * no print colour gets nothing: its documents follow the workspace main colour
 * (2.8), as the empty print colour already did through the brand colour.
 *
 * The setting is then removed everywhere. Templates published before this
 * printed with the print colour and keep printing with it: they have no frozen
 * theme, so they resolve to the workspace default, which now holds the same
 * colour.
 *
 * Idempotent (the theme and the default are inserted only when absent). down()
 * puts the colour back as a workspace override and removes the `workspace`
 * themes and defaults it finds, which can't tell one made here from one a
 * person made later with the same key.
 */
class MovePrintColorIntoWorkspaceTheme
{
    private const OLD_KEY = 'documents.print_color';
    private const DEFAULT_KEY = 'documents.theme';
    private const THEME_KEY = 'workspace';
    private const THEME_REF = 'tenant.workspace';

    public static function up(Database $db): void
    {
        $global = self::value($db->query(
            'SELECT value FROM app_settings WHERE setting_key = :key',
            [':key' => self::OLD_KEY]
        )->fetch());

        $tenants = $db->query('SELECT id FROM tenants WHERE id <> 0')->fetchAll();
        foreach ($tenants === false ? [] : $tenants as $tenant) {
            $tenantId = (int) $tenant['id'];
            $own = $db->query(
                'SELECT value FROM tenant_settings WHERE tenant_id = :tenant_id AND setting_key = :key',
                [':tenant_id' => $tenantId, ':key' => self::OLD_KEY]
            )->fetch();
            $color = $own === false ? $global : self::value($own);
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                continue;
            }

            $db->query(
                "INSERT INTO document_themes (tenant_id, theme_key, name, name_ar, extends, tokens, version, created_at, updated_at)
                 VALUES (:tenant_id, :key, 'Workspace', 'مساحة العمل', 'whity.standard', :tokens, 1, NOW(), NOW())
                 ON CONFLICT (tenant_id, theme_key) DO NOTHING",
                [
                    ':tenant_id' => $tenantId,
                    ':key'       => self::THEME_KEY,
                    ':tokens'    => json_encode(['accent' => strtolower($color)], JSON_THROW_ON_ERROR),
                ]
            );
            $db->query(
                'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
                 VALUES (:tenant_id, :key, :value, NOW())
                 ON CONFLICT (tenant_id, setting_key) DO NOTHING',
                [':tenant_id' => $tenantId, ':key' => self::DEFAULT_KEY, ':value' => self::THEME_REF]
            );
        }

        $db->query('DELETE FROM tenant_settings WHERE setting_key = :key', [':key' => self::OLD_KEY]);
        $db->query('DELETE FROM app_settings WHERE setting_key = :key', [':key' => self::OLD_KEY]);
    }

    public static function down(Database $db): void
    {
        $themes = $db->query(
            'SELECT tenant_id, tokens FROM document_themes WHERE theme_key = :key',
            [':key' => self::THEME_KEY]
        )->fetchAll();
        foreach ($themes === false ? [] : $themes as $theme) {
            $tokens = json_decode((string) $theme['tokens'], true);
            $color = is_array($tokens) && is_string($tokens['accent'] ?? null) ? $tokens['accent'] : '';
            if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color) !== 1) {
                continue;
            }
            $db->query(
                'INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at)
                 VALUES (:tenant_id, :key, :value, NOW())
                 ON CONFLICT (tenant_id, setting_key) DO NOTHING',
                [':tenant_id' => (int) $theme['tenant_id'], ':key' => self::OLD_KEY, ':value' => $color]
            );
        }

        $db->query(
            'DELETE FROM tenant_settings WHERE setting_key = :key AND value = :value',
            [':key' => self::DEFAULT_KEY, ':value' => self::THEME_REF]
        );
        $db->query('DELETE FROM document_themes WHERE theme_key = :key', [':key' => self::THEME_KEY]);
    }

    /** @param array<string, mixed>|false $row */
    private static function value(array|false $row): string
    {
        return $row === false ? '' : trim((string) ($row['value'] ?? ''));
    }
}
