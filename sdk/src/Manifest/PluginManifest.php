<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use JsonException;
use Whity\Sdk\Tenant\PluginTablesInterface;

/**
 * A validated `plugin.json`, and the declarations it maps to (SDK 1.47, WHIT-758).
 *
 * The accessors return exactly the shapes the existing capability interfaces
 * return, so a plugin declared in JSON registers through the same host code as
 * one declared in PHP. That is the point of the format: one runtime, two ways
 * to write the same plugin.
 *
 * Only a manifest that passed {@see ManifestValidator} can be constructed, so
 * the accessors never need to defend against a malformed document.
 */
final class PluginManifest
{
    /**
     * @param array<string, mixed> $data A manifest that passed validation.
     */
    private function __construct(private readonly array $data)
    {
    }

    /**
     * @param array<mixed> $data
     * @throws InvalidManifestException
     */
    public static function fromArray(array $data, string $source = 'manifest'): self
    {
        $result = ManifestValidator::validate($data);
        if ($result['ok'] !== true) {
            throw new InvalidManifestException($source, $result['errors']);
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    /**
     * @throws InvalidManifestException When the file is missing, isn't a JSON
     *         object, or doesn't validate.
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidManifestException($path, ['file not found or not readable']);
        }
        $json = file_get_contents($path);
        try {
            $data = json_decode((string) $json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidManifestException($path, ['not valid JSON: ' . $e->getMessage()]);
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidManifestException($path, ['the top level must be a JSON object']);
        }

        return self::fromArray($data, $path);
    }

    /** @return array<string, mixed> The manifest as declared. */
    public function toArray(): array
    {
        return $this->data;
    }

    public function name(): string
    {
        return (string) $this->plugin()['name'];
    }

    public function version(): string
    {
        return (string) $this->plugin()['version'];
    }

    /** @return array{en: string, ar: string} */
    public function label(): array
    {
        /** @var array{en: string, ar: string} */
        return $this->plugin()['label'];
    }

    /** SDK constraint, '' when the manifest declares none. */
    public function sdkConstraint(): string
    {
        return (string) ($this->requires()['sdk'] ?? '');
    }

    /** Core constraint, '' when the manifest declares none. */
    public function coreConstraint(): string
    {
        return (string) ($this->requires()['core'] ?? '');
    }

    /** @return array<string, string> */
    public function pluginDependencies(): array
    {
        /** @var array<string, string> */
        return $this->requires()['plugins'] ?? [];
    }

    /** @return list<string> Permission slugs, in declared order. */
    public function permissions(): array
    {
        return array_map(
            static fn (array $p): string => (string) $p['slug'],
            $this->list('permissions'),
        );
    }

    /** @return array<string, array{en: string, ar: string}> Slug => label. */
    public function permissionLabels(): array
    {
        $labels = [];
        foreach ($this->list('permissions') as $p) {
            /** @var array{en: string, ar: string} $label */
            $label = $p['label'];
            $labels[(string) $p['slug']] = $label;
        }

        return $labels;
    }

    /**
     * The {@see \Whity\Sdk\PluginRolesInterface::getRoles()} shape. The role's
     * English label is its description, as an operator reads it.
     *
     * @return array<string, array{description?: string, parent?: string}>
     */
    public function roles(): array
    {
        $roles = [];
        foreach ($this->map('roles') as $name => $role) {
            $entry = ['description' => (string) $role['label']['en']];
            if (isset($role['parent'])) {
                $entry['parent'] = (string) $role['parent'];
            }
            $roles[$name] = $entry;
        }

        return $roles;
    }

    /** @return array<string, list<string>> */
    public function rolePermissions(): array
    {
        $grants = [];
        foreach ($this->map('roles') as $name => $role) {
            /** @var list<string> $permissions */
            $permissions = $role['permissions'] ?? [];
            $grants[$name] = $permissions;
        }

        return $grants;
    }

    /**
     * {@see \Whity\Sdk\PluginFrontendInterface} descriptors, one `blocks`
     * screen per declared screen. The Arabic label travels as `labelAr`; the
     * host doesn't read it yet (plugin screen and nav labels are English-only
     * today), and ignores it until it does.
     *
     * @return list<array<string, mixed>>
     */
    public function frontendFeatures(): array
    {
        $features = [];
        foreach ($this->list('screens') as $screen) {
            $feature = [
                'id' => $screen['id'],
                'label' => $screen['label']['en'],
                'labelAr' => $screen['label']['ar'],
                'screen' => 'blocks',
                'requiredPermission' => $screen['permission'],
                'blocks' => $screen['blocks'],
            ];
            foreach (['group', 'order', 'icon'] as $key) {
                if (isset($screen[$key])) {
                    $feature[$key] = $screen[$key];
                }
            }
            $features[] = $feature;
        }

        return $features;
    }

    /**
     * The {@see PluginTablesInterface::getOwnedTables()} shape.
     *
     * @return array<string, string>
     */
    public function ownedTables(): array
    {
        $tables = [];
        foreach ($this->map('tables') as $table => $scope) {
            $tables[$table] = $scope === 'global' ? PluginTablesInterface::SCOPE_GLOBAL : PluginTablesInterface::SCOPE_TENANT;
        }

        return $tables;
    }

    /**
     * The {@see \Whity\Sdk\DataType\PluginDataTypesInterface::getDataTypes()}
     * shape, passed on as declared. A `label` is the per-language map the host
     * reads (`{en, ar}`).
     *
     * @return array<string, array<string, mixed>>
     */
    public function dataTypes(): array
    {
        return $this->map('dataTypes');
    }

    /**
     * The {@see \Whity\Sdk\PluginEntitlementsInterface::getEntitlements()}
     * shape: defaults as text and the English description, as that contract
     * asks.
     *
     * @return list<array{key: string, type: string, default: string, description: string, period?: string}>
     */
    public function entitlements(): array
    {
        $out = [];
        foreach ($this->list('limits') as $limit) {
            $default = $limit['default'];
            $entry = [
                'key' => (string) $limit['key'],
                'type' => (string) $limit['type'],
                'default' => is_bool($default) ? ($default ? 'true' : 'false') : (string) $default,
                'description' => (string) $limit['description']['en'],
            ];
            if (isset($limit['period'])) {
                $entry['period'] = (string) $limit['period'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Record types kept in the host's record store (SDK 1.50), as declared.
     *
     * @return array<string, array<string, mixed>>
     */
    public function records(): array
    {
        /** @var array<string, array<string, mixed>> */
        return $this->map('records');
    }

    /** @return array<string, mixed> */
    private function plugin(): array
    {
        /** @var array<string, mixed> */
        return $this->data['plugin'];
    }

    /** @return array<string, mixed> */
    private function requires(): array
    {
        /** @var array<string, mixed> */
        return $this->data['requires'] ?? [];
    }

    /** @return list<array<string, mixed>> */
    private function list(string $key): array
    {
        /** @var list<array<string, mixed>> */
        return $this->data[$key] ?? [];
    }

    /** @return array<string, mixed> */
    private function map(string $key): array
    {
        /** @var array<string, mixed> */
        return $this->data[$key] ?? [];
    }
}
