<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * Checks a submitted answer set against the form's fields, and returns the
 * NORMALIZED values that will be stored.
 *
 * VALIDATE AND NORMALIZE ARE ONE PASS, NOT TWO
 * ---------------------------------------------
 * The method returns the values rather than returning a verdict about them, and
 * that is the point. Two passes — one that checks and one that coerces — is two
 * readings of the same rules, and the day they disagree the checker approves a
 * value the coercer then changes into something the checker would have refused.
 * Here `"42"` becomes `42` inside the same function that decided `42` was in
 * range, so there is no window between the two.
 *
 * WHAT IS *NOT* HERE, AND WHY
 * ----------------------------
 * FILE CONTENT. A `file` field's answer is a reference to an already-stored
 * object, not bytes, and this class refuses to become an upload path. Uploading
 * is the storage subsystem's job ({@see \Whity\Storage}), it has its own size,
 * type and quota policy, and a validator that accepted a base64 blob in a JSON
 * body would quietly route every uploaded file around all of it.
 *
 * REFERENCE EXISTENCE. `profile_ref` and `ou_ref` answers are checked for SHAPE
 * here and for EXISTENCE by {@see SubmissionIssuer}, which has the database
 * handle. Splitting it that way keeps this class pure — no PDO, no I/O — so it
 * is testable without a database, which is what makes it worth testing at all.
 *
 * UNKNOWN KEYS ARE DROPPED, NOT REFUSED
 * --------------------------------------
 * An answer whose key names no field on the form is discarded rather than 422'd.
 * The realistic cause is a stale client — somebody had the form open while an
 * author removed a field — and refusing the whole submission would throw away
 * everything the person typed to punish them for a race they did not cause. The
 * dropped keys are REPORTED alongside the result so a caller can say so, rather
 * than silently swallowing them.
 *
 * Stateless — worker-safe.
 */
