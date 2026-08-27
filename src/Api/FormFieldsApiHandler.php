<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Form\FieldType;
use Whity\Core\Form\FormFieldRepository;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormRepository;
use Whity\Core\Form\FormStatus;
use Whity\Core\Form\LocalizedLabel;
use Whity\Core\Form\PrefillSource;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * The fields that compose a form — the builder's surface.
 *
 * EVERY WRITE IS NESTED UNDER THE FORM, AND THE NESTING IS LOAD-BEARING
 * ----------------------------------------------------------------------
 * Every write route is `/api/v1/forms/{id}/fields/...` and every repository call
 * binds BOTH the form id and the field id. That is not decoration on a URL: it
 * is what makes `DELETE /api/v1/forms/7/fields/42` refuse when field 42 belongs
 * to form 9. A flat `/api/v1/form-fields/{id}` for a write would have made the
 * form segment an unenforced hint and let a caller edit any field in the tenant
 * through any form's URL.
 *
 * THE ONE FLAT ROUTE IS A READ, AND HERE IS WHY IT IS NOT A HOLE
 * ---------------------------------------------------------------
 * `GET /api/v1/form-fields?form_id=N` ({@see listByQuery()}) exists beside the
 * nested list, and the reason is the master-detail contract in
 * {@see \Whity\Sdk\Frontend\Blocks\BlockContract}: a `selector` publishes its
 * value into a data-bound block's `params`, which append QUERY PARAMS to a fixed
 * `source`. They cannot fill a PATH SEGMENT. Without this route the builder could
 * not put a form picker above a field table without hand-written client code, and
 * the whole point of the descriptor is that there is none.
 *
 * It is safe where a flat WRITE would not be, and the difference is not a matter
 * of degree. A read returns rows of ONE form in the CALLER'S tenant — the
 * predicate is bound either way and there is no second record to confuse — so the
 * form id being a query param instead of a path segment changes nothing about
 * what may be reached. A write's form id decides WHICH form a mutation lands on,
 * which is exactly the thing the path segment is there to pin.
 *
 * WHY `field_key` IS IMMUTABLE AND `field_type` IS NOT
 * -----------------------------------------------------
 * Answers already in `form_submissions.data` are keyed by `field_key`. Renaming
 * a key in place does not rename the answers — it ORPHANS them, and every past
 * submission silently loses that field while the request reports success. So a
 * body carrying `field_key` is REFUSED rather than ignored, and the message says
 * to delete and re-add, which has the same effect on old data and is at least
 * visibly destructive.
 *
 * `field_type` may change, because fixing `text` to `textarea` is a real edit an
 * author makes. Options are re-validated against the new type in the same
 * request, so a `select` demoted to `text` cannot keep choices nothing will draw.
 *
 * PERMISSIONS: reading on `forms:read`, every write on `forms:manage`. Fields are
 * the form's substance, so editing one is authoring — see
 * {@see \Whity\Core\RBAC\CorePermissions}.
 */
final class FormFieldsApiHandler
{
    public function __construct(
        private readonly FormRepository $forms,
        private readonly FormFieldRepository $fields,
    ) {
    }

