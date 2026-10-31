<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * A workspace plugin's record type as a document record type (WHIT-742,
 * DOCUMENT-AUTOMATION.md 3.11): a template for "a cake order" gets the
 * order's fields, and issuing from it reads the order from the record store.
 *
 * The record type is `<plugin name>.<type>`, e.g. `CakeOrders.order`, and a
 * record is addressed as `CakeOrders.order:12`. Its fields are
 * `record.<field>`, so they can't collide with the fields every template has
 * (`company.*`, `today.*`).
 *
 * Supplied per workspace through {@see DocumentFieldRegistry::addTenantSource()}:
 * the type exists only where the plugin is published and switched on. Reading
 * needs the record type's own read permission, which the manifest declares.
 *
 * Values print as a reader sees them: money in major units with its currency
 * (JOD has three decimals), dates as "29 October 2026", choices by their label
 * in the reader's language, a person by name, a linked record by its title.
 */
final class TenantRecordFieldProvider implements DocumentFieldProvider
{
    /** How many recent records the record picker searches. */
    private const SEARCH_WINDOW = 200;

    /** Minor units per currency where it isn't two. */
    private const DECIMALS = ['JOD' => 3, 'KWD' => 3, 'BHD' => 3, 'OMR' => 3, 'TND' => 3, 'IQD' => 3, 'LYD' => 3, 'JPY' => 0, 'KRW' => 0];

    private FieldLabels $labels;

    /** @var callable(): string */
    private $language;

    /** @var (callable(int, int): ?string)|null */
    private $personName;

    /**
     * @param callable(): string|null $language the reader's language code
     * @param (callable(int, int): ?string)|null $personName tenant id, profile id => display name
     */
    public function __construct(
        private readonly PluginManifest $manifest,
        private readonly string $type,
        private readonly TenantRecordRepository $records,
        ?FieldLabels $labels = null,
        ?callable $language = null,
        ?callable $personName = null,
    ) {
        $this->labels = $labels ?? new FieldLabels();
        $this->language = $language ?? static fn (): string => 'en';
        $this->personName = $personName;
    }

    /**
     * One provider per record type of each of the workspace's live manifests.
     *
     * @param list<PluginManifest> $manifests
     * @param callable(): string|null $language
     * @param (callable(int, int): ?string)|null $personName
     * @return list<self>
     */
    public static function forManifests(
        array $manifests,
        TenantRecordRepository $records,
        ?FieldLabels $labels = null,
        ?callable $language = null,
        ?callable $personName = null,
    ): array {
        $out = [];
        foreach ($manifests as $manifest) {
            foreach (array_keys($manifest->records()) as $type) {
                $out[] = new self($manifest, (string) $type, $records, $labels, $language, $personName);
            }
        }

        return $out;
    }

    public function recordType(): string
    {
        return $this->manifest->name() . '.' . $this->type;
    }

    public function recordLabel(): string
    {
        return $this->text($this->declaration()['label'] ?? null, $this->type);
    }

    public function groups(int $tenantId): array
    {
        $items = [];
        foreach ($this->fields() as $name => $field) {
            $item = ['path' => 'record.' . $name, 'label' => $this->text($field['label'] ?? null, $name)];
            if (($field['required'] ?? false) === true) {
                $item['required'] = true;
            }
            $items[] = $item;
        }

        return [['group' => $this->recordLabel(), 'items' => $items]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        if ($recordId === null || !ctype_digit($recordId) || !$this->canRead($hasPermission)) {
            return null;
        }
        $row = $this->records->find($tenantId, $this->manifest->name(), $this->type, (int) $recordId);
        if ($row === null) {
            return null;
        }
        /** @var array<string, mixed> $data */
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];

        $values = [];
        foreach ($this->fields() as $name => $field) {
            $values['record.' . $name] = $this->format($tenantId, $field, $data[$name] ?? null);
        }

        return ['label' => $this->title($row), 'values' => $values];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$this->canRead($hasPermission)) {
            return [];
        }
        $rows = $this->records->list($tenantId, $this->manifest->name(), $this->type, null, null, self::SEARCH_WINDOW);
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach ($rows as $row) {
            $label = $this->title($row);
            if ($q !== '' && !str_contains(mb_strtolower($label), $q)) {
                continue;
            }
            $out[] = ['id' => (string) $row['id'], 'label' => $label];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /** @param callable(string): bool $hasPermission */
    private function canRead(callable $hasPermission): bool
    {
        $read = $this->declaration()['permissions']['read'] ?? null;

        return is_string($read) && $read !== '' && $hasPermission($read);
    }

    /**
     * "#12 · Example Bakery": the record's title field when it has one.
     *
     * @param array<string, mixed> $row
     */
    private function title(array $row): string
    {
        $titleField = $this->declaration()['titleField'] ?? null;
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];
        $title = is_string($titleField) && is_scalar($data[$titleField] ?? null) ? trim((string) $data[$titleField]) : '';

        return '#' . (int) $row['id'] . ($title !== '' ? ' · ' . $title : '');
    }

