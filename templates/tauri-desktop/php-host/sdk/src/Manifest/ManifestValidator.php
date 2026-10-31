<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use Whity\Sdk\Frontend\Blocks\BlockValidator;

/**
 * The whole-manifest validator for `plugin.json` (SDK 1.47, WHIT-758).
 *
 * WHY A WHOLE-MANIFEST CHECK when every registry already validates its own
 * piece: a PHP plugin that declares a bad permission loses that permission and
 * keeps loading, which is the right posture for code an author can read in one
 * file. A manifest is different. It is data, it may be written by a person
 * clicking in a builder or by an agent, and its parts point at each other (a
 * role grants a permission the manifest declares; a screen is gated by one; a
 * data type lives in a table the manifest owns). Dropping one piece silently
 * would leave the others pointing at nothing, so a manifest is checked as a
 * whole and REFUSED as a whole: it never half-loads.
 *
 * WHAT THIS DOES NOT CHECK, deliberately: what the host checks again at
 * registration anyway — block trees are run through {@see BlockValidator} here
 * for the author's sake, but whether a data-bound block's `source` is a route
 * the plugin owns is a host question, and so is the full data-type contract.
 * Those keep their one implementation in the host; this class only refuses
 * what it can see is wrong from the document alone.
 *
 * UNKNOWN KEYS ARE REFUSED, at every level. A manifest that declares something
 * this SDK cannot run (a `records` section before the record store exists, a
 * misspelt `screns`) would otherwise be accepted and silently ignored, and the
 * author would find out when the screen never appeared. New sections arrive in
 * later minors and widen {@see self::TOP_LEVEL_KEYS}.
 *
 * Pure and static: no I/O, no host types, so a builder, a CLI and a test can
 * all call it on an array.
 */
final class ManifestValidator
{
    /** The format identifier every manifest carries in `manifest`. */
    public const FORMAT = 'whity.plugin/1';

    /** @var list<string> */
    public const TOP_LEVEL_KEYS = [
        'manifest', 'plugin', 'requires', 'permissions', 'roles', 'screens', 'tables', 'dataTypes', 'limits', 'records',
        'automations', 'tests', 'documents',
    ];

    private const NAME_PATTERN = '/^[A-Za-z][A-Za-z0-9_]{0,63}$/';
    private const SEMVER_PATTERN = '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/';
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/';
    private const ROLE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const SCREEN_ID_PATTERN = '/^[a-z][a-z0-9-]*$/';
    private const TABLE_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';
    private const DATA_TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const ICON_PATTERN = '/^[a-z0-9-]{1,64}$/';
    private const MAX_PERMISSIONS = 200;
    private const MAX_ROLES = 50;
    private const MAX_SCREENS = 50;
    private const MAX_LABEL = 200;
    private const MAX_DESCRIPTION = 500;

    /** Static helper only — never instantiated. */
    private function __construct()
    {
    }

