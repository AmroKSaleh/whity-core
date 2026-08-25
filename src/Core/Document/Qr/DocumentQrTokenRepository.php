<?php

declare(strict_types=1);

namespace Whity\Core\Document\Qr;

use PDO;

/**
 * Data-access for `document_qr_tokens` (#1036) — the code printed on a document.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT OFFER
 * -------------------------------------------
 * There is no `update()` and no `delete()`. A minted code is a fact about paper
 * that already exists in the world, so the row is written once and thereafter
 * only LATCHED — {@see revoke()} sets `revoked_at`/`revoked_by`/`revoked_reason`
 * and refuses to touch a row that already carries them. Rows leave only by
 * cascade from `documents` or `tenants` (migration 120); there is no path from
 * here, for the same reason
 * {@see \Whity\Core\Document\Routing\RouteEventRepository} offers none.
 *
 * A code being wrong is not fixed by editing it. It is fixed by revoking it and
 * minting another, which leaves both rows and therefore leaves the answer to
 * "what was on the paper we posted in March".
 *
 * TENANT-OWNED, WITH ONE EXCEPTION THAT IS THE WHOLE FEATURE
 * ----------------------------------------------------------
 * Every statement here binds a literal `tenant_id` predicate, spelled out in SQL
 * so scripts/ci-tenant-predicate-guard.php can verify it by reading this file —
 * EXCEPT {@see findByToken()}, which is reached from the anonymous public
 * verification endpoint where there is no tenant context by construction. It
 * carries an explicit guard annotation and the reasoning is in its own docblock.
 */
