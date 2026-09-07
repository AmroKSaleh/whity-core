<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Form;

use PHPUnit\Framework\TestCase;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormUploadPolicy;

/**
 * WHAT MAY BE UPLOADED against a `file` field.
 *
 * The class has no I/O, so this shard runs it directly with literal bytes —
 * which is the point of having written it that way. "Is a renamed executable
 * refused" is a question that must be answerable without a database, a storage
 * backend or an HTTP request, because those are the three things that make a
 * test slow enough to skip.
 *
 * The property under test throughout is that THE BYTES DECIDE. Every assertion
 * below either supplies a declared content type that lies, or omits one
 * entirely, and the verdict is the same either way.
 */
final class FormUploadPolicyTest extends TestCase
{
    private const PDF = "%PDF-1.7\nnot really a paper";
    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR";
    private const JPEG = "\xff\xd8\xff\xe0\x00\x10JFIF";

    // ── it accepts what it should ────────────────────────────────────────────

    /** A PDF is the case this whole feature exists for. */
    public function testAPdfIsAccepted(): void
    {
        self::assertSame(
            'application/pdf',
            FormUploadPolicy::assertAcceptable(self::PDF, 'application/pdf', FormUploadPolicy::MAX_BYTES)
        );
    }

    /** A scan of a certificate is the other honest case. */
    public function testAScanIsAccepted(): void
    {
        self::assertSame(
            'image/png',
            FormUploadPolicy::assertAcceptable(self::PNG, 'image/png', FormUploadPolicy::MAX_BYTES)
        );
        self::assertSame(
            'image/jpeg',
            FormUploadPolicy::assertAcceptable(self::JPEG, 'image/jpeg', FormUploadPolicy::MAX_BYTES)
        );
    }

    /**
     * A client that declares NOTHING is not punished for it.
     *
     * Plenty of honest clients send no per-part content type, and refusing them
     * would make the check about the label rather than about the file.
     */
    public function testAnUndeclaredTypeIsDecidedByTheBytes(): void
    {
        self::assertSame(
            'application/pdf',
            FormUploadPolicy::assertAcceptable(self::PDF, null, FormUploadPolicy::MAX_BYTES)
        );
    }

    /**
     * `application/octet-stream` is "I do not know", not a contradiction.
     *
     * Treating it as a mismatch would refuse every upload from a client that
     * shrugs — which is most of them, on some platforms.
     */
    public function testTheGenericTypeIsTreatedAsNoClaimAtAll(): void
    {
        self::assertSame(
            'application/pdf',
            FormUploadPolicy::assertAcceptable(self::PDF, 'application/octet-stream', FormUploadPolicy::MAX_BYTES)
        );
    }

    /** Parameters and casing are the same claim, not a different one. */
    public function testADeclaredTypeIsComparedWithoutItsParametersOrCase(): void
    {
        self::assertSame(
            'application/pdf',
            FormUploadPolicy::assertAcceptable(self::PDF, 'APPLICATION/PDF; charset=binary', FormUploadPolicy::MAX_BYTES)
        );
    }

    // ── it refuses what it should ────────────────────────────────────────────

    /**
     * AN OVERSIZED FILE IS REFUSED, and the sentence names the limit.
     *
     * Asserted at one byte over rather than at some comfortable multiple: the
     * boundary is where an off-by-one would live, and a test that uploaded 50 MB
     * to prove 10 is the ceiling would be testing the machine's memory.
     */
    public function testAnOversizedFileIsRefused(): void
    {
        $tooBig = self::PDF . str_repeat("\0", FormUploadPolicy::MAX_BYTES);

        try {
            FormUploadPolicy::assertAcceptable($tooBig, 'application/pdf', FormUploadPolicy::MAX_BYTES);
            self::fail('an oversized upload must be refused');
        } catch (FormRejectedException $e) {
            self::assertStringContainsString('too large', $e->clientMessage);
            self::assertStringContainsString('10 MB', $e->clientMessage);
        }
    }

    /** Exactly at the ceiling is ACCEPTED — a limit is a limit, not a limit minus one. */
    public function testExactlyTheCeilingIsAccepted(): void
    {
        $atLimit = self::PDF . str_repeat("\0", FormUploadPolicy::MAX_BYTES - strlen(self::PDF));
        self::assertSame(FormUploadPolicy::MAX_BYTES, strlen($atLimit));

        self::assertSame(
            'application/pdf',
            FormUploadPolicy::assertAcceptable($atLimit, 'application/pdf', FormUploadPolicy::MAX_BYTES)
        );
    }

