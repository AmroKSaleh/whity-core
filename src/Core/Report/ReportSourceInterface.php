<?php

declare(strict_types=1);

namespace Whity\Core\Report;

/**
 * One thing a report can be run over (#947 item 6).
 *
 * The epic asks for "a query result spanning first-party and plugin entities."
 * This is the seam that spans them, and its most important property is what it
 * does NOT accept.
 *
 * A SOURCE RETURNS ROWS. IT NEVER RETURNS SQL.
 * --------------------------------------------
 * The tempting design is a declaration — a table, some columns, maybe a filter
 * — that core assembles into a statement. This codebase has already refused
 * that shape twice, and both refusals apply here verbatim:
 *
 *   {@see \Whity\Core\Document\Organizer\DocumentCriteria} refuses to let a
 *   view supply its own `AND …` fragment, because `ci-tenant-predicate-guard.php`
 *   proves tenant isolation by READING LITERAL SQL OUT OF THE SOURCE — and an
 *   assembled predicate is exactly the statement CI cannot police.
 *
 *   {@see \Whity\Http\ListSpec} states the same split one layer down: column
 *   expressions are CODE, not input, and a handler must never build them from
 *   anything a caller sent. A source declaration loaded at runtime is input.
 *
 * So each source runs its own query, in its own file, with its own literal
 * `tenant_id` predicate that the scanner can see and the reviewer can read. The
 * cost is that a source is a class rather than an array. The return is that
 * every report in the system is tenant-scoped by a statement CI has checked,
 * and that adding a source cannot weaken that.
 *
 * PERMISSION IS THE SOURCE'S OWN, NOT A NEW ONE
 * ----------------------------------------------
 * {@see requiredPermission()} names an EXISTING permission — the one that
 * already governs reading this data on its own screen. Reporting on documents
 * needs `documents:read` and nothing more, because a report is a READ: inventing
 * `reports:run` would create a second answer to "may this person see these
 * rows", and the two would drift the moment either moved.
 *
 * A source is additionally handed the caller's capability closure and OU reach
 * so it can NARROW further. Reach has to be a second predicate ANDed onto the
 * capability gate — resource-scoped RBAC is additive by construction and can
 * express an exception but never a restriction (see
 * {@see \Whity\Core\Ou\OuReachResolver}).
 *
 * WHY THIS IS NOT IN THE SDK
 * --------------------------
 * Same reason {@see \Whity\Core\Inbox\InboxSourceRegistry} withheld its own
 * plugin-facing half: an SDK contract, once vendored and version-pinned into
 * every device host, cannot be quietly broken. Three questions this interface
 * does not yet answer — how heterogeneous sources are ordered against each
 * other, what happens when one of several fails, and how pagination works
 * across them — are the same three left open there. Publishing the contract
 * before they are settled would publish one that then has to break. Core
 * sources first; the SDK version once the shape has survived contact.
 */
interface ReportSourceInterface
{
    /**
     * The stable key this source is addressed by, e.g. `documents`.
     *
     * Bare for core sources. A plugin-contributed source, when that exists,
     * will be namespaced by the registry the way every other plugin
     * contribution is — the plugin does not get to choose an unqualified name.
     */
    public function key(): string;

    /**
     * The report's title, in the reader's language.
     *
     * Resolved by the source rather than passed in, because only the source
     * knows which i18n domain its wording lives in.
     */
    public function label(string $language): string;

    /**
     * The columns, in the order they are printed.
     *
     * Declared rather than inferred from the first row: a report whose columns
     * came from `array_keys($rows[0])` would silently change shape when a row
     * happened to carry a null, and would produce NO columns at all for an
     * empty result — printing a document that says nothing was found while also
     * failing to say what was looked for.
     *
     * @return list<ReportColumn>
     */
    public function columns(string $language): array;

    /**
     * The rows, already materialised, tenant-scoped and filtered for this
     * caller.
     *
     * VISIBILITY BELONGS IN THE `WHERE`, not in a post-filter over the result.
     * Filtering after the fact makes the count wrong, makes the limit mean a
     * different number of rows on every page, and quietly turns a report into a
     * sampling of one. {@see \Whity\Core\Document\DocumentVisibilityPolicy} is
     * the pattern: it answers with a value that gets folded into the query.
     *
     * @param int      $tenantId       Never trusted from a caller; the host's own.
     * @param int      $callerId       The acting profile.
     * @param callable(string, int|null=): bool $hasPermission The caller's
     *        capability closure — {@see \Whity\Core\RBAC\ScopedPermissionSet::forProfile()}.
     *        Six handlers each carried a copy of this before it existed; do not
     *        write a seventh.
     * @param callable(int): bool $reachesOu Whether this caller has standing in
     *        a given organizational unit — {@see \Whity\Core\Ou\OuReachResolver::reachFor()}.
     *        A predicate rather than a list of ids, matching
     *        {@see \Whity\Core\Document\DocumentAccessPolicy::canView()}: the
     *        resolver memoises the subtree walk once per request, and a source
     *        handed the expanded list could pass it somewhere that then treated
     *        an empty list as "no restriction".
     * @param int      $limit          Hard ceiling on rows returned.
     *
     * @return list<array<string, mixed>> Keyed by the declared column keys.
     */
    public function rows(
        int $tenantId,
        int $callerId,
        callable $hasPermission,
        callable $reachesOu,
        int $limit,
    ): array;

    /**
     * How many rows MATCH, which is not how many were returned.
     *
     * The real total, counted by the same predicate `rows()` uses. A report
     * that printed 5,000 rows and a total of 5,000 when 40,000 matched would be
     * wrong in the one way a printed document cannot be corrected — the reader
     * has no way to tell they are holding a truncation.
     *
     * @param callable(string, int|null=): bool $hasPermission
     * @param callable(int): bool               $reachesOu
     */
    public function total(
        int $tenantId,
        int $callerId,
        callable $hasPermission,
        callable $reachesOu,
    ): int;

    /**
     * The EXISTING permission that governs reading this data.
     *
     * See the class note: a report is a read, and a second permission
     * vocabulary for it would be a second answer to a question that already
     * has one.
     */
    public function requiredPermission(): string;
}
