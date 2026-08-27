<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The LINK half of a public form (migration 132): minting the slug, composing
 * the address, and answering the two questions the public endpoints ask before
 * they will do anything at all.
 *
 * The other half — what an anonymous caller is actually SHOWN — is
 * {@see PublicFormView}. They are separate classes because they answer different
 * questions and change for different reasons: this one is about whether the door
 * is open, that one is about what is visible through it.
 *
 * THE SLUG IS THE ONLY CREDENTIAL, WHICH IS WHY IT IS 256 BITS
 * ------------------------------------------------------------
 * There is no session on the public endpoints and there never will be — the
 * whole point of the feature is a person with no account. So the slug is not "an
 * identifier that happens to be random"; it is the entire authentication story,
 * and every disclosure decision downstream rests on it being unguessable.
 *
 * 32 bytes from {@see random_bytes()} — a CSPRNG, never `rand()`, `mt_rand()` or
 * `uniqid()`, all of which are predictable from a handful of outputs —
 * hex-encoded to 64 characters. That is the SAME strength
 * {@see \Whity\Core\Document\Qr\DocumentQrService::TOKEN_BYTES} and
 * `InvitationService::TOKEN_BYTES` already use, deliberately rather than a third
 * number: a platform with three answers to "how strong is a public token" has
 * two answers nobody chose.
 *
 * `random_bytes()` THROWS rather than returning weak output when the system
 * CSPRNG is unavailable, which is the behaviour a slug generator should have: a
 * form that could not be opened is a visible problem, and a form opened behind a
 * predictable slug is not.
 *
 * WHY ROTATION IS RE-ENABLING, NOT A SEPARATE VERB
 * ------------------------------------------------
 * {@see newSlug()} is called on every enable, so closing a link and opening it
 * again produces a DIFFERENT address and the old one is dead. There is no
 * "re-open with the same slug", because the slug's whole job is to be the thing
 * on the poster, and a closed-then-reopened link that still answered would make
 * "I revoked that" untrue. Same argument
 * {@see \Whity\Core\Document\Qr\DocumentQrService::mint()} makes about rotating
 * a printed code.
 *
 * WHY THE URL IS THE API ADDRESS AND NOT A `/forms/{slug}` PAGE
 * -------------------------------------------------------------
 * `DocumentQrService::verificationUrl()` emits a HUMAN page (`/verify/{token}`)
 * and explicitly refuses to emit an API path, because a phone camera opens a QR
 * in a browser and JSON is the wrong answer for a courier. The opposite call is
 * correct here, for the same underlying rule — emit the address that actually
 * answers — applied to a different set of facts: this change ships the public
 * ENDPOINTS, and the browser page that will consume them is not in it. Emitting
 * `/forms/{slug}` would hand every author a link that 404s for every member of
 * the public who follows it, which is worse than an ugly URL by exactly the
 * margin that "renders fine, does nothing" is worse than "looks technical".
 *
 * When the page lands, `publicUrl()` is the one place that changes and the
 * builder inherits it — which is the reason the URL is composed here rather than
 * spelled out in the descriptor or in a client.
 *
 * `/api/v1/...` IS WRITTEN OUT, and that is deliberate: {@see \Whity\Core\Router}
 * prepends `/v1` to a DECLARED path, so `public/index.php` registers
 * `/api/public/forms/{slug}` while the live address carries the version. A URL
 * this class RETURNS is one a client will fetch, so it must be the emitted form.
 *
 * Stateless apart from the injected base URL — worker-safe.
 */
final class PublicFormLink
{
    /**
     * Slug entropy in bytes, before hex encoding. 32 → 256 bits → 64 characters,
     * which is exactly `forms.public_slug`'s VARCHAR(64).
     *
     * The column width and this constant are two spellings of one fact. They are
     * kept equal so a wider slug is a test failure rather than a silent
     * truncation that makes two forms collide.
     */
    public const SLUG_BYTES = 32;

    /** The encoded width — {@see SLUG_BYTES} hex-encoded. */
    public const SLUG_CHARS = self::SLUG_BYTES * 2;

