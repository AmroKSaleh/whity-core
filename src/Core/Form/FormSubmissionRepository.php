<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;

/**
 * Data-access layer for `form_submissions` (migration 127). All SQL touching the
 * table lives here so API handlers never issue raw queries (project convention).
 *
 * TENANT-OWNED, and the strictest case in this subsystem. A submission's `data`
 * column holds whatever a person typed into a form their organisation wrote, and
 * the row ALSO points at a `documents` row that carries its own tenant scoping.
 * Two scoping mechanisms that could disagree is exactly one too many, so every
 * statement here binds its OWN `tenant_id` and never infers one from the
 * document it names.
 *
 * NO UPDATE AND NO DELETE, AND THAT IS DELIBERATE
 * ------------------------------------------------
 * A submission is what somebody declared, at a moment, under their own name.
 * Editing one after the fact is not a correction, it is a rewrite of evidence
 * that other people have already acted on — the document it produced may be
 * halfway through an approval, and `document_route_events` records approvals of
 * a thing that would no longer be what was approved.
 *
 * A person who got it wrong submits again. Two submissions with two timestamps
 * is a true account of what happened; one edited submission is not. This is the
 * same posture {@see \Whity\Api\TimeWindowsApiHandler} takes toward a closed
 * period and {@see \Whity\Core\Document\DocumentIssuer::appendArtifact()} takes
 * toward a corrected document: append, never overwrite.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormSubmissionRepository
{
    private const COLUMNS = 's.id, s.tenant_id, s.form_id, s.form_version, s.submitted_by_profile_id,
                             s.document_id, s.data, s.submitted_at, s.created_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Insert a submission. Called only from inside
     * {@see SubmissionIssuer::submit()}'s transaction — a submission that exists
     * without the document it was supposed to produce is the one torn state this
     * subsystem must not leave behind.
     *
     * @param array<string, mixed> $values The validated, normalized answers.
     */
    /**
     * Where the paper is now, derived from the routing trail.
     *
     * Derived and not stored: `documents` has no status column by design —
     * state lives in the append-only `document_route_events` — so a copy kept
     * here could only ever drift from it. `current_step` is the label of the
     * desk still holding it, which is the question somebody who submitted
     * actually has.
     */
    private const ROUTE_STATE = ",
                       CASE
                         WHEN s.document_id IS NULL THEN 'not routed'
                         WHEN NOT EXISTS (
                           SELECT 1 FROM document_routes dr
                            WHERE dr.document_id = s.document_id AND dr.tenant_id = s.tenant_id
                         ) THEN 'not routed'
                         WHEN EXISTS (
                           SELECT 1 FROM document_route_recipients rc
                            WHERE rc.document_id = s.document_id AND rc.tenant_id = s.tenant_id
                              AND rc.closed_by_event_id IS NULL
                         ) THEN 'in progress'
                         ELSE 'completed'
                       END AS state,
                       (SELECT st.label
                          FROM document_route_recipients rc
                          JOIN document_route_steps st ON st.id = rc.step_id
                         WHERE rc.document_id = s.document_id AND rc.tenant_id = s.tenant_id
                           AND rc.closed_by_event_id IS NULL
                         ORDER BY rc.id DESC LIMIT 1) AS current_step";

    /**
     * @param array<string, mixed> $values The answers, keyed by field key.
     *                                     `mixed` is honest rather than lazy:
     *                                     a field yields a string, a list of
     *                                     strings for choose-several, a number,
     *                                     a bool, an upload reference or null,
     *                                     and which one is the field type's
     *                                     business — this repository stores the
     *                                     answer set, it does not interpret it.
     */
    public function create(
        int $tenantId,
        int $formId,
        int $formVersion,
        ?int $submittedByProfileId,
        ?int $documentId,
        array $values,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO form_submissions
                 (tenant_id, form_id, form_version, submitted_by_profile_id, document_id,
                  data, submitted_at, created_at)
             VALUES
                 (:tenant_id, :form_id, :form_version, :submitted_by, :document_id,
                  :data, NOW(), NOW())'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':form_id' => $formId,
            ':form_version' => $formVersion,
            ':submitted_by' => $submittedByProfileId,
            ':document_id' => $documentId,
            // JSON_UNESCAPED_UNICODE, for the reason
            // DocumentRepository::create() gives: an Arabic answer would
            // otherwise be stored as escape sequences. They decode back
            // correctly, but the column stops being readable by the operator
            // running a query against it — and Arabic content is a first-class
            // requirement here, not an edge case.
            ':data' => self::encode($values),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A page of the tenant's submissions, newest first, optionally narrowed to
     * one form and/or one submitter.
     *
     * The join to `forms` is what lets a list render "which form was this?"
     * without a second round trip per row, and it binds `tenant_id` on BOTH
     * sides — a form row is only ever joined to a submission of the same tenant,
     * which is a statement the query makes rather than one it assumes.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(
        int $tenantId,
        ?int $formId = null,
        ?int $submittedByProfileId = null,
        int $limit = 50,
        int $offset = 0,
    ): array {
        $sql = 'SELECT ' . self::COLUMNS . ', f.form_key, f.name AS form_name' . self::ROUTE_STATE . '
                  FROM form_submissions s
                  JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
                 WHERE s.tenant_id = :tenant_id';
        $params = [':tenant_id' => $tenantId];

        if ($formId !== null) {
            $sql .= ' AND s.form_id = :form_id';
            $params[':form_id'] = $formId;
        }
        if ($submittedByProfileId !== null) {
            $sql .= ' AND s.submitted_by_profile_id = :submitted_by';
            $params[':submitted_by'] = $submittedByProfileId;
        }

        // `s.id DESC` beside the timestamp so two submissions in the same clock
        // tick cannot tie — a tie makes the boundary between two pages
        // non-deterministic, which shows up as a row appearing twice or not at
        // all while somebody pages through.
        $sql .= ' ORDER BY s.submitted_at DESC, s.id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);
        foreach ($params as $name => $value) {
            $stmt->bindValue($name, $value);
        }
        $stmt->bindValue(':limit', max(1, min($limit, 200)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One submission, tenant-scoped.
     *
     * `$submittedByProfileId`, when given, narrows it further to that person's
     * own submission — which is how `GET /api/v1/me/form-submissions/{id}` can
     * be served without a second authorization decision inside the handler. An
     * id belonging to somebody else comes back as ABSENT rather than forbidden,
     * because a 403 on one id and a 404 on another is an oracle for which
     * submissions exist.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $id, ?int $submittedByProfileId = null): ?array
    {
        $sql = 'SELECT ' . self::COLUMNS . ', f.form_key, f.name AS form_name' . self::ROUTE_STATE . '
                  FROM form_submissions s
                  JOIN forms f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
                 WHERE s.tenant_id = :tenant_id AND s.id = :id';
        $params = [':tenant_id' => $tenantId, ':id' => $id];

        if ($submittedByProfileId !== null) {
            $sql .= ' AND s.submitted_by_profile_id = :submitted_by';
            $params[':submitted_by'] = $submittedByProfileId;
        }
        $sql .= ' LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * How many submissions a form has received.
     *
     * Asked by the builder so an author about to change a published form can see
     * that thirty people have already answered it — the cheapest possible way to
     * turn "I'll just rename this field" into a decision rather than a reflex.
     */
    public function countForForm(int $tenantId, int $formId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS total FROM form_submissions
              WHERE tenant_id = :tenant_id AND form_id = :form_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':form_id' => $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : (int) $row['total'];
    }

    /**
     * @param array<string, mixed> $values
     */
    private static function encode(array $values): string
    {
        $json = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? '{}' : $json;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $data = [];
        if (isset($row['data']) && is_string($row['data'])) {
            $decoded = json_decode($row['data'], true);
            if (is_array($decoded)) {
                foreach ($decoded as $key => $value) {
                    if (is_string($key)) {
                        $data[$key] = $value;
                    }
                }
            }
        }

        $normalized = [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'form_id' => (int) $row['form_id'],
            'form_version' => (int) $row['form_version'],
            'submitted_by_profile_id' => $row['submitted_by_profile_id'] === null
                ? null
                : (int) $row['submitted_by_profile_id'],
            // Null is an ORDINARY value, not a failure: a submission to a form
            // with no route template attached never mints a document, and one
            // whose document was later deleted keeps the answers. See migration
            // 127 for both cases.
            'document_id' => $row['document_id'] === null ? null : (int) $row['document_id'],
            // `{}` rather than `[]` for a submission with no answers (every field
            // optional, none filled in). Same reason as
            // {@see FormFieldRepository}'s `validation`: PHP cannot tell an empty
            // map from an empty list, `json_encode` picks the list, and the
            // published schema declares an object.
            'data' => $data === [] ? new \stdClass() : $data,
            'submitted_at' => (string) $row['submitted_at'],
            'created_at' => (string) $row['created_at'],
        ];

        // Same posture for the routing state: present only on the reads that
        // derive it. `current_step` is genuinely null once nothing holds the
        // document any more, which is why it is not folded into `state`.
        if (array_key_exists('state', $row)) {
            $normalized['state'] = (string) $row['state'];
            $normalized['current_step'] = $row['current_step'] === null
                ? null
                : (string) $row['current_step'];
        }

        // Present only on the reads that join `forms`. Absent means "not fetched
        // by this read", never "empty" — the same posture DocumentPresenter takes
        // toward `sections` and DocumentRepository toward `variable_data`.
        if (array_key_exists('form_key', $row)) {
            $normalized['form_key'] = (string) $row['form_key'];
            $normalized['form_name'] = LocalizedLabel::decode(
                isset($row['form_name']) ? (string) $row['form_name'] : null
            );
        }

        return $normalized;
    }
}
