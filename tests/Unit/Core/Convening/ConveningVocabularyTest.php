<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Convening;

use PHPUnit\Framework\TestCase;
use Whity\Core\Convening\ConveningBodyRepository;
use Whity\Core\Convening\ConveningRejectedException;
use Whity\Core\Convening\DecisionNumbers;
use Whity\Core\Convening\DecisionVerdict;
use Whity\Core\Convening\InvitationStatus;
use Whity\Core\Convening\LocalizedText;
use Whity\Core\Convening\MeetingStatus;
use Whity\Core\Convening\MemberRole;
use Whity\Core\Document\Routing\RouteVerdict;

/**
 * The vocabularies, and the three places they are load-bearing rather than
 * decorative.
 *
 *  1. A DEFERRAL MAPS TO NO ROUTING VERDICT. The one assertion in this file that
 *     protects somebody's document: a total mapping with a fallback arm would
 *     silently send every deferral down whichever branch the arm chose.
 *  2. `held` AND `cancelled` ARE TERMINAL. A state machine that lets a held
 *     meeting be un-held would let the minute-book disagree with a routing trail
 *     that has already moved documents.
 *  3. A DECISION NUMBER'S YEAR COMES FROM THE DECISION. Asserted against a date
 *     that is not this year, which is the only way to tell it apart from
 *     `date('Y')`.
 */
final class ConveningVocabularyTest extends TestCase
{
    public function testADeferralDrivesNoRoutingVerdict(): void
    {
        self::assertSame(RouteVerdict::APPROVED, DecisionVerdict::toRouteVerdict(DecisionVerdict::APPROVED));
        self::assertSame(RouteVerdict::REJECTED, DecisionVerdict::toRouteVerdict(DecisionVerdict::REJECTED));
        self::assertNull(
            DecisionVerdict::toRouteVerdict(DecisionVerdict::DEFERRED),
            'a deferral must map to NOTHING. Forcing it onto either routing verdict would advance a '
            . 'document nobody approved or reject one nobody refused, and both would look correct.'
        );
    }

    public function testTheConveningVerdictVocabularyIsAStrictSupersetOfTheRoutingOne(): void
    {
        // The two vocabularies must stay recognisably related: every routing
        // verdict has to be expressible as a body's conclusion, or a body could
        // not answer a step at all.
        foreach (RouteVerdict::all() as $verdict) {
            self::assertContains($verdict, DecisionVerdict::all());
        }
        self::assertContains(DecisionVerdict::DEFERRED, DecisionVerdict::all());
        self::assertNotContains(DecisionVerdict::DEFERRED, RouteVerdict::all());
    }

    public function testAHeldOrCancelledMeetingIsTerminal(): void
    {
        foreach ([MeetingStatus::HELD, MeetingStatus::CANCELLED] as $terminal) {
            self::assertFalse(MeetingStatus::canSchedule($terminal));
            self::assertFalse(MeetingStatus::canHold($terminal));
            self::assertFalse(MeetingStatus::canCancel($terminal));
            self::assertFalse(MeetingStatus::isOpenForAgenda($terminal));
        }

        // Positive control: the non-terminal states must actually allow the
        // things the assertions above deny, or this test would pass over a
        // predicate that returns false for everything.
        self::assertTrue(MeetingStatus::canSchedule(MeetingStatus::DRAFT));
        self::assertTrue(MeetingStatus::canHold(MeetingStatus::SCHEDULED));
        self::assertTrue(
            MeetingStatus::canHold(MeetingStatus::DRAFT),
            'a body that convened at short notice held a meeting that was never scheduled, and the '
            . 'minute must not require a fabricated date first'
        );
        self::assertTrue(MeetingStatus::isOpenForAgenda(MeetingStatus::SCHEDULED));
    }

    public function testInvitedIsNotAnAnswerSomebodyCanGive(): void
    {
        self::assertContains(InvitationStatus::INVITED, InvitationStatus::all());
        self::assertNotContains(
            InvitationStatus::INVITED,
            InvitationStatus::responses(),
            '`invited` is the state the system puts the row in; a person "answering" with it would be '
            . 'un-answering, which nothing in the vocabulary means'
        );
        self::assertSame(
            [InvitationStatus::ACCEPTED, InvitationStatus::DECLINED, InvitationStatus::TENTATIVE],
            InvitationStatus::responses()
        );
    }

