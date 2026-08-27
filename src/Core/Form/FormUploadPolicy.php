<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * WHAT MAY BE UPLOADED AGAINST A `file` FIELD: how big, and of what kind.
 *
 * One class, no I/O, no database handle — the same shape
 * {@see PublicFormView} takes and for the same reason. "What will this platform
 * accept from a stranger" must be ONE list a reviewer can read in a minute,
 * rather than a size check in one handler and a type check in another that
 * disagree by the time anybody looks.
 *
 * THE TYPE IS SNIFFED, NEVER BELIEVED
 * ------------------------------------
 * `Content-Type` on a multipart part is a string the CALLER typed.
 * {@see \Whity\Sdk\Http\UploadedFile::getClientMediaType()} says so in its own
 * docblock. A check that trusted it would be satisfied by `application/pdf` on
 * anything at all, which is not a check.
 *
 * So the verdict comes from the LEADING BYTES, and the declared type is used
 * only to REFUSE a mismatch — never to admit one. A part that says `image/png`
 * and sniffs as PDF is refused even though PDF is on the allow-list: the two
 * disagreeing is itself the signal, and the object would go on to be served with
 * a type its bytes contradict.
 *
 * WHY THE ALLOW-LIST IS THREE ENTRIES AND MUST STAY SMALL
 * -------------------------------------------------------
 * The case this exists for is an applicant attaching a published paper, or a
 * scan of a certificate. That is a PDF or a photograph, and both have magic
 * bytes that identify them with certainty in the first eight bytes.
 *
 * OFFICE FORMATS ARE DELIBERATELY ABSENT, and the reason is not fastidiousness.
 * A `.docx` IS a ZIP; so is a `.xlsx`, a `.jar`, and a zip bomb. Their leading
 * bytes are `PK\x03\x04` and nothing else, so "accept DOCX" cannot be
 * implemented as a magic-byte check at all — it is "accept any ZIP", plus a
 * decision to open and walk an archive supplied by an anonymous caller in order
 * to tell which kind it is. {@see \Whity\Core\PluginInstaller} does that work
 * for plugin packages, with zip-slip and zip-bomb defences and a permission gate
 * in front of it; a form attachment is not worth a second copy of that
 * apparatus. An author who needs a Word document asks for a PDF export, which is
 * what the recipient wanted anyway.
 *
 * Adding a kind here is a real decision: it needs an unambiguous signature, and
 * it widens what an unauthenticated caller can put in a tenant's bucket.
 *
 * THE CEILINGS
 * -------------
 * {@see self::MAX_BYTES} is 10 MiB for a signed-in member and
 * {@see self::PUBLIC_MAX_BYTES} is 5 MiB for an anonymous one. A published paper
 * is single-digit megabytes; a dataset, a video or a disk image is not this
 * field, and a `file` answer that could be any size would make one form field a
 * way to spend a tenant's storage budget.
 *
 * The public ceiling is HALF, because the two callers are not comparable. A
 * signed-in member is a known person inside the organisation whose upload is
 * attributable and revocable. An anonymous caller is bounded by a rate limit and
 * nothing else, so the product of the two limits — how many bytes one address
 * can commission per hour — is what actually matters, and the size is the half
 * of it that costs an honest applicant nothing.
 *
 * These numbers are the APPLICATION's, and the deployment has to be able to
 * express them: PHP's own `upload_max_filesize` ships at 2 MiB, which would
 * refuse a three-megabyte paper before any of this code ran, with PHP's error
 * rather than a sentence anybody can act on. The Dockerfile raises the ini
 * limits ABOVE these ceilings precisely so that THIS class is what refuses, and
 * says why.
 *
 * Stateless — worker-safe.
 */
final class FormUploadPolicy
{
    /** Ceiling for an authenticated upload: 10 MiB. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** Ceiling for an anonymous upload against a public form link: 5 MiB. */
    public const PUBLIC_MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Accepted content types, and the file extension each is stored under.
     *
     * The extension matters at write time rather than read time:
     * {@see \Whity\Storage\S3\S3StorageDriver::put()} stamps a type onto the
     * object permanently, and {@see \Whity\Storage\LocalStorageDriver} derives
     * one from the key's extension — so a key with no meaningful extension is a
     * file whose type cannot be recovered on one of the two backends. Same
     * argument {@see \Whity\Storage\MimeTypes} makes.
     *
     * @var array<string, string>
     */
    private const ACCEPTED = [
        'application/pdf' => 'pdf',
        'image/png'       => 'png',
        'image/jpeg'      => 'jpg',
    ];

