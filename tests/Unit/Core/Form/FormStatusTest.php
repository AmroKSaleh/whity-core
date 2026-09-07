<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormStatus;

/**
 * The form lifecycle, and the two refusals that make it worth having.
 *
 * These are not "does the enum enumerate" tests. Each one pins a decision that
 * would be silently reversible by an edit that looks like a simplification:
 * that a draft cannot be submitted to, that a published form can never go back
 * to draft, and that an archived form's fields are frozen.
 */
final class FormStatusTest extends TestCase
{
    public function testOnlyAPublishedFormAcceptsSubmissions(): void
    {
        self::assertTrue(FormStatus::acceptsSubmissions(FormStatus::PUBLISHED));

        // A draft is half-written by definition: answers given to it would be
        // answers to questions its author was still changing, with nothing
        // afterwards to say which question was asked.
        self::assertFalse(FormStatus::acceptsSubmissions(FormStatus::DRAFT));
        // Archiving IS the act of closing the door. If this returned true the
        // operation would be decoration.
        self::assertFalse(FormStatus::acceptsSubmissions(FormStatus::ARCHIVED));
    }

    public function testAPublishedFormCanNeverReturnToDraft(): void
    {
        // The load-bearing refusal. A form that could return to draft could have
        // its questions rewritten underneath answers already given, while its own
        // state read "not live".
        self::assertFalse(
            FormStatus::canTransition(FormStatus::PUBLISHED, FormStatus::DRAFT),
            'Reworking a live form is done by archiving it, which stops new submissions without '
            . 'pretending the old ones were made against something else.'
        );
        self::assertFalse(FormStatus::canTransition(FormStatus::ARCHIVED, FormStatus::DRAFT));

        self::assertSame([FormStatus::ARCHIVED], FormStatus::transitionsFrom(FormStatus::PUBLISHED));
    }

    public function testArchivingIsReversible(): void
    {
        // Retiring a form at the end of a cycle and wanting it back at the start
        // of the next one is the ordinary case, not an edge case.
        self::assertTrue(FormStatus::canTransition(FormStatus::ARCHIVED, FormStatus::PUBLISHED));
        self::assertSame([FormStatus::PUBLISHED], FormStatus::transitionsFrom(FormStatus::ARCHIVED));
    }

    public function testADraftMayBePublishedOrAbandoned(): void
    {
        self::assertTrue(FormStatus::canTransition(FormStatus::DRAFT, FormStatus::PUBLISHED));
        self::assertTrue(FormStatus::canTransition(FormStatus::DRAFT, FormStatus::ARCHIVED));
    }

    public function testNoStateIsTerminal(): void
    {
        foreach (FormStatus::all() as $status) {
            self::assertNotSame(
                [],
                FormStatus::transitionsFrom($status),
                "{$status} has no way out — nothing in this lifecycle is meant to be irreversible."
            );
        }
    }

    public function testAnArchivedFormsFieldsAreFrozen(): void
    {
        // Its fields are the only remaining explanation of what its submissions
        // answered; editing them rewrites that explanation after the fact.
        self::assertFalse(FormStatus::allowsFieldEditing(FormStatus::ARCHIVED));

        // A published form stays editable: a typo in a label must be fixable
        // without retiring the form.
        self::assertTrue(FormStatus::allowsFieldEditing(FormStatus::PUBLISHED));
        self::assertTrue(FormStatus::allowsFieldEditing(FormStatus::DRAFT));
    }

    public function testUnknownStatesAreRefusedRatherThanTreatedAsDrafts(): void
    {
        self::assertFalse(FormStatus::isValid('open'));
        self::assertFalse(FormStatus::isValid('closed'));
        self::assertFalse(FormStatus::isValid(''));

        self::assertSame([], FormStatus::transitionsFrom('open'));
        self::assertFalse(FormStatus::acceptsSubmissions('open'));
        self::assertFalse(FormStatus::allowsFieldEditing('open'));
    }
}
