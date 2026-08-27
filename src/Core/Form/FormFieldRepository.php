<?php

declare(strict_types=1);

namespace Whity\Core\Form;

use PDO;
use PDOException;

/**
 * Data-access layer for `form_fields` (migration 127). All SQL touching the
 * table lives here so API handlers never issue raw queries (project convention).
 *
 * TENANT-OWNED. Every statement binds `tenant_id` DIRECTLY rather than reaching
 * it through `form_id`, which is why migration 127 denormalises the column onto
 * this table at all: the predicate guard polices a field read on its own terms
 * instead of trusting a join it cannot see. Migration 120's step and edge tables
 * make the same choice for the same reason.
 *
 * ORDERING IS `position ASC, id ASC`, ALWAYS
 * -------------------------------------------
 * There is deliberately no unique index on `(form_id, position)` — see migration
 * 127 for why a drag-reorderable ordinal must not be constrained by a
 * non-deferrable unique — so ties are possible and the `id` tie-break is what
 * makes the sequence TOTAL anyway. A `LIMIT`-free query with a partial ORDER BY
 * still returns rows in whatever order the plan produced, which is stable in one
 * database and different in a restore of it; a form whose fields silently
 * reshuffle between two reads is a form nobody can trust.
 *
 * Stateless apart from the injected handle — worker-safe.
 */
final class FormFieldRepository
{
    private const COLUMNS = 'id, tenant_id, form_id, field_key, field_type, label, help_text,
                             is_required, options, validation, prefill_source, section_key, position,
                             created_at, updated_at';

    /** Matches `VARCHAR(128)` in migration 127. */
    public const KEY_MAX = 128;

