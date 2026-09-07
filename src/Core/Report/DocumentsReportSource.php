<?php

declare(strict_types=1);

namespace Whity\Core\Report;

use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\RBAC\CorePermissions;

/**
 * Core's first report source: the issued documents this caller may see
 * (#947 item 6).
 *
 * Chosen as the first source for a reason beyond convenience. #947's own
 * argument for a document browser is that every useful folder is DERIVABLE from
 * what routing already records rather than stored as a tree — and a report is
 * the same claim carried one step further: if the folders are queries, then a
 * printed report of a folder is that query, printed. Proving the seam over the
 * data the epic was written about is a stronger proof than inventing a source
 * for it.
 *
 * IT WRITES NO NEW SQL, AND THAT IS THE POINT
 * -------------------------------------------
 * Everything here goes through {@see DocumentCriteria} and
 * {@see DocumentRepository}, which already hold the tenant predicate that
 * `ci-tenant-predicate-guard.php` reads out of the source, and already push
 * visibility into the WHERE rather than filtering the result afterwards. A
 * source that reached for its own `SELECT` would have needed its own tenant
 * predicate, its own visibility disjuncts, and its own review — three copies of
 * decisions that are already argued and shipped, and the copy that drifted
 * would be a report that showed a reader documents their own list does not.
 *
 * The visibility narrowing is the platform's, not this class's:
 * {@see DocumentVisibilityPolicy::restrictToCreator()} answers with a profile id
 * or null, and that value is folded into the criteria — so a caller without
 * `documents:read:all` gets exactly the rows their own document list would show.
 *
 * @i18n-keys admin
 *   report.documents.title = Issued documents
 *   report.documents.reference = Reference
 *   report.documents.docTitle = Title
 *   report.documents.template = Template
 *   report.documents.raised = Raised
 */
final class DocumentsReportSource implements ReportSourceInterface
{
    private const DOMAIN = 'admin';

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly ServerLabels $labels,
    ) {
    }

    public function key(): string
    {
        return ReportSourceRegistry::CORE_DOCUMENTS;
    }

    public function label(string $language): string
    {
        // The language is set on the registry's behalf before this is called;
        // ServerLabels resolves against the active catalogue and falls back to
        // the declared English, so a tenant that has not translated the key
        // gets readable text rather than the key itself.
        unset($language);

        return $this->labels->label(self::DOMAIN, 'report.documents.title', 'Issued documents');
    }

    /**
     * @return list<ReportColumn>
     */
    public function columns(string $language): array
    {
        unset($language);

        return [
            ReportColumn::text('reference', $this->labels->label(self::DOMAIN, 'report.documents.reference', 'Reference')),
            ReportColumn::text('title', $this->labels->label(self::DOMAIN, 'report.documents.docTitle', 'Title')),
            ReportColumn::text('template_name', $this->labels->label(self::DOMAIN, 'report.documents.template', 'Template')),
            ReportColumn::dateTime('created_at', $this->labels->label(self::DOMAIN, 'report.documents.raised', 'Raised')),
        ];
    }

    /**
     * @param callable(string, int|null=): bool $hasPermission
     * @param callable(int): bool               $reachesOu
     * @return list<array<string, mixed>>
     */
    public function rows(
        int $tenantId,
        int $callerId,
        callable $hasPermission,
        callable $reachesOu,
        int $limit,
    ): array {
        $rows = $this->documents->listForCriteria($tenantId, $this->criteria($callerId, $hasPermission), $limit, 0);

        return array_map(
            static fn (array $row): array => [
                // The id IS the reference: it is what a route step, an audit
                // query and a pasted link all name, so a report that invented a
                // prettier one would be printing a number nobody can look up.
                'reference' => (string) $row['id'],
                'title' => (string) ($row['title'] ?? ''),
                'template_name' => (string) ($row['template_name'] ?? ''),
                'created_at' => $row['created_at'] ?? null,
            ],
            $rows
        );
    }

    /**
     * @param callable(string, int|null=): bool $hasPermission
     * @param callable(int): bool               $reachesOu
     */
    public function total(
        int $tenantId,
        int $callerId,
        callable $hasPermission,
        callable $reachesOu,
    ): int {
        return $this->documents->countForCriteria($tenantId, $this->criteria($callerId, $hasPermission));
    }

    public function requiredPermission(): string
    {
        return CorePermissions::DOCUMENTS_READ;
    }

    /**
     * The one predicate both `rows()` and `total()` use.
     *
     * Built once, in one place, because a count derived from a different
     * predicate than the rows is the exact failure the printed summary line
     * exists to prevent — it would tell a reader they are holding a subset of a
     * number that was never the size of the set they are holding a subset of.
     *
     * @param callable(string, int|null=): bool $hasPermission
     */
    private function criteria(int $callerId, callable $hasPermission): DocumentCriteria
    {
        return DocumentCriteria::unfiltered()->withRequestScope(
            $this->visibility->restrictToCreator($callerId, $hasPermission),
            null
        );
    }
}
