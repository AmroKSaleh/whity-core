<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

/**
 * What a caller asks to issue (WHIT-742, DOCUMENT-AUTOMATION.md 3.3): a slot
 * or a template, the record as `type:id`, merge values, and optionally a
 * title, an origin key and whether to render. Unchecked: {@see TemplateIssuing}
 * checks every part and refuses with a reason.
 */
final class IssueInput
{
    public function __construct(
        public readonly ?string $slot = null,
        public readonly mixed $sourceId = null,
        public readonly mixed $record = null,
        public readonly mixed $values = null,
        public readonly mixed $title = null,
        public readonly mixed $originKey = null,
        public readonly mixed $render = null,
        // Both a slot and a template were named: refused rather than guessed.
        public readonly bool $both = false,
    ) {
    }

    /**
     * From a JSON body: `slot` or `document_source_id`, `record`, `values`, `title`, `origin_key`, `render`.
     *
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $slot = $body['slot'] ?? null;

        return new self(
            slot: $slot === null ? null : (is_string($slot) ? $slot : ''),
            sourceId: $body['document_source_id'] ?? null,
            record: $body['record'] ?? null,
            values: $body['values'] ?? null,
            title: $body['title'] ?? null,
            originKey: $body['origin_key'] ?? null,
            render: $body['render'] ?? null,
            both: $slot !== null && array_key_exists('document_source_id', $body),
        );
    }
}
