<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

use PDO;

/**
 * Minting, rotating, revoking and resolving a document's verification code
 * (#1036) — and recording that somebody scanned it.
 *
 * THE ONE INVARIANT EVERYTHING HERE PROTECTS
 * ------------------------------------------
 * The token IDENTIFIES a document. It never AUTHORISES access to one.
 *
 * Read that as a claim about this class specifically: nothing in it consults a
 * permission, grants one, widens one, or returns a document's contents. The most
 * a token can produce is a `document_qr_tokens` row — a tenant id, a document
 * id, and whether the code is still honoured. Turning that into a page a
 * stranger may read is {@see VerificationPresenter}'s job and it discloses only
 * what the tenant chose; turning it into the RECORD is
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy}'s job, unchanged, which
 * has never heard of a token and answers 404 to a caller without reach exactly
 * as it does today.
 *
 * That is what makes photographing a printed document harmless. If possession of
 * the code granted anything, a photocopier would be a privilege-escalation tool.
 *
 * THE TOKEN
 * ---------
 * 32 bytes from {@see random_bytes()} — a CSPRNG, never `rand()`, `mt_rand()` or
 * `uniqid()`, all of which are predictable from a few outputs — hex-encoded to
 * 64 characters. That is 256 bits of entropy in a namespace of 2^256, so the
 * expected number of guesses to hit ANY live token is astronomically beyond what
 * a rate-limited HTTP endpoint could serve, and it is not the document id, so
 * scanning one code tells a holder nothing about the next.
 *
 * Hex rather than base64url, at 64 characters rather than 43: the extra length
 * costs one QR version and buys a payload with no case-sensitivity to lose in a
 * transcription, no characters that need URL-escaping, and the same encoding
 * every other token in this codebase already uses.
 *
 * WHY MINTING ROTATES
 * -------------------
 * {@see mint()} always issues a NEW code and retires whatever came before it as
 * {@see QrRevocationReason::SUPERSEDED}. There is no "re-mint the same value",
 * because the value's whole job is to be the one on the paper, and two live
 * codes for one document would make "is this the current printing" unanswerable.
 * {@see ensure()} is the idempotent entry point every render path uses; `mint()`
 * is the deliberate rotation an operator asks for.
 */
final class DocumentQrService
{
    /**
     * Token entropy in bytes, before hex encoding. 32 → 256 bits → 64 chars.
     *
     * Well past the platform's ≥32-character floor for a secret, and chosen to
     * match `InvitationService::TOKEN_BYTES` rather than invent a second
     * strength for a second token.
     */
    public const TOKEN_BYTES = 32;

    /**
     * How much of the token is printed beneath the code as a human reference.
     *
     * 12 hex characters = 48 bits, shown as three groups of four. It is not a
     * second credential and it is never accepted as one — {@see resolve()} takes
     * the whole token or nothing. It exists so a person on the phone can read
     * something aloud, and so a holder can match the page they are looking at to
     * the paper in their hand without trusting that they scanned the right
     * sheet.
     *
     * Disclosing a prefix of the token to somebody who already holds the token
     * discloses nothing.
     */
    private const REFERENCE_CHARS = 12;

    /**
     * Scans of the same code by the same scanner inside this window count once.
     *
     * A phone that opens the page, rotates and reloads has scanned the paper
     * once. Without a window the trail becomes a page-view log — useless to the
     * person reading it, and an amplification surface, since the caller deciding
     * how many rows to write is an anonymous stranger with a photograph.
     */
    private const SCAN_COALESCE_SECONDS = 60;