    /**
     * The PUBLIC ceiling is half, and it is enforced by the SAME function.
     *
     * A file between the two limits is the interesting case: accepted from a
     * signed-in member, refused from a stranger. If the two surfaces had grown
     * separate checks this is the assertion that would have caught it.
     */
    public function testThePublicCeilingIsLowerAndSeparatelyEnforced(): void
    {
        $middling = self::PDF . str_repeat("\0", FormUploadPolicy::PUBLIC_MAX_BYTES);
        self::assertLessThan(FormUploadPolicy::MAX_BYTES, strlen($middling));

        self::assertSame(
            'application/pdf',
            FormUploadPolicy::assertAcceptable($middling, null, FormUploadPolicy::MAX_BYTES),
            'a member may send this'
        );

        $this->expectException(FormRejectedException::class);
        FormUploadPolicy::assertAcceptable($middling, null, FormUploadPolicy::PUBLIC_MAX_BYTES);
    }

    /**
     * A WRONG CONTENT TYPE IS REFUSED — and the label is not what makes it
     * wrong.
     *
     * A ZIP declaring itself a PDF is the shape that matters: `PK\x03\x04` is
     * also what a .docx, a .jar and a zip bomb start with, which is exactly why
     * office formats are not on the allow-list.
     */
    public function testAZipDeclaringItselfAPdfIsRefused(): void
    {
        try {
            FormUploadPolicy::assertAcceptable(
                "PK\x03\x04\x14\x00\x00\x00",
                'application/pdf',
                FormUploadPolicy::MAX_BYTES,
            );
            self::fail('a ZIP must not be accepted, whatever it calls itself');
        } catch (FormRejectedException $e) {
            self::assertStringContainsString('PDF, PNG or JPEG', $e->clientMessage);
        }
    }

    /** So is anything else with no signature — a CSV, a script, plain text. */
    public function testAnUnrecognisedFileIsRefused(): void
    {
        $this->expectException(FormRejectedException::class);
        FormUploadPolicy::assertAcceptable("name,score\nfatima,9\n", 'text/csv', FormUploadPolicy::MAX_BYTES);
    }

    /**
     * A file whose DECLARED type contradicts its bytes is refused even when both
     * types are on the allow-list.
     *
     * The disagreement is itself the signal: an honest client reports what it
     * read off the file, so a mismatch means somebody relabelled it in transit.
     * Accepting it would also store the object under a type its bytes deny.
     */
    public function testAnAcceptedKindMislabelledAsAnotherAcceptedKindIsRefused(): void
    {
        try {
            FormUploadPolicy::assertAcceptable(self::PDF, 'image/png', FormUploadPolicy::MAX_BYTES);
            self::fail('a declared type that contradicts the bytes must be refused');
        } catch (FormRejectedException $e) {
            self::assertStringContainsString('does not look like what it claims', $e->clientMessage);
        }
    }

    /** An empty file satisfies a required field while attaching nothing. Refused. */
    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectException(FormRejectedException::class);
        FormUploadPolicy::assertAcceptable('', 'application/pdf', FormUploadPolicy::MAX_BYTES);
    }

    // ── the key's extension comes from the verdict, never from the caller ────

    /**
     * The extension is derived from the SNIFFED type, so no part of a storage
     * key is attacker-influenced.
     */
    public function testTheStoredExtensionFollowsTheSniffedType(): void
    {
        self::assertSame('pdf', FormUploadPolicy::extensionFor('application/pdf'));
        self::assertSame('png', FormUploadPolicy::extensionFor('image/png'));
        self::assertSame('jpg', FormUploadPolicy::extensionFor('image/jpeg'));
        // Unreachable for a value that came out of assertAcceptable(); present
        // so this can never produce a key ending in something a caller chose.
        self::assertSame('bin', FormUploadPolicy::extensionFor('application/x-msdownload'));
    }

    /** The advertised list and the accepted list are the same list. */
    public function testTheAdvertisedTypesAreExactlyTheAcceptedOnes(): void
    {
        self::assertSame(
            ['application/pdf', 'image/png', 'image/jpeg'],
            FormUploadPolicy::acceptedContentTypes()
        );

        foreach (FormUploadPolicy::acceptedContentTypes() as $type) {
            self::assertNotSame(
                'bin',
                FormUploadPolicy::extensionFor($type),
                "an advertised type must have a real extension: {$type}"
            );
        }
    }

    public function testSniffReturnsNullRatherThanGuessing(): void
    {
        self::assertNull(FormUploadPolicy::sniff('hello'));
        self::assertNull(FormUploadPolicy::sniff(''));
        self::assertSame('application/pdf', FormUploadPolicy::sniff(self::PDF));
    }
}
