<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Whity\Http\PaginationParams;

/**
 * Unit tests for {@see PaginationParams}.
 */
final class PaginationParamsTest extends TestCase
{
    // ── defaults ────────────────────────────────────────────────────────────

    public function testDefaultsWhenNoParams(): void
    {
        $p = PaginationParams::fromQuery([]);

        $this->assertSame(1, $p->page);
        $this->assertSame(PaginationParams::DEFAULT_PER_PAGE, $p->perPage);
        $this->assertSame(0, $p->offset);
    }

    // ── page parsing ────────────────────────────────────────────────────────

    public function testPageParsedFromDigitString(): void
    {
        $p = PaginationParams::fromQuery(['page' => '3']);

        $this->assertSame(3, $p->page);
    }

    public function testPageClampedToOneForZero(): void
    {
        $p = PaginationParams::fromQuery(['page' => '0']);

        $this->assertSame(1, $p->page);
    }

    public function testPageDefaultsForNonDigitString(): void
    {
        $p = PaginationParams::fromQuery(['page' => 'abc']);

        $this->assertSame(1, $p->page);
    }

    // ── per_page parsing ────────────────────────────────────────────────────

    public function testPerPageParsedFromDigitString(): void
    {
        $p = PaginationParams::fromQuery(['per_page' => '50']);

        $this->assertSame(50, $p->perPage);
    }

    public function testPerPageClampedToMax(): void
    {
        $p = PaginationParams::fromQuery(['per_page' => '999']);

        $this->assertSame(PaginationParams::MAX_PER_PAGE, $p->perPage);
    }

    public function testPerPageDefaultsForZero(): void
    {
        $p = PaginationParams::fromQuery(['per_page' => '0']);

        $this->assertSame(PaginationParams::DEFAULT_PER_PAGE, $p->perPage);
    }

    public function testPerPageDefaultsForNonDigitString(): void
    {
        $p = PaginationParams::fromQuery(['per_page' => 'big']);

        $this->assertSame(PaginationParams::DEFAULT_PER_PAGE, $p->perPage);
    }

    // ── offset calculation ──────────────────────────────────────────────────

    public function testOffsetIsZeroOnFirstPage(): void
    {
        $p = PaginationParams::fromQuery(['page' => '1', 'per_page' => '10']);

        $this->assertSame(0, $p->offset);
    }

    public function testOffsetCalculatedForPageTwo(): void
    {
        $p = PaginationParams::fromQuery(['page' => '2', 'per_page' => '10']);

        $this->assertSame(10, $p->offset);
    }

    public function testOffsetCalculatedForPageThree(): void
    {
        $p = PaginationParams::fromQuery(['page' => '3', 'per_page' => '25']);

        $this->assertSame(50, $p->offset);
    }

    // ── meta() ──────────────────────────────────────────────────────────────

    public function testMetaShape(): void
    {
        $p    = PaginationParams::fromQuery(['page' => '2', 'per_page' => '10']);
        $meta = $p->meta(35);

        $this->assertSame([
            'page'       => 2,
            'perPage'    => 10,
            'total'      => 35,
            'totalPages' => 4,
        ], $meta);
    }

    public function testMetaTotalPagesRoundsUp(): void
    {
        $p    = PaginationParams::fromQuery(['per_page' => '10']);
        $meta = $p->meta(11);

        $this->assertSame(2, $meta['totalPages']);
    }

    public function testMetaTotalPagesForExactDivision(): void
    {
        $p    = PaginationParams::fromQuery(['per_page' => '10']);
        $meta = $p->meta(30);

        $this->assertSame(3, $meta['totalPages']);
    }

    public function testMetaForEmptyResult(): void
    {
        $p    = PaginationParams::fromQuery([]);
        $meta = $p->meta(0);

        $this->assertSame(0, $meta['total']);
        $this->assertSame(0, $meta['totalPages']);
    }
}
