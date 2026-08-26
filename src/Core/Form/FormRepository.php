<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use PDOException;

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
                             route_template_id, created_by_profile_id, created_at, updated_at';

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
            // Derived, never stored: a client rendering the lifecycle controls
            // should not have to carry a second copy of the transition table.
            // Absent from the column list on purpose — it is an opinion about
            // what may happen next, not a fact about the row.
            'available_transitions' => FormStatus::transitionsFrom($status),
            'accepts_submissions' => FormStatus::acceptsSubmissions($status),
        ];
    }
}
