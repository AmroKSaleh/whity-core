<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Which template to issue from (SDK 1.56, WHIT-742): a slot the plugin
 * declared, so the workspace decides the template, or a template by id.
 * Prefer the slot: a workspace can then swap its template without the plugin.
 */
final class TemplateRef
{
    private function __construct(
        public readonly ?string $slot,
        public readonly ?int $sourceId,
    ) {
    }

    /** A slot, `<plugin name>.<slot>`, e.g. `Invoicing.invoice`. */
    public static function slot(string $key): self
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,63}\.[a-z][a-z0-9_]{0,39}$/', $key) !== 1) {
            throw new \InvalidArgumentException("'{$key}' is not a slot key (<plugin name>.<slot>)");
        }

        return new self($key, null);
    }

    /** A template by its id. */
    public static function source(int $id): self
    {
        if ($id <= 0) {
            throw new \InvalidArgumentException('A template id is a positive integer');
        }

        return new self(null, $id);
    }
}
