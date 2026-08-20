<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

/**
 * One DECLARED organizational-unit type (#822) — a catalogue entry, not a row.
 *
 * A definition says "this key exists, and here is what a tenant adopting it
 * starts with". It never belongs to a tenant: the tenant's own vocabulary lives
 * in `ou_types`, and adopting a declared key copies these values in as the
 * initial label and rank, after which the tenant owns them.
 *
 * @see OuTypeRegistry for the catalogue and the namespacing rule.
 */
final class OuTypeDefinition
{
    /**
     * @param string   $key       The canonical key — bare for core, `plugin:slug` for a plugin.
     * @param string   $source    The declaring source (raw plugin name, or {@see OuTypeRegistry::CORE_SOURCE}).
     * @param string   $slug      The bare slug as declared, before namespacing.
     * @param string   $label     Default human label for an adopting tenant.
     * @param int|null $sortOrder Default rank, or null to let the adopting tenant append.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $source,
        private readonly string $slug,
        private readonly string $label,
        private readonly ?int $sortOrder,
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
     * The declared rank, or null when the declaration expressed no opinion.
     *
     * Null is meaningful rather than a defaulted zero: "no opinion" must let the
     * adopting tenant append the type to the end of its own list, whereas a
     * declared 0 pins it to the front. Collapsing the two would silently promote
     * every unopinionated plugin type above the tenant's own root type.
     */
    public function sortOrder(): ?int
    {
        return $this->sortOrder;
    }

    /**
     * The catalogue representation returned by `GET /api/ou-types/catalog`.
     *
     * @return array{key: string, source: string, label: string, sort_order: int|null}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'source' => $this->source,
            'label' => $this->label,
            'sort_order' => $this->sortOrder,
        ];
    }
}