    /**
     * @param string $publicBaseUrl The instance's public web origin (APP_URL),
     *        already trimmed of a trailing slash. EMPTY is a real state — an
     *        instance that has not been told its own address — and it is handled
     *        by refusing to mint rather than by emitting a relative URL that
     *        would encode into a QR nothing can follow.
     */
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentQrTokenRepository $tokens,
        private readonly DocumentQrScanRepository $scans,
        private readonly string $publicBaseUrl,
    ) {
    }

    /**
     * Whether this instance can mint a code at all.
     *
     * Checked BEFORE the tenant switch by every caller, because "the operator
     * never set APP_URL" is an instance fault and reporting it as "the tenant
     * turned QR off" would send somebody to the wrong settings page.
     */
    public function isConfigured(): bool
    {
        return $this->publicBaseUrl !== '';
    }

    /**
     * The code currently in force for a document, or null.
     *
     * @return array<string, mixed>|null
     */
    public function active(int $tenantId, int $documentId): ?array
    {
        return $this->tokens->findActiveForDocument($tenantId, $documentId);
    }

    /**
     * The code in force, minting one if the document has none.
     *
     * The idempotent entry point: every render path calls this, and calling it
     * twice for the same document produces one code. A render must never rotate,
     * because rotating retires the paper already in circulation and a render is
     * not a decision to do that.
     *
     * @return array<string, mixed>|null Null when this instance cannot mint.
     */
    public function ensure(int $tenantId, int $documentId, ?int $actorId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $existing = $this->tokens->findActiveForDocument($tenantId, $documentId);
        if ($existing !== null) {
            return $existing;
        }

        return $this->mint($tenantId, $documentId, $actorId);
    }

    /**
     * Issue a NEW code, retiring the current one as superseded.
     *
     * Both statements run inside one transaction — its own only if the caller
     * has not already opened one, the same shape
     * {@see \Whity\Core\Document\DocumentIssuer::raise()} uses — so a document
     * can never be left with two live codes or with none after a failure
     * halfway.
     *
     * If a concurrent mint won the race, the row read back afterwards is
     * whichever one is live; both callers get a usable code and neither gets a
     * constraint violation. Migration 120 records why there is no partial unique
     * index that would have turned this into an error.
     *
     * @return array<string, mixed>|null Null when this instance cannot mint.
     */
    public function mint(int $tenantId, int $documentId, ?int $actorId): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $current = $this->tokens->findActiveForDocument($tenantId, $documentId);
            if ($current !== null) {
                $this->tokens->revoke(
                    $tenantId,
                    (int) $current['id'],
                    $actorId,
                    QrRevocationReason::SUPERSEDED,
                );
            }

            $this->tokens->insert($tenantId, $documentId, self::newToken(), $actorId);

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $this->tokens->findActiveForDocument($tenantId, $documentId);
    }

    /**
     * Stop honouring this document's current code. Returns whether one was live.
     *
     * THE ANSWER TO "PAPER CANNOT BE RECALLED". It cannot, so the recall happens
     * server-side: the code stays legible on every copy in the world and stops
     * confirming anything. Nothing is deleted — the row survives with its
     * timestamp, which is what lets somebody later answer "was this code live
     * when the letter was received".
     *
     * Deliberately does NOT mint a replacement. Withdrawing a code and issuing a
     * new one are different decisions ("this paper is not to be trusted" versus
     * "here is a fresh printing"), and folding them together would mean an
     * operator who withdrew a forgery's code immediately published a new one
     * that would verify.
     */
    public function revoke(int $tenantId, int $documentId, ?int $actorId): bool
    {
        $current = $this->tokens->findActiveForDocument($tenantId, $documentId);
        if ($current === null) {
            return false;
        }

        return $this->tokens->revoke(
            $tenantId,
            (int) $current['id'],
            $actorId,
            QrRevocationReason::WITHDRAWN,
        );
    }

    /**
     * The row a scanned token names — live or revoked — or null.
     *
     * The ONLY method reachable from the anonymous public endpoint, and it
     * returns a token row, never a document and never a permission. See
     * {@see DocumentQrTokenRepository::findByToken()} for why the lookup carries
     * no tenant predicate, and why a revoked row comes back rather than being
     * filtered here.
     *
     * The token is length-checked before it reaches the database. Not as
     * security — a wrong-length value simply would not match — but so that a
     * caller pasting a whole URL, or a scanner emitting a kilobyte of noise,
     * costs a string comparison instead of a query.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $token): ?array
    {
        if (!self::looksLikeToken($token)) {
            return null;
        }

        return $this->tokens->findByToken($token);
    }

    /**
     * Record that a resolved code was scanned. Returns whether a row was added.
     *
     * WHAT IS RECORDED: the code, the moment, the outcome, and the authenticated
     * principal IF THERE WAS ONE. WHAT IS NOT: anything about an anonymous
     * scanner. Migration 120 carries the full argument; the short version is
     * that a member of the public checking a document is genuine should not be
     * building a record of themselves by doing it, and that the count and the
     * timestamps are the part of "tracking" that answers a tenant's question
     * anyway.
     *
     * Called AFTER the disclosure decision and never before it: the row records
     * what the server did, so it has to be written once the server has done it.
     *
     * @param array<string, mixed> $tokenRow From {@see DocumentQrTokenRepository::findByToken()}.
     */
    public function recordScan(array $tokenRow, ?int $scannerProfileId): bool
    {
        $tenantId = (int) ($tokenRow['tenant_id'] ?? 0);
        $documentId = (int) ($tokenRow['document_id'] ?? 0);
        $tokenId = (int) ($tokenRow['id'] ?? 0);
        if ($tenantId <= 0 || $documentId <= 0 || $tokenId <= 0) {
            return false;
        }

        $since = gmdate('Y-m-d H:i:s', time() - self::SCAN_COALESCE_SECONDS);
        if ($this->scans->recentlyRecorded($tenantId, $tokenId, $scannerProfileId, $since)) {
            return false;
        }

        $outcome = ($tokenRow['revoked_at'] ?? null) === null
            ? QrScanOutcome::VERIFIED
            : QrScanOutcome::REFUSED;

        $this->scans->append($tenantId, $documentId, $tokenId, $scannerProfileId, $outcome);

        return true;
    }

    /**
     * The URL a QR code encodes: the PUBLIC VERIFICATION PAGE, not an API route.
     *
     * Read that carefully, because the version-prefix trap one directory over
     * ({@see \Whity\Core\Router::versionedPath()}, and the release where every
     * document's `content_url` pointed at a path nothing served) makes `/api/v1`
     * look like the answer here. It is not, and the reason is what a QR is FOR:
     * a phone camera opens it in a browser. A caller who lands on an API route
     * gets raw JSON, which is the wrong answer for a courier holding a printed
     * decision.
     *
     * So the payload is the human page, built from the instance's own public
     * origin and nothing else. No API path is emitted anywhere in this
     * subsystem — the record panel is handed this same absolute URL and the
     * public page's own fetch is a literal in the client — which is the cheapest
     * way to be sure none of them can drift out of step with the router.
     */
    public function verificationUrl(string $token): string
    {
        return $this->publicBaseUrl . '/verify/' . rawurlencode($token);
    }

    /**
     * The short reference printed beneath the code, e.g. `9F2A-4C11-8B03`.
     *
     * Uppercased because it is read aloud and hand-copied, grouped in fours
     * because a 12-character run is not, and derived from the token so it needs
     * no column and cannot drift from the code it labels.
     */
    public function reference(string $token): string
    {
        $prefix = strtoupper(substr($token, 0, self::REFERENCE_CHARS));

        return implode('-', str_split($prefix, 4));
    }

    /**
     * A fresh 256-bit token, hex-encoded.
     *
     * `random_bytes()` throws rather than returning weak output when the system
     * CSPRNG is unavailable, which is the behaviour a token generator should
     * have: a document with no code is a visible problem, and a document with a
     * predictable one is not.
     */
    private static function newToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    /**
     * Cheap shape check before the database is asked anything.
     *
     * Not a security boundary — a wrong-shaped token would simply fail to match
     * — but it keeps a scanner emitting noise, or a caller pasting a whole URL,
     * from costing a query each time.
     */
    private static function looksLikeToken(string $token): bool
    {
        return strlen($token) === self::TOKEN_BYTES * 2
            && ctype_xdigit($token);
    }
}
