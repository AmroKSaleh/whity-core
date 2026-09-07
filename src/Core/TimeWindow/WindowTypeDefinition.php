<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

/**
 * One DECLARED time-window type (#1070) — a catalogue entry, not a row.
 *
 * A definition says "this key exists, and here is what a tenant adopting it
 * starts with". It never belongs to a tenant: the tenant's own vocabulary lives
 * in `time_window_types`, and adopting a declared key copies these values in as
 * the initial label and nesting, after which the tenant owns them.
 *
 * Nothing here describes WHEN. A type is a kind of period, not a period, and the
 * boundaries of an actual period are authored per instance — see
 * {@see \Whity\Sdk\TimeWindow\PluginWindowTypesInterface} for why they are never
 * derived from a calendar.
 *
 * @see WindowTypeRegistry for the catalogue and the namespacing rule.
 */
final class WindowTypeDefinition
{
    /**
     * @param string      $key       The canonical key — bare for core, `plugin:slug` for a plugin.
     * @param string      $source    The declaring source (raw plugin name, or {@see WindowTypeRegistry::CORE_SOURCE}).
     * @param string      $slug      The bare slug as declared, before namespacing.
     * @param string      $label     Default human label for an adopting tenant.
     * @param string|null $parentKey Canonical key of the type this nests inside, or null for a top-level type.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $source,
        private readonly string $slug,
        private readonly string $label,
        private readonly ?string $parentKey,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function source(): string
    {
        return $this->source;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function label(): string
    {
        return $this->label;
    }

    /**
     * The canonical key of the declared parent type, or null when this type
     * nests inside nothing.
     *
     * Already namespaced: a plugin declares `'parent' => 'crop_year'` and reads
     * back `acme:crop_year`, because the plugin may only nest inside its OWN
     * vocabulary and the prefix is the host's to apply.
     */
    public function parentKey(): ?string
    {
        return $this->parentKey;
    }

    /**
     * The catalogue representation returned by `GET /api/v1/time-window-types/catalog`.
     *
     * @return array{key: string, source: string, label: string, parent_key: string|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'source' => $this->source,
            'label' => $this->label,
            'parent_key' => $this->parentKey,
        ];
    }
}
