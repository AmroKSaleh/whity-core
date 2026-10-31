<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * What to issue (SDK 1.56, WHIT-742, DOCUMENT-AUTOMATION.md 3.3).
 *
 * - `record`: the record the document is for, `type:id`
 *   (`Invoicing.invoice:4812`), read with the acting person's permissions.
 * - `values`: field path => text, for the template's own fields
 *   (`template.note`) and fields the record left empty. A value for a field
 *   the record filled is refused, so an amount or a tax number can't be
 *   overwritten.
 * - `originKey`: your own name for this document, e.g. `order:4812:invoice`.
 *   Issuing again with it returns the first document instead of a second.
 * - `render`: null renders when the workspace can, true requires a rendered
 *   file, false records without one.
 */
final class IssueRequest
{
    /** @param array<string, string> $values */
    public function __construct(
        public readonly TemplateRef $template,
        public readonly ?string $record = null,
        public readonly array $values = [],
        public readonly ?string $title = null,
        public readonly ?string $originKey = null,
        public readonly ?bool $render = null,
    ) {
    }
}
