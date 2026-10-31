<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

/**
 * Something that happened to a workspace plugin's record, as automations
 * see it (WHIT-763): it was created, changed (these fields), or moved (into
 * this state). Carries the record as it now is and who did it.
 */
final class RecordEvent
{
    public const CREATED = 'created';
    public const CHANGED = 'changed';
    public const MOVED = 'moved';

    /**
     * @param list<string> $changedFields Fields whose value changed.
     * @param array<string, mixed> $data The record's fields after the write.
     * @param list<string> $roles The actor's role names (none for the plugin alone).
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $recordType,
        public readonly int $recordId,
        public readonly ?string $state,
        public readonly array $data,
        public readonly array $changedFields,
        public readonly RecordActor $actor,
        public readonly array $roles,
    ) {
    }

    /** As an automation's `on` names it: `order.moved.confirmed`, `order.changed`. */
    public function name(): string
    {
        $name = $this->recordType . '.' . $this->kind;

        return $this->kind === self::MOVED && $this->state !== null ? $name . '.' . $this->state : $name;
    }
}
