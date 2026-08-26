<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * THE REDACTION BOUNDARY for a publicly-submitted form (migration 132): what an
 * anonymous stranger is shown, and which questions they may be asked.
 *
 * Written as one class, with no I/O and no database handle, precisely so the
 * answer to "what leaves this system to somebody with no account" is ONE list
 * that a reviewer can read in a minute rather than a set of judgements scattered
 * through a handler. A field added to `forms` or `form_fields` next year is
 * invisible to the public surface unless somebody adds it HERE, which is the
 * property worth having: the default for a new column is "not disclosed".
 *
 * WHAT AN ALLOW-LIST BUYS THAT A DENY-LIST DOES NOT
 * --------------------------------------------------
 * {@see form()} and {@see field()} rebuild their output key by key rather than
 * unsetting the sensitive ones from a normalized row. A deny-list is correct on
 * the day it is written and wrong on the day somebody adds a column, and it
 * fails OPEN — the new field is disclosed until somebody notices. An allow-list
 * fails closed. This is the same argument
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}'s `dataRecord.fields` makes
 * about publishing a record into a block tree.
 *
 * WHAT IS DELIBERATELY WITHHELD, AND WHY EACH ONE MATTERS
 * -------------------------------------------------------
 *   - `id`, `tenant_id`, `form_id` — internal identifiers. A stranger who
 *     learns a tenant id learns which of an install's organisations they are
 *     talking to and gains an integer to try in every other path. The slug they
 *     already hold is the only identifier they need.
 *   - `created_by_profile_id`, `public_enabled_by_profile_id`,
 *     `public_enabled_at` — WHO AUTHORED IT and who opened it. Naming a person
 *     to an anonymous caller is the disclosure with the least justification of
 *     any on this list.
 *   - `route_template_id` — where submissions GO. A pointer into the tenant's
 *     approval design; disclosing it tells an outsider that an internal process
 *     exists, and gives them an id to guess with.
 *   - `form_key`, `version`, `status`, `created_at`, `updated_at`,
 *     `available_transitions` — the tenant's own naming and lifecycle. None of
 *     it helps somebody fill the form in, all of it describes how the
 *     organisation works.
 *   - `submission_count` — how many people have already answered. That is a
 *     submission LIST reduced to a number and it is nobody outside's business;
 *     on a grant application or a complaints form it is competitive
 *     intelligence.
 *   - `prefill` and `unresolved_prefill` — see below.
 *   - `prefill_source` / `prefill_backed` on a field — even the SOURCE NAME is
 *     withheld. It says nothing about this caller, but `profile.ou` on a field
 *     tells an outsider the platform models organisational units and that this
 *     form expects one, which is a free sentence about internals for no
 *     benefit: a field a stranger fills in by hand renders identically without
 *     it.
 *
 * PREFILL IS STRUCTURALLY ABSENT, NOT FILTERED
 * ---------------------------------------------
 * {@see PrefillResolver::forFields()} returns `[]` for a null profile id and
 * issues no query at all, so an anonymous render resolves NOTHING even before
 * this class redacts anything — a prefill value is by definition the SUBMITTER'S
 * OWN saved details, and an anonymous caller has none for the platform to know.
 * The public render therefore does not call the resolver at all (see
 * {@see \Whity\Api\PublicFormsApiHandler}), and this class emits no `prefill`
 * key even if one were somehow produced. Two independent reasons a value cannot
 * appear, rather than one, because "the resolver happens to return empty" is a
 * property of another class's implementation and this surface should not depend
 * on it staying true.
 *
 * WHICH QUESTIONS A STRANGER MAY BE ASKED
 * ----------------------------------------
 * Three of the ten {@see FieldType}s are UNANSWERABLE BY AN OUTSIDER, and two of
 * those three are worse than unanswerable — they are an oracle. See
 * {@see isPubliclyAnswerable()}.
 *
 * Stateless — worker-safe.
 */
final class PublicFormView
{
    /**
     * Static boundary only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Whether a field of this type is one an anonymous caller can meaningfully
     * and safely be asked.
     *
     * `profile_ref` AND `ou_ref` ARE THE SECURITY CASE, and it is not that they
     * are awkward. {@see SubmissionIssuer::assertReferencesExist()} refuses a
     * reference answer naming a row this tenant does not have — which is exactly
     * right on the authenticated path and is a MEMBERSHIP ORACLE on a public
     * one. An anonymous caller submitting `{"contact": 41}` and being told
     * "Contact must name a record in this tenant", versus being accepted, learns
     * whether profile 41 holds an active membership in this organisation. Repeat
     * over a range and the public form becomes a staff directory, one integer at
     * a time. Nothing about the shape of the message fixes that; the check
     * itself is the oracle, so the fields that reach it must not exist on this
     * surface.
     *
     * `file` is the ordinary-unfillable case rather than the dangerous one. A
     * `file` answer is a REFERENCE to an already-stored object
     * ({@see SubmissionValidator}), and every upload route in this platform is
     * gated — so an anonymous caller has no way to produce one. A file input
     * they cannot fill, above a submit button that then refuses them, is the
     * "renders fine, behaves wrongly" failure this codebase keeps writing
     * against, so it is omitted instead.
     *
     * Everything else — text, textarea, number, date, select, multiselect,
     * checkbox — is a value a stranger types or picks, with no reference into
     * this tenant's data at all.
     */
    public static function isPubliclyAnswerable(string $fieldType): bool
    {
        return !FieldType::isReference($fieldType) && $fieldType !== FieldType::FILE;
    }

