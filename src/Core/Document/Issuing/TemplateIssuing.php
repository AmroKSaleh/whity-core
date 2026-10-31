<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * Issue a document from a template or a slot, for a record, with merge values
 * (WHIT-742, DOCUMENT-AUTOMATION.md 3.1 to 3.3). One entry point for
 * `POST /api/documents`, the SDK's `Documents::issue()` and automations, so
 * all three resolve, check and refuse the same way.
 *
 * Steps, each refusing with a {@see DocumentIssueRefused} and writing nothing:
 * the template (a slot through the workspace's binding, or an id, as the actor
 * may see it), its version (published, or a letter frozen now), the record
 * (read with the actor's permissions), the values (only fields the template
 * offers, never over one the record resolved), required fields, the origin
 * key, and whether a render was required where none can happen. Then
 * {@see DocumentIssuing::issue()} raises, renders and announces it.
 */
final class TemplateIssuing
{
    /** How many unknown or conflicting fields a refusal names. */
    private const FIELDS_REPORTED = 10;

    /** @var callable(int, int, int): (array<string, mixed>|null) */
    private $visibleSource;

    /** @var DocumentIssuing|\Closure(): DocumentIssuing */
    private DocumentIssuing|\Closure $issuing;

    /**
     * @param callable(int, int, int): (array<string, mixed>|null) $visibleSource
     *        template id, tenant id, profile id => the template row when the
     *        profile may see it (the editor's own visibility policy)
     * @param DocumentIssuing|\Closure(): DocumentIssuing $issuing built on the
     *        first issue when a closure: a worker that never issues never
     *        needs the render service, storage or encryption key
     */
    public function __construct(
        DocumentIssuing|\Closure $issuing,
        private readonly DocumentFieldRegistry $fields,
        private readonly SettingsService $settings,
        callable $visibleSource,
        private readonly ?TemplateSlotRegistry $slots = null,
    ) {
        $this->visibleSource = $visibleSource;
        $this->issuing = $issuing;
    }

    private function issuing(): DocumentIssuing
    {
        if ($this->issuing instanceof \Closure) {
            $this->issuing = ($this->issuing)();
        }

        return $this->issuing;
    }