    /**
     * @param string $publicBaseUrl The instance's own public origin (APP_URL),
     *        already trimmed of a trailing slash. EMPTY is a real state — an
     *        instance nobody has told its own address — and it is handled by
     *        returning null from {@see publicUrl()} rather than by emitting a
     *        relative path a poster cannot carry. Minting is NOT refused on it:
     *        the slug is what makes the endpoint reachable, and an operator who
     *        sets APP_URL a week later must not have to re-open every form.
     */
    public function __construct(private readonly string $publicBaseUrl)
    {
    }

    /**
     * A fresh 256-bit slug, hex-encoded.
     */
    public static function newSlug(): string
    {
        return bin2hex(random_bytes(self::SLUG_BYTES));
    }

    /**
     * Cheap shape check before the database is asked anything.
     *
     * NOT a security boundary — a wrong-shaped slug would simply match no row —
     * but it keeps a crawler, or a caller who pasted a whole URL into the
     * segment, from costing a query each time. It is also why the public
     * handlers can throttle BEFORE looking at the slug without that ordering
     * leaking anything: the shape check is constant work either way.
     */
    public static function looksLikeSlug(string $slug): bool
    {
        return strlen($slug) === self::SLUG_CHARS && ctype_xdigit($slug);
    }

    /**
     * Whether this instance knows its own address well enough to compose a link.
     */
    public function isConfigured(): bool
    {
        return $this->publicBaseUrl !== '';
    }

    /**
     * The address the form is served from, or null when there is no slug or the
     * instance has never been told its own origin.
     *
     * Null rather than a relative path: a link an author copies onto a poster
     * must be absolute or it is not a link, and returning `/api/v1/...` would
     * produce something that looks copyable and is not.
     */
    public function publicUrl(?string $slug): ?string
    {
        if ($slug === null || $slug === '' || !$this->isConfigured()) {
            return null;
        }

        // THE PAGE, NOT THE ENDPOINT. This URL is copied into an email and
        // opened in a browser by somebody with no account, so it has to be
        // an address that renders a form. Pointing it at the API meant the
        // recipient was handed raw JSON — the API is what that page calls,
        // not what a person opens.
        return $this->publicBaseUrl . '/f/' . rawurlencode($slug);
    }

    /**
     * THE HARD GATE: may this form be served to somebody with no account at all?
     *
     * Both halves must be true, and each is a separate deliberate act by a
     * different kind of decision:
     *
     *   - `public_enabled` — somebody with `forms:manage` opened this specific
     *     form to the public. Off by default, per migration 132.
     *   - `status === published` — the form is live at all. An archived form's
     *     public link dies WITH the archive rather than needing a second act of
     *     housekeeping, which matters because "archive it" is what a person
     *     reaches for when they want submissions to stop.
     *
     * Everything this returns false for collapses to ONE generic 404 in the
     * handlers — the same 404 an unknown slug gets — so the endpoints cannot be
     * asked which slugs name a real form. See {@see \Whity\Api\PublicFormsApiHandler}.
     *
     * @param array<string, mixed> $form A normalized `forms` row.
     */
    public static function servesPublicly(array $form): bool
    {
        return ($form['public_enabled'] ?? false) === true
            && (string) ($form['status'] ?? '') === FormStatus::PUBLISHED;
    }

    /**
     * THE SOFT GATE: is the form inside its submission window right now?
     *
     * Read off the row rather than recomputed here, because the comparison is
     * made in SQL against the database's own clock
     * ({@see FormRepository::COLUMNS}) — the same clock that writes every
     * `created_at` in the schema. Doing it in PHP would introduce a SECOND clock
     * and a timezone question, and a window that opens at different moments
     * depending on which process asked is a window nobody can reason about.
     *
     * A form with no window at all is inside it, which is what makes both
     * columns nullable rather than sentinel-defaulted.
     *
     * @param array<string, mixed> $form A normalized `forms` row.
     */
    public static function withinWindow(array $form): bool
    {
        return ($form['public_window_open'] ?? true) === true;
    }

    /**
     * Whether an anonymous caller may SUBMIT to this form right now.
     *
     * The three gates in one place so a render and a submit can never disagree
     * about what "open" means: the form must serve publicly, be inside its
     * window, and be in a status that accepts submissions at all.
     *
     * @param array<string, mixed> $form A normalized `forms` row.
     */
    public static function acceptsPublicSubmissions(array $form): bool
    {
        return self::servesPublicly($form)
            && self::withinWindow($form)
            && FormStatus::acceptsSubmissions((string) ($form['status'] ?? ''));
    }
}
