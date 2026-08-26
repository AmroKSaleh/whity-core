<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\SubmissionValidator;

/**
 * What a submitted answer set is allowed to be.
 *
 * The fixtures are a facilities request and an equipment return, from two
 * unrelated corners of an ordinary organisation. That is deliberate: a test whose
 * fixtures all read as one canonical use teaches the next reader that the use is
 * canonical, and a tenant-authored form engine has no canonical form.
 */
final class SubmissionValidatorTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function field(string $key, string $type, array $overrides = []): array
    {
        // `$overrides + $defaults`, in that order: PHP's union operator keeps the
        // LEFT operand's value for a duplicate key, so writing the defaults first
        // would discard every override silently — the tests would still run, and
        // several would pass for the wrong reason.
        return $overrides + [
            'field_key' => $key,
            'field_type' => $type,
            'label' => ['en' => ucfirst(str_replace('_', ' ', $key))],
            'is_required' => false,
            'options' => [],
            'validation' => [],
        ];
    }

    public function testAnAbsentOptionalAnswerIsAbsentFromTheStoredObjectRatherThanNull(): void
    {
        $result = SubmissionValidator::validate([self::field('notes', FieldType::TEXTAREA)], []);

        // `data ? 'notes'` then answers "did they say anything" honestly. A
        // stored null would make "left blank" and "answered with nothing"
        // indistinguishable.
        self::assertSame([], $result['values']);
        self::assertArrayNotHasKey('notes', $result['values']);
    }

    public function testARequiredFieldIsNamedByItsOwnLabelNotItsKey(): void
    {
        $fields = [self::field('contact_number', FieldType::TEXT, ['is_required' => true])];

        $this->expectException(FormRejectedException::class);
        // The message is written for the person staring at the form, not for the
        // author who named the column.
        $this->expectExceptionMessage('Contact number is required');

        SubmissionValidator::validate($fields, []);
    }

    public function testAFalseCheckboxIsAnAnswerAndNotABlank(): void
    {
        $fields = [self::field('accepts_terms', FieldType::CHECKBOX, ['is_required' => true])];

        // The exception that makes the blank rule worth writing down: treating
        // `false` as blank would make a required consent box impossible to
        // DECLINE, and would store nothing where the form promised a decision.
        $result = SubmissionValidator::validate($fields, ['accepts_terms' => false]);

        self::assertSame(['accepts_terms' => false], $result['values']);
    }

    public function testARequiredCheckboxStillRefusesAnAnswerThatWasNeverGiven(): void
    {
        $fields = [self::field('accepts_terms', FieldType::CHECKBOX, ['is_required' => true])];

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, []);
    }

    public function testACheckboxRefusesAStringItCannotMeanAndDoesNotCoerceIt(): void
    {
        $fields = [self::field('accepts_terms', FieldType::CHECKBOX)];

        // `(bool) 'no'` is TRUE. Coercing here would record consent that was
        // explicitly withheld.
        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['accepts_terms' => 'no']);
    }

    public function testAnIntegralNumericStringStaysAnInteger(): void
    {
        $fields = [self::field('quantity', FieldType::NUMBER)];

        $result = SubmissionValidator::validate($fields, ['quantity' => '3']);

        self::assertSame(3, $result['values']['quantity']);
    }

    public function testNumberRangesAreEnforcedAgainstTheCoercedValue(): void
    {
        $fields = [self::field('quantity', FieldType::NUMBER, ['validation' => ['min' => 1, 'max' => 10]])];

        self::assertSame(10, SubmissionValidator::validate($fields, ['quantity' => '10'])['values']['quantity']);

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['quantity' => 11]);
    }

    public function testADateThatMatchesTheShapeButIsNotADayIsRefused(): void
    {
        $fields = [self::field('return_by', FieldType::DATE)];

        self::assertSame(
            '2026-02-28',
            SubmissionValidator::validate($fields, ['return_by' => '2026-02-28'])['values']['return_by']
        );

        // Matches every date regex ever written and is not a day. A lenient
        // reader rolls it into March, silently changing what was declared.
        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['return_by' => '2026-02-31']);
    }

    public function testASelectAcceptsOnlyTheOfferedChoices(): void
    {
        $fields = [self::field('urgency', FieldType::SELECT, [
            'options' => [
                ['value' => 'routine', 'label' => ['en' => 'Routine']],
                ['value' => 'urgent', 'label' => ['en' => 'Urgent']],
            ],
        ])];

        self::assertSame(
            'urgent',
            SubmissionValidator::validate($fields, ['urgency' => 'urgent'])['values']['urgency']
        );

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['urgency' => 'critical']);
    }

    public function testASelectWithNoUsableOptionsRefusesEveryAnswer(): void
    {
        // The correct failure for a picker whose author never finished writing
        // the choices — not a pass-through that stores an unvalidated string.
        $fields = [self::field('urgency', FieldType::SELECT, ['options' => [['label' => 'Routine']]])];

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['urgency' => 'routine']);
    }

    public function testAMultiselectDeduplicatesAndBoundsTheCount(): void
    {
        $fields = [self::field('areas', FieldType::MULTISELECT, [
            'options' => [
                ['value' => 'lighting', 'label' => ['en' => 'Lighting']],
                ['value' => 'plumbing', 'label' => ['en' => 'Plumbing']],
                ['value' => 'heating', 'label' => ['en' => 'Heating']],
            ],
            'validation' => ['max' => 2],
        ])];

        // The same choice twice is one choice; storing it twice would make a
        // count of selections wrong for no benefit.
        $result = SubmissionValidator::validate($fields, ['areas' => ['lighting', 'lighting']]);
        self::assertSame(['lighting'], $result['values']['areas']);

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['areas' => ['lighting', 'plumbing', 'heating']]);
    }

    public function testAnAuthorSuppliedPatternIsAppliedWithDelimitersItCannotInfluence(): void
    {
        $fields = [self::field('asset_tag', FieldType::TEXT, [
            'validation' => ['pattern' => '^[A-Z]{2}-\d{4}$'],
        ])];

        self::assertSame(
            'FM-0042',
            SubmissionValidator::validate($fields, ['asset_tag' => 'FM-0042'])['values']['asset_tag']
        );

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['asset_tag' => 'fm-42']);
    }

    public function testAnInvalidPatternRefusesTheAnswerRatherThanAcceptingEverything(): void
    {
        // The whole failure class: a check that reports success without checking
        // anything. A broken expression must fail CLOSED.
        $fields = [self::field('asset_tag', FieldType::TEXT, [
            'validation' => ['pattern' => '([unclosed'],
        ])];

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['asset_tag' => 'anything at all']);
    }

    public function testAFieldMayTightenTheTextCeilingButNotRaiseIt(): void
    {
        $tightened = [self::field('summary', FieldType::TEXT, ['validation' => ['maxLength' => 5]])];
        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($tightened, ['summary' => 'far too long']);
    }

    public function testAFieldCannotRaiseTheTextCeilingAboveThePlatformMaximum(): void
    {
        $raised = [self::field('summary', FieldType::TEXT, [
            'validation' => ['maxLength' => SubmissionValidator::TEXT_MAX * 10],
        ])];

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($raised, [
            'summary' => str_repeat('x', SubmissionValidator::TEXT_MAX + 1),
        ]);
    }

    public function testAReferenceAnswerIsNormalizedToAPositiveIntegerId(): void
    {
        $fields = [self::field('approver', FieldType::PROFILE_REF)];

        self::assertSame(41, SubmissionValidator::validate($fields, ['approver' => '41'])['values']['approver']);

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['approver' => 0]);
    }

    public function testUnknownAnswerKeysAreDroppedAndReportedRatherThanRefused(): void
    {
        $fields = [self::field('notes', FieldType::TEXTAREA)];

        $result = SubmissionValidator::validate($fields, [
            'notes' => 'Lift on level 2 is out.',
            // The realistic cause is a stale client: somebody had the form open
            // while an author removed a field. Refusing the whole submission
            // would throw away everything they typed to punish a race they did
            // not cause.
            'removed_field' => 'still typed something here',
        ]);

        self::assertSame(['notes' => 'Lift on level 2 is out.'], $result['values']);
        self::assertSame(['removed_field'], $result['ignored']);
    }

    public function testAnswersAreTrimmedSoSurroundingWhitespaceIsNotStored(): void
    {
        $fields = [self::field('notes', FieldType::TEXT)];

        $result = SubmissionValidator::validate($fields, ['notes' => "  spare key returned \n"]);

        self::assertSame('spare key returned', $result['values']['notes']);
    }

    public function testRulesSurviveTheEmptyMapAsObjectPresentationCast(): void
    {
        // FormFieldRepository emits an EMPTY rule set as \stdClass so it
        // serialises as {} rather than []. That cast passes through this class,
        // and a bare is_array() read would treat ANY object as "no rules" — which
        // is correct today only by coincidence. This pins the explicit handling,
        // so the day somebody casts non-empty maps too, the rules still apply
        // instead of every min/max/pattern in the install silently going quiet.
        $rules = new \stdClass();
        $rules->max = 5;

        $fields = [self::field('quantity', FieldType::NUMBER, ['validation' => $rules])];

        self::assertSame(5, SubmissionValidator::validate($fields, ['quantity' => 5])['values']['quantity']);

        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['quantity' => 6]);
    }

    public function testAnEmptyRuleSetAsAnObjectMeansNoRules(): void
    {
        $fields = [self::field('quantity', FieldType::NUMBER, ['validation' => new \stdClass()])];

        self::assertSame(9999, SubmissionValidator::validate($fields, ['quantity' => 9999])['values']['quantity']);
    }

    public function testAWhitespaceOnlyAnswerToARequiredFieldIsStillMissing(): void
    {
        $fields = [self::field('notes', FieldType::TEXT, ['is_required' => true])];

        // '   ' is not an answer. Accepting it would let a required field be
        // satisfied by pressing space.
        $this->expectException(FormRejectedException::class);
        SubmissionValidator::validate($fields, ['notes' => '   ']);
    }
}
