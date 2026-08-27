<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Tenant\TenantPredicateScanner;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * Teeth for the WC-194 SDK tenant-predicate scanner used by the conformance
 * kit: an unscoped SELECT/UPDATE/DELETE on a PLUGIN's own tenant-owned table
 * (or, via a merged registry, a HOST tenant table) must FAIL; a scoped one, a
 * declared-global one, and an annotated one must NOT.
 *
 * The scanner is registry-driven (unlike core's hardwired guard), so these
 * exercise a PLUGIN-declared table set — proving the engine polices whatever
 * tables it is handed, which is what makes it reusable for out-of-repo plugins.
 */
final class TenantPredicateScannerTest extends TestCase
{
    /** A registry as a plugin would build it: own table + (here, a stand-in) host table. */
    private function registry(): TenantTableRegistry
    {
        return TenantTableRegistry::for(
            ['announcements' => 'plugin tenant table', 'users' => 'host tenant table (merged)'],
            ['app_settings' => 'platform-wide settings']
        );
    }

    /** @param non-empty-string $sql */
    private function scan(string $sql): array
    {
        $source = <<<PHP
        <?php
        class Probe
        {
            public function run(\$db): void
            {
                \$stmt = \$db->prepare('{$sql}');
            }
        }
        PHP;

        return (new TenantPredicateScanner($this->registry()))->scanSource($source, 'Probe.php');
    }

    public function testUnscopedQueryOnPluginTenantTableIsFlagged(): void
    {
        // THE TEETH: the plugin's own tenant table queried without a tenant_id.
        $violations = $this->scan('SELECT id, body FROM announcements WHERE id = ?');

        self::assertCount(1, $violations, 'An unscoped query on the plugin tenant table must FAIL.');
        self::assertSame(['announcements'], $violations[0]['tables']);
    }

    public function testUnscopedQueryOnMergedHostTableIsFlagged(): void
    {
        // A plugin that touches a HOST tenant table unscoped is flagged too,
        // because the conformance test merges the host registry in.
        self::assertCount(1, $this->scan('DELETE FROM users WHERE id = ?'));
    }

    public function testScopedQueryOnPluginTenantTableIsNotFlagged(): void
    {
        self::assertSame([], $this->scan('SELECT id FROM announcements WHERE id = ? AND tenant_id = ?'));
        self::assertSame([], $this->scan('UPDATE announcements SET body = ? WHERE id = ? AND tenant_id = :tid'));
    }

    public function testDeclaredGlobalTableIsNeverFlagged(): void
    {
        self::assertSame([], $this->scan('SELECT value FROM app_settings WHERE id = ?'));
    }

    public function testUnknownTableIsNotPoliced(): void
    {
        // A table the registry knows nothing about is out of scope (the plugin
        // only owns the isolation invariant for tables it declares).
        self::assertSame([], $this->scan('SELECT * FROM some_other_thing WHERE id = ?'));
    }

    public function testAnnotationSuppressesAFlagWithReason(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                // @tenant-guard-ignore: system-tenant (id 0) sees all tenants
                $stmt = $db->prepare('SELECT * FROM announcements WHERE id = ?');
            }
        }
        PHP;

        self::assertSame([], (new TenantPredicateScanner($this->registry()))->scanSource($source, 'Probe.php'));
    }

    /**
     * The failure this diagnostic exists for, and it is worth stating plainly:
     * an annotation written tag-FIRST with the reason continuing beneath it puts
     * the tag out of the lookback window, so the annotation stops applying while
     * still reading, to a person, exactly as though it does.
     *
     * A `//` comment block is one token per line and only the line bearing the
     * tag is marked, so the length of the reason decides whether the suppression
     * works. That is a trap, and it caught a real retention sweep.
     */
    public function testAnAnnotationTooFarAboveDoesNotSuppressButIsReported(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                // @tenant-guard-ignore: system-tenant (id 0) sees all tenants, and
                // this reason runs on for long enough that the tag itself ends up
                // well outside the window the scanner actually looks in, which is
                // the whole point of this fixture — the annotation reads as
                // deliberate and does nothing at all.
                $stmt = $db->prepare('SELECT * FROM announcements WHERE id = ?');
            }
        }
        PHP;

        $violations = (new TenantPredicateScanner($this->registry()))->scanSource($source, 'Probe.php');

        self::assertCount(1, $violations, 'A stranded annotation must NOT suppress the violation');
        self::assertArrayHasKey(
            'strandedAnnotation',
            $violations[0],
            'The violation must carry the line of the annotation that did not apply, so the '
            . 'report can say "you annotated this and it did not take" rather than "you did not annotate this"'
        );
        // `?? null` because the key is optional in the shape: asserting presence
        // above does not narrow it for static analysis, and reaching in blind
        // would trade a clear failure for a notice.
        self::assertSame(6, $violations[0]['strandedAnnotation'] ?? null);
    }

    /**
     * The other side of the same boundary: a reason written BENEATH the tag is
     * fine as long as the tag stays within the window. This is the shape the
     * codebase should use, so it is pinned rather than left to chance.
     */
    public function testAnAnnotationAtTheEndOfABlockStillSuppresses(): void
    {
        $source = <<<'PHP'
        <?php
        class Probe
        {
            public function run($db): void
            {
                // A long explanation of why this access is legitimate, written
                // first, with the tag placed last so that it lands inside the
                // lookback window.
                // @tenant-guard-ignore: system-tenant (id 0) sees all tenants
                $stmt = $db->prepare('SELECT * FROM announcements WHERE id = ?');
            }
        }
        PHP;

        self::assertSame([], (new TenantPredicateScanner($this->registry()))->scanSource($source, 'Probe.php'));
    }

    /**
     * A clean violation must NOT claim a stranded annotation, or the note becomes
     * noise that reviewers learn to skip.
     */
    public function testAViolationWithNoAnnotationCarriesNoStrandedNote(): void
    {
        $violations = $this->scan('SELECT * FROM announcements WHERE id = ?');

        self::assertCount(1, $violations);
        self::assertArrayNotHasKey('strandedAnnotation', $violations[0]);
    }

    public function testInsertIsOutOfScope(): void
    {
        self::assertSame([], $this->scan('INSERT INTO announcements (tenant_id, body) VALUES (?, ?)'));
    }
}