    /**
     * What a `field_key` may contain.
     *
     * A field key becomes a KEY IN `form_submissions.data` and an input name in
     * a rendered form, so it has to survive JSON and HTML without quoting. The
     * pattern is the same shape as {@see FormRepository::KEY_PATTERN} because two
     * different key rules in one subsystem is a rule nobody remembers which half
     * applies where.
     */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Every field on one form, in authoring order.
     *
     * @return list<array<string, mixed>>
     */
    public function listForForm(int $tenantId, int $formId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM form_fields
              WHERE tenant_id = :tenant_id AND form_id = :form_id
              ORDER BY position ASC, id ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':form_id' => $formId]);

        return array_map([self::class, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * One field, scoped to BOTH its tenant and its form.
     *
     * The `form_id` predicate is not redundant beside the primary key: it is what
     * makes `DELETE /api/v1/forms/7/fields/42` refuse when field 42 belongs to
     * form 9. Without it the path segment would be decoration and a caller could
     * edit any field in the tenant through any form's URL.
     *
     * @return array<string, mixed>|null
     */
    /**
     * Save a form's whole field set in one transaction.
     *
     * RECONCILED BY `field_key`. The key is the stable identity a recorded
     * ANSWER refers to, and it is deliberately not updatable — so a key in both
     * the incoming set and the stored one is the SAME question (edited, perhaps
     * moved), and a stored key absent from the incoming set is a question
     * genuinely withdrawn. Matching on position instead would rename every
     * question below an insertion and silently reattribute its answers.
     *
     * POSITION IS THE ORDER GIVEN. The caller owns the sequence it shows; making
     * it maintain a separate position integer is two things that must agree and
     * eventually will not.
     *
     * ALL OR NOTHING. A half-applied reorder is a form whose questions sit in an
     * order nobody chose, which is worse than a refused save.
     *
     * @param list<array<string, mixed>> $fields already validated and normalised
     * @return list<array<string, mixed>> the resulting field set, in order
     */
    public function replaceAll(int $tenantId, int $formId, array $fields): array
    {
        $existing = [];
        foreach ($this->listForForm($tenantId, $formId) as $row) {
            $existing[(string) $row['field_key']] = $row;
        }

        $incomingKeys = [];
        foreach ($fields as $field) {
            $incomingKeys[(string) $field['field_key']] = true;
        }

        $ownTransaction = !$this->db->inTransaction();
        if ($ownTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $position = 0;
            foreach ($fields as $field) {
                $position++;
                $key = (string) $field['field_key'];

                if (isset($existing[$key])) {
                    $changes = $field;
                    unset($changes['field_key']);
                    $changes['position'] = $position;
                    $this->update($tenantId, $formId, (int) $existing[$key]['id'], $changes);

                    continue;
                }

                $this->create(
                    $tenantId,
                    $formId,
                    $key,
                    (string) $field['field_type'],
                    /** @var array<string, string> */ $field['label'],
                    $field['help_text'] === null ? null : (string) $field['help_text'],
                    (bool) $field['is_required'],
                    /** @var list<mixed> */ $field['options'],
                    /** @var array<string, mixed> */ $field['validation'],
                    $field['prefill_source'] === null ? null : (string) $field['prefill_source'],
                    $field['section_key'] === null ? null : (string) $field['section_key'],
                    $position,
                );
            }

            // Withdrawn questions. Answers already given to them stay recorded
            // and simply stop having a label — the same consequence the
            // single-field delete carries, and the reason this is a deliberate
            // act rather than a side effect of reordering.
            foreach ($existing as $key => $row) {
                if (!isset($incomingKeys[$key])) {
                    $this->delete($tenantId, $formId, (int) $row['id']);
                }
            }

            if ($ownTransaction) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }

        return $this->listForForm($tenantId, $formId);
    }

    /**
     * One field of one form, or null.
     *
     * @return array<string, mixed>|null
     */
    public function find(int $tenantId, int $formId, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . '
               FROM form_fields
              WHERE tenant_id = :tenant_id AND form_id = :form_id AND id = :id
              LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':form_id' => $formId, ':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalizeRow($row);
    }

    /**
     * Append a field to a form.
     *
     * An absent `$position` puts it after the current maximum rather than at 0.
     * A builder that adds a field expects it at the END of the form — that is
     * where the author's attention is — and defaulting to 0 would silently push
     * every new field in front of everything already written.
     *
     * @param array<string, string> $label      A `{ar?, en?}` label.
     * @param list<array{value: string, label: array<string, string>}> $options
     * @param array<string, mixed>  $validation
     *
     * @throws FormRejectedException When the form already holds the key.
     */
    public function create(
        int $tenantId,
        int $formId,
        string $fieldKey,
        string $fieldType,
        array $label,
        ?string $helpText,
        bool $isRequired,
        array $options,
        array $validation,
        ?string $prefillSource,
        ?string $sectionKey,
        ?int $position,
    ): int {
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO form_fields
                     (tenant_id, form_id, field_key, field_type, label, help_text, is_required,
                      options, validation, prefill_source, section_key, position, created_at, updated_at)
                 VALUES
                     (:tenant_id, :form_id, :field_key, :field_type, :label, :help_text, :is_required,
                      :options, :validation, :prefill_source, :section_key, :position, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':form_id' => $formId,
                ':field_key' => $fieldKey,
                ':field_type' => $fieldType,
                ':label' => LocalizedLabel::encode($label),
                ':help_text' => $helpText,
                // Bound as an int rather than a PHP bool: PDO renders `false` as
                // an empty string on some drivers, which PostgreSQL refuses for a
                // BOOLEAN column.
                ':is_required' => $isRequired ? 1 : 0,
                ':options' => self::encodeJson($options, '[]'),
                ':validation' => self::encodeJson($validation, '{}'),
                ':prefill_source' => $prefillSource,
                ':section_key' => $sectionKey,
                ':position' => $position ?? $this->nextPosition($tenantId, $formId),
            ]);
        } catch (PDOException $e) {
            throw new FormRejectedException(
                'A field with that key already exists on this form',
                'form_fields insert failed: ' . $e->getMessage(),
                $e
            );
        }

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a field in place.
     *
     * `field_key` is absent, and refused with a 422 by the API rather than
     * ignored. Answers already in `form_submissions.data` are keyed by it, so
     * renaming a key in place does not rename the answers — it ORPHANS them, and
     * every past submission loses that field while still reporting success.
     * Deleting the field and adding a new one has the same effect on old data and
     * is at least visibly a destructive act.
     *
     * `field_type` is NOT absent — changing `text` to `textarea` is a real edit —
     * but the caller re-validates `options` against the new type, because a
     * `select` demoted to `text` leaves options nothing will ever draw.
     *
     * @param array<string, mixed> $changes
     */
    public function update(int $tenantId, int $formId, int $id, array $changes): bool
    {
        $sets = ['updated_at = NOW()'];
        $params = [':tenant_id' => $tenantId, ':form_id' => $formId, ':id' => $id];

        $simple = [
            'field_type' => ':field_type',
            'help_text' => ':help_text',
            'prefill_source' => ':prefill_source',
            'section_key' => ':section_key',
            'position' => ':position',
        ];
        foreach ($simple as $column => $placeholder) {
            if (array_key_exists($column, $changes)) {
                $sets[] = "{$column} = {$placeholder}";
                $params[$placeholder] = $changes[$column];
            }
        }

        if (array_key_exists('label', $changes)) {
            /** @var array<string, string> $label */
            $label = $changes['label'];
            $sets[] = 'label = :label';
            $params[':label'] = LocalizedLabel::encode($label);
        }
        if (array_key_exists('is_required', $changes)) {
            $sets[] = 'is_required = :is_required';
            $params[':is_required'] = $changes['is_required'] === true ? 1 : 0;
        }
        if (array_key_exists('options', $changes)) {
            /** @var list<mixed> $options */
            $options = $changes['options'];
            $sets[] = 'options = :options';
            $params[':options'] = self::encodeJson($options, '[]');
        }
        if (array_key_exists('validation', $changes)) {
            /** @var array<string, mixed> $validation */
            $validation = $changes['validation'];
            $sets[] = 'validation = :validation';
            $params[':validation'] = self::encodeJson($validation, '{}');
        }

        $stmt = $this->db->prepare(
            'UPDATE form_fields SET ' . implode(', ', $sets)
            . ' WHERE tenant_id = :tenant_id AND form_id = :form_id AND id = :id'
        );
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Remove a field from a form.
     *
     * This one IS a real delete, unlike a form's, and the asymmetry is
     * deliberate. A field removed from a draft is an author changing their mind
     * and there is nothing to preserve. A field removed from a PUBLISHED form
     * leaves the answers already given to it stranded in
     * `form_submissions.data` — they are not deleted, they simply stop having a
     * label — which is why {@see FormStatus::allowsFieldEditing()} refuses the
     * operation on an ARCHIVED form, where the fields are the only remaining
     * explanation of what its submissions answered.
     */
    public function delete(int $tenantId, int $formId, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM form_fields WHERE tenant_id = :tenant_id AND form_id = :form_id AND id = :id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':form_id' => $formId, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * One past the highest position currently on the form; 0 for an empty form.
     */
    private function nextPosition(int $tenantId, int $formId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COALESCE(MAX(position), -1) AS max_position
               FROM form_fields
              WHERE tenant_id = :tenant_id AND form_id = :form_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':form_id' => $formId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? 0 : ((int) $row['max_position']) + 1;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function encodeJson(array $value, string $fallback): string
    {
        // JSON_UNESCAPED_UNICODE for the same reason LocalizedLabel gives: an
        // Arabic option label stored as escape sequences decodes back correctly
        // and stops being readable by whoever queries the column.
        //
        // JSON_FORCE_OBJECT is NOT used — an empty options list must encode as
        // `[]` and an empty rule set as `{}`, which is exactly the distinction
        // migration 127's two different column defaults exist to preserve.
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $json === false ? $fallback : $json;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalizeRow(array $row): array
    {
        $type = (string) $row['field_type'];
        $prefill = $row['prefill_source'] === null ? null : (string) $row['prefill_source'];

        return [
            'id' => (int) $row['id'],
            'tenant_id' => (int) $row['tenant_id'],
            'form_id' => (int) $row['form_id'],
            'field_key' => (string) $row['field_key'],
            'field_type' => $type,
            'label' => LocalizedLabel::decode(isset($row['label']) ? (string) $row['label'] : null),
            'help_text' => $row['help_text'] === null ? null : (string) $row['help_text'],
            // PostgreSQL hands a BOOLEAN back as the string 't'/'f' through PDO
            // on some driver builds and as a PHP bool on others; SQLite hands
            // back 0/1. Normalising here is what stops a client from having to
            // know which build it is talking to.
            'is_required' => self::toBool($row['is_required'] ?? false),
            'options' => self::decodeJsonList($row['options'] ?? null),
            // An EMPTY rule set goes out as `{}`, never `[]`. PHP cannot tell an
            // empty map from an empty list and `json_encode` picks the list, so a
            // field with no rules would serialise as a JSON ARRAY where the
            // published schema (and every client generated from it) declares an
            // object — the exact confusion migration 127's two different column
            // defaults exist to prevent, reappearing one layer up. The stdClass
            // cast is the convention {@see \Whity\Api\AuditLogApiHandler} and
            // {@see \Whity\Core\Document\Render\DocumentRenderer} already use,
            // and it touches ONLY the empty case.
            'validation' => self::emptyMapAsObject(self::decodeJsonMap($row['validation'] ?? null)),
            'prefill_source' => $prefill,
            // Reported beside the source rather than left for a client to derive:
            // an author choosing an unbacked source must see, in the field editor,
            // that it will never produce a value. See PrefillSource.
            'prefill_backed' => $prefill !== null && PrefillSource::isBacked($prefill),
            'section_key' => $row['section_key'] === null ? null : (string) $row['section_key'],
            'position' => (int) $row['position'],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
            // Derived from the type, so a renderer does not carry a second copy
            // of the vocabulary's rules.
            'multi_valued' => FieldType::isMultiValued($type),
        ];
    }

    /**
     * An empty associative array as `{}` rather than `[]` once encoded.
     *
     * @param array<string, mixed> $map
     * @return array<string, mixed>|\stdClass
     */
    private static function emptyMapAsObject(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }

    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return $value === 't' || $value === 'true' || $value === '1';
        }

        return false;
    }

    /**
     * @return list<mixed>
     */
    private static function decodeJsonList(mixed $stored): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }
        $decoded = json_decode($stored, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeJsonMap(mixed $stored): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }
        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [];
        }

        $map = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }
}