final class SubmissionValidator
{
    /**
     * Hard ceiling on the length of one text answer, in bytes.
     *
     * Not a modelling opinion — a stop on unbounded growth. `form_submissions.data`
     * is one jsonb column holding every answer, and a form with forty text fields
     * and no cap is one request away from a row nothing can page through. An
     * author who needs more than this per field wants a file attachment, which is
     * a different kind of field.
     *
     * A field may lower it via `validation.maxLength` and may not raise it, the
     * same direction {@see \Whity\Sdk\Frontend\Blocks\BlockContract::FLOW_MAX_NODES}
     * takes and for the same reason: an author knows things this class does not,
     * and lowering is that knowledge being applied, while raising is an assertion
     * about a storage cost they cannot see.
     */
    public const TEXT_MAX = 10000;

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Validate and normalize one answer set.
     *
     * @param list<array<string, mixed>> $fields Normalized `form_fields` rows, in order.
     * @param array<string, mixed>       $data   The raw submitted answers, keyed by field key.
     *
     * @return array{values: array<string, mixed>, ignored: list<string>}
     *         `values` is what to store; `ignored` names answer keys that match no
     *         field on the form.
     *
     * @throws FormRejectedException On the first field that fails, naming that field.
     */
    public static function validate(array $fields, array $data): array
    {
        $values = [];
        $known = [];

        foreach ($fields as $field) {
            $key = (string) ($field['field_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $known[$key] = true;

            $type = (string) ($field['field_type'] ?? FieldType::TEXT);
            $required = ($field['is_required'] ?? false) === true;
            $rules = self::rulesOf($field);
            /** @var list<mixed> $options */
            $options = is_array($field['options'] ?? null) ? array_values($field['options']) : [];

            $raw = $data[$key] ?? null;

            if (self::isBlank($raw, $type)) {
                if ($required) {
                    throw new FormRejectedException(
                        self::describe($field) . ' is required',
                    );
                }
                // An absent optional answer is ABSENT from the stored object, not
                // stored as null. `data ? 'notes'` then answers "did they say
                // anything" honestly, which a null would not.
                continue;
            }

            $values[$key] = self::normalizeOne($field, $type, $raw, $rules, $options);
        }

        $ignored = [];
        foreach (array_keys($data) as $key) {
            if (!isset($known[$key])) {
                $ignored[] = (string) $key;
            }
        }

        return ['values' => $values, 'ignored' => $ignored];
    }

    /**
     * A field's validation rules, whichever of the two shapes they arrive in.
     *
     * {@see FormFieldRepository} emits an EMPTY rule set as a `\stdClass` so it
     * serialises as `{}` rather than `[]` — a presentation fix for a real
     * client-contract problem. That cast passes through this class, and a bare
     * `is_array()` here would read the object as "no rules", which happens to be
     * CORRECT today only because the cast is applied exclusively to the empty
     * case.
     *
     * Correct-by-coincidence is the shape of the bug this whole subsystem is
     * written against: the day somebody casts non-empty maps too, every
     * `min`, `max` and `pattern` in the install stops being enforced, silently,
     * while every request still returns 200. So the object case is handled
     * EXPLICITLY and rules survive either shape.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>
     */
    private static function rulesOf(array $field): array
    {
        $raw = $field['validation'] ?? null;

        if ($raw instanceof \stdClass) {
            $raw = get_object_vars($raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $rules = [];
        foreach ($raw as $name => $value) {
            if (is_string($name)) {
                $rules[$name] = $value;
            }
        }

        return $rules;
    }

    /**
     * Whether an answer counts as "not given".
     *
     * A `checkbox` is the exception that makes the rule worth writing down: a
     * false checkbox is an ANSWER — "no, I do not consent" — and treating it as
     * blank would make a required consent box impossible to decline and, worse,
     * would store nothing where the form promised to store a decision.
     *
     * A WHITESPACE-ONLY STRING IS BLANK. The check trims before deciding rather
     * than after, because the per-kind normalizers trim too and a required field
     * whose blank test ran on the untrimmed value would be satisfiable by
     * pressing the space bar — the answer would pass the required check, then be
     * trimmed to nothing, and be stored as an empty string that every reader
     * downstream treats as an answer.
     */
    private static function isBlank(mixed $raw, string $type): bool
    {
        if ($type === FieldType::CHECKBOX) {
            return $raw === null;
        }

        return $raw === null
            || (is_string($raw) && trim($raw) === '')
            || (is_array($raw) && $raw === []);
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $rules
     * @param list<mixed>          $options
     */
    private static function normalizeOne(
        array $field,
        string $type,
        mixed $raw,
        array $rules,
        array $options,
    ): mixed {
        return match ($type) {
            FieldType::TEXT, FieldType::TEXTAREA => self::text($field, $raw, $rules),
            FieldType::NUMBER => self::number($field, $raw, $rules),
            FieldType::DATE => self::date($field, $raw),
            FieldType::SELECT => self::choice($field, $raw, $options),
            FieldType::MULTISELECT => self::choices($field, $raw, $options, $rules),
            FieldType::CHECKBOX => self::checkbox($field, $raw),
            FieldType::FILE => self::storageReference($field, $raw),
            FieldType::PROFILE_REF, FieldType::OU_REF => self::reference($field, $raw),
            // Unreachable while the CHECK constraint and FieldType agree, which
            // FieldTypeTest enforces. Refused rather than passed through: a value
            // this class does not understand is a value it cannot promise
            // anything about.
            default => throw new FormRejectedException(
                self::describe($field) . ' has a kind this server does not understand',
                "unknown field_type '{$type}' on field " . (string) ($field['id'] ?? '?'),
            ),
        };
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $rules
     */
    private static function text(array $field, mixed $raw, array $rules): string
    {
        if (!is_string($raw)) {
            throw new FormRejectedException(self::describe($field) . ' must be text');
        }
        $value = trim($raw);

        $max = self::intRule($rules, 'maxLength');
        // The field may only TIGHTEN the platform ceiling — see TEXT_MAX.
        $limit = $max === null ? self::TEXT_MAX : min($max, self::TEXT_MAX);
        if (strlen($value) > $limit) {
            throw new FormRejectedException(
                self::describe($field) . " must be {$limit} characters or fewer"
            );
        }

        $pattern = $rules['pattern'] ?? null;
        if (is_string($pattern) && $pattern !== '') {
            if (!self::matchesPattern($pattern, $value)) {
                throw new FormRejectedException(
                    self::describe($field) . ' is not in the expected format'
                );
            }
        }

        return $value;
    }

    /**
     * Apply an author-supplied pattern WITHOUT handing it to preg_match as a
     * delimited expression.
     *
     * The rule is stored as a bare regular expression body (the shape
     * `BlockContract`'s validation facet and every HTML `pattern` attribute use),
     * so it is wrapped here with a delimiter the author cannot influence. Passing
     * the stored string straight to `preg_match` would let an author supply their
     * own delimiters AND MODIFIERS — including `e`-style behaviours on older
     * builds and, more realistically, a catastrophically backtracking expression
     * with no anchors.
     *
     * `preg_match` is called with error suppression and a false return is treated
     * as a FAILED MATCH, not as a pass: an invalid pattern must not become a
     * validator that accepts everything. That is the whole failure class — a
     * check that reports success without checking anything.
     */
    private static function matchesPattern(string $pattern, string $value): bool
    {
        $delimited = '/' . str_replace('/', '\\/', $pattern) . '/u';

        return @preg_match($delimited, $value) === 1;
    }

    /**
     * @param array<string, mixed> $field
     * @param array<string, mixed> $rules
     */
    private static function number(array $field, mixed $raw, array $rules): int|float
    {
        if (is_int($raw) || is_float($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && is_numeric(trim($raw))) {
            $trimmed = trim($raw);
            // An integral string stays an integer so a round trip through the
            // API does not turn 3 into 3.0 for every consumer downstream.
            $value = (string) (int) $trimmed === $trimmed ? (int) $trimmed : (float) $trimmed;
        } else {
            throw new FormRejectedException(self::describe($field) . ' must be a number');
        }

        $min = self::numericRule($rules, 'min');
        if ($min !== null && $value < $min) {
            throw new FormRejectedException(self::describe($field) . " must be at least {$min}");
        }
        $max = self::numericRule($rules, 'max');
        if ($max !== null && $value > $max) {
            throw new FormRejectedException(self::describe($field) . " must be at most {$max}");
        }

        return $value;
    }

    /**
     * A calendar date, stored as `YYYY-MM-DD`.
     *
     * Checked with `checkdate` after the shape match rather than by the pattern
     * alone: `2026-02-31` matches every date regex ever written and is not a day.
     * A form that accepted it would store an answer that no date library can read
     * back.
     *
     * @param array<string, mixed> $field
     */
    private static function date(array $field, mixed $raw): string
    {
        if (!is_string($raw) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($raw), $m) !== 1) {
            throw new FormRejectedException(self::describe($field) . ' must be a date, as YYYY-MM-DD');
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new FormRejectedException(self::describe($field) . ' is not a real date');
        }

        return trim($raw);
    }

    /**
     * @param array<string, mixed> $field
     * @param list<mixed>          $options
     */
    private static function choice(array $field, mixed $raw, array $options): string
    {
        if (!is_string($raw)) {
            throw new FormRejectedException(self::describe($field) . ' must be one of the offered choices');
        }
        $allowed = self::optionValues($options);
        if (!in_array($raw, $allowed, true)) {
            throw new FormRejectedException(self::describe($field) . ' must be one of the offered choices');
        }

        return $raw;
    }

    /**
     * @param array<string, mixed> $field
     * @param list<mixed>          $options
     * @param array<string, mixed> $rules
     * @return list<string>
     */
    private static function choices(array $field, mixed $raw, array $options, array $rules): array
    {
        if (!is_array($raw)) {
            throw new FormRejectedException(self::describe($field) . ' must be a list of choices');
        }
        $allowed = self::optionValues($options);

        $chosen = [];
        foreach ($raw as $one) {
            if (!is_string($one) || !in_array($one, $allowed, true)) {
                throw new FormRejectedException(
                    self::describe($field) . ' must contain only the offered choices'
                );
            }
            // De-duplicated: the same choice twice is one choice, and storing it
            // twice would make a count of selections wrong for no benefit.
            $chosen[$one] = true;
        }
        $values = array_keys($chosen);

        $min = self::intRule($rules, 'min');
        if ($min !== null && count($values) < $min) {
            throw new FormRejectedException(self::describe($field) . " needs at least {$min} choices");
        }
        $max = self::intRule($rules, 'max');
        if ($max !== null && count($values) > $max) {
            throw new FormRejectedException(self::describe($field) . " allows at most {$max} choices");
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $field
     */
    private static function checkbox(array $field, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        // A checkbox arrives as a bool from a JSON client and as a string from a
        // form-encoded one. Both are accepted; anything else is refused rather
        // than coerced, because `(bool) 'no'` is true and that is precisely the
        // silent wrong answer this branch exists to prevent.
        if (is_string($raw) && in_array($raw, ['true', 'false', '1', '0'], true)) {
            return $raw === 'true' || $raw === '1';
        }
        if ($raw === 1 || $raw === 0) {
            return $raw === 1;
        }

        throw new FormRejectedException(self::describe($field) . ' must be yes or no');
    }

    /**
     * A `file` answer: the storage key of an already-uploaded object.
     *
     * Shape only. Whether the object EXISTS, and whether this caller may point at
     * it, is the storage subsystem's question and is deliberately not answered
     * here — see the class docblock.
     *
     * @param array<string, mixed> $field
     */
    private static function storageReference(array $field, mixed $raw): string
    {
        if (!is_string($raw) || trim($raw) === '') {
            throw new FormRejectedException(self::describe($field) . ' must be an uploaded file reference');
        }
        $value = trim($raw);
        if (strlen($value) > 512) {
            throw new FormRejectedException(self::describe($field) . ' has an unusable file reference');
        }

        return $value;
    }

    /**
     * A `profile_ref` / `ou_ref` answer: a positive integer id.
     *
     * Existence is checked by {@see SubmissionIssuer}, in the caller's tenant.
     *
     * @param array<string, mixed> $field
     */
    private static function reference(array $field, mixed $raw): int
    {
        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new FormRejectedException(self::describe($field) . ' must name a record in this tenant');
    }

    /**
     * The `value` of each declared option.
     *
     * An option list that is not shaped `{value, label}` yields no values, so
     * every answer is refused — which is the correct failure for a `select` whose
     * author never finished writing the choices. The field editor refuses to save
     * such a list in the first place; this is the second line.
     *
     * @param list<mixed> $options
     * @return list<string>
     */
    private static function optionValues(array $options): array
    {
        $values = [];
        foreach ($options as $option) {
            if (is_array($option) && isset($option['value']) && is_string($option['value'])) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private static function intRule(array $rules, string $name): ?int
    {
        $raw = $rules[$name] ?? null;
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $rules
     */
    private static function numericRule(array $rules, string $name): int|float|null
    {
        $raw = $rules[$name] ?? null;
        if (is_int($raw) || is_float($raw)) {
            return $raw;
        }
        if (is_string($raw) && is_numeric($raw)) {
            return $raw + 0;
        }

        return null;
    }

    /**
     * How a field is named back to the person who filled it in.
     *
     * Their own label, in their own words, never `field_key` — a message reading
     * "applicant_phone_2 is required" is written for the author, not for the
     * person staring at the form. The key is the fallback only when the label is
     * somehow empty, because a message naming nothing is worse than a technical
     * one.
     *
     * @param array<string, mixed> $field
     */
    private static function describe(array $field): string
    {
        /** @var array<string, string> $label */
        $label = is_array($field['label'] ?? null) ? $field['label'] : [];
        $preferred = LocalizedLabel::preferred($label);

        return $preferred !== '' ? $preferred : (string) ($field['field_key'] ?? 'A field');
    }
}
