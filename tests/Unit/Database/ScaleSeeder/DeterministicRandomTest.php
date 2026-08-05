<?php

declare(strict_types=1);

namespace Tests\Unit\Database\ScaleSeeder;

use PHPUnit\Framework\TestCase;
use Whity\Database\ScaleSeeder\DeterministicRandom;

/**
 * Unit tests for {@see DeterministicRandom} (WC-35).
 *
 * The scale-seeder's "deterministic" contract rests entirely on this class:
 * the same seed must always produce the same sequence, on any machine, and a
 * different seed must (with overwhelming probability) diverge. Also proves
 * the derived helpers (pick/weightedPick/shuffle/nextInt/chance) stay within
 * their documented bounds.
 */
final class DeterministicRandomTest extends TestCase
{
    public function testSameSeedProducesTheSameSequence(): void
    {
        $a = new DeterministicRandom(42);
        $b = new DeterministicRandom(42);

        $sequenceA = [];
        $sequenceB = [];
        for ($i = 0; $i < 50; $i++) {
            $sequenceA[] = $a->nextUint32();
            $sequenceB[] = $b->nextUint32();
        }

        self::assertSame($sequenceA, $sequenceB);
    }

    public function testDifferentSeedsProduceDifferentSequences(): void
    {
        $a = new DeterministicRandom(42);
        $b = new DeterministicRandom(43);

        $sequenceA = [];
        $sequenceB = [];
        for ($i = 0; $i < 20; $i++) {
            $sequenceA[] = $a->nextUint32();
            $sequenceB[] = $b->nextUint32();
        }

        self::assertNotSame($sequenceA, $sequenceB);
    }

    public function testZeroSeedDoesNotStickAtZero(): void
    {
        $rng = new DeterministicRandom(0);

        $sawNonZero = false;
        for ($i = 0; $i < 10; $i++) {
            if ($rng->nextUint32() !== 0) {
                $sawNonZero = true;
                break;
            }
        }

        self::assertTrue($sawNonZero, 'A zero seed must not produce a stuck-at-zero stream.');
    }

    public function testNextUint32StaysWithin32Bits(): void
    {
        $rng = new DeterministicRandom(12345);
        for ($i = 0; $i < 200; $i++) {
            $value = $rng->nextUint32();
            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(0xFFFFFFFF, $value);
        }
    }

    public function testNextFloatStaysWithinUnitInterval(): void
    {
        $rng = new DeterministicRandom(7);
        for ($i = 0; $i < 200; $i++) {
            $value = $rng->nextFloat();
            self::assertGreaterThanOrEqual(0.0, $value);
            self::assertLessThan(1.0, $value);
        }
    }

    public function testNextIntStaysWithinInclusiveRange(): void
    {
        $rng = new DeterministicRandom(99);
        for ($i = 0; $i < 200; $i++) {
            $value = $rng->nextInt(5, 9);
            self::assertGreaterThanOrEqual(5, $value);
            self::assertLessThanOrEqual(9, $value);
        }
    }

    public function testNextIntHandlesASingleValueRange(): void
    {
        $rng = new DeterministicRandom(1);
        for ($i = 0; $i < 10; $i++) {
            self::assertSame(7, $rng->nextInt(7, 7));
        }
    }

    public function testChanceAtZeroIsAlwaysFalse(): void
    {
        $rng = new DeterministicRandom(1);
        for ($i = 0; $i < 20; $i++) {
            self::assertFalse($rng->chance(0.0));
        }
    }

    public function testChanceAtOneIsAlwaysTrue(): void
    {
        $rng = new DeterministicRandom(1);
        for ($i = 0; $i < 20; $i++) {
            self::assertTrue($rng->chance(1.0));
        }
    }

    public function testPickAlwaysReturnsAnElementOfTheList(): void
    {
        $rng = new DeterministicRandom(55);
        $items = ['a', 'b', 'c', 'd'];
        for ($i = 0; $i < 50; $i++) {
            self::assertContains($rng->pick($items), $items);
        }
    }

    public function testPickRejectsAnEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeterministicRandom(1))->pick([]);
    }

    public function testWeightedPickHonoursZeroWeightEntries(): void
    {
        $rng = new DeterministicRandom(3);
        $weighted = [['never', 0.0], ['always', 1.0]];
        for ($i = 0; $i < 50; $i++) {
            self::assertSame('always', $rng->weightedPick($weighted));
        }
    }

    public function testWeightedPickRejectsNonPositiveTotalWeight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeterministicRandom(1))->weightedPick([['a', 0.0], ['b', 0.0]]);
    }

    public function testShuffleIsAPermutationOfTheOriginalItems(): void
    {
        $rng = new DeterministicRandom(8);
        $items = range(1, 20);
        $shuffled = $items;
        $rng->shuffle($shuffled);

        self::assertCount(count($items), $shuffled);
        sort($shuffled);
        self::assertSame($items, $shuffled);
    }

    public function testShuffleIsDeterministicForTheSameSeed(): void
    {
        $itemsA = range(1, 15);
        $itemsB = range(1, 15);

        (new DeterministicRandom(2024))->shuffle($itemsA);
        (new DeterministicRandom(2024))->shuffle($itemsB);

        self::assertSame($itemsA, $itemsB);
    }

    public function testDeriveWithTheSamePartsProducesTheSameSequence(): void
    {
        $a = DeterministicRandom::derive(42, 'user', '3', '7');
        $b = DeterministicRandom::derive(42, 'user', '3', '7');

        $sequenceA = [$a->nextUint32(), $a->nextUint32(), $a->nextUint32()];
        $sequenceB = [$b->nextUint32(), $b->nextUint32(), $b->nextUint32()];

        self::assertSame($sequenceA, $sequenceB);
    }

    public function testDeriveWithDifferentPartsProducesDifferentSequences(): void
    {
        $a = DeterministicRandom::derive(42, 'user', '3', '7');
        $b = DeterministicRandom::derive(42, 'user', '3', '8');

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }

    public function testDeriveIsIndependentOfHowManyDrawsAnUnrelatedGeneratorConsumed(): void
    {
        // This is the property ScaleSeeder relies on: entity B's derived
        // generator must be identical regardless of how many draws some
        // OTHER entity A's (independently-derived) generator happened to
        // consume beforehand.
        $unrelated = DeterministicRandom::derive(42, 'tenant', '1');
        for ($i = 0; $i < 37; $i++) {
            $unrelated->nextUint32(); // simulate arbitrary unrelated consumption
        }

        $b1 = DeterministicRandom::derive(42, 'user', '1', '5');
        $b2 = DeterministicRandom::derive(42, 'user', '1', '5');

        self::assertSame($b1->nextUint32(), $b2->nextUint32());
    }

    public function testDeriveWithDifferentParentSeedsProducesDifferentSequences(): void
    {
        $a = DeterministicRandom::derive(1, 'relations', '1');
        $b = DeterministicRandom::derive(2, 'relations', '1');

        self::assertNotSame($a->nextUint32(), $b->nextUint32());
    }
}
