<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * The core navigation registrations in public/index.php, as DATA.
 *
 * WHY THIS PARSES SOURCE
 * ----------------------
 * The items are built inside a `navigation.register` listener closure that only
 * exists once the whole application has bootstrapped — services, database,
 * plugin loader and all. There is no seam to call and no fixture that returns
 * them, so the registrations are read as text. That is crude, and it is still
 * worth it: what it checks is a property of the LIST rather than of any one
 * item, which is exactly the kind of thing no per-item review notices.
 *
 * WHAT WENT WRONG WITHOUT IT (#1007)
 * ----------------------------------
 * Before grouping, all 22 items shared one group and their `order` values
 * collided — seven were `order => 9`. Sequence therefore came down to
 * registration order plus a stable sort, and the workaround for inserting an
 * item without renumbering its neighbours was a FRACTIONAL order: #670 landed
 * `order => 9.2`, and 1.5, 9.3, 9.5, 9.6 and 9.7 followed.
 *
 * Those fractions are what made the regrouping go half-done. A rewrite pass
 * matched `'order' => N,` — integer, trailing comma — so it silently skipped
 * every fractional entry, and six items kept an order from the old flat scheme
 * while their group moved to the new one. Nothing failed: the sidebar rendered,
 * every link worked, and only the SEQUENCE inside two groups was wrong, which
 * no test and no reviewer would catch by reading a diff. It was found by
 * querying the live API afterwards.
 *
 * So the invariant is asserted rather than intended:
 *
 *  - every item declares a `group`, except the one documented ungrouped link;
 *  - `order` is a positive INTEGER — a fraction means someone is inserting
 *    between neighbours again, which is what produced the mess above;
 *  - `order` is unique WITHIN a group, so the sequence is chosen and not the
 *    residue of a sort;
 *  - `group` comes from the known set, so a typo does not silently create a
 *    seventh group that renders after the declared ones.
 */
final class CoreNavigationRegistrationTest extends TestCase
{
    /**
     * Groups the shell knows how to order and label. Mirrors NAV_GROUP_ORDER in
     * web/components/sidebar.tsx. An unlisted group still RENDERS (after the
     * declared ones, unlabelled beyond its prettified id) — this list exists so
     * that happens on purpose rather than through a typo.
     *
     * @var list<string>
     */
    private const KNOWN_GROUPS = [
        'overview',
        'access',
        'documents',
        'records',
        'extend',
        'system',
        'plugins',
    ];

    /**
     * The account link is deliberately ungrouped: it renders last with no
     * heading, which is also what keeps it outside group disclosure and so
     * reachable in every state. See the comment above the listener.
     */
    private const UNGROUPED_ITEM_IDS = ['settings'];

    /**
     * @return array<string, array{group: string|null, order: string}>
     */
    private function registrations(): array
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');

        $start = strpos($source, "\$hookManager->listen('navigation.register'");
        self::assertNotFalse($start, 'The core navigation.register listener is no longer recognisable.');

        $end = strpos($source, "\n});", $start);
        self::assertNotFalse($end, 'Could not find the end of the navigation.register listener.');

        $block = substr($source, $start, $end - $start);

        preg_match_all('/\$items\[\] = \[(.*?)\n    \];/s', $block, $matches, PREG_SET_ORDER);
        self::assertNotEmpty($matches, 'No navigation items parsed — has the registration style changed?');

        $items = [];
        foreach ($matches as $match) {
            $body = $match[1];

            if (preg_match("/'id' => '([^']*)'/", $body, $id) !== 1) {
                self::fail('A navigation item declares no id: ' . trim($body));
            }

            // Captured as a STRING on purpose: casting to int here would turn
            // 9.2 into 9 and hide the very thing being checked.
            if (preg_match("/'order' => ([0-9.]+)/", $body, $order) !== 1) {
                self::fail(sprintf('Navigation item "%s" declares no order.', $id[1]));
            }

            $group = preg_match("/'group' => '([^']*)'/", $body, $g) === 1 ? $g[1] : null;

            $items[$id[1]] = ['group' => $group, 'order' => $order[1]];
        }

        return $items;
    }

    public function testEveryItemDeclaresAKnownGroupOrIsDocumentedAsUngrouped(): void
    {
        foreach ($this->registrations() as $id => $item) {
            if (in_array($id, self::UNGROUPED_ITEM_IDS, true)) {
                self::assertNull(
                    $item['group'],
                    sprintf(
                        'Item "%s" is documented as ungrouped (renders last, no heading, never hidden by '
                        . 'disclosure) but now declares a group. Update UNGROUPED_ITEM_IDS if that is intended.',
                        $id
                    )
                );
                continue;
            }

            self::assertNotNull(
                $item['group'],
                sprintf(
                    'Item "%s" declares no group, so it renders in the trailing unheaded bucket beside the '
                    . 'account link. Give it a group, or add it to UNGROUPED_ITEM_IDS with a reason.',
                    $id
                )
            );

            self::assertContains(
                $item['group'],
                self::KNOWN_GROUPS,
                sprintf(
                    'Item "%s" declares group "%s", which the shell does not know. It will still render, '
                    . 'after every declared group and with a heading derived from the id — usually a typo. '
                    . 'Add it to NAV_GROUP_ORDER (web/components/sidebar.tsx) and here if it is real.',
                    $id,
                    (string) $item['group']
                )
            );
        }
    }

    public function testEveryOrderIsAPositiveInteger(): void
    {
        foreach ($this->registrations() as $id => $item) {
            self::assertMatchesRegularExpression(
                '/^[0-9]+$/',
                $item['order'],
                sprintf(
                    'Item "%s" has order "%s". A FRACTIONAL order means an item is being wedged between two '
                    . 'neighbours instead of renumbering them — the habit that left six items on the old flat '
                    . 'scheme when the nav was regrouped (#1007), because a rewrite matching integers skipped '
                    . 'them in silence. Renumber the group instead; orders are group-local, so it is cheap.',
                    $id,
                    $item['order']
                )
            );

            self::assertGreaterThan(
                0,
                (int) $item['order'],
                sprintf('Item "%s" has order %s; orders start at 1.', $id, $item['order'])
            );
        }
    }

    public function testOrdersAreUniqueWithinEachGroup(): void
    {
        $byGroup = [];
        foreach ($this->registrations() as $id => $item) {
            $byGroup[$item['group'] ?? '(ungrouped)'][$id] = (int) $item['order'];
        }

        foreach ($byGroup as $group => $orders) {
            $duplicated = array_keys(array_filter(array_count_values($orders), static fn (int $n): bool => $n > 1));

            self::assertSame(
                [],
                $duplicated,
                sprintf(
                    'Group "%s" has more than one item at order(s) %s (%s). Ties are broken by label, so the '
                    . 'sequence stops being a choice and becomes a side effect of sorting — which is how seven '
                    . 'items at order 9 made the old sidebar order unpredictable.',
                    $group,
                    implode(', ', $duplicated),
                    implode(', ', array_map(
                        static fn (string $id, int $order): string => $id . '=' . $order,
                        array_keys($orders),
                        $orders
                    ))
                )
            );
        }
    }
}
