<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use Whity\Core\Hooks\HookManager;

/**
 * Assembles {@see WindowCloseReport} — what a close is about to seal (#1070).
 *
 * "You are about to close this period with four items still unfinished" is the
 * difference between a control and a trap, and a report is cheap: one query for
 * the structural half and one hook dispatch for the domain half.
 *
 * THE HOOK
 * --------
 * `time_window.close_report` is a FILTER hook. It is dispatched with the period
 * and an empty `unfinished` list, and a listener appends to that list:
 *
 *     $hooks->listen('time_window.close_report', function (array $data): array {
 *         $data['unfinished'][] = [
 *             'label'  => 'Firing runs without a cooling log',
 *             'count'  => 4,
 *             'source' => 'acme',
 *         ];
 *         return $data;
 *     });
 *
 * A listener is given `window_id`, `tenant_id`, `starts_on` and `ends_on`, which
 * is everything needed to count records in the period without core knowing what
 * a record is. It is a REPORT: a listener may not block the close, may not
 * modify the period, and anything it returns other than a well-formed list is
 * discarded rather than trusted — see {@see normalizeContributions()}.
 *
 * WHY A LISTENER CANNOT BLOCK
 * ---------------------------
 * Because whether unfinished work should stop a close is exactly the question
 * this subsystem ships WITHOUT an answer to, and a veto is that question
 * answered. Handing plugins a veto would settle it by accident, in whichever
 * plugin implemented it first, for every deployment that installed that plugin.
 * The one blocking condition is structural and core owns it: an open period
 * nested inside the one being closed (see {@see WindowCloseReport::isBlocked()}).
 *
 * WHY THE EMPTY CASE IS DISTINGUISHED
 * -----------------------------------
 * "No listener answered" and "every listener answered zero" both produce an empty
 * list, and only the second is a reason to close with confidence. The report
 * carries which one it was, so a screen can say "nothing is tracking unfinished
 * work in this period" rather than implying an all-clear nobody gave.
 */
final class WindowCloseReporter
{
    public const HOOK = 'time_window.close_report';

    public function __construct(
        private readonly TimeWindowRepository $windows,
        private readonly ?HookManager $hooks = null,
    ) {
    }

    /**
     * Report on what closing this period would seal.
     *
     * @return WindowCloseReport|null Null when the period does not exist for this tenant.
     */
    public function report(int $tenantId, int $windowId): ?WindowCloseReport
    {
        $window = $this->windows->find($tenantId, $windowId);
        if ($window === null) {
            return null;
        }

        $openChildren = $this->windows->openChildren($tenantId, $windowId);

        $contributions = [];
        $answered = false;
        if ($this->hooks !== null) {
            $result = $this->hooks->dispatch(self::HOOK, [
                'tenant_id' => $tenantId,
                'window_id' => $windowId,
                'window_type_id' => $window['window_type_id'],
                'starts_on' => $window['starts_on'],
                'ends_on' => $window['ends_on'],
                'unfinished' => [],
            ]);
            $raw = $result['unfinished'] ?? [];
            $answered = is_array($raw) && $raw !== [];
            $contributions = self::normalizeContributions($raw);
        }

        return new WindowCloseReport($window, $openChildren, $contributions, $answered);
    }

    /**
     * Keep only well-formed contributions, and discard the rest without failing.
     *
     * A malformed entry costs that contributor its line and costs the report
     * nothing. The alternative — throwing — would let one plugin's typo make a
     * period impossible to close, which is a far worse failure than an
     * incomplete report, and it would do so at the exact moment somebody is
     * trying to close the books.
     *
     * @param mixed $raw
     * @return list<array{label: string, count: int, source: string}>
     */
    private static function normalizeContributions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $label = $entry['label'] ?? null;
            $count = $entry['count'] ?? null;
            $source = $entry['source'] ?? null;
            if (!is_string($label) || trim($label) === '') {
                continue;
            }
            if (!is_int($count) || $count < 0) {
                continue;
            }
            $clean[] = [
                'label' => trim($label),
                'count' => $count,
                'source' => is_string($source) && trim($source) !== '' ? trim($source) : 'unknown',
            ];
        }

        return $clean;
    }
}
