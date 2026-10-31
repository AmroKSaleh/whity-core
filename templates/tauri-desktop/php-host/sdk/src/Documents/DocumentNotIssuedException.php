<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * Why {@see Documents::issue()} issued nothing (SDK 1.56, WHIT-742).
 * `reason` is stable, one of the constants below; `details` adds what the
 * reason needs, such as the missing fields. Nothing was written.
 */
final class DocumentNotIssuedException extends \RuntimeException
{
    /** No person to act for (outside a request), or no workspace. */
    public const NO_ACTOR = 'no_actor';
    public const INVALID_REQUEST = 'invalid_request';
    public const SLOT_UNAVAILABLE = 'slot_unavailable';
    public const SLOT_UNBOUND = 'slot_unbound';
    public const SLOT_MISFIT = 'slot_misfit';
    public const TEMPLATE_NOT_FOUND = 'template_not_found';
    public const TEMPLATE_UNPUBLISHED = 'template_unpublished';
    public const RECORD_NOT_FOUND = 'record_not_found';
    public const MISSING_FIELDS = 'missing_fields';
    public const FIELD_CONFLICT = 'field_conflict';
    public const VETOED = 'vetoed';
    public const ORIGIN_KEY_CONFLICT = 'origin_key_conflict';
    public const RENDER_UNAVAILABLE = 'render_unavailable';
    public const PERSIST_UNAVAILABLE = 'persist_unavailable';

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