    /**
     * GET /api/v1/forms/{id}/fields — the form's fields, in authoring order.
     *
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        try {
            $context = $this->context($params);
            if ($context instanceof Response) {
                return $context;
            }
            [$tenantId, $form] = $context;

            return Response::json([
                'data' => $this->fields->listForForm($tenantId, (int) $form['id']),
                // The vocabularies a builder needs in order to render its own
                // pickers, shipped with the list so the client does not need a
                // second endpoint (or, worse, a hardcoded copy that drifts from
                // the server's).
                'meta' => self::vocabularies(),
            ]);
        } catch (\Exception $e) {
            error_log('[FormFieldsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch form fields', 500);
        }
    }

    /**
     * GET /api/v1/form-fields?form_id=N — the same list, addressed by query
     * param so a master-detail `selector` can drive it. See the class docblock
     * for why the flat form exists and why it is a read only.
     *
     * An absent or unknown `form_id` returns an EMPTY list rather than a 422.
     * The block renders before anybody has picked a form, and answering the
     * first paint with an error would put a red box on a screen where nothing is
     * wrong yet.
     */
    public function listByQuery(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $formId = self::intQuery($request, 'form_id');
            if ($formId === null || $this->forms->find($tenantId, $formId) === null) {
                return Response::json(['data' => [], 'meta' => self::vocabularies()]);
            }

            return Response::json([
                'data' => $this->fields->listForForm($tenantId, $formId),
                'meta' => self::vocabularies(),
            ]);
        } catch (\Exception $e) {
            error_log('[FormFieldsApiHandler] listByQuery failed: ' . $e->getMessage());

            return Response::error('Failed to fetch form fields', 500);
        }
    }

    /**
     * POST /api/v1/forms/{id}/fields — add a field, at the end by default.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        try {
            $context = $this->context($params);
            if ($context instanceof Response) {
                return $context;
            }
            [$tenantId, $form] = $context;

            if ($editable = self::refuseIfNotEditable($form)) {
                return $editable;
            }

            $body = JsonBody::parsed($request);

            $fieldKey = trim((string) ($body['field_key'] ?? ''));
            if ($fieldKey === '') {
                return Response::error('field_key is required', 422);
            }
            if ($keyError = self::validateKey($fieldKey)) {
                return $keyError;
            }

            $fieldType = trim((string) ($body['field_type'] ?? ''));
            if (!FieldType::isValid($fieldType)) {
                return Response::error(
                    'field_type must be one of: ' . implode(', ', FieldType::all()),
                    422
                );
            }

            $label = LocalizedLabel::fromInput($body['label'] ?? null, 'label');

            $options = self::options($body, $fieldType);
            if ($options instanceof Response) {
                return $options;
            }

            $validation = self::validationRules($body);
            if ($validation instanceof Response) {
                return $validation;
            }

            $prefill = self::prefillSource($body);
            if ($prefill instanceof Response) {
                return $prefill;
            }

            $helpText = self::optionalText($body, 'help_text');
            if ($helpText instanceof Response) {
                return $helpText;
            }
            $sectionKey = self::optionalName($body, 'section_key');
            if ($sectionKey instanceof Response) {
                return $sectionKey;
            }

            $id = $this->fields->create(
                $tenantId,
                (int) $form['id'],
                $fieldKey,
                $fieldType,
                $label,
                $helpText,
                ($body['is_required'] ?? false) === true,
                $options,
                $validation,
                $prefill,
                $sectionKey,
                self::intField($body, 'position'),
            );

            return Response::json([
                'data' => $this->fields->find($tenantId, (int) $form['id'], $id),
            ], 201);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage() (WC-186).
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[FormFieldsApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create form field', 500);
        }
    }

    /**
     * PUT /api/v1/forms/{id}/fields — save the whole field set at once.
     *
     * Authoring a form is one act of composition, not a sequence of independent
     * single-field decisions. An editor that mirrors that — question cards you
     * add to, reorder and delete in place, saved together — cannot rest on
     * per-field calls without inventing a client-side transaction and hoping
     * every leg lands. One request, one reconciliation, one outcome.
     *
     * @param array<string, string> $params
     */
    public function replace(Request $request, array $params): Response
    {
        try {
            $context = $this->context($params);
            if ($context instanceof Response) {
                return $context;
            }
            [$tenantId, $form] = $context;

            if ($editable = self::refuseIfNotEditable($form)) {
                return $editable;
            }

            $body = JsonBody::parsed($request);
            $incoming = $body['fields'] ?? null;
            if (!is_array($incoming) || !array_is_list($incoming)) {
                return Response::error("'fields' must be a list of field definitions", 422);
            }

            /** @var list<array<string, mixed>> $normalised */
            $normalised = [];
            $seen = [];

            foreach ($incoming as $index => $entry) {
                if (!is_array($entry)) {
                    return Response::error("fields[{$index}] must be an object", 422);
                }

                $fieldKey = trim((string) ($entry['field_key'] ?? ''));
                if ($fieldKey === '') {
                    return Response::error("fields[{$index}].field_key is required", 422);
                }
                if ($keyError = self::validateKey($fieldKey)) {
                    return $keyError;
                }
                if (isset($seen[$fieldKey])) {
                    // Two questions cannot share a key: an answer could then
                    // belong to either and nothing downstream could tell which.
                    return Response::error(
                        "fields[{$index}].field_key '{$fieldKey}' appears more than once in this request",
                        422
                    );
                }
                $seen[$fieldKey] = true;

                $fieldType = trim((string) ($entry['field_type'] ?? ''));
                if (!FieldType::isValid($fieldType)) {
                    return Response::error(
                        "fields[{$index}].field_type must be one of: " . implode(', ', FieldType::all()),
                        422
                    );
                }

                $label = LocalizedLabel::fromInput($entry['label'] ?? null, "fields[{$index}].label");

                $options = self::options($entry, $fieldType);
                if ($options instanceof Response) {
                    return $options;
                }
                $validation = self::validationRules($entry);
                if ($validation instanceof Response) {
                    return $validation;
                }
                $prefill = self::prefillSource($entry);
                if ($prefill instanceof Response) {
                    return $prefill;
                }
                $helpText = self::optionalText($entry, 'help_text');
                if ($helpText instanceof Response) {
                    return $helpText;
                }
                $sectionKey = self::optionalName($entry, 'section_key');
                if ($sectionKey instanceof Response) {
                    return $sectionKey;
                }

                $normalised[] = [
                    'field_key' => $fieldKey,
                    'field_type' => $fieldType,
                    'label' => $label,
                    'help_text' => $helpText,
                    'is_required' => ($entry['is_required'] ?? false) === true,
                    'options' => $options,
                    'validation' => $validation,
                    'prefill_source' => $prefill,
                    'section_key' => $sectionKey,
                ];
            }

            return Response::json([
                'data' => $this->fields->replaceAll($tenantId, (int) $form['id'], $normalised),
            ]);
        } catch (FormRejectedException $e) {
            // ->clientMessage, never ->getMessage() (WC-186).
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[FormFieldsApiHandler] replace failed: ' . $e->getMessage());

            return Response::error('Failed to save the form fields', 500);
        }
    }

    /**
     * PATCH /api/v1/forms/{id}/fields/{fieldId} — edit a field in place, or move
     * it in the order.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $context = $this->context($params);
            if ($context instanceof Response) {
                return $context;
            }
            [$tenantId, $form] = $context;

            if ($editable = self::refuseIfNotEditable($form)) {
                return $editable;
            }

            $formId = (int) $form['id'];
            $fieldId = (int) ($params['fieldId'] ?? 0);
            $field = $this->fields->find($tenantId, $formId, $fieldId);
            if ($field === null) {
                return Response::error('Form field not found', 404);
            }

            $body = JsonBody::parsed($request);

            if (array_key_exists('field_key', $body)) {
                return Response::error(
                    'field_key cannot be changed — answers already submitted are keyed by it. '
                    . 'Delete the field and add a new one instead.',
                    422
                );
            }

            $changes = [];

            // The type is resolved first because options are validated AGAINST
            // it: a body that demotes a `select` to `text` and keeps its options
            // must be refused in the same pass, not accepted and then found
            // inconsistent by whatever reads it next.
            $effectiveType = (string) $field['field_type'];
            if (array_key_exists('field_type', $body)) {
                $fieldType = trim((string) $body['field_type']);
                if (!FieldType::isValid($fieldType)) {
                    return Response::error(
                        'field_type must be one of: ' . implode(', ', FieldType::all()),
                        422
                    );
                }
                $changes['field_type'] = $fieldType;
                $effectiveType = $fieldType;
            }

            if (array_key_exists('label', $body)) {
                $changes['label'] = LocalizedLabel::fromInput($body['label'], 'label');
            }
            if (array_key_exists('is_required', $body)) {
                $changes['is_required'] = $body['is_required'] === true;
            }
            if (array_key_exists('help_text', $body)) {
                $helpText = self::optionalText($body, 'help_text');
                if ($helpText instanceof Response) {
                    return $helpText;
                }
                $changes['help_text'] = $helpText;
            }
            if (array_key_exists('section_key', $body)) {
                $sectionKey = self::optionalName($body, 'section_key');
                if ($sectionKey instanceof Response) {
                    return $sectionKey;
                }
                $changes['section_key'] = $sectionKey;
            }
            if (array_key_exists('prefill_source', $body)) {
                $prefill = self::prefillSource($body);
                if ($prefill instanceof Response) {
                    return $prefill;
                }
                $changes['prefill_source'] = $prefill;
            }
            if (array_key_exists('position', $body)) {
                $position = self::intField($body, 'position');
                if ($position === null || $position < 0) {
                    return Response::error('position must be a non-negative integer', 422);
                }
                $changes['position'] = $position;
            }
            if (array_key_exists('validation', $body)) {
                $validation = self::validationRules($body);
                if ($validation instanceof Response) {
                    return $validation;
                }
                $changes['validation'] = $validation;
            }

            // Options are re-derived whenever EITHER the options or the type
            // moved, so a type change alone cannot leave a stale list behind.
            if (array_key_exists('options', $body) || array_key_exists('field_type', $body)) {
                $source = array_key_exists('options', $body)
                    ? $body
                    : ['options' => $field['options']];
                $options = self::options($source, $effectiveType);
                if ($options instanceof Response) {
                    return $options;
                }
                $changes['options'] = $options;
            }

            if ($changes === []) {
                return Response::error('No updatable fields supplied', 422);
            }

            $this->fields->update($tenantId, $formId, $fieldId, $changes);

            return Response::json(['data' => $this->fields->find($tenantId, $formId, $fieldId)]);
        } catch (FormRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (\Exception $e) {
            error_log('[FormFieldsApiHandler] update failed: ' . $e->getMessage());

            return Response::error('Failed to update form field', 500);
        }
    }

    /**
     * DELETE /api/v1/forms/{id}/fields/{fieldId} — take a field off a form.
     *
     * Answers already given to it are NOT deleted; they stay in
     * `form_submissions.data` and simply stop having a label. That is why an
     * ARCHIVED form refuses this: its fields are the only remaining explanation
     * of what its submissions answered.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $context = $this->context($params);
            if ($context instanceof Response) {
                return $context;
            }
            [$tenantId, $form] = $context;

            if ($editable = self::refuseIfNotEditable($form)) {
                return $editable;
            }

            $formId = (int) $form['id'];
            $fieldId = (int) ($params['fieldId'] ?? 0);

            if (!$this->fields->delete($tenantId, $formId, $fieldId)) {
                return Response::error('Form field not found', 404);
            }

            return Response::json(['data' => ['deleted' => true]]);
        } catch (\Exception $e) {
            error_log('[FormFieldsApiHandler] delete failed: ' . $e->getMessage());

            return Response::error('Failed to delete form field', 500);
        }
    }

    /**
     * Resolve the tenant and the form named in the path, or the response that
     * refuses the request.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: array<string, mixed>}|Response
     */
    private function context(array $params): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $form = $this->forms->find($tenantId, (int) ($params['id'] ?? 0));
        if ($form === null) {
            // Absent, never forbidden: a form id is an enumerable integer, and a
            // 403 on one id beside a 404 on another confirms which ids exist.
            return Response::error('Form not found', 404);
        }

        return [$tenantId, $form];
    }

    /**
     * @param array<string, mixed> $form
     */
    private static function refuseIfNotEditable(array $form): ?Response
    {
        if (FormStatus::allowsFieldEditing((string) $form['status'])) {
            return null;
        }

        return Response::error(
            'An archived form\'s fields cannot be changed — they are the only remaining explanation '
            . 'of what its submissions answered. Republish the form first.',
            409
        );
    }

    private static function validateKey(string $key): ?Response
    {
        if (strlen($key) > FormFieldRepository::KEY_MAX) {
            return Response::error(
                'field_key must be ' . FormFieldRepository::KEY_MAX . ' characters or fewer',
                422
            );
        }
        if (preg_match(FormFieldRepository::KEY_PATTERN, $key) !== 1) {
            return Response::error(
                'field_key must start with a letter and contain only lowercase letters, '
                . 'digits and underscores',
                422
            );
        }

        return null;
    }

    /**
     * Normalize and check a `select` / `multiselect` option list.
     *
     * A choice-bearing field with no choices is refused, for the reason
     * publishing an empty form is refused: it renders, it accepts nothing, and it
     * reports success. A field that does NOT bear options and was sent some gets
     * an empty list rather than a 422 — a client that changed a type and left the
     * old options attached made a harmless mistake, and the correct repair is to
     * drop them.
     *
     * @param array<string, mixed> $body
     * @return list<array{value: string, label: array<string, string>}>|Response
     */
    private static function options(array $body, string $fieldType): array|Response
    {
        $raw = $body['options'] ?? [];
        if (!is_array($raw)) {
            return Response::error('options must be a list of {value, label} objects', 422);
        }

        if (!FieldType::requiresOptions($fieldType)) {
            return [];
        }

        $options = [];
        $seen = [];
        foreach (array_values($raw) as $index => $option) {
            if (!is_array($option)) {
                return Response::error("options[{$index}] must be an object with 'value' and 'label'", 422);
            }
            $value = isset($option['value']) && is_string($option['value']) ? trim($option['value']) : '';
            if ($value === '') {
                return Response::error("options[{$index}].value is required", 422);
            }
            if (strlen($value) > InputLimits::NAME_MAX) {
                return Response::error(
                    "options[{$index}].value must be " . InputLimits::NAME_MAX . ' characters or fewer',
                    422
                );
            }
            if (isset($seen[$value])) {
                // Two options with one value is two answers to one question: the
                // validator would accept either and nothing could say which the
                // person meant.
                return Response::error("options[{$index}].value repeats an earlier option", 422);
            }
            $seen[$value] = true;

            try {
                $label = LocalizedLabel::fromInput($option['label'] ?? $value, "options[{$index}].label");
            } catch (FormRejectedException $e) {
                return Response::error($e->clientMessage, 422);
            }

            $options[] = ['value' => $value, 'label' => $label];
        }

        if ($options === []) {
            return Response::error(
                "A {$fieldType} field needs at least one choice — without any it renders as an empty "
                . 'picker that can never be answered.',
                422
            );
        }

        return $options;
    }

    /**
     * Normalize the `{min, max, pattern, maxLength}` rule set.
     *
     * Unknown keys are DROPPED rather than refused, and the reason is not
     * leniency: an unknown rule is a rule nothing enforces, and storing it would
     * put a promise on the row that no code keeps. Dropping it means the stored
     * rule set is exactly what
     * {@see \Whity\Core\Form\SubmissionValidator} will actually apply.
     *
     * The pattern is compiled HERE, once, at authoring time. An author who typed
     * a broken expression finds out while they are looking at it — rather than
     * every future submitter's answer being silently refused by a validator whose
     * `preg_match` returns false.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>|Response
     */
    private static function validationRules(array $body): array|Response
    {
        // `??` already turns an explicit null into the empty rule set, which is
        // what "clear the rules" means on a PATCH.
        $raw = $body['validation'] ?? [];
        if (!is_array($raw)) {
            return Response::error('validation must be an object', 422);
        }

        $rules = [];
        foreach (['min', 'max', 'maxLength'] as $numeric) {
            if (!array_key_exists($numeric, $raw) || $raw[$numeric] === null) {
                continue;
            }
            $value = $raw[$numeric];
            if (is_int($value) || is_float($value)) {
                $rules[$numeric] = $value;
            } elseif (is_string($value) && is_numeric($value)) {
                $rules[$numeric] = $value + 0;
            } else {
                return Response::error("validation.{$numeric} must be a number", 422);
            }
        }

        if (isset($rules['min'], $rules['max']) && $rules['min'] > $rules['max']) {
            return Response::error('validation.min cannot be greater than validation.max', 422);
        }

        // `isset` already excludes null, so an explicit null `pattern` means
        // "no pattern" and falls through — which is the same thing as omitting it.
        if (isset($raw['pattern'])) {
            if (!is_string($raw['pattern']) || trim($raw['pattern']) === '') {
                return Response::error('validation.pattern must be a non-empty string', 422);
            }
            $pattern = trim($raw['pattern']);
            if (strlen($pattern) > 512) {
                return Response::error('validation.pattern must be 512 characters or fewer', 422);
            }
            // Compiled with the SAME delimiter and flags the validator will use,
            // so "it compiled here" is a real promise about there rather than a
            // coincidence of two similar strings.
            if (@preg_match('/' . str_replace('/', '\\/', $pattern) . '/u', '') === false) {
                return Response::error('validation.pattern is not a valid expression', 422);
            }
            $rules['pattern'] = $pattern;
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function prefillSource(array $body): string|null|Response
    {
        $raw = $body['prefill_source'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw) || !PrefillSource::isValid($raw)) {
            return Response::error(
                'prefill_source must be null or one of: ' . implode(', ', PrefillSource::all()),
                422
            );
        }

        // An UNBACKED source is accepted, not refused. Refusing would make the
        // picker lie about what the platform knows about, and the field simply
        // starts empty — which the render response reports explicitly under
        // `unresolved_prefill`. See PrefillSource's class docblock.
        return $raw;
    }

    /**
     * The vocabularies a builder renders its pickers from.
     *
     * Served by the server so a client cannot hold a stale copy. `prefill_sources`
     * carries the `backed` flag and its reason so a form author sees, at the
     * moment they pick one, that a source will never produce a value here.
     *
     * @return array<string, mixed>
     */
    private static function vocabularies(): array
    {
        $sources = [];
        foreach (PrefillSource::all() as $source) {
            $sources[] = [
                'source' => $source,
                'backed' => PrefillSource::isBacked($source),
                'reason' => PrefillSource::unbackedReason($source),
            ];
        }

        return [
            'field_types' => FieldType::all(),
            'option_bearing_field_types' => array_values(array_filter(
                FieldType::all(),
                static fn (string $type): bool => FieldType::requiresOptions($type)
            )),
            'prefill_sources' => $sources,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalText(array $body, string $field): string|null|Response
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_string($body[$field])) {
            return Response::error("{$field} must be text or null", 422);
        }
        $value = trim($body[$field]);
        if ($value === '') {
            return null;
        }
        if ($violation = InputLimits::firstViolation([$field => [$value, InputLimits::TEXT_MAX]])) {
            return $violation;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optionalName(array $body, string $field): string|null|Response
    {
        if (!array_key_exists($field, $body) || $body[$field] === null) {
            return null;
        }
        if (!is_string($body[$field])) {
            return Response::error("{$field} must be text or null", 422);
        }
        $value = trim($body[$field]);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > FormFieldRepository::KEY_MAX) {
            return Response::error(
                "{$field} must be " . FormFieldRepository::KEY_MAX . ' characters or fewer',
                422
            );
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function intField(array $body, string $field): ?int
    {
        $raw = $body[$field] ?? null;
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return null;
    }

    /**
     * One integer query param, from $_GET (production) or the path query string
     * (tests) — the same precedence {@see TimeWindowsApiHandler} uses, so a test
     * that puts params in the path and production traffic resolve identically.
     */
    private static function intQuery(Request $request, string $name): ?int
    {
        $raw = null;
        if (isset($_GET[$name]) && is_string($_GET[$name])) {
            $raw = $_GET[$name];
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            if (isset($parsed[$name]) && is_string($parsed[$name])) {
                $raw = $parsed[$name];
            }
        }

        return is_string($raw) && preg_match('/^\d+$/', $raw) === 1 ? (int) $raw : null;
    }
}
