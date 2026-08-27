<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use PDOException;
use Whity\Core\Db\DbBool;

/**
 * Data-access layer for `forms` (migration 127). All SQL touching the table
 * lives here so API handlers never issue raw queries (project convention).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every
 * SELECT/UPDATE/DELETE binds an explicit `tenant_id` predicate, so a form
 * written under one tenant can never be read or mutated under another. A form id
 * from another tenant is reported as ABSENT rather than forbidden — form ids are
 * enumerable integers, and "403" on one and "404" on another is an enumeration
 * oracle for which ids exist elsewhere in the install.
 *
 * WITH EXACTLY ONE EXCEPTION, AND IT IS THE FEATURE (migration 132)
 * -----------------------------------------------------------------
 * {@see findByPublicSlug()} binds NO tenant predicate, because it is reached
 * from the anonymous public endpoints where there is no tenant context by
 * construction — the caller has no account, so there is nothing to resolve one
 * from. It is the read that DERIVES the tenant, and it derives it from a 256-bit
 * slug that names exactly one row (migration 132's global partial unique index)
 * rather than from any header or host the caller supplies. It carries an
 * explicit guard annotation, the reasoning is in its own docblock, and
 * `forms` records the exception in {@see \Whity\Core\Tenant\TenantOwnedTables} —
 * the same shape
 * {@see \Whity\Core\Document\Qr\DocumentQrTokenRepository::findByToken()} and
 * `InvitationService::findLiveByToken()` already have.
 *
 * Everything AFTER that lookup binds the tenant it returned.
 *
 * NO DELETE. See {@see FormStatus} for the argument: a form is what somebody's
 * submission was an answer to, and destroying it makes every submission against
 * it unreadable. `archive()` is the operation that gets asked for and it costs
 * nothing.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormRepository
{
    /**
     * Written once and shared by every read, so a column added to the table
     * cannot reach one caller and not another.
     */
    private const COLUMNS = 'id, tenant_id, form_key, name, description, status, version,
                             route_template_id, created_by_profile_id, created_at, updated_at,
                             public_enabled, public_slug, public_opens_at, public_closes_at,
                             public_enabled_at, public_enabled_by_profile_id,
                             ' . self::WINDOW_OPEN_SQL . ' AS public_window_open';

    /**
     * Whether the form is inside its public submission window RIGHT NOW,
     * computed in SQL against the DATABASE'S clock.
     *
     * In the query rather than in PHP, deliberately. `public_opens_at` and
     * `public_closes_at` are written from `NOW()` like every other timestamp in
     * this schema, and comparing them against a PHP `new DateTimeImmutable()`
     * would introduce a SECOND clock plus a timezone question — so a window
     * would open at a different instant depending on which process asked, and
     * nothing on the row would say why. One clock, the one that wrote the
     * columns.
     *
     * A NULL boundary means "no boundary on this side" (migration 132), which is
     * why each half is `IS NULL OR …` rather than a comparison against a
     * sentinel date. Both null therefore yields TRUE: a form with no window is
     * always inside it.
     *
     * The parentheses are load-bearing — `AND` binds tighter than `OR`, and
     * without them this reads as an entirely different predicate that is true
     * whenever the form has no opening date.
     */
    private const WINDOW_OPEN_SQL = '((public_opens_at IS NULL OR public_opens_at <= NOW())
                                      AND (public_closes_at IS NULL OR public_closes_at > NOW()))';

    /**
     * The widest a `form_key` may be, matching `VARCHAR(128)` in migration 127.
     *
     * The API refuses longer, so the column and the validator agree instead of
     * one truncating what the other accepted — migration 120 records the same
     * reasoning for its own width.
     */
    public const KEY_MAX = 128;

    /**
     * What a `form_key` may contain: lowercase letters, digits, hyphen,
     * underscore, starting with a letter.
     *
     * Tighter than the column, on purpose. The key appears in URLs and in
     * client-side routing, so a key with a slash, a space or a percent in it is a
     * key that works in the database and breaks somewhere downstream — and the
     * place to find that out is the request that creates it.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_-]*$/';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The tenant's forms, newest first, optionally narrowed to one status.
     *
     * Newest first rather than by key: a catalogue is browsed by "what changed
     * lately", and a key ordering would bury a form somebody created a minute ago
     * under two years of alphabet. `id DESC` rather than `created_at DESC`
     * because two forms created in the same clock tick would otherwise tie, and a
     * tie makes the page boundary of a paginated list non-deterministic.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?string $status = null, int $limit = 100, int $offset = 0): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM forms WHERE tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }

        $sql .= ' ORDER BY id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        // Bound as integers explicitly: PDO would otherwise quote them and
        // PostgreSQL refuses a quoted LIMIT.
        $stmt->bindValue(':limit', max(1, min($limit, 500)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One form, tenant-scoped. Null when absent — including when the id belongs
     * to a DIFFERENT tenant, which the tenant predicate makes indistinguishable
     * from "does not exist".
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM forms WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * One form by its stable key.
     *
     * @return array<string, mixed>|null
     */
    public function findByKey(int $tenantId, string $formKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM forms WHERE tenant_id = :tenant_id AND form_key = :form_key LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':form_key' => $formKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * THE ANONYMOUS LOOKUP (migration 132): the one form a public slug names, or
     * null.
     *
     * THIS IS THE ONLY READ IN THE SUBSYSTEM WITH NO TENANT PREDICATE, and that
     * is the feature rather than a gap. The caller has no account, so there is no
     * session, no JWT and no tenant to bind — and the tenant must come from
     * SOMEWHERE. Every alternative source is a value the anonymous caller
     * chooses: an `X-Tenant-Id` header, a `?tenant=` parameter, the Host header.
     * Reading the tenant off any of them would let a stranger point a public form
     * at somebody else's organisation by editing a request.
     *
     * So the tenant is DERIVED FROM THE SLUG, and the slug can name exactly one
     * row because migration 132 declares a GLOBAL partial unique index on it. The
     * row it returns carries `tenant_id`, and every read and write the public
     * handlers make afterwards binds THAT value — never anything from the
     * request. See {@see \Whity\Api\PublicFormsApiHandler}.
     *
     * IT RETURNS THE ROW WHATEVER ITS STATE — draft, archived, public link
     * disabled, window closed. Filtering here would push the disclosure decision
     * into the data layer, where the tenant's status and the caller's entitlement
     * to know it are both out of scope, and it would make "unknown slug" and
     * "known slug, closed form" indistinguishable to the HANDLER as well as to
     * the caller — so the handler could no longer choose to distinguish them
     * where that is the right answer (an expired window says when it reopens; a
     * disabled link says nothing at all). {@see PublicFormLink} makes that
     * decision, once, where the whole row is in scope. Same split
     * {@see \Whity\Core\Document\Qr\DocumentQrTokenRepository::findByToken()}
     * makes with {@see \Whity\Core\Document\Qr\VerificationPresenter}.
     *
     * The slug is compared with `=` on an indexed column, so a wrong slug costs
     * one index probe and nothing else.
     *
     * @return array<string, mixed>|null
     */
    public function findByPublicSlug(string $slug): ?array
    {
        // The annotation is ONE comment, on the line directly above the
        // statement, because that is where the scanner looks — a multi-line `//`
        // block is a separate token per line and only the line carrying the tag
        // counts. The full argument is in this method's docblock.
        //
        // @tenant-guard-ignore: the anonymous public-form lookup — this read is what RESOLVES the tenant, from a 256-bit slug under a global unique index (migration 132), on a path where the caller has no account and there is nothing else to resolve one from. Every subsequent read and write in PublicFormsApiHandler binds the tenant_id it returns. Mirrors DocumentQrTokenRepository::findByToken(); recorded against `forms` in TenantOwnedTables.
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM forms WHERE public_slug = :public_slug LIMIT 1'
        );
        $stmt->execute([':public_slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Open a form to the public, minting a fresh slug.
     *
     * ALWAYS A NEW SLUG, even on a form that was open a minute ago and closed:
     * see {@see PublicFormLink} for why re-opening must not resurrect a
     * withdrawn address.
     *
     * The WHERE clause re-binds `public_enabled = FALSE` so two concurrent
     * enables cannot both mint. The second one writes nothing, its `rowCount()`
     * is 0, and the caller is told the form moved under it — which is true, and
     * better than two slugs existing where the loser's is unreachable but live.
     *
     * `tenant_id` is bound as well as `id`: a form id from another tenant
     * updates nothing, so a caller cannot open somebody else's form to the
     * internet by guessing an integer.
     *
     * @throws FormRejectedException When the form is no longer closed, or when
     *         the minted slug collided (which is not a state that occurs — see
     *         below — and is refused rather than retried anyway).
     */
    public function enablePublicLink(
        int $tenantId,
        int $id,
        string $slug,
        ?string $opensAt,
        ?string $closesAt,
        ?int $enabledByProfileId,
    ): void {
        try {
            $stmt = $this->db->prepare(
                'UPDATE forms
                    SET public_enabled = TRUE,
                        public_slug = :public_slug,
                        public_opens_at = :opens_at,
                        public_closes_at = :closes_at,
                        public_enabled_at = NOW(),
                        public_enabled_by_profile_id = :enabled_by,
                        updated_at = NOW()
                  WHERE tenant_id = :tenant_id AND id = :id AND public_enabled = FALSE'
            );
            $stmt->execute([
                ':public_slug' => $slug,
                ':opens_at' => $opensAt,
                ':closes_at' => $closesAt,
                ':enabled_by' => $enabledByProfileId,
                ':tenant_id' => $tenantId,
                ':id' => $id,
            ]);
        } catch (PDOException $e) {
            // The unique index is the authority on collision, not a preceding
            // SELECT — the same posture create() takes about `form_key`. At 256
            // bits a collision is not an event anybody will observe; the branch
            // exists so that if the slug source were ever weakened, the failure
            // is a refusal rather than two forms sharing an address.
            throw new FormRejectedException(
                'Could not open a public link for this form — please try again',
                'forms public_slug update failed: ' . $e->getMessage(),
                $e
            );
        }

        if ($stmt->rowCount() === 0) {
            throw new FormRejectedException(
                'This form already has a public link — close it first if you want a new address'
            );
        }
    }

    /**
     * Close a form's public link.
     *
     * The slug is set to NULL rather than kept beside `public_enabled = FALSE`,
     * and that is the point of the operation: a retained slug is a live row in a
     * unique index and a value that a future bug could serve again. Nulling it
     * makes the old address unresolvable by construction —
     * {@see findByPublicSlug()} matches on the column, and there is nothing left
     * to match.
     *
     * `public_enabled_at` / `public_enabled_by_profile_id` are cleared with it so
     * the pair always describes the CURRENT opening; migration 132 records why.
     * The window dates are cleared too — they belonged to the link that just
     * ended, and leaving them would silently apply March's deadline to a link
     * opened in November.
     *
     * IDEMPOTENT: closing a link that is already closed writes nothing and is not
     * an error. A client that lost a response must be able to retry, and "the
     * door you asked me to shut is shut" is a success.
     *
     * @return bool Whether a row actually changed.
     */
    public function disablePublicLink(int $tenantId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE forms
                SET public_enabled = FALSE,
                    public_slug = NULL,
                    public_opens_at = NULL,
                    public_closes_at = NULL,
                    public_enabled_at = NULL,
                    public_enabled_by_profile_id = NULL,
                    updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :id AND public_slug IS NOT NULL'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Create a form. Always `draft` — a form is never born live, because a form
     * with no fields yet that accepted submissions would collect empty ones.
     *
     * @param array<string, string> $name A `{ar?, en?}` label.
     *
     * @throws FormRejectedException When the tenant already holds the key.
     */
    public function create(
        int $tenantId,
        string $formKey,
        array $name,
        ?string $description,
        ?int $routeTemplateId,
        ?int $createdByProfileId,
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO forms
                     (tenant_id, form_key, name, description, status, version,
                      route_template_id, created_by_profile_id, created_at, updated_at)
                 VALUES
                     (:tenant_id, :form_key, :name, :description, :status, 1,
                      :route_template_id, :created_by, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':form_key' => $formKey,
                ':name' => LocalizedLabel::encode($name),
                ':description' => $description,
                ':status' => FormStatus::DRAFT,
                ':route_template_id' => $routeTemplateId,
                ':created_by' => $createdByProfileId,
            ]);
        } catch (PDOException $e) {
            // The UNIQUE (tenant_id, form_key) index is the authority on
            // collision, not a preceding SELECT: two concurrent creates both pass
            // a check-then-insert and one of them still has to be told no.
            throw new FormRejectedException(
                'A form with that key already exists in this tenant',
                'forms insert failed: ' . $e->getMessage(),
                $e
            );
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update the mutable fields of a form.
     *
     * `form_key` is absent on purpose and the API refuses a body carrying one.
     * Code and links bind to the key, so editing it in place would silently
     * repoint every reference at a form that no longer exists — the same
     * immutability {@see \Whity\Api\OuTypesApiHandler} enforces on a type key,
     * and refused with a 422 rather than ignored, so a caller who meant it finds
     * out.
     *
     * Returns false when the form does not exist in this tenant.
     *
     * @param array<string, mixed> $changes Any of: name, description, route_template_id.
     */
    public function update(int $tenantId, int $id, array $changes): bool
    {
        $sets = ['updated_at = NOW()'];
        $params = [':tenant_id' => $tenantId, ':id' => $id];

        if (array_key_exists('name', $changes)) {
            /** @var array<string, string> $name */
            $name = $changes['name'];
            $sets[] = 'name = :name';
            $params[':name'] = LocalizedLabel::encode($name);
        }
        if (array_key_exists('description', $changes)) {
            $sets[] = 'description = :description';
            $params[':description'] = $changes['description'];
        }
        if (array_key_exists('route_template_id', $changes)) {
            $sets[] = 'route_template_id = :route_template_id';
            $params[':route_template_id'] = $changes['route_template_id'];
        }

        $stmt = $this->db->prepare(
            'UPDATE forms SET ' . implode(', ', $sets) . ' WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Move a form to a new lifecycle state, bumping `version` when it becomes
     * live.
     *
     * The transition is checked against {@see FormStatus} by the caller; this
     * method re-binds the CURRENT status in the WHERE clause anyway, so two
     * concurrent requests cannot both see `draft`, both decide the transition is
     * legal, and both write. The second one's `rowCount()` is 0 and it is told
     * the form moved under it — which is true, and better than a lost update.
     *
     * The version bump lives in the same statement as the status change for the
     * same reason: a form that became published without its version moving, or
     * moved its version without publishing, is a row nothing can explain.
     *
     * @throws FormRejectedException When the form is no longer in `$from`.
     */
    public function transition(int $tenantId, int $id, string $from, string $to): void
    {
        // Publishing is what mints a new version — see FormStatus for exactly
        // what that stamp does and does not promise.
        $versionSql = $to === FormStatus::PUBLISHED ? 'version = version + 1, ' : '';

        $stmt = $this->db->prepare(
            'UPDATE forms
                SET status = :to, ' . $versionSql . 'updated_at = NOW()
              WHERE tenant_id = :tenant_id AND id = :id AND status = :from'
        );
        $stmt->execute([
            ':to' => $to,
            ':from' => $from,
            ':tenant_id' => $tenantId,
            ':id' => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new FormRejectedException(
                'The form is no longer in the state this change assumed — reload and try again'
            );
        }
    }

    /**
     * Whether the named route template exists in this tenant.
     *
     * Asked before a form is pointed at one, so a typo'd id is a 422 at
     * authoring time rather than a form that quietly never circulates. The read
     * binds `tenant_id`, so a template id from another tenant is absent — a form
     * cannot be wired to another organisation's flow by guessing an integer.
     */
    public function routeTemplateExists(int $tenantId, int $routeTemplateId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM document_route_templates
              WHERE tenant_id = :tenant_id AND id = :id LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':id' => $routeTemplateId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Shape a raw row for every consumer.
     *
     * `name` is decoded here rather than by each caller so a client never has to
     * know that the column holds JSON in a TEXT column — that is a storage
     * decision (see migration 127) and it stops at this boundary.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $status = (string) $row['status'];

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'form_key' => (string) $row['form_key'],
            'name' => LocalizedLabel::decode(isset($row['name']) ? (string) $row['name'] : null),
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'status' => $status,
            'version' => (int) $row['version'],
            'route_template_id' => $row['route_template_id'] === null ? null : (int) $row['route_template_id'],
            'created_by_profile_id' => $row['created_by_profile_id'] === null
                ? null
                : (int) $row['created_by_profile_id'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            // ---- the public link (migration 132) ----
            //
            // Read through DbBool rather than a `(bool)` cast: a BOOLEAN column
            // comes back as bool, '1'/'0', 't'/'f' or 'true'/'false' depending on
            // the driver, and `(bool) 'false'` is TRUE — which on THIS column
            // would report every form in the install as open to the public. See
            // {@see \Whity\Core\Db\DbBool} and scripts/ci-db-bool-guard.php.
            'public_enabled' => DbBool::of($row['public_enabled'] ?? false),
            // The slug is returned to tenant members, and that is a decision
            // rather than an oversight: it is not a secret FROM them. The point
            // of enabling a link is to hand it out, so an author who cannot read
            // it back cannot use the feature at all, and a `forms:read` holder is
            // somebody the tenant already trusts with every submission the form
            // received. It is withheld from exactly one audience — the anonymous
            // caller, who already has it — see {@see PublicFormView}.
            'public_slug' => $row['public_slug'] === null ? null : (string) $row['public_slug'],
            'public_opens_at' => $row['public_opens_at'] === null ? null : (string) $row['public_opens_at'],
            'public_closes_at' => $row['public_closes_at'] === null ? null : (string) $row['public_closes_at'],
            'public_enabled_at' => $row['public_enabled_at'] === null
                ? null
                : (string) $row['public_enabled_at'],
            'public_enabled_by_profile_id' => $row['public_enabled_by_profile_id'] === null
                ? null
                : (int) $row['public_enabled_by_profile_id'],
            // Computed by the query, not by this method — see
            // {@see self::WINDOW_OPEN_SQL} for why the comparison is the
            // database's and not PHP's. Absent from a row this class did not
            // fetch (it never is today) defaults to "inside", matching a form
            // with no window at all.
            'public_window_open' => DbBool::of($row['public_window_open'] ?? true),
            // Derived, never stored: a client rendering the lifecycle controls
            // should not have to carry a second copy of the transition table.
            // Absent from the column list on purpose — it is an opinion about
            // what may happen next, not a fact about the row.
            'available_transitions' => FormStatus::transitionsFrom($status),
            'accepts_submissions' => FormStatus::acceptsSubmissions($status),
        ];
    }
}
