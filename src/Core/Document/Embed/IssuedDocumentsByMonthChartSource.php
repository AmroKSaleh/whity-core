<?php

declare(strict_types=1);

namespace Whity\Core\Document\Embed;

use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\RBAC\CorePermissions;

/**
 * "Documents issued per month" for Chart blocks: the last six calendar months,
 * oldest first, every month present (a month with none is a zero bar, not a
 * gap).
 *
 * Counted with the documents list's own predicate
 * ({@see DocumentVisibilityPolicy::restrictToCreator()} pushed into SQL), so
 * two readers of one shared document can see different bars, and neither sees
 * more than their own document list.
 */
final class IssuedDocumentsByMonthChartSource implements DocumentChartSource
{
    private const MONTHS = 6;

    /** @var \Closure(): \DateTimeImmutable */
    private \Closure $now;

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $now For tests.
     */
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function key(): string
    {
        return 'documents-by-month';
    }

    public function label(): string
    {
        return 'Documents issued per month';
    }

    public function load(int $tenantId, int $callerId, callable $hasPermission, array $params): ?array
    {
        if (!$hasPermission(CorePermissions::DOCUMENTS_READ)) {
            return null;
        }

        $first = ($this->now)()->modify('first day of this month')->setTime(0, 0)->modify('-' . (self::MONTHS - 1) . ' months');
        $criteria = DocumentCriteria::unfiltered()->withRequestScope($this->visibility->restrictToCreator($callerId, $hasPermission), null);
        $counts = $this->documents->countByMonthForCriteria($tenantId, $criteria, $first->format('Y-m-d H:i:s'));

        $points = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $first->modify("+{$i} months");
            $points[] = ['label' => $month->format('M Y'), 'value' => $counts[$month->format('Y-m')] ?? 0];
        }

        return ['points' => $points, 'value_label' => 'Documents'];
    }
}
