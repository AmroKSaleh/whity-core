<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Licensing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Licensing\ActivationCode;

/**
 * An activation code is typed by a person, off a surface, and consumes
 * something worth money. The tests that matter are therefore about the two
 * things that can go wrong in that sentence: a typo being accepted, and a code
 * being guessable from another.
 *
 * THE EXHAUSTIVE SWEEPS BELOW ARE NOT CEREMONY. A previous check-character
 * implementation in this codebase looked correct, passed hand-written
 * examples, and accepted two mistyped references as valid — found only by
 * trying every single-character substitution. Spot checks cannot find that
 * class of bug, because the failures are rare and unremarkable-looking.
 */
final class ActivationCodeTest extends TestCase
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    // ── the typo guarantees ─────────────────────────────────────────────────

    /**
     * EVERY single-character substitution must be refused, on many codes.
     *
     * This is the exact sweep that exposed the earlier bug. If it ever fails,
     * the check-character construction is wrong — not the test.
     */
    public function testEverySingleCharacterTypoIsRejected(): void
    {
        $accepted = [];

        for ($n = 0; $n < 40; $n++) {
            $code = ActivationCode::canonicalize(ActivationCode::generate());

            for ($position = 0; $position < strlen($code); $position++) {
                foreach (str_split(self::ALPHABET) as $replacement) {
                    if ($replacement === $code[$position]) {
                        continue;
                    }

                    $typo = substr_replace($code, $replacement, $position, 1);

                    if (ActivationCode::isWellFormed($typo)) {
                        $accepted[] = "{$code} -> {$typo}";
                    }
                }
            }
        }

        self::assertSame(
            [],
            $accepted,
            'a mistyped code verified as valid — it would activate the wrong device, or a stranger\'s'
        );
    }

    /** Adjacent transposition is the other mistake people actually make. */
    public function testEveryAdjacentTranspositionIsRejected(): void
    {
        $accepted = [];

        for ($n = 0; $n < 40; $n++) {
            $code = ActivationCode::canonicalize(ActivationCode::generate());

            for ($i = 0; $i < strlen($code) - 1; $i++) {
                if ($code[$i] === $code[$i + 1]) {
                    continue;
                }

                $swapped = $code;
                [$swapped[$i], $swapped[$i + 1]] = [$code[$i + 1], $code[$i]];

                if (ActivationCode::isWellFormed($swapped)) {
                    $accepted[] = "{$code} -> {$swapped}";
                }
            }
        }

        self::assertSame([], $accepted, 'a transposed code verified as valid');
    }

    public function testAGeneratedCodeIsWellFormed(): void
    {
        for ($i = 0; $i < 200; $i++) {
            $code = ActivationCode::generate();
            self::assertTrue(
                ActivationCode::isWellFormed($code),
                "generate() produced a code its own validator rejects: {$code}"
            );
        }
    }

    // ── unguessability ──────────────────────────────────────────────────────

    /**
     * Codes must not be derivable from one another. If they encoded a device id
     * the way a payment reference encodes an invoice, a school holding codes
     * for devices 41–70 could mint the rest of the batch.
     */
    public function testCodesAreNotSequentialOrDerivable(): void
    {
        $codes = [];
        for ($i = 0; $i < 500; $i++) {
            $codes[] = ActivationCode::canonicalize(ActivationCode::generate());
        }

        self::assertCount(500, array_unique($codes), 'generated codes collided');

        // Every position should vary across the sample. A derived or
        // partially-static body shows up here as a position that never changes.
        $length = strlen($codes[0]);
        for ($position = 0; $position < $length - 2; $position++) {
            $seen = [];
            foreach ($codes as $code) {
                $seen[$code[$position]] = true;
            }
            self::assertGreaterThan(
                8,
                count($seen),
                "position {$position} barely varies across 500 codes — the body may not be random"
            );
        }
    }

    // ── transcription ───────────────────────────────────────────────────────

    /** The alphabet must exclude the characters people confuse. */
    public function testAlphabetExcludesLookalikeCharacters(): void
    {
        foreach (['0', 'O', '1', 'I'] as $confusable) {
            self::assertStringNotContainsString(
                $confusable,
                self::ALPHABET,
                "'{$confusable}' is unreadable off a sticker"
            );
        }

        for ($i = 0; $i < 50; $i++) {
            $code = ActivationCode::canonicalize(ActivationCode::generate());
            foreach (['0', 'O', '1', 'I'] as $confusable) {
                self::assertStringNotContainsString($confusable, $code);
            }
        }
    }

    /**
     * The same code typed in the ways people actually type it must compare
     * equal. Storing a decorated form would make two equal codes unequal to
     * the database, which reads to the user as "my code does not work".
     */
    public function testDecorationAndCaseDoNotChangeTheCode(): void
    {
        $code = ActivationCode::generate();
        $canonical = ActivationCode::canonicalize($code);

        $variants = [
            strtolower($code),
            str_replace('-', '', $code),
            str_replace('-', ' ', $code),
            "  {$code}  ",
            strtolower(str_replace('-', '', $code)) . "\n",
        ];

        foreach ($variants as $variant) {
            self::assertSame($canonical, ActivationCode::canonicalize($variant), "variant: {$variant}");
            self::assertTrue(ActivationCode::isWellFormed($variant), "variant rejected: {$variant}");
        }
    }

    public function testFormatProducesReadableGroups(): void
    {
        $formatted = ActivationCode::format(ActivationCode::generate());

        self::assertMatchesRegularExpression('/^[A-Z0-9]{4}(-[A-Z0-9]{1,4})+$/', $formatted);
        self::assertTrue(ActivationCode::isWellFormed($formatted));
    }

    // ── what it must NOT claim ──────────────────────────────────────────────

    public function testGarbageIsRejected(): void
    {
        foreach (['', 'hello', '----', 'AAAA-AAAA-AAAA', '0000-0000-0000'] as $rubbish) {
            self::assertFalse(
                ActivationCode::isWellFormed($rubbish),
                "accepted rubbish: '{$rubbish}'"
            );
        }
    }

    /**
     * A truncated code must fail. Length is checked before the checksum, so a
     * short string cannot accidentally satisfy it.
     */
    public function testTruncatedCodesAreRejected(): void
    {
        $code = ActivationCode::canonicalize(ActivationCode::generate());

        for ($cut = 1; $cut < strlen($code); $cut++) {
            self::assertFalse(
                ActivationCode::isWellFormed(substr($code, 0, $cut)),
                "accepted a truncated code of length {$cut}"
            );
        }
    }
}
