<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * Assembles what a client needs to DRAW a form for one particular person: the
 * form, its fields in order, the fields grouped into sections, and the prefill
 * values resolved for whoever is asking.
 *
 * WHY THE PREFILL VALUES COME BACK IN A SEPARATE MAP RATHER THAN INLINED ONTO
 * EACH FIELD
 * ---------------------------------------------------------------------------
 * Because a field is the same for everybody and a prefill value is not. The
 * field list is a property of the FORM — cacheable, shareable, identical for
 * every caller — while `prefill` is a property of the CALLER. Merging them would
 * produce a payload that looks cacheable and is not, which is the shape of a bug
 * that only appears under a CDN, months later, as one person seeing another
 * person's name in a box.
 *
 * Keeping them apart also makes the client's job unambiguous: `prefill[key]` is
 * a SUGGESTED starting value the person may overwrite, never an answer. Nothing
 * is submitted until they submit it.
 *
 * SECTIONS ARE DERIVED, NOT STORED
 * ---------------------------------
 * `form_fields.section_key` is a label on a field, and the section list here is
 * computed from the fields in order. There is no `form_sections` table, and that
 * is a deliberate omission rather than a gap: a section with no fields is not
 * something anybody means to author, and a table would make one possible — along
 * with the mismatch where a field points at a section that was deleted. Deriving
 * it means the two can never disagree, and reordering fields reorders sections
 * for free.
 *
 * Fields with no `section_key` come FIRST, under a null section, because a form
 * that grew sections later has its original fields at the top and moving them
 * below the new sections would silently reorder somebody's form.
 *
 * Stateless apart from the injected collaborators — worker-safe.
 */
final class FormRenderer
{
    public function __construct(
        private readonly FormFieldRepository $fields,
        private readonly PrefillResolver $prefill,
    ) {
    }

    /**
     * Everything needed to draw `$form` for `$profileId`.
     *
     * @param array<string, mixed> $form A normalized `forms` row.
     *
     * @return array{
     *     form: array<string, mixed>,
     *     fields: list<array<string, mixed>>,
     *     sections: list<array{key: ?string, field_keys: list<string>}>,
     *     prefill: array<string, string>,
     *     unresolved_prefill: list<array{field_key: string, source: string, reason: string}>,
     *     accepts_submissions: bool
     * }
     */
    public function render(int $tenantId, ?int $profileId, array $form): array
    {
        $formId = (int) $form['id'];
        $fields = $this->fields->listForForm($tenantId, $formId);

        return [
            'form' => $form,
            'fields' => $fields,
            'sections' => self::sectionsOf($fields),
            'prefill' => $this->prefill->forFields($tenantId, $profileId, $fields),
            // Reported rather than swallowed. A field naming a source this
            // install cannot resolve renders as an empty box, which is correct
            // behaviour and indistinguishable from a bug — so the response SAYS
            // which fields those are and why. See PrefillSource for the two
            // declared-but-unbacked sources and the argument for declaring them.
            'unresolved_prefill' => self::unresolved($fields),
            // Restated at the top level even though `form.accepts_submissions`
            // carries it: this is the single fact the renderer branches on
            // (draw a submit button, or draw a notice), and making it dig for it
            // inside a nested object is how a client ends up not checking.
            'accepts_submissions' => FormStatus::acceptsSubmissions((string) ($form['status'] ?? '')),
        ];
    }

    /**
     * Group the field keys by section, preserving field order.
     *
     * PUBLIC, and called from {@see PublicFormView::form()} as well as from
     * {@see render()} above. Sections are DERIVED from the fields (see the class
     * docblock), so the public surface must derive them the same way or a
     * publicly-served form would group its fields differently from the same form
     * seen by a signed-in colleague — for no reason either of them could find.
     * One function, two callers, is what makes that impossible.
     *
     * It reads only `section_key` and `field_key`, so it works unchanged on the
     * REDUCED field rows the public view emits.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array{key: ?string, field_keys: list<string>}>
     */
    public static function sectionsOf(array $fields): array
    {
        /** @var array<string, list<string>> $grouped Keyed by section, '' for none. */
        $grouped = [];
        $order = [];

        foreach ($fields as $field) {
            $key = $field['section_key'] ?? null;
            $bucket = is_string($key) && $key !== '' ? $key : '';
            if (!isset($grouped[$bucket])) {
                $grouped[$bucket] = [];
                $order[] = $bucket;
            }
            $grouped[$bucket][] = (string) ($field['field_key'] ?? '');
        }

        // The unsectioned bucket first, whatever order it was encountered in —
        // see the class docblock.
        usort($order, static fn (string $a, string $b): int => ($a === '' ? 0 : 1) <=> ($b === '' ? 0 : 1));

        $sections = [];
        foreach ($order as $bucket) {
            $sections[] = [
                'key' => $bucket === '' ? null : $bucket,
                'field_keys' => $grouped[$bucket],
            ];
        }

        return $sections;
    }

    /**
     * The fields whose prefill source will never produce a value in this install.
     *
     * @param list<array<string, mixed>> $fields
     * @return list<array{field_key: string, source: string, reason: string}>
     */
    private static function unresolved(array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            $source = $field['prefill_source'] ?? null;
            if (!is_string($source) || $source === '' || PrefillSource::isBacked($source)) {
                continue;
            }
            $reason = PrefillSource::unbackedReason($source);
            $out[] = [
                'field_key' => (string) ($field['field_key'] ?? ''),
                'source' => $source,
                'reason' => $reason ?? '',
            ];
        }

        return $out;
    }
}
