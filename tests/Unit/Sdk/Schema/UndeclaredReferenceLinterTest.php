<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Schema;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Schema\ReferenceDeclarations;
use Whity\Sdk\Schema\UndeclaredReferenceLinter;

/**
 * Teeth AND restraint. Both halves matter and each is worthless without the
 * other:
 *
 *  - a linter that does not FIRE on the orphaning bug is decoration;
 *  - a linter that fires on this platform's FK-less-by-convention design would
 *    flag nearly every plugin table, be muted within a day, and take the
 *    credibility of the tenant guards with it.
 *
 * So the central pair is
 * {@see testUndeclaredReferenceToAKnownTableIsFlagged} and
 * {@see testTheSameSchemaPassesOnceTheEdgeIsDeclared} — the SAME schema,
 * failing and then passing, with nothing changed but the declaration.
 */
final class UndeclaredReferenceLinterTest extends TestCase
{
    /** The tables the platform can resolve in these fixtures. */
    private const KNOWN = ['tenants', 'acme_records', 'acme_entries', 'users', 'audit_log', 'categories'];

    // ==================== the teeth ====================

    public function testUndeclaredReferenceToAKnownTableIsFlagged(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            acme_record_id INTEGER NOT NULL,
            note TEXT
        )';

        $violations = $linter->lintSource($sql, 'm.php');

