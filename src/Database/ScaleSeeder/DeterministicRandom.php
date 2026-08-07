<?php

declare(strict_types=1);

namespace Whity\Database\ScaleSeeder;

/**
 * Self-contained deterministic pseudo-random generator (xorshift32) for the
 * scale-seeder CLI (WC-35 / performance-baseline epic #35).
 *
 * "Deterministic" per the task spec means: the SAME seed + the SAME
 * parameters always produce the SAME dataset, reproducibly across separate
 * runs AND across machines. PHP's built-ins do not fit:
 *
 *  - `mt_srand()`/`mt_rand()` mutate PROCESS-GLOBAL interpreter state. Any
 *    other code in the same request/process that also calls `mt_rand()`
 *    (directly or via a library) would have its own sequence perturbed by our
 *    seeding, and vice versa — a shared mutable global is exactly the kind of
 *    cross-request contamination this codebase forbids in FrankenPHP workers.
 *  - `random_int()`/`random_bytes()` are CSPRNGs by design: they are
 *    intentionally NOT seedable/reproducible, which is the opposite of what a
 *    reproducible fixture generator needs.
 *
 * This class instead owns a private 32-bit xorshift state. Seeding it can
 * never affect, or be affected by, unrelated code, and the algorithm is pure
 * integer arithmetic with no libc/OS RNG dependency, so the exact same seed
 * yields the exact same output sequence on every platform PHP runs on (the
 * xorshift step is masked to 32 bits after every operation so it behaves
 * identically regardless of the host's native int width).
 *
 * NOT cryptographically secure — by design, and not used for anything
 * security-sensitive. This generates synthetic load/graph/pagination test
 * data, never credentials (the one password these fixtures need is resolved
 * separately via {@see \Whity\Database\InitialPassword}, which uses a real
 * CSPRNG when no operator-supplied value is configured).
 */
final class DeterministicRandom
{
    /** Mask applied after every shift/xor to keep the math exactly 32-bit wide. */
    private const MASK32 = 0xFFFFFFFF;

    private int $state;

    public function __construct(int $seed)
    {
        // xorshift requires a non-zero state; fold the seed into 32 bits and
        // substitute a fixed non-zero fallback for the one seed (0) that would
        // otherwise stick at zero forever.
        $folded = $seed & self::MASK32;
        $this->state = $folded !== 0 ? $folded : 0x9E3779B9;
    }

    /**
     * Derive an independent child generator from a parent seed plus arbitrary
     * discriminator parts (e.g. a phase name, a tenant/user index).
     *
     * Two calls with the same parent seed and the same parts always produce
     * the same child sequence — REGARDLESS of how many draws any OTHER
     * derived generator consumed. This is what lets {@see \Whity\Database\ScaleSeeder\ScaleSeeder}
     * regenerate the exact same value for a given entity whether or not that
     * entity already existed in the database: a single SHARED stream would
     * desync the moment any earlier step skipped a draw because its row was
     * already present (the "reuse" branch never calls the name generator), so
     * every independently-reproducible value gets its own derived generator
     * instead of consuming a shared position in one global stream.
     */
    public static function derive(int $parentSeed, string ...$parts): self
    {
        $material = $parentSeed . '|' . implode('|', $parts);

        // crc32() is deterministic and platform-independent (a fixed,
        // standardised algorithm) — not used for anything security-sensitive,
        // just to mix a seed + labels into a well-distributed 32-bit value.
        return new self((int) crc32($material));
    }

    /** Next raw unsigned 32-bit integer from the stream. */
    public function nextUint32(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & self::MASK32;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & self::MASK32;
        $x &= self::MASK32;
        $this->state = $x;

        return $x;
    }

    /** Next float uniformly in [0, 1). */
    public function nextFloat(): float
    {
        return $this->nextUint32() / 4294967296.0; // 2^32
    }

    /** Next integer uniformly in [$min, $max], inclusive on both ends. */
    public function nextInt(int $min, int $max): int
    {
        if ($max < $min) {
            [$min, $max] = [$max, $min];
        }
        $range = $max - $min + 1;
        if ($range <= 0) {
            // Wider than 32 bits: not needed by this seeder's use cases.
            return $min;
        }

        return $min + (int) ($this->nextUint32() % $range);
    }

    /** True with probability $p (clamped to [0.0, 1.0]). */
    public function chance(float $p): bool
    {
        if ($p <= 0.0) {
            return false;
        }
        if ($p >= 1.0) {
            return true;
        }

        return $this->nextFloat() < $p;
    }

    /**
     * Pick one element (uniformly) from a non-empty list.
     *
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        if ($items === []) {
            throw new \InvalidArgumentException('DeterministicRandom::pick() requires a non-empty list.');
        }

        return $items[$this->nextInt(0, count($items) - 1)];
    }

    /**
     * Weighted pick from a list of [value, weight] pairs (weight > 0).
     *
     * @template T
     * @param list<array{0: T, 1: float|int}> $weighted
     * @return T
     */
    public function weightedPick(array $weighted): mixed
    {
        if ($weighted === []) {
            throw new \InvalidArgumentException('DeterministicRandom::weightedPick() requires a non-empty list.');
        }

        $total = 0.0;
        foreach ($weighted as $entry) {
            $total += (float) $entry[1];
        }
        if ($total <= 0.0) {
            throw new \InvalidArgumentException(
                'DeterministicRandom::weightedPick() requires a positive total weight.'
            );
        }

        $roll = $this->nextFloat() * $total;
        $cursor = 0.0;
        foreach ($weighted as $entry) {
            $cursor += (float) $entry[1];
            if ($roll < $cursor) {
                return $entry[0];
            }
        }

        // Floating-point edge case (roll landed exactly on the total): last value.
        return $weighted[count($weighted) - 1][0];
    }

    /**
     * Fisher-Yates shuffle, in place.
     *
     * @param array<int, mixed> $items
     */
    public function shuffle(array &$items): void
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $this->nextInt(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
    }
}
