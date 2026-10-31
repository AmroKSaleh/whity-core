<?php

declare(strict_types=1);

namespace Whity\Core\Document\Slots;

/**
 * A template slot (DOCUMENT-AUTOMATION.md 3.2, WHIT-742): a named place a
 * plugin needs a document template, such as "the invoice" or "the order
 * confirmation". A workspace binds one of its published templates to it, so
 * the plugin issues by slot and the workspace can swap the template.
 *
 * `key` is `<plugin>.<name>`. `recordType` is the field record type the
 * slot's documents are filled from; a bound template must have the same one.
 */
final class TemplateSlot
{
    public const KINDS = ['template', 'label'];

    /**
     * @param array{en: string, ar: string} $label
     * @param list<string> $kinds
     */
    public function __construct(
        public readonly string $key,
        public readonly string $plugin,
        public readonly array $label,
        public readonly string $recordType,
        public readonly array $kinds = ['template'],
        public readonly ?string $starter = null,
    ) {
    }

    /**
     * The slots a workspace plugin declares under `documents.slots`, already
     * checked by the SDK's manifest validator. Each record type is the
     * manifest's own, served as `<plugin>.<record>`.
     *
     * @param array<string, array<string, mixed>> $declared
     * @return list<self>
     */
    public static function fromManifest(string $plugin, array $declared): array
    {
        $slots = [];
        foreach ($declared as $name => $slot) {
            /** @var array{en: string, ar: string} $label */
            $label = $slot['label'];
            /** @var list<string> $kinds */
            $kinds = is_array($slot['kinds'] ?? null) ? array_values($slot['kinds']) : ['template'];
            $slots[] = new self(
                key: "{$plugin}.{$name}",
                plugin: $plugin,
                label: $label,
                recordType: $plugin . '.' . (string) $slot['record'],
                kinds: $kinds,
            );
        }

        return $slots;
    }

    /** @return array{key: string, plugin: string, label: string, labelAr: string, recordType: string, kinds: list<string>, starter: string|null} */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'plugin' => $this->plugin,
            'label' => $this->label['en'],
            'labelAr' => $this->label['ar'],
            'recordType' => $this->recordType,
            'kinds' => $this->kinds,
            'starter' => $this->starter,
        ];
    }
}
