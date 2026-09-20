<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Billing\External;

use PHPUnit\Framework\TestCase;
use Whity\Core\Billing\External\BillingActor;

/**
 * Who asked for a change, in terms the billing service can write down.
 *
 * ── This class is a mirror of somebody else's validator ────────────────────
 *
 * The billing service refuses an actor that is too long, contains an `@`, or is
 * not printable ASCII without spaces. Every rule below exists there too, and is
 * duplicated here on purpose: a violation should be an exception while somebody
 * is writing the call, not a 422 on a tier change while a customer waits.
 *
 * That duplication is the risk as well as the point. If their rules move and
 * ours do not, we start refusing things they would accept (harmless, and
 * visible) or sending things they reject (a failed plan change, and not visible
 * until it happens). These tests are written against the rules as stated, so a
 * divergence shows up as a test to argue with rather than a silent drift.
 *
 * ── Why an email is refused rather than trimmed ────────────────────────────
 *
 * What we send is rendered on their operator dashboard, which is shared across
 * every client they serve. An address there is personal data leaving this
 * deployment for a screen we do not control. Refusing is the only safe answer:
 * quietly stripping it would produce a mangled identifier that still resolves to
 * a person to anyone who recognises the local part.
 */
final class BillingActorTest extends TestCase
{
    // ── The two kinds ───────────────────────────────────────────────────────

    public function testAPersonIsNamedByTheirProfileId(): void
    {
        self::assertSame('profile:412', BillingActor::person(412)->value);
    }

    /**
     * A MACHINE SAYS IT IS A MACHINE. The device sweep resizes subscriptions on
     * nobody's instruction; attributing that to whichever profile was nearby
     * would invent a person, and an invented actor is indistinguishable from a
     * real one to whoever reads the log afterwards.
     */
    public function testAJobIsNamedAsAJob(): void
    {
        self::assertSame('job:device-quantity-sync', BillingActor::job('device-quantity-sync')->value);
    }

    /**
     * A JOB NAME IS NARROWER THAN AN ACTOR, and the case has to prove it.
     *
     * A space would be caught by the general rule anyway, so asserting on one
     * proves nothing about the job rule — mutation testing removed that rule and
     * the test still passed, because the value was refused a step later. A slash
     * is printable ASCII, has no `@` and is well under the cap: the general rule
     * accepts it, so only the job rule can refuse it.
     */
    public function testAJobNameIsNarrowerThanTheGeneralRule(): void
    {
        // Proof the general rule would have allowed it.
        self::assertSame('job/nightly', BillingActor::of('job/nightly')->value);

        $this->expectException(\InvalidArgumentException::class);
        BillingActor::job('device/quantity');
    }

    // ── The rules their side enforces ───────────────────────────────────────

    /**
     * AN EMAIL IS REFUSED BEFORE THE REQUEST IS BUILT. Their audit log renders
     * on a dashboard shared across every client they serve.
     */
    public function testAnEmailAddressIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/not an email address/');
        BillingActor::of('amro@example.com');
    }

    /** Even an `@` that is not obviously an address. */
    public function testAnyAtSignIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BillingActor::of('profile:412@v2');
    }

    /**
     * A NEWLINE IS THE ONE THAT MATTERS. Their audit entry is a single line, so
     * `profile:1\nclient:someone-else` would forge a second one — an actor who
     * never acted, in a log somebody trusts.
     *
     * @dataProvider valuesThatAreNotPrintableAscii
     */
    public function testAValueThatCouldForgeALogLineIsRefused(string $value): void
    {
        $this->expectExceptionMessageMatches('/printable ASCII/');
        BillingActor::of($value);
    }

    /** @return array<string, array{string}> */
    public static function valuesThatAreNotPrintableAscii(): array
    {
        return [
            'a newline' => ["profile:1\nclient:someone-else"],
            'a carriage return' => ["profile:1\rprofile:2"],
            'a tab' => ["profile:1\tprofile:2"],
            'a space' => ['profile: 412'],
            'a null byte' => ["profile:1\0"],
            'non-ascii' => ['profile:٤١٢'],
        ];
    }

    /**
     * THE LENGTH CAP IS THEIR COLUMN, NOT A STYLE RULE. Their audit value is
     * `client:<slug> actor:<hint>`; a hint past the cap overflows the row, and on
     * PostgreSQL that is an error which takes the whole request with it. So an
     * over-long actor would not degrade attribution — it would refuse the plan
     * change.
     */
    public function testAnActorLongerThanTheirColumnAllowsIsRefused(): void
    {
        $this->expectExceptionMessageMatches('/64 characters or fewer/');
        BillingActor::of(str_repeat('a', 65));
    }

    public function testExactlyTheLimitIsAccepted(): void
    {
        self::assertSame(str_repeat('a', 64), BillingActor::of(str_repeat('a', 64))->value);
    }

    /** A profile id can never approach the cap, but the rule is the rule. */
    public function testAVeryLargeProfileIdStillFits(): void
    {
        self::assertSame('profile:' . PHP_INT_MAX, BillingActor::person(PHP_INT_MAX)->value);
    }

    public function testAnEmptyActorIsRefusedRatherThanSentAsNothing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        BillingActor::of('');
    }

    // ── What it produces ────────────────────────────────────────────────────

    public function testItTravelsAsTheHeaderTheirMiddlewareReads(): void
    {
        self::assertSame(
            ['X-Pay-Actor' => 'profile:412'],
            BillingActor::person(412)->header()
        );
    }

    /**
     * THE PREFIXES ARE OURS AND THEIRS IS VERBATIM STORAGE. Their side derives
     * nothing from `profile:` or `job:` — deliberately, so this naming scheme
     * never becomes their problem — which means anything else valid must pass
     * through too.
     */
    public function testAnUnprefixedIdentifierIsStillAcceptable(): void
    {
        self::assertSame('ZZ/9-x_7.a', BillingActor::of('ZZ/9-x_7.a')->value);
    }
}
