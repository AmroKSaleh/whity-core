<?php

declare(strict_types=1);

namespace Tests\Core\Db;

use PHPUnit\Framework\TestCase;
use Whity\Core\Db\DbBool;

/**
 * Unit cover for the canonical SQL-boolean coercion (#891).
 *
 * Every case asserts the FALSE direction as well as the true one. That is the
 * whole point: the defect class this class replaces — `(bool) 'false'`,
 * `(bool) 'f'` — produces TRUE for a false value, so a test that only checks
 * the true case passes with the bug fully present.
 */
final class DbBoolTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function representations(): iterable
    {
        // Native PHP — pdo_pgsql with ATTR_STRINGIFY_FETCHES off (the default),
        // and any value already normalised by a repository.
        yield 'native true' => [true, true];
        yield 'native false' => [false, false];

        // Integers — SQLite, which stores BOOLEAN as INTEGER.
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'int 2' => [2, true];
        yield 'int -1' => [-1, true];

        // Stringified — pdo_pgsql with ATTR_STRINGIFY_FETCHES on, and SQLite
        // under the RealEngine harness.
        yield "string '1'" => ['1', true];
        yield "string '0'" => ['0', false];

        // The pgsql text spelling. Not produced by pdo_pgsql on PHP 8.4, but it
        // is what older drivers returned and what `SELECT flag::text` still
        // produces via 'true'/'false' below.
        yield "string 't'" => ['t', true];
        yield "string 'f'" => ['f', false];

        // `SELECT flag::text` on PostgreSQL. THIS is the representation a bare
        // (bool) cast gets wrong today: `(bool) 'false'` is true.
        yield "string 'true'" => ['true', true];
        yield "string 'false'" => ['false', false];

        // Case and whitespace. The three private helpers this class replaced
        // were case-SENSITIVE and answered false for 'TRUE'.
        yield "string 'TRUE'" => ['TRUE', true];
        yield "string 'FALSE'" => ['FALSE', false];
        yield "string 'T'" => ['T', true];
        yield "string 'F'" => ['F', false];
        yield "padded ' t '" => [' t ', true];
        yield "padded ' f '" => [' f ', false];

        // Absent / empty.
        yield 'null' => [null, false];
        yield 'empty string' => ['', false];
        yield "string 'no'" => ['no', false];

        // Floats, for completeness — no driver returns these for a BOOLEAN,
        // but `mixed` accepts them and silently guessing would be worse.
        yield 'float 1.0' => [1.0, true];
        yield 'float 0.0' => [0.0, false];
    }

    /**
     * @dataProvider representations
     */
    public function testCoercesEveryRepresentationADriverCanReturn(mixed $value, bool $expected): void
    {
        self::assertSame($expected, DbBool::of($value), sprintf(
            'DbBool::of(%s) should be %s',
            var_export($value, true),
            var_export($expected, true)
        ));
    }

    /**
     * The exact inversion #891 is about, pinned as its own test so a regression
     * names itself rather than hiding inside a data provider.
     */
    public function testFalseSpellingsAreNotTrue(): void
    {
        foreach (['f', 'false', 'FALSE', '0', ''] as $falseSpelling) {
            self::assertFalse(
                DbBool::of($falseSpelling),
                "'{$falseSpelling}' must read as FALSE; a bare (bool) cast reads some of these as TRUE."
            );
        }
    }

    /**
     * A bare `(bool)` cast and this class must DISAGREE on the text spellings —
     * if they ever agree everywhere, the cast would be a fine replacement and
     * this class (plus its CI guard) would be dead weight. Documents exactly
     * what the guard is buying.
     */
    public function testDivergesFromABareCastPreciselyWhereItMatters(): void
    {
        self::assertTrue((bool) 'false', 'PHP casts the non-empty string "false" to TRUE.');
        self::assertFalse(DbBool::of('false'));

        self::assertTrue((bool) 'f', 'PHP casts the non-empty string "f" to TRUE.');
        self::assertFalse(DbBool::of('f'));
    }

    public function testRejectsNonScalarsRatherThanGuessing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DbBool::of(['t']);
    }
}