    /**
     * Validate a decoded manifest.
     *
     * @param array<mixed> $manifest The decoded `plugin.json`.
     * @return array{ok: bool, errors: list<string>} Each error names its path,
     *         e.g. `roles.baker.permissions[1]: unknown permission "x:y"`.
     */
    public static function validate(array $manifest): array
    {
        $errors = [];

        foreach (array_keys($manifest) as $key) {
            if (!in_array($key, self::TOP_LEVEL_KEYS, true)) {
                $errors[] = self::unknownKey('', (string) $key);
            }
        }

        if (($manifest['manifest'] ?? null) !== self::FORMAT) {
            $errors[] = 'manifest: must be "' . self::FORMAT . '"';
        }

        $pluginName = self::checkPlugin($manifest['plugin'] ?? null, $errors);
        self::checkRequires($manifest['requires'] ?? null, $errors);
        $permissions = self::checkPermissions($manifest['permissions'] ?? null, $errors);
        self::checkRoles($manifest['roles'] ?? null, $permissions, $errors);
        self::checkScreens($manifest['screens'] ?? null, $permissions, $errors);
        $tables = self::checkTables($manifest['tables'] ?? null, $errors);
        self::checkDataTypes($manifest['dataTypes'] ?? null, $tables, $permissions, $errors);
        self::checkLimits($manifest['limits'] ?? null, $pluginName, $errors);
        RecordTypeValidator::check(
            $manifest['records'] ?? null,
            $permissions,
            static function (mixed $label, string $path, int $max, bool $required, array &$errs): void {
                self::checkLabel($label, $path, $max, $required, $errs);
            },
            $errors,
        );
        AutomationValidator::check(
            $manifest['automations'] ?? null,
            $manifest['records'] ?? null,
            is_array($manifest['roles'] ?? null) ? array_map('strval', array_keys($manifest['roles'])) : [],
            static function (mixed $label, string $path, int $max, bool $required, array &$errs): void {
                self::checkLabel($label, $path, $max, $required, $errs);
            },
            $errors,
            $manifest['documents'] ?? null,
        );
        ManifestTestValidator::check(
            $manifest['tests'] ?? null,
            $manifest['records'] ?? null,
            is_array($manifest['roles'] ?? null) ? array_map('strval', array_keys($manifest['roles'])) : [],
            static function (mixed $label, string $path, int $max, bool $required, array &$errs): void {
                self::checkLabel($label, $path, $max, $required, $errs);
            },
            $errors,
        );
        DocumentSlotValidator::check(
            $manifest['documents'] ?? null,
            $manifest['records'] ?? null,
            static function (mixed $label, string $path, int $max, bool $required, array &$errs): void {
                self::checkLabel($label, $path, $max, $required, $errs);
            },
            $errors,
        );

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @param list<string> $errors
     */
    private static function checkPlugin(mixed $plugin, array &$errors): ?string
    {
        if (!is_array($plugin)) {
            $errors[] = 'plugin: required object';
            return null;
        }
        self::onlyKeys($plugin, ['name', 'version', 'label', 'description'], 'plugin', $errors);

        $name = $plugin['name'] ?? null;
        if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = 'plugin.name: required, a letter then letters, digits or underscores (at most 64)';
            $name = null;
        }
        $version = $plugin['version'] ?? null;
        if (!is_string($version) || preg_match(self::SEMVER_PATTERN, $version) !== 1) {
            $errors[] = 'plugin.version: required semantic version, e.g. "1.0.0"';
        }
        self::checkLabel($plugin['label'] ?? null, 'plugin.label', self::MAX_LABEL, true, $errors);
        self::checkLabel($plugin['description'] ?? null, 'plugin.description', self::MAX_DESCRIPTION, false, $errors);

        return $name;
    }

    /**
     * @param list<string> $errors
     */
    private static function checkRequires(mixed $requires, array &$errors): void
    {
        if ($requires === null) {
            return;
        }
        if (!is_array($requires) || array_is_list($requires) && $requires !== []) {
            $errors[] = 'requires: must be an object';
            return;
        }
        self::onlyKeys($requires, ['sdk', 'core', 'plugins'], 'requires', $errors);
        foreach (['sdk', 'core'] as $axis) {
            if (isset($requires[$axis]) && (!is_string($requires[$axis]) || trim($requires[$axis]) === '')) {
                $errors[] = "requires.{$axis}: must be a non-empty version constraint";
            }
        }
        if (isset($requires['plugins'])) {
            if (!is_array($requires['plugins']) || array_is_list($requires['plugins']) && $requires['plugins'] !== []) {
                $errors[] = 'requires.plugins: must be an object of plugin name to constraint';
                return;
            }
            foreach ($requires['plugins'] as $dep => $constraint) {
                if (preg_match(self::NAME_PATTERN, (string) $dep) !== 1 || !is_string($constraint) || trim($constraint) === '') {
                    $errors[] = "requires.plugins.{$dep}: must name a plugin and a non-empty constraint";
                }
            }
        }
    }