    /**
     * Leading-byte signatures, longest first so a prefix cannot shadow a longer
     * match.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const SIGNATURES = [
        ["\x89PNG\r\n\x1a\n", 'image/png'],
        ["%PDF-", 'application/pdf'],
        ["\xff\xd8\xff", 'image/jpeg'],
    ];

    /**
     * Static policy only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The accepted content types, for a client that wants to set an `accept`
     * attribute or an error message that lists them.
     *
     * @return list<string>
     */
    public static function acceptedContentTypes(): array
    {
        return array_keys(self::ACCEPTED);
    }

    /**
     * The extension an accepted content type is stored under.
     *
     * Returns `bin` for an unknown type, which cannot happen for a value that
     * came out of {@see self::assertAcceptable()} — the fallback exists so this
     * can never produce a key with an attacker-influenced extension.
     */
    public static function extensionFor(string $contentType): string
    {
        return self::ACCEPTED[$contentType] ?? 'bin';
    }

    /**
     * Decide whether these bytes may be stored, and under which content type.
     *
     * The ORDER is size, then emptiness, then sniff, then declared-type
     * agreement — cheapest and most-likely-wrong first, so an oversized upload
     * is refused without hashing or inspecting anything.
     *
     * @param string      $bytes    The file as received.
     * @param string|null $declared The client's `Content-Type`, if it sent one.
     * @param int         $maxBytes {@see self::MAX_BYTES} or {@see self::PUBLIC_MAX_BYTES}.
     *
     * @return string The SNIFFED content type — the only one a caller may store.
     *
     * @throws FormRejectedException Naming the limit or the accepted kinds, in a
     *         sentence written for whoever is looking at the form.
     */
    public static function assertAcceptable(string $bytes, ?string $declared, int $maxBytes): string
    {
        $size = strlen($bytes);

        if ($size > $maxBytes) {
            throw new FormRejectedException(
                'That file is too large — the limit is ' . self::describeSize($maxBytes) . '.',
                'form upload refused: ' . $size . ' bytes exceeds the ' . $maxBytes . '-byte ceiling',
            );
        }
        if ($size === 0) {
            // Refused rather than stored: a zero-byte object is an attachment
            // that will read back as nothing, and a submission carrying one
            // would satisfy a required `file` field while attaching no evidence
            // at all.
            throw new FormRejectedException('That file is empty.');
        }

        $sniffed = self::sniff($bytes);
        if ($sniffed === null) {
            throw new FormRejectedException(
                'That kind of file cannot be attached — please upload a PDF, PNG or JPEG.',
                'form upload refused: leading bytes match no accepted signature',
            );
        }

        $normalizedDeclared = self::normalizeDeclared($declared);
        if ($normalizedDeclared !== null && $normalizedDeclared !== $sniffed) {
            // Not merely "the client was wrong". The two disagreeing is the
            // signal — an honest browser sends what it read off the file, so a
            // mismatch means somebody relabelled it on the way out.
            throw new FormRejectedException(
                'That file does not look like what it claims to be.',
                "form upload refused: declared '{$normalizedDeclared}' but bytes are '{$sniffed}'",
            );
        }

        return $sniffed;
    }

    /**
     * The content type implied by the leading bytes, or null when nothing
     * accepted matches.
     *
     * Deliberately NOT `finfo`/`mime_content_type`: those return a type for
     * every input, so a caller would have to compare the answer against an
     * allow-list anyway — and their answer depends on which magic database the
     * container happens to ship, which is a policy that changes under you on a
     * base-image bump. Three fixed signatures give the same verdict on every
     * host, forever.
     */
    public static function sniff(string $bytes): ?string
    {
        foreach (self::SIGNATURES as [$magic, $type]) {
            if (str_starts_with($bytes, $magic)) {
                return $type;
            }
        }

        return null;
    }

    /**
     * A client-supplied media type reduced to its bare type, or null when it
     * says nothing useful.
     *
     * `application/pdf; charset=binary` and `APPLICATION/PDF` are the same
     * claim, and `application/octet-stream` is the browser saying "I do not
     * know" — which must not be read as a contradiction of the sniff, or every
     * upload from a client that omits the type would be refused.
     */
    private static function normalizeDeclared(?string $declared): ?string
    {
        if ($declared === null) {
            return null;
        }
        $type = strtolower(trim(explode(';', $declared, 2)[0]));

        if ($type === '' || $type === 'application/octet-stream') {
            return null;
        }

        return $type;
    }

    /**
     * A byte ceiling as a person would say it, so the refusal names a number
     * they can compare their file against.
     */
    private static function describeSize(int $bytes): string
    {
        return (string) intdiv($bytes, 1024 * 1024) . ' MB';
    }
}
