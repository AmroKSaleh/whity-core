<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

/**
 * Who a write to a workspace plugin's record is for (WHIT-763).
 *
 * A person writing through the API is `person($id)`. An automation run writes
 * as the plugin acting for the person who caused the event that started it,
 * so it carries that person (or none, for a run nobody caused) plus how many
 * automation hops led here: the depth guard reads it to stop automations
 * triggering each other forever. `rootRunId` is the first run of the chain,
 * which the per-write run budget counts against.
 */
final class RecordActor
{
    private function __construct(
        public readonly ?int $profileId,
        public readonly int $depth,
        public readonly ?int $runId,
        public readonly ?string $automation,
        public readonly ?int $rootRunId,
    ) {
    }

    public static function person(int $profileId): self
    {
        return new self($profileId, 0, null, null, null);
    }

    /** An automation run acting for `$profileId`, in the chain `$rootRunId` started. */
    public static function automation(?int $profileId, int $depth, int $runId, string $automation, int $rootRunId): self
    {
        return new self($profileId, $depth, $runId, $automation, $rootRunId);
    }

    public function isAutomation(): bool
    {
        return $this->runId !== null;
    }
}