    /**
     * The fields of a form that the public surface serves, in the given order.
     *
     * Applied to BOTH the render and the submit, from the same function, so the
     * set of fields a stranger is shown and the set validated against what they
     * sent cannot differ. If they could, a required field omitted from the
     * render would refuse every submission for a question nobody was asked.
     *
     * @param list<array<string, mixed>> $fields Normalized `form_fields` rows.
     * @return list<array<string, mixed>> The same rows, filtered — NOT redacted.
     *         Redaction is {@see field()}'s job and happens only on the way out;
     *         the validator needs the whole row.
     */
    public static function answerableFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (self::isPubliclyAnswerable((string) ($field['field_type'] ?? ''))) {
                $out[] = $field;
            }
        }

        return $out;
    }

    /**
     * The fields a form carries that the public surface CANNOT serve, named by
     * their keys.
     *
     * Used by `POST /api/v1/forms/{id}/public-link` to refuse opening a form
     * whose questions a stranger could not answer — at the moment the author
     * opens the door, where it is a sentence they can act on, rather than as a
     * silently shorter form weeks later. See
     * {@see \Whity\Api\FormsApiHandler::enablePublicLink()}.
     *
     * @param list<array<string, mixed>> $fields Normalized `form_fields` rows.
     * @return list<string>
     */
    public static function unanswerableFieldKeys(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (!self::isPubliclyAnswerable((string) ($field['field_type'] ?? ''))) {
                $out[] = (string) ($field['field_key'] ?? '');
            }
        }

        return $out;
    }

    /**
     * One field, reduced to what drawing it requires.
     *
     * Rebuilt key by key. See the class docblock for why this is not an `unset()`
     * of the sensitive keys.
     *
     * @param array<string, mixed> $field A normalized `form_fields` row.
     * @return array<string, mixed>
     */
    public static function field(array $field): array
    {
        return [
            // The key the answer is submitted under. The one identifier this
            // surface needs, and it is the tenant's own field name rather than a
            // database id — so it discloses nothing an answer would not.
            'field_key' => (string) ($field['field_key'] ?? ''),
            'field_type' => (string) ($field['field_type'] ?? ''),
            'label' => $field['label'] ?? [],
            'help_text' => $field['help_text'] ?? null,
            'is_required' => ($field['is_required'] ?? false) === true,
            // The choices for a select, and the rules the server will apply
            // anyway. Withholding `validation` would only mean the person
            // discovers the rule by being refused — the server enforces it
            // either way, so telling them is disclosure of nothing and a better
            // form.
            'options' => $field['options'] ?? [],
            'validation' => $field['validation'] ?? new \stdClass(),
            'section_key' => $field['section_key'] ?? null,
            'position' => (int) ($field['position'] ?? 0),
            'multi_valued' => ($field['multi_valued'] ?? false) === true,
        ];
    }

    /**
     * The form itself, reduced to what a stranger needs in order to answer it.
     *
     * `slug` is echoed back deliberately: the caller already holds it (they used
     * it to get here), and returning it lets a client hold one object rather
     * than pairing the response with the URL it came from.
     *
     * The two window dates are returned WHEN SET, and this is a real disclosure
     * decision rather than an oversight. They tell the holder of a link why a
     * form is not accepting anything right now and when that changes, which is
     * the difference between "this is broken" and "come back on the 1st". The
     * fact disclosed is a deadline the organisation published on the link
     * itself; there is nothing in a closing date that describes how the
     * organisation works.
     *
     * @param array<string, mixed>       $form   A normalized `forms` row.
     * @param list<array<string, mixed>> $fields The ALREADY-FILTERED answerable fields.
     *
     * @return array<string, mixed>
     */
    public static function form(array $form, array $fields): array
    {
        $public = [];
        foreach ($fields as $field) {
            $public[] = self::field($field);
        }

        return [
            'slug' => $form['public_slug'] ?? null,
            'name' => $form['name'] ?? [],
            'description' => $form['description'] ?? null,
            'fields' => $public,
            'sections' => FormRenderer::sectionsOf($public),
            // The single fact a renderer branches on: draw a submit button, or
            // draw a notice. Restated at the top level for the reason
            // {@see FormRenderer} restates it — a client made to dig for it
            // inside a nested object is a client that ends up not checking.
            //
            // `$fields !== []` is the third condition, and it is not padding.
            // Opening a link is refused on a form with no answerable field, but
            // a form can ARRIVE in that state afterwards — an author retypes a
            // text field as a person field on a form whose link is already open.
            // Without this the public surface would draw a page with no
            // questions and a submit button, accept an empty submission, and
            // report success: the precise failure this subsystem's refusal to
            // publish an empty form exists to prevent, reappearing one door
            // further out. {@see \Whity\Api\PublicFormsApiHandler::submit()}
            // applies the same condition to the WRITE, so the two cannot say
            // different things.
            'accepts_submissions' => PublicFormLink::acceptsPublicSubmissions($form) && $fields !== [],
            'opens_at' => $form['public_opens_at'] ?? null,
            'closes_at' => $form['public_closes_at'] ?? null,
        ];
    }
}