    /** @param array<string, mixed> $field */
    private function format(int $tenantId, array $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        return match ((string) ($field['type'] ?? 'text')) {
            'money' => is_int($value) ? $this->money($value, (string) ($field['currency'] ?? '')) : null,
            'number' => is_int($value) || is_float($value) ? (string) $value : null,
            'boolean' => is_bool($value) ? ($value ? $this->labels->get('fields.yes', 'Yes') : $this->labels->get('fields.no', 'No')) : null,
            'date' => is_string($value) ? $this->date($value, false) : null,
            'datetime' => is_string($value) ? $this->date($value, true) : null,
            'choice' => is_scalar($value) ? $this->option($field, (string) $value) : null,
            'multi_choice' => is_array($value) ? implode(', ', array_map(fn (mixed $v): string => $this->option($field, (string) $v), $value)) : null,
            'person' => is_int($value) && $this->personName !== null ? ($this->personName)($tenantId, $value) : null,
            'record' => is_int($value) ? $this->linked($tenantId, (string) ($field['record'] ?? ''), $value) : null,
            // An organisational unit by name comes later; its id isn't worth printing.
            'ou' => null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }

    private function money(int $minor, string $currency): string
    {
        $decimals = self::DECIMALS[$currency] ?? 2;
        $major = number_format($minor / (10 ** $decimals), $decimals, '.', ',');

        return $currency !== '' ? "{$major} {$currency}" : $major;
    }

    private function date(string $value, bool $withTime): ?string
    {
        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }

        return $this->labels->date($date) . ($withTime ? ' ' . $date->format('H:i') : '');
    }

    /** @param array<string, mixed> $field */
    private function option(array $field, string $value): string
    {
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && (string) ($option['value'] ?? '') === $value) {
                return $this->text($option['label'] ?? null, $value);
            }
        }

        return $value;
    }

    /** A linked record of this plugin, by its title, if it still exists. */
    private function linked(int $tenantId, string $type, int $id): ?string
    {
        if ($type === '' || !isset($this->manifest->records()[$type])) {
            return null;
        }
        $row = $this->records->find($tenantId, $this->manifest->name(), $type, $id);
        if ($row === null) {
            return null;
        }
        $titleField = $this->manifest->records()[$type]['titleField'] ?? null;
        $data = is_array($row['data'] ?? null) ? $row['data'] : [];

        return is_string($titleField) && is_scalar($data[$titleField] ?? null) ? (string) $data[$titleField] : '#' . $id;
    }

    /** The label in the reader's language, English when there is none. */
    private function text(mixed $label, string $fallback): string
    {
        if (!is_array($label)) {
            return $fallback;
        }
        $lang = ($this->language)() === 'ar' ? 'ar' : 'en';
        $text = $label[$lang] ?? $label['en'] ?? null;

        return is_string($text) && $text !== '' ? $text : $fallback;
    }

    /** @return array<string, mixed> */
    private function declaration(): array
    {
        /** @var array<string, mixed> */
        return $this->manifest->records()[$this->type] ?? [];
    }

    /** @return array<string, array<string, mixed>> */
    private function fields(): array
    {
        $fields = $this->declaration()['fields'] ?? [];

        /** @var array<string, array<string, mixed>> */
        return is_array($fields) ? $fields : [];
    }
}