    /**
     * @return array{document: array<string, mixed>, artifacts: list<array<string, mixed>>, render: array<string, mixed>, replayed: bool}
     * @throws DocumentIssueRefused
     */
    public function issue(ActingAs $actor, IssueInput $input): array
    {
        // A workspace's plugins can change between two issues in one worker.
        $this->fields->resetRequest();
        $tenantId = $actor->tenantId;
        $callerId = $actor->profileId;
        $hasPermission = $actor->hasPermission;

        $source = $this->source($actor, $input);
        if ($source['kind'] === 'template') {
            $version = $this->issuing()->published($tenantId, $source);
            if ($version === null) {
                throw new DocumentIssueRefused('template_unpublished', 'This template has no published version yet', 409);
            }
        } elseif (in_array($source['kind'], ['letter', 'report'], true)) {
            // A one-off document ("Edit before issuing"): what it says now is frozen as a version.
            $version = $this->issuing()->freeze($tenantId, $callerId, $source);
            if ($version === null) {
                throw new DocumentIssueRefused('concurrent_edit', 'The document changed while it was being issued; try again', 409);
            }
        } else {
            throw DocumentIssueRefused::invalid('Only templates, letters and reports issue documents; a label sheet prints labels');
        }

        $type = $source['record_type'] !== null ? (string) $source['record_type'] : null;
        $record = self::parseRecord($input->record);
        if ($type !== null && ($record === null || $record[0] !== $type)) {
            throw DocumentIssueRefused::invalid('record must be "' . $type . ':<id>"');
        }
        $resolved = $this->fields->resolve($tenantId, $callerId, $hasPermission, $type, $record[1] ?? null);
        if ($resolved === null) {
            throw new DocumentIssueRefused('record_not_found', 'Record not found', 404);
        }

        $given = self::givenValues(
            $input->values,
            $version['own_fields'] ?? null,
            $this->fields->paths($tenantId, $type),
            $resolved['values'],
        );
        $values = $given + $resolved['values'];

        $missing = $this->issuing()->missing(new IssueRequest($source, $version, $values, ''), $actor);
        if ($missing !== []) {
            throw new DocumentIssueRefused('missing_fields', 'Required fields have no value for this record', 422, ['missing' => $missing]);
        }

        // The caller's own name for what this document is: issuing it again returns it.
        $originKey = $input->originKey;
        if ($originKey !== null && (!is_string($originKey) || preg_match('/^[A-Za-z0-9:._\/-]{1,160}$/', $originKey) !== 1)) {
            throw DocumentIssueRefused::invalid("'origin_key' must be 1 to 160 letters, digits or : . _ / -");
        }

        $title = $input->title;
        $title = is_string($title) && trim($title) !== ''
            ? trim($title)
            : ($resolved['label'] !== '' ? $version['title'] . ' · ' . $resolved['label'] : (string) $version['title']);

        $effective = $this->settings->effective($tenantId);
        $renderable = ($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') === 'true';
        // The artifact half only: an operator who capped storage can still raise documents.
        $persistable = ($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') === 'true';

        // Tri-state: true = "I require an artifact"; false = "record only";
        // absent = "render if this instance can".
        $requested = $input->render;
        if ($requested === true && !$renderable) {
            throw new DocumentIssueRefused('render_unavailable', 'Server-side document rendering is disabled on this instance', 503);
        }
        if ($requested === true && !$persistable) {
            throw new DocumentIssueRefused('persist_unavailable', 'Persisting rendered documents is disabled on this instance', 503);
        }

        try {
            return $this->issuing()->issue(new IssueRequest(
                source: $source,
                version: $version,
                values: $values,
                title: $title,
                recordLabel: $resolved['label'],
                render: is_bool($requested) ? $requested : null,
                record: $record !== null ? $record[0] . ':' . $record[1] : null,
                originKey: $originKey,
            ), $actor);
        } catch (HookVetoException $e) {
            // A `document.issuing` listener refused it; nothing was written.
            throw new DocumentIssueRefused('vetoed', $e->reason(), 409, ['code' => 'vetoed', 'reason' => 'blocked_by_plugin']);
        } catch (DocumentOriginKeyConflictException $e) {
            throw new DocumentIssueRefused('origin_key_conflict', 'This origin key already names another document', 409, [
                'code' => 'origin_key_conflict',
                'document_id' => $e->documentId,
            ]);
        }
    }

    /**
     * The template to issue from, as the actor may see it.
     *
     * @return array<string, mixed>
     */
    private function source(ActingAs $actor, IssueInput $input): array
    {
        if ($input->both) {
            throw DocumentIssueRefused::invalid("Send 'slot' or 'document_source_id', not both");
        }
        if ($input->slot !== null) {
            return $this->slotSource($actor, $input->slot);
        }

        $sourceId = $input->sourceId;
        // A string id is accepted: JSON from a form-driven client routinely
        // carries numbers as strings.
        if (is_string($sourceId) && ctype_digit($sourceId)) {
            $sourceId = (int) $sourceId;
        }
        if (!is_int($sourceId) || $sourceId <= 0) {
            throw DocumentIssueRefused::invalid("'document_source_id' must be the id of a template to issue this document from, or send 'slot'");
        }

        return $this->visible($actor, $sourceId);
    }

    /**
     * The template bound to a slot (DOCUMENT-AUTOMATION.md 3.2). A slot whose
     * plugin is off, or that nothing fills, is refused with a code the plugin
     * can show; a bound template that no longer fits (unpublished, made
     * personal, a different record type) names why, never a silent fallback.
     *
     * @return array<string, mixed>
     */
    private function slotSource(ActingAs $actor, string $key): array
    {
        if ($key === '') {
            throw DocumentIssueRefused::invalid("'slot' must be a slot key such as bakery.order_confirmation");
        }
        $slot = $this->slots?->find($actor->tenantId, $key);
        if ($slot === null) {
            $plugin = strstr($key, '.', true);

            throw new DocumentIssueRefused('slot_unavailable', 'This slot is unknown here, or its plugin is off', 409, [
                'code' => 'slot_unavailable',
                'plugin' => $plugin !== false ? $plugin : $key,
            ]);
        }
        $effective = $this->settings->effective($actor->tenantId);
        $templateId = SlotBindings::parse((string) ($effective[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''))[$slot->key] ?? null;
        if ($templateId === null) {
            throw new DocumentIssueRefused('slot_unbound', 'No template is bound to this slot yet', 409, ['code' => 'slot_unbound', 'slot' => $slot->key]);
        }
        $source = $this->visible($actor, $templateId);
        $problem = $slot->misfit($source);
        if ($problem !== null) {
            throw new DocumentIssueRefused('slot_misfit', 'The template bound to this slot no longer fits it: ' . $problem['message'], 409, [
                'code' => 'slot_misfit',
                'slot' => $slot->key,
                'reason' => $problem['code'],
            ]);
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function visible(ActingAs $actor, int $id): array
    {
        // Not found rather than forbidden on a miss: forbidden would confirm the template exists.
        $source = ($this->visibleSource)($id, $actor->tenantId, $actor->profileId);
        if ($source === null) {
            throw new DocumentIssueRefused('template_not_found', 'Template not found', 404);
        }

        return $source;
    }

    /**
     * The values a request filled (merge data, DOCUMENT-AUTOMATION.md 3.3). A
     * value may fill a template's own field (`template.<key>`) or a field the
     * record left empty; a value for a field the record RESOLVED is refused as
     * `field_conflict`, so a caller can't overwrite an amount, a tax number or
     * any other value the record owns. Unknown names are echoed (up to a few)
     * so a developer can see the typo.
     *
     * @param array<string, true>         $paths    The fields the template is offered.
     * @param array<string, string|null> $resolved What the record resolved.
     * @return array<string, string>
     */
    private static function givenValues(mixed $values, mixed $ownFields, array $paths, array $resolved): array
    {
        if ($values === null) {
            return [];
        }
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            throw DocumentIssueRefused::invalid("'values' must be an object of field paths and their text");
        }
        $declared = $paths;
        foreach (is_array($ownFields) ? $ownFields : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $declared['template.' . $field['key']] = true;
            }
        }
        $out = [];
        $unknown = [];
        $conflicts = [];
        foreach ($values as $path => $value) {
            $path = (string) $path;
            if (!isset($declared[$path])) {
                if (count($unknown) < self::FIELDS_REPORTED) {
                    $unknown[] = $path;
                }
                continue;
            }
            if (($resolved[$path] ?? null) !== null && $resolved[$path] !== '') {
                $conflicts[] = $path;
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > 1000) {
                throw DocumentIssueRefused::invalid("'values." . $path . "' must be text of at most 1000 characters");
            }
            $out[$path] = $value;
        }
        if ($unknown !== []) {
            throw DocumentIssueRefused::invalid('These are not fields of this template: ' . implode(', ', $unknown), ['unknown' => $unknown]);
        }
        if ($conflicts !== []) {
            throw new DocumentIssueRefused(
                'field_conflict',
                'These fields come from the record and can\'t be given a value: ' . implode(', ', array_slice($conflicts, 0, self::FIELDS_REPORTED)),
                422,
                ['code' => 'field_conflict', 'conflicts' => $conflicts],
            );
        }

        return $out;
    }

    /**
     * "type:id" as [type, id], or null. A workspace plugin's type keeps its
     * plugin's casing and can be 129 characters (`CakeOrders.order`, WHIT-742).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseRecord(mixed $value): ?array
    {
        if (!is_string($value) || preg_match('/^([A-Za-z0-9._-]{1,160}):([A-Za-z0-9._-]{1,64})$/', $value, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }
}
