<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * The tenant-predicate guard must be invoked with EXPLICIT roots in CI.
 *
 * #1262: the workflow ran `php scripts/ci-tenant-predicate-guard.php` with no
 * arguments, and the script defaults to `src/` alone. So `database/` and
 * `plugins/` had never been scanned, while the guard was described — and
 * trusted — as the enforcement point for the platform's isolation invariant.
 * Plugin `Api/` and `Jobs/` code is request-scoped exactly like core's, and
 * nothing was watching it.
 *
 * The dangerous property is that reverting this is INVISIBLE. Dropping the
 * arguments does not fail anything: the guard still runs, still prints OK, and
 * still exits 0 — it simply stops looking at two thirds of what it claims to
 * cover. A green check would go on reporting success over an unscanned tree,
 * which is the exact shape of the bug this test exists to prevent recurring.
 *
 * So the invocation is pinned here rather than left to review. Scanning fewer
 * roots must require deleting an assertion that says why they are there.
 */
final class TenantPredicateGuardCiInvocationTest extends TestCase
{
    /** Roots the guard must be pointed at. */
    private const REQUIRED_ROOTS = ['src', 'database', 'plugins'];

    private function workflow(): string
    {
        $path = dirname(__DIR__, 2) . '/.github/workflows/automated-tests.yml';
        $raw = file_get_contents($path);
        self::assertNotFalse($raw, 'automated-tests.yml must be readable');

        return str_replace("\r\n", "\n", $raw);
    }

    /** The one `run:` line that invokes the guard. */
    private function invocation(): string
    {
        $matched = preg_match(
            '#^\s*run:\s*(php\s+scripts/ci-tenant-predicate-guard\.php[^\n]*)$#m',
            $this->workflow(),
            $m
        );

        // Not "no match means fine": if the step were renamed or removed, an
        // assertion that merely looked for roots would pass by matching nothing.
        self::assertSame(
            1,
            $matched,
            'automated-tests.yml must invoke scripts/ci-tenant-predicate-guard.php exactly once.'
        );

        $command = $m[1] ?? '';
        self::assertNotSame('', $command, 'The invocation pattern must capture the run: command.');

        return trim($command);
    }

    public function testGuardIsInvokedWithExplicitRoots(): void
    {
        $invocation = $this->invocation();

        self::assertNotSame(
            'php scripts/ci-tenant-predicate-guard.php',
            $invocation,
            'The guard is invoked bare, so it silently scans src/ only (#1262). '
            . 'Pass the roots explicitly: ' . implode(' ', self::REQUIRED_ROOTS)
        );
    }

    public function testEveryRequiredRootIsScanned(): void
    {
        $invocation = $this->invocation();
        $arguments = array_slice(preg_split('/\s+/', $invocation) ?: [], 2);

        foreach (self::REQUIRED_ROOTS as $root) {
            self::assertContains(
                $root,
                $arguments,
                sprintf(
                    'CI must scan %s/. Without it that tree is never checked for unscoped '
                    . 'tenant-table queries, and the guard still exits 0 (#1262).',
                    $root
                )
            );
        }
    }
}
