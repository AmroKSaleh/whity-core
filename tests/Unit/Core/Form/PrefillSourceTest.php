<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\PrefillSource;

/**
 * The prefill vocabulary, and the DECLARED-versus-BACKED distinction.
 *
 * The interesting tests here are the ones asserting that two sources do NOT
 * resolve. `profile.phone` and `profile.job_title` are declared because a form
 * author wants them in the picker, and unbacked because no column in
 * whity-core's schema holds either — verified against every migration, not
 * assumed. Pinning that here means the day somebody adds a contact-details store
 * and flips the flag, a test tells them to, rather than the gap quietly
 * persisting behind a picker that looks complete.
 */
final class PrefillSourceTest extends TestCase
{
    public function testTheThreeBackedSourcesAreTheOnesWithColumnsBehindThem(): void
    {
        // profiles.display_name (028), profile_emails.email (029),
        // organizational_units.name reached through memberships.ou_id (005/030).
        self::assertSame(
            [
                PrefillSource::DISPLAY_NAME,
                PrefillSource::EMAIL,
                PrefillSource::OU,
            ],
            PrefillSource::backed()
        );
    }

    public function testPhoneAndJobTitleAreDeclaredButResolveToNothing(): void
    {
        foreach ([PrefillSource::PHONE, PrefillSource::JOB_TITLE] as $source) {
            // Declared: it appears in the picker an author reads.
            self::assertTrue(
                PrefillSource::isValid($source),
                "{$source} must stay in the vocabulary — omitting it pushes authors into adding a "
                . 'plain text box and making every submitter retype the value.'
            );
            // Unbacked: nothing in this schema stores it, so it resolves to null
            // and the platform says so rather than pretending.
            self::assertFalse(
                PrefillSource::isBacked($source),
                "{$source} has no column in whity-core's schema. If a contact-details store has "
                . 'landed, back it in PrefillResolver and flip this — do not just change the flag.'
            );
            self::assertNotNull(PrefillSource::unbackedReason($source));
        }
    }

    public function testABackedSourceHasNothingToExplain(): void
    {
        foreach (PrefillSource::backed() as $source) {
            self::assertNull(
                PrefillSource::unbackedReason($source),
                'A reason emitted for a source that works would invite a client to render a warning '
                . 'beside a field that is about to fill in correctly.'
            );
        }
    }

    public function testAnUnknownSourceIsNeitherValidNorBacked(): void
    {
        foreach (['profile.mobile', 'profile.department', 'display_name', ''] as $unknown) {
            self::assertFalse(PrefillSource::isValid($unknown));
            self::assertFalse(PrefillSource::isBacked($unknown));
            self::assertNotNull(PrefillSource::unbackedReason($unknown));
        }
    }

    public function testEveryBackedSourceIsAlsoDeclared(): void
    {
        foreach (PrefillSource::backed() as $source) {
            self::assertContains($source, PrefillSource::all());
        }
    }
}
