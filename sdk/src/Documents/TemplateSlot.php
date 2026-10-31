<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * A template slot a code plugin declares (SDK 1.56, WHIT-742,
 * DOCUMENT-AUTOMATION.md 3.2): a named place it needs a document template,
 * such as its invoice. The host addresses it as `<plugin name>.<name>`, each
 * workspace binds one of its published templates to it, and the plugin
 * issues with `TemplateRef::slot()`.
 *
 * `recordType` must be one the host serves: core's, or one this plugin's own
 * {@see DocumentFieldProvider} serves (`<plugin name>.<type>`). A slot naming
 * any other is dropped at load with a logged reason.
 */
final class TemplateSlot
{
    public const KINDS = ['template', 'label'];

    /**
     * @param array{en: string, ar: string} $label
     * @param list<string> $kinds
     */
    public function __construct(
        public readonly string $name,
        public readonly array $label,
        public readonly string $recordType,
        public readonly array $kinds = ['template'],
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) !== 1) {
            throw new \InvalidArgumentException("Slot name '{$name}': lowercase letters, digits and underscores, up to 40");
        }
        if ($label['en'] === '' || $label['ar'] === '') {
            throw new \InvalidArgumentException("Slot '{$name}' needs an English and an Arabic label");
        }
        if ($kinds === [] || array_diff($kinds, self::KINDS) !== [] || count(array_unique($kinds)) !== count($kinds)) {
            throw new \InvalidArgumentException("Slot '{$name}': kinds are one or more of " . implode(', ', self::KINDS));
        }
    }
}