    /**
     * @param list<string> $errors
     * @return array<string, true> Declared permission slugs.
     */
    private static function checkPermissions(mixed $permissions, array &$errors): array
    {
        $declared = [];
        if ($permissions === null) {
            return $declared;
        }
        if (!is_array($permissions) || !array_is_list($permissions)) {
            $errors[] = 'permissions: must be a list';
            return $declared;
        }
        if (count($permissions) > self::MAX_PERMISSIONS) {
            $errors[] = 'permissions: at most ' . self::MAX_PERMISSIONS;
        }
        foreach ($permissions as $i => $permission) {
            $path = "permissions[{$i}]";
            if (!is_array($permission)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($permission, ['slug', 'label'], $path, $errors);
            $slug = $permission['slug'] ?? null;
            if (!is_string($slug) || preg_match(self::PERMISSION_PATTERN, $slug) !== 1) {
                $errors[] = "{$path}.slug: must read resource:action in lowercase";
                continue;
            }
            if (isset($declared[$slug])) {
                $errors[] = "{$path}.slug: \"{$slug}\" is declared twice";
            }
            $declared[$slug] = true;
            self::checkLabel($permission['label'] ?? null, "{$path}.label", self::MAX_LABEL, true, $errors);
        }

        return $declared;
    }

    /**
     * @param array<string, true> $permissions
     * @param list<string> $errors
     */
    private static function checkRoles(mixed $roles, array $permissions, array &$errors): void
    {
        if ($roles === null) {
            return;
        }
        if (!is_array($roles) || array_is_list($roles) && $roles !== []) {
            $errors[] = 'roles: must be an object of role name to role';
            return;
        }
        if (count($roles) > self::MAX_ROLES) {
            $errors[] = 'roles: at most ' . self::MAX_ROLES;
        }
        foreach ($roles as $name => $role) {
            $path = "roles.{$name}";
            if (preg_match(self::ROLE_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$path}: role names are lowercase letters, digits and underscores";
            }
            if (!is_array($role)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($role, ['label', 'parent', 'permissions'], $path, $errors);
            self::checkLabel($role['label'] ?? null, "{$path}.label", self::MAX_LABEL, true, $errors);
            if (isset($role['parent']) && (!is_string($role['parent']) || $role['parent'] === '')) {
                $errors[] = "{$path}.parent: must be a role name";
            }
            $grants = $role['permissions'] ?? [];
            if (!is_array($grants) || !array_is_list($grants)) {
                $errors[] = "{$path}.permissions: must be a list";
                continue;
            }
            foreach ($grants as $j => $slug) {
                if (!is_string($slug) || !isset($permissions[$slug])) {
                    $errors[] = "{$path}.permissions[{$j}]: unknown permission " . self::quote($slug)
                        . ' (a role may grant only permissions this manifest declares)';
                }
            }
        }
    }

    /**
     * @param array<string, true> $permissions
     * @param list<string> $errors
     */
    private static function checkScreens(mixed $screens, array $permissions, array &$errors): void
    {
        if ($screens === null) {
            return;
        }
        if (!is_array($screens) || !array_is_list($screens)) {
            $errors[] = 'screens: must be a list';
            return;
        }
        if (count($screens) > self::MAX_SCREENS) {
            $errors[] = 'screens: at most ' . self::MAX_SCREENS;
        }
        $ids = [];
        foreach ($screens as $i => $screen) {
            $path = "screens[{$i}]";
            if (!is_array($screen)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($screen, ['id', 'label', 'permission', 'group', 'order', 'icon', 'blocks'], $path, $errors);
            $id = $screen['id'] ?? null;
            if (!is_string($id) || preg_match(self::SCREEN_ID_PATTERN, $id) !== 1) {
                $errors[] = "{$path}.id: kebab-case, starting with a letter";
            } elseif (isset($ids[$id])) {
                $errors[] = "{$path}.id: \"{$id}\" is used twice";
            } else {
                $ids[$id] = true;
            }
            self::checkLabel($screen['label'] ?? null, "{$path}.label", self::MAX_LABEL, true, $errors);
            $permission = $screen['permission'] ?? null;
            if (!is_string($permission) || !isset($permissions[$permission])) {
                $errors[] = "{$path}.permission: required, and must be a permission this manifest declares";
            }
            if (isset($screen['group']) && (!is_string($screen['group']) || $screen['group'] === '')) {
                $errors[] = "{$path}.group: must be a non-empty string";
            }
            if (isset($screen['order']) && !is_int($screen['order']) && !is_float($screen['order'])) {
                $errors[] = "{$path}.order: must be a number";
            }
            if (isset($screen['icon']) && (!is_string($screen['icon']) || preg_match(self::ICON_PATTERN, $screen['icon']) !== 1)) {
                $errors[] = "{$path}.icon: a Tabler icon name";
            }
            $blocks = $screen['blocks'] ?? null;
            if (!is_array($blocks) || !array_is_list($blocks) || $blocks === []) {
                $errors[] = "{$path}.blocks: required, a non-empty list of blocks";
                continue;
            }
            $result = BlockValidator::validate($blocks);
            foreach ($result['errors'] as $blockError) {
                $errors[] = "{$path}.blocks: {$blockError}";
            }
        }
    }

    /**
     * @param list<string> $errors
     * @return array<string, string> Declared table => scope.
     */
    private static function checkTables(mixed $tables, array &$errors): array
    {
        $declared = [];
        if ($tables === null) {
            return $declared;
        }
        if (!is_array($tables) || array_is_list($tables) && $tables !== []) {
            $errors[] = 'tables: must be an object of table name to "tenant" or "global"';
            return $declared;
        }
        foreach ($tables as $table => $scope) {
            if (preg_match(self::TABLE_PATTERN, (string) $table) !== 1) {
                $errors[] = "tables.{$table}: table names are lowercase letters, digits and underscores";
                continue;
            }
            if ($scope !== 'tenant' && $scope !== 'global') {
                $errors[] = "tables.{$table}: scope must be \"tenant\" or \"global\"";
                continue;
            }
            $declared[(string) $table] = $scope;
        }

        return $declared;
    }

    /**
     * Data types are checked for shape and for what they point at; the full
     * contract (lifecycle states, guard semantics) stays the host's, which
     * validates every data type the same way whatever declared it.
     *
     * @param array<string, string> $tables
     * @param array<string, true> $permissions
     * @param list<string> $errors
     */
    private static function checkDataTypes(mixed $dataTypes, array $tables, array $permissions, array &$errors): void
    {
        if ($dataTypes === null) {
            return;
        }
        if (!is_array($dataTypes) || array_is_list($dataTypes) && $dataTypes !== []) {
            $errors[] = 'dataTypes: must be an object of type name to declaration';
            return;
        }
        foreach ($dataTypes as $name => $type) {
            $path = "dataTypes.{$name}";
            if (preg_match(self::DATA_TYPE_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$path}: type names are lowercase letters, digits and underscores";
            }
            if (!is_array($type)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            $table = $type['table'] ?? null;
            if (!is_string($table) || !isset($tables[$table])) {
                $errors[] = "{$path}.table: must be a table this manifest declares under \"tables\"";
            } elseif ($tables[$table] !== 'tenant') {
                $errors[] = "{$path}.table: a data type needs a tenant-scoped table";
            }
            if (isset($type['label'])) {
                self::checkLabel($type['label'], "{$path}.label", self::MAX_LABEL, true, $errors);
            }
            foreach (['blocks_delete', 'cascade_delete'] as $edge) {
                foreach (is_array($type[$edge] ?? null) ? $type[$edge] : [] as $k => $ref) {
                    $refTable = is_array($ref) ? ($ref['table'] ?? null) : null;
                    if (!is_string($refTable) || !isset($tables[$refTable])) {
                        $errors[] = "{$path}.{$edge}[{$k}].table: must be a table this manifest declares";
                    }
                }
            }
            foreach (is_array($type['permissions'] ?? null) ? $type['permissions'] : [] as $action => $slug) {
                if (!is_string($slug) || !isset($permissions[$slug])) {
                    $errors[] = "{$path}.permissions.{$action}: must be a permission this manifest declares";
                }
            }
        }
    }

    /**
     * @param list<string> $errors
     */
    private static function checkLimits(mixed $limits, ?string $pluginName, array &$errors): void
    {
        if ($limits === null) {
            return;
        }
        if (!is_array($limits) || !array_is_list($limits)) {
            $errors[] = 'limits: must be a list';
            return;
        }
        $prefix = $pluginName === null ? null : strtolower($pluginName) . '.';
        foreach ($limits as $i => $limit) {
            $path = "limits[{$i}]";
            if (!is_array($limit)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($limit, ['key', 'type', 'default', 'description', 'period'], $path, $errors);
            $key = $limit['key'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $key) !== 1) {
                $errors[] = "{$path}.key: lowercase, dotted, e.g. \"cakeorders.orders.max\"";
            } elseif ($prefix !== null && !str_starts_with($key, $prefix)) {
                $errors[] = "{$path}.key: must start with \"{$prefix}\"";
            }
            $type = $limit['type'] ?? null;
            if ($type !== 'bool' && $type !== 'int') {
                $errors[] = "{$path}.type: \"bool\" or \"int\"";
            }
            $default = $limit['default'] ?? null;
            $defaultOk = match ($type) {
                'bool' => is_bool($default),
                'int' => is_int($default) && $default >= -1,
                default => true,
            };
            if (!$defaultOk) {
                $errors[] = "{$path}.default: true/false for bool, an integer of at least -1 (unlimited) for int";
            }
            self::checkLabel($limit['description'] ?? null, "{$path}.description", self::MAX_DESCRIPTION, true, $errors);
            if (isset($limit['period']) && !in_array($limit['period'], ['day', 'week', 'month'], true)) {
                $errors[] = "{$path}.period: \"day\", \"week\" or \"month\"";
            }
        }
    }

    /**
     * Every visible string carries English and Arabic (design register).
     *
     * @param list<string> $errors
     */
    private static function checkLabel(mixed $label, string $path, int $max, bool $required, array &$errors): void
    {
        if ($label === null) {
            if ($required) {
                $errors[] = "{$path}: required, with \"en\" and \"ar\"";
            }
            return;
        }
        if (!is_array($label)) {
            $errors[] = "{$path}: must be an object with \"en\" and \"ar\"";
            return;
        }
        self::onlyKeys($label, ['en', 'ar'], $path, $errors);
        foreach (['en', 'ar'] as $lang) {
            $text = $label[$lang] ?? null;
            if (!is_string($text) || trim($text) === '') {
                $errors[] = "{$path}.{$lang}: required, non-empty";
            } elseif (mb_strlen($text) > $max) {
                $errors[] = "{$path}.{$lang}: at most {$max} characters";
            }
        }
    }

    /**
     * @param array<mixed> $object
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private static function onlyKeys(array $object, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = self::unknownKey($path, (string) $key);
            }
        }
    }

    private static function unknownKey(string $path, string $key): string
    {
        return ($path === '' ? $key : "{$path}.{$key}") . ': unknown key (this SDK can\'t run it, so it is refused rather than ignored)';
    }

    private static function quote(mixed $value): string
    {
        return is_string($value) ? '"' . $value . '"' : gettype($value);
    }
}
