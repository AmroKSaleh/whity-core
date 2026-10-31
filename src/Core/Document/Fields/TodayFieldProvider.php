<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

/**
 * The Today group every template has: the date the document is made. In a PDF
 * it is the date Whity Render made it, frozen with the other values (E.16).
 */
final class TodayFieldProvider implements DocumentFieldProvider
{
    private FieldLabels $labels;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $now;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now For tests.
     */
    public function __construct(?FieldLabels $labels = null, ?\Closure $now = null)
    {
        $this->labels = $labels ?? new FieldLabels();
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now');
    }

    public function recordType(): string
    {
        return '*';
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.group.today', 'Today');
    }

    public function groups(int $tenantId): array
    {
        return [[
            'group' => $this->recordLabel(),
            'items' => [['path' => 'today', 'label' => $this->labels->get('fields.today', 'Today'), 'sample' => $this->today()]],
        ]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        return ['label' => '', 'values' => ['today' => $this->today()]];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        return [];
    }

    private function today(): string
    {
        return $this->labels->date(($this->now)());
    }
}