        self::assertCount(1, $violations, 'A reference core cannot see must FAIL.');
        self::assertSame('acme_entries', $violations[0]['table']);
        self::assertSame('acme_record_id', $violations[0]['column']);
        self::assertSame('acme_records', $violations[0]['target']);
        self::assertStringContainsString('blocks_delete', $violations[0]['reason']);
        self::assertStringContainsString('cascade_delete', $violations[0]['reason']);
    }

    public function testTheSameSchemaPassesOnceTheEdgeIsDeclared(): void
    {
        // Not one byte of schema changes. The plugin told core about the
        // relationship, which is the entire remedy the linter asks for.
        $declarations = ReferenceDeclarations::fromDataTypes([
            'record' => [
                'table' => 'acme_records',
                'blocks_delete' => [
                    ['table' => 'acme_entries', 'column' => 'acme_record_id', 'label' => 'recorded entries'],
                ],
            ],
        ], 'Acme');

        $linter = new UndeclaredReferenceLinter(self::KNOWN, $declarations);

        $sql = 'CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            acme_record_id INTEGER NOT NULL,
            note TEXT
        )';

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    /**
     * The composition half of the graph counts identically. `blocks_delete` and
     * `cascade_delete` are opposite ANSWERS — must outlive versus dies with —
     * but for "does core know this edge exists?" they are the same fact, and a
     * cascade is if anything the stronger declaration.
     */
    public function testACascadeDeclarationSatisfiesTheLinterToo(): void
    {
        $declarations = ReferenceDeclarations::fromDataTypes([
            'record' => [
                'table' => 'acme_records',
                'cascade_delete' => [
                    ['table' => 'acme_entries', 'column' => 'acme_record_id', 'label' => 'line items'],
                ],
            ],
        ], 'Acme');

        $linter = new UndeclaredReferenceLinter(self::KNOWN, $declarations);

        $sql = 'CREATE TABLE acme_entries (id SERIAL PRIMARY KEY, acme_record_id INTEGER NOT NULL)';

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    // ==================== the restraint ====================

    /**
     * THE RULE THIS LINTER MUST NOT IMPLEMENT. No foreign keys between plugin
     * tables is the established convention here — it is why declared guards
     * exist at all. A schema with zero FKs and a complete declaration passes
     * completely, and the linter says nothing about the missing FKs.
     */
    public function testAnFkLessSchemaWithDeclaredEdgesIsEntirelyClean(): void
    {
        $declarations = ReferenceDeclarations::fromDataTypes([
            'record' => [
                'table' => 'acme_records',
                'blocks_delete' => [
                    ['table' => 'acme_entries', 'column' => 'acme_record_id', 'label' => 'entries'],
                ],
                'cascade_delete' => [
                    ['table' => 'acme_lines', 'column' => 'acme_record_id', 'label' => 'lines'],
                ],
            ],
        ], 'Acme');

        $linter = new UndeclaredReferenceLinter(self::KNOWN, $declarations);

        $sql = '
            CREATE TABLE acme_records (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL
            );
            CREATE TABLE acme_entries (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                acme_record_id INTEGER NOT NULL
            );
            CREATE TABLE acme_lines (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                acme_record_id INTEGER NOT NULL
            );
        ';

        self::assertSame(
            [],
            $linter->lintSource($sql, 'm.php'),
            'Not one foreign key anywhere, and nothing to report — the convention is not the defect.'
        );
    }

    /**
     * `tenant_id` is on every tenant-owned table, carries no FK by convention,
     * and is never in a reference graph. Flagging it would fire on literally
     * every plugin table and prove nothing that
     * MigrationTenantColumnLinter + TenantPredicateScanner do not prove better.
     */
    public function testTenantIdIsNeverFlagged(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_notes (id SERIAL PRIMARY KEY, tenant_id INTEGER NOT NULL, body TEXT)';

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    /**
     * An `*_id` that names nothing the platform knows is not a reference. This
     * is what keeps the rule narrow enough to be believed rather than a
     * name-shape heuristic that invents relationships.
     */
    public function testIdColumnsThatNameNoKnownTableAreNotReferences(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_notes (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            stripe_customer_id VARCHAR(64),
            external_ref_id VARCHAR(64),
            legacy_id INTEGER
        )';

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    public function testAnEnforcedReferenceNeedsNoDeclaration(): void
    {
        // The engine already refuses the orphan. There is nothing for core to
        // be told, and asking for a declaration on top would be busywork.
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            acme_record_id INTEGER NOT NULL REFERENCES acme_records(id) ON DELETE CASCADE
        )';

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    public function testATableLevelForeignKeyCountsAsEnforcement(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            tenant_id INTEGER NOT NULL,
            acme_record_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            FOREIGN KEY (acme_record_id) REFERENCES acme_records(id),
            FOREIGN KEY (user_id) REFERENCES users(id)
        )';

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    /**
     * A nested `REFERENCES parent(id)` paren must not truncate the body, or
     * every column after the first FK would go unexamined — a silent hole that
     * looks exactly like a clean pass.
     */
    public function testNestedReferenceParensDoNotTruncateTheBody(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            acme_record_id INTEGER NOT NULL
        )';

        $violations = $linter->lintSource($sql, 'm.php');

        self::assertCount(1, $violations);
        self::assertSame('acme_record_id', $violations[0]['column']);
    }

    /**
     * A body written with several columns on one line must be examined in full.
     * Stopping at the first comma is a false negative that looks exactly like a
     * clean pass — the failure mode this whole file exists to avoid.
     */
    public function testSeveralColumnsOnOneLineAreAllExamined(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_entries (id SERIAL PRIMARY KEY, acme_record_id INTEGER, user_id INTEGER)';

        $flagged = array_map(
            static fn (array $v): string => $v['column'],
            $linter->lintSource($sql, 'm.php')
        );
        sort($flagged);

        self::assertSame(['acme_record_id', 'user_id'], $flagged);
    }

    public function testInlineForeignKeyIsCreditedToItsOwnColumnOnASharedLine(): void
    {
        // Crediting the line's FIRST identifier would mark `acme_record_id`
        // enforced and let `user_id` off, which is both wrong answers at once.
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = 'CREATE TABLE acme_entries (acme_record_id INTEGER, user_id INTEGER REFERENCES users(id))';

        $violations = $linter->lintSource($sql, 'm.php');

        self::assertCount(1, $violations);
        self::assertSame('acme_record_id', $violations[0]['column']);
    }

    // ==================== the escape hatch ====================

    public function testAReasonedIgnoreAnnotationOnTheColumnSilencesIt(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = "CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            acme_record_id INTEGER, -- @reference-lint-ignore: import staging key, resolved and cleared by the importer
            note TEXT
        )";

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    public function testTheAnnotationIsAlsoHonouredOnThePrecedingLine(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = "CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            -- @reference-lint-ignore: denormalised snapshot, deliberately not a live edge
            acme_record_id INTEGER,
            note TEXT
        )";

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    /**
     * A bare tag does NOT silence anything. The value of the annotation is that
     * a human decided; a decision with no reason recorded is indistinguishable
     * from a muted alarm, and would let the hatch become the default.
     */
    public function testABareIgnoreTagWithNoReasonDoesNotSilenceAnything(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = "CREATE TABLE acme_entries (
            id SERIAL PRIMARY KEY,
            acme_record_id INTEGER -- @reference-lint-ignore:
        )";

        self::assertCount(1, $linter->lintSource($sql, 'm.php'));
    }

    public function testAnAnnotationDoesNotLeakOntoAnUnrelatedColumn(): void
    {
        // An exemption that applied from a distance would drift onto columns it
        // was never written for — the way a linter stops meaning anything.
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = "CREATE TABLE acme_entries (
            acme_record_id INTEGER, -- @reference-lint-ignore: staging key
            note TEXT,
            category_id INTEGER
        )";

        $violations = $linter->lintSource($sql, 'm.php');

        self::assertCount(1, $violations);
        self::assertSame('category_id', $violations[0]['column']);
    }

    public function testABareTableIgnoreTagDoesNotSilenceAnythingEither(): void
    {
        // The wider the exemption, the more the reason matters.
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = "CREATE TABLE acme_import_staging (
            -- @reference-lint-ignore-table:
            acme_record_id INTEGER,
            user_id INTEGER
        )";

        self::assertCount(2, $linter->lintSource($sql, 'm.php'));
    }

    public function testAWholeTableCanBeExempted(): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $sql = "CREATE TABLE acme_import_staging (
            -- @reference-lint-ignore-table: raw import rows; ids are unresolved text until the importer runs
            acme_record_id INTEGER,
            user_id INTEGER,
            category_id INTEGER
        )";

        self::assertSame([], $linter->lintSource($sql, 'm.php'));
    }

    // ==================== plural resolution ====================

    /**
     * @dataProvider pluralForms
     */
    public function testColumnStemsResolveThroughOrdinaryPlurals(string $column, string $expectedTarget): void
    {
        $linter = new UndeclaredReferenceLinter(self::KNOWN, new ReferenceDeclarations());

        $violations = $linter->lintSource("CREATE TABLE t (\n    {$column} INTEGER\n)", 'm.php');

        self::assertCount(1, $violations, "{$column} must resolve to {$expectedTarget}");
        self::assertSame($expectedTarget, $violations[0]['target']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function pluralForms(): array
    {
        return [
            'exact table name' => ['audit_log_id', 'audit_log'],
            'simple plural' => ['user_id', 'users'],
            'y to ies' => ['category_id', 'categories'],
        ];
    }

    // ==================== declaration reading ====================

    public function testMalformedDeclarationEntriesAreSkippedRatherThanThrowing(): void
    {
        // A linter that dies on a declaration the host would merely log is a
        // linter people stop running.
        //
        // The @var is the point of the test, not a workaround: the parameter is
        // TYPED array<string, array<string, mixed>>, but a plugin's
        // getDataTypes() is plugin-authored PHP and can return anything at
        // runtime. This is the shape a real malformed declaration arrives in.
        /** @var array<string, array<string, mixed>> $malformed */
        $malformed = [
            'record' => [
                'blocks_delete' => [
                    ['column' => 'acme_record_id'],            // no table
                    ['table' => 'acme_entries'],               // no column
                    'not-an-array',
                    ['table' => 'acme_entries', 'column' => 'acme_record_id'],
                ],
            ],
            'broken' => 'not-an-array',
        ];

        $declarations = ReferenceDeclarations::fromDataTypes($malformed, 'Acme');

        self::assertTrue($declarations->declares('acme_entries', 'acme_record_id'));
        self::assertSame(['acme_entries.acme_record_id' => 'acme:record'], $declarations->all());
    }

    public function testDeclarationsFromSeveralPluginsMerge(): void
    {
        $a = ReferenceDeclarations::fromDataTypes([
            'record' => ['blocks_delete' => [['table' => 'acme_entries', 'column' => 'acme_record_id']]],
        ], 'Acme');
        $b = ReferenceDeclarations::fromDataTypes([
            'thing' => ['cascade_delete' => [['table' => 'other_rows', 'column' => 'user_id']]],
        ], 'Other');

        $merged = $a->merge($b);

        self::assertTrue($merged->declares('acme_entries', 'acme_record_id'));
        self::assertTrue($merged->declares('other_rows', 'user_id'));
    }

    public function testDeclarationLookupIsCaseInsensitive(): void
    {
        $declarations = (new ReferenceDeclarations())->with('Acme_Entries', 'Acme_Record_Id');

        self::assertTrue($declarations->declares('acme_entries', 'acme_record_id'));
    }
}