final class DocumentQrTokenRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert one minted code and return its id.
     *
     * The token is supplied rather than generated here: minting is a two-step
     * sequence (retire the old code, insert the new one) that
     * {@see DocumentQrService::mint()} runs inside a transaction, and a
     * repository that also generated the secret would put the CSPRNG call
     * somewhere a test could not substitute it.
     */
    public function insert(int $tenantId, int $documentId, string $token, ?int $issuedBy): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO document_qr_tokens
                 (tenant_id, document_id, token, issued_by, issued_at)
             VALUES (:tenant_id, :document_id, :token, :issued_by, NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':document_id' => $documentId,
            ':token' => $token,
            ':issued_by' => $issuedBy,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * The code currently in force for a document, or null when it has none.
     *
     * "In force" is `revoked_at IS NULL`, read off the row rather than derived
     * from anywhere — see migration 120 on why this latch is not the status
     * column migration 108 refuses.
     *
     * Newest first, because a document may legitimately have several rows: every
     * rotation leaves the retired one behind. Under normal operation at most one
     * is un-revoked; ordering by id makes the answer deterministic even if two
     * interleaved mints both won.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveForDocument(int $tenantId, int $documentId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, token, issued_by, issued_at,
                    revoked_at, revoked_by, revoked_reason
               FROM document_qr_tokens
              WHERE tenant_id = :tenant_id
                AND document_id = :document_id
                AND revoked_at IS NULL
              ORDER BY id DESC
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * Every code ever minted for a document, newest first — live and retired.
     *
     * The record panel shows the live one and how many were retired; an
     * investigator needs the retired ones to answer "was this the code on the
     * copy we sent in March".
     *
     * @return list<array<string, mixed>>
     */
    public function listForDocument(int $tenantId, int $documentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, document_id, token, issued_by, issued_at,
                    revoked_at, revoked_by, revoked_reason
               FROM document_qr_tokens
              WHERE tenant_id = :tenant_id AND document_id = :document_id
              ORDER BY id DESC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':document_id' => $documentId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /**
     * The row a raw token names, live or revoked, with its tenant's own name.
     *
     * THE ONE READ IN THIS SUBSYSTEM THAT ARRIVES WITHOUT A TENANT. It is the
     * anonymous public verification endpoint: a stranger holding a piece of
     * paper, who has no session, no membership and nothing to resolve a tenant
     * from. Requiring a tenant predicate here would mean requiring the CALLER to
     * name a tenant, which is both unanswerable for them and the enumeration
     * surface #1036 exists to avoid.
     *
     * The 256-bit token IS the selector: it is globally unique
     * (`UNIQUE (token)`), so it resolves to exactly one row in exactly one
     * tenant, and every read the handler makes afterwards binds the `tenant_id`
     * this row returned. That is the same construction
     * {@see \Whity\Core\Identity\InvitationService::findLiveByToken()} uses on
     * the public accept endpoint, and `invitations` records the same exception
     * in {@see \Whity\Core\Tenant\TenantOwnedTables}.
     *
     * LIVE AND REVOKED ROWS BOTH COME BACK. Filtering revoked ones out here
     * would make a withdrawn code indistinguishable from an unknown one at the
     * DATA layer, which sounds like the privacy posture #1036 asks for and is
     * the wrong place for it: the tenant may have chosen to tell a holder their
     * paper is out of date, the scan of a revoked code is the one most worth
     * RECORDING, and neither is possible if the row never came back.
     * {@see VerificationPresenter} makes the disclosure decision, once, where
     * the tenant's setting is in scope.
     *
     * `tenants.name` is joined here rather than fetched separately because it is
     * the one fact the public page always shows — "issued by X" — and it must
     * come from the token's own tenant rather than from anything the caller
     * said.
     *
     * @return array<string, mixed>|null
     */
    public function findByToken(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        // @tenant-guard-ignore: reached from the PUBLIC verification endpoint, which has no tenant context by construction — the globally-unique 256-bit token is the selector, and every read the handler makes afterwards binds the tenant_id this row returns
        $stmt = $this->db->prepare(
            'SELECT q.id, q.tenant_id, q.document_id, q.token, q.issued_by, q.issued_at,
                    q.revoked_at, q.revoked_by, q.revoked_reason, t.name AS tenant_name
               FROM document_qr_tokens q
               LEFT JOIN tenants t ON t.id = q.tenant_id
              WHERE q.token = :token
              LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::normalize($row) : null;
    }

    /**
     * Latch one live code into the revoked state. Returns whether it changed.
     *
     * The `revoked_at IS NULL` predicate is what makes this a LATCH rather than
     * an update: a second revoke of the same row matches nothing, so the first
     * reason and the first timestamp are the ones that survive. Two operators
     * withdrawing the same code concurrently produce one revocation, not a race
     * over whose reason is recorded.
     *
     * The reason is validated by the caller against
     * {@see QrRevocationReason::isKnown()} and by the database against migration
     * 120's CHECK — belt and braces, because the CHECK is the half that survives
     * a future caller who forgets.
     */
    public function revoke(int $tenantId, int $tokenId, ?int $revokedBy, string $reason): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE document_qr_tokens
                SET revoked_at = NOW(), revoked_by = :revoked_by, revoked_reason = :reason
              WHERE tenant_id = :tenant_id
                AND id = :id
                AND revoked_at IS NULL'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':id' => $tokenId,
            ':revoked_by' => $revokedBy,
            ':reason' => $reason,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Ints as ints and the nullable columns as null, so callers never compare a
     * PostgreSQL string id against an int and get false — the normalisation
     * every repository in this subsystem does at its edge.
     *
     * `token` is left exactly as stored: it is the value that has to match the
     * bytes printed on paper, and any coercion here would be a bug that only
     * shows up on a scanner.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['document_id'] = (int) $row['document_id'];
        $row['issued_by'] = isset($row['issued_by']) ? (int) $row['issued_by'] : null;
        $row['revoked_by'] = isset($row['revoked_by']) ? (int) $row['revoked_by'] : null;
        $row['revoked_at'] = $row['revoked_at'] ?? null;
        $row['revoked_reason'] = $row['revoked_reason'] ?? null;

        return $row;
    }
}
