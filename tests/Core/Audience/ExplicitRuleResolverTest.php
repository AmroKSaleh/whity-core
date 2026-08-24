<?php

declare(strict_types=1);

namespace Tests\Core\Audience;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Sdk\Audience\AudienceRuleContext;
use Whity\Sdk\Audience\AudienceRuleResolverInterface;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * {@see ExplicitRuleResolver} — the enumerated case, expressed as a RULE (#999).
 *
 * What is worth failing a build over here is not the happy path. It is the three
 * refusals and the one type relationship:
 *
 *  1. AN EMPTY LIST IS REFUSED. A group naming nobody would resolve to nobody,
 *     render as a perfectly valid group, and be addressable by a route that then
 *     delivers to no one and reports success. That is the silent omission the
 *     whole design is written against. Contrast a `role` rule matching nobody,
 *     which IS allowed — there the emptiness is a fact about the organisation
 *     that may change tomorrow, not a fact about the author having filled
 *     nothing in.
 *
 *  2. BOTH SPELLINGS OF A NUMBER ARE ACCEPTED. A JSON body decodes `7` as int
 *     and `"7"` as string, and a JSONB round trip returns either depending on
 *     the driver. A resolver that accepted only one would make a group work on
 *     PostgreSQL and break on the offline SQLite engine — the worst possible
 *     place for a dialect difference.
 *
 *  3. THE SAME PERSON LISTED TWICE COUNTS ONCE, here rather than only in the
 *     host. The host de-duplicates across RULES; this is one author listing
 *     somebody twice, and a preview reporting 3 for `[11, 11, 12]` would
 *     disagree with itself.
 *
 *  4. ONE BODY SERVES BOTH INTERFACES. `resolve()` takes the widened
 *     {@see AudienceRuleContext}, which is what makes the kind usable both as a
 *     route step and as a group definition — and the reason
 *     {@see RoutingRuleContext} was made a SUBTYPE rather than having its
 *     routing fields made nullable.
 */
final class ExplicitRuleResolverTest extends TestCase
{
    public function testItSatisfiesBothTheRoutingAndTheAudienceContract(): void
    {
        $resolver = new ExplicitRuleResolver();

        self::assertInstanceOf(RoutingRuleResolverInterface::class, $resolver);
        self::assertInstanceOf(AudienceRuleResolverInterface::class, $resolver);
    }

    public function testTheSameCallAnswersForAGroupAndForARouteStep(): void
    {
        $resolver = new ExplicitRuleResolver();
        $config = ['profile_ids' => [11, 12]];

        $asGroup = $resolver->resolve(new AudienceRuleContext(
            tenantId: 1,
            actorProfileId: 10,
            actorOuId: null,
            config: $config,
        ));

        $asStep = $resolver->resolve(new RoutingRuleContext(
            tenantId: 1,
            documentId: 5,
            routeId: 6,
            stepId: 7,
            position: 1,
            actorProfileId: 10,
            actorOuId: null,
            config: $config,
        ));

        self::assertSame(
            array_map(static fn ($r): int => $r->profileId, $asGroup),
            array_map(static fn ($r): int => $r->profileId, $asStep),
            'a RoutingRuleContext IS an AudienceRuleContext — one body, one answer'
        );
    }

    public function testItResolvesExactlyThePeopleNamedAndAttributesNoUnit(): void
    {
        $resolved = (new ExplicitRuleResolver())->resolve($this->context(['profile_ids' => [12, 11]]));

        self::assertSame([12, 11], array_map(static fn ($r): int => $r->profileId, $resolved));
        foreach ($resolved as $recipient) {
            self::assertNull(
                $recipient->ouId,
                'this rule reached people BY NAME, through no unit — substituting their primary '
                . 'membership would attribute a routing to a department that had nothing to do with it'
            );
        }
    }

    public function testAStringIdAndAnIntegerIdAreTheSamePerson(): void
    {
        $resolved = (new ExplicitRuleResolver())->resolve($this->context(['profile_ids' => [11, '11', 12]]));

        self::assertSame(
            [11, 12],
            array_map(static fn ($r): int => $r->profileId, $resolved),
            'a JSONB round trip may return either spelling; both must mean the same person'
        );
    }

    public function testAnEmptyListIsRefusedRatherThanMeaningNobody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/at least one/');

        (new ExplicitRuleResolver())->validate(['profile_ids' => []]);
    }

    public function testAMissingKeyIsRefusedWithAMessageNamingIt(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/profile_ids/');

        (new ExplicitRuleResolver())->validate([]);
    }

    public function testAJsonObjectWhereAListWasExpectedIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/profile_ids/');

        (new ExplicitRuleResolver())->validate(['profile_ids' => ['a' => 11]]);
    }

    /**
     * @dataProvider notAProfileId
     */
    public function testAnEntryThatIsNotAPositiveWholeNumberIsRefused(mixed $entry): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/positive whole number/');

        (new ExplicitRuleResolver())->validate(['profile_ids' => [$entry]]);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAProfileId(): array
    {
        return [
            'zero' => [0],
            'negative' => [-3],
            'a float' => [1.5],
            'a word' => ['eleven'],
            'null' => [null],
            'nested' => [[11]],
            // Not a whole number in any spelling — refusing it here is what stops
            // a typo becoming a group that resolves to nobody months later.
            'numeric-ish' => ['11a'],
        ];
    }

    public function testAListLongerThanTheStructuralCeilingIsRefusedNamingTheLimit(): void
    {
        $tooMany = range(1, ExplicitRuleResolver::MAX_MEMBERS + 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . ExplicitRuleResolver::MAX_MEMBERS . '/');

        (new ExplicitRuleResolver())->validate(['profile_ids' => $tooMany]);
    }

    public function testExactlyTheCeilingIsAllowed(): void
    {
        $atLimit = range(1, ExplicitRuleResolver::MAX_MEMBERS);

        (new ExplicitRuleResolver())->validate(['profile_ids' => $atLimit]);

        self::assertTrue(true, 'the ceiling is inclusive — an off-by-one here refuses a legal set');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function context(array $config): AudienceRuleContext
    {
        return new AudienceRuleContext(
            tenantId: 1,
            actorProfileId: 10,
            actorOuId: 20,
            config: $config,
        );
    }
}