    public function testSeatPrecedencePutsTheChairFirst(): void
    {
        self::assertLessThan(
            MemberRole::precedence(MemberRole::SECRETARY),
            MemberRole::precedence(MemberRole::CHAIR)
        );
        self::assertLessThan(
            MemberRole::precedence(MemberRole::MEMBER),
            MemberRole::precedence(MemberRole::SECRETARY)
        );
        // An unknown seat sorts last rather than throwing: the ordering decides
        // only whose name goes on a decision among people the route already
        // reached, so a stored value nobody recognises must not stop the body
        // deciding.
        self::assertSame(
            MemberRole::precedence(MemberRole::MEMBER),
            MemberRole::precedence('observer')
        );
    }

    public function testTheDecisionYearComesFromTheDecisionNotTheClock(): void
    {
        self::assertSame(2019, DecisionNumbers::yearOf('2019-12-19 16:00:00'));
        self::assertSame(2026, DecisionNumbers::yearOf('2026-01-02T09:30:00Z'));
        self::assertNotSame(
            (int) date('Y'),
            DecisionNumbers::yearOf('2019-12-19 16:00:00'),
            'the fixture date must not be this year, or the assertion above cannot tell the decision '
            . "date apart from date('Y')"
        );
    }

    public function testTheCounterNameSurvivesTheAllocatorsOwnNameRule(): void
    {
        // `SequenceCounters` refuses a name outside
        // /^[a-z][a-z0-9_:-]*$/ — a dot is not in it, and a dotted name is
        // refused at allocation time rather than stored. Pinned here so the next
        // edit to the format is told immediately rather than at the first
        // decision anybody records.
        self::assertMatchesRegularExpression(
            '/^[a-z][a-z0-9_:-]*$/',
            DecisionNumbers::counterName('standards-board', 2026)
        );
        self::assertMatchesRegularExpression(
            '/^[a-z][a-z0-9_:-]*$/',
            \Whity\Core\Convening\MeetingRepository::counterName('standards-board')
        );
    }

    // -- localized labels ----------------------------------------------------

    public function testABareStringIsFiledUnderTheFallbackLocale(): void
    {
        self::assertSame(
            ['en' => 'Standards Board'],
            LocalizedText::normalize('Standards Board', 'en', 'name')
        );
    }

    public function testAnArabicOnlyLabelIsKeptAndSurvivesEncoding(): void
    {
        $map = LocalizedText::normalize(['ar' => 'مجلس المعايير'], 'en', 'name');
        $encoded = LocalizedText::encode($map);

        self::assertStringNotContainsString(
            '\\u',
            $encoded,
            'an Arabic name must be stored as Arabic, not as a wall of escapes: the escaped form triples '
            . 'the stored length and makes a database dump unreadable to the people most likely to be '
            . 'reading one'
        );
        self::assertSame($map, LocalizedText::decode($encoded, 'en'));
    }

    public function testABlankLocaleIsDroppedButAnEntirelyBlankLabelIsRefused(): void
    {
        self::assertSame(
            ['en' => 'Board'],
            LocalizedText::normalize(['en' => 'Board', 'ar' => '   '], 'en', 'name'),
            'a client sending every language it knows with the unfilled ones blank is doing the '
            . 'ordinary thing'
        );

        $this->expectException(ConveningRejectedException::class);
        LocalizedText::normalize(['en' => '', 'ar' => ''], 'en', 'name');
    }

    public function testAMalformedLanguageKeyIsRefused(): void
    {
        $this->expectException(ConveningRejectedException::class);
        LocalizedText::normalize(['English' => 'Board'], 'en', 'name');
    }

    public function testALegacyNonJsonValueStillRenders(): void
    {
        self::assertSame(
            ['en' => 'Written before this column held JSON'],
            LocalizedText::decode('Written before this column held JSON', 'en'),
            'refusing to render a row written by hand turns a cosmetic inconsistency into a blank screen'
        );
    }

    public function testPreferredFallsBackToAnyLanguageRatherThanToNothing(): void
    {
        self::assertSame('Board', LocalizedText::preferred(['en' => 'Board', 'ar' => 'مجلس'], 'en'));
        self::assertSame(
            'مجلس',
            LocalizedText::preferred(['ar' => 'مجلس'], 'en'),
            'a subject line in a language the reader did not ask for is still information; an empty one '
            . 'is not'
        );
        self::assertSame('fallback', LocalizedText::preferred([], 'en', 'fallback'));
    }

    public function testABodyKeyIsNarrowEnoughToQuoteInsideADecisionNumber(): void
    {
        ConveningBodyRepository::assertKey('standards-board');
        ConveningBodyRepository::assertKey('ops_group_2');

        foreach (['Standards Board', 'standards/board', 'Standards-Board', '-leading', ''] as $bad) {
            try {
                ConveningBodyRepository::assertKey($bad);
                self::fail("'{$bad}' must be refused as a body key");
            } catch (ConveningRejectedException) {
                self::assertTrue(true);
            }
        }
    }
}
