<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlot;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Template slots and the workspace's bindings (WHIT-742,
 * DOCUMENT-AUTOMATION.md 3.2 and 3.4).
 *
 * GET /api/document-template-slots (documents:read) lists the slots of the
 * workspace's switched-on plugins, each with the template bound to it. The
 * response's ETag names the whole binding set.
 *
 * PUT /api/document-template-slots/{key} (documents:publish) binds a template,
 * or unbinds with `template_id: null`. If-Match with that ETag is required:
 * 428 without it, 409 when another binding changed since. A template fits a
 * slot when it is published, shared with the workspace (not personal), of a
 * kind the slot takes and for the slot's record type; anything else is a 422
 * with a `code` saying which. The bindings are the tenant setting
 * `documents.slot_bindings`, which the settings PATCH refuses.
 */
final class DocumentTemplateSlotsApiHandler
{
    public function __construct(
        private readonly TemplateSlotRegistry $slots,
        private readonly SettingsService $settings,
        private readonly DocumentSourcesApiHandler $sources,
    ) {
    }

    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $bindings = $this->bindings($tenantId);
        $data = [];
        foreach ($this->slots->forTenant($tenantId) as $slot) {
            $data[] = $this->present($slot, $bindings[$slot->key] ?? null, $tenantId, $callerId);
        }

        return Response::json(['data' => $data])->withHeaders(['ETag' => SlotBindings::etag($bindings)]);
    }

    /** @param array<string, string> $params */
    public function bind(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        if ($tenantId === 0) {
            return Response::error('The system tenant has no template slots; sign in to a workspace to bind its templates', 422);
        }

        $slot = $this->slots->find($tenantId, (string) ($params['key'] ?? ''));
        if ($slot === null) {
            return Response::error('Slot not found, or its plugin is off', 404);
        }

        $bindings = $this->bindings($tenantId);
        $current = SlotBindings::etag($bindings);
        $ifMatch = trim((string) ($request->getHeader('If-Match') ?? ''));
        if ($ifMatch === '') {
            return Response::error('If-Match with the ETag from GET /api/document-template-slots is required', 428);
        }
        if ($ifMatch !== $current) {
            return Response::error('The bindings changed since; read them again', 409, ['code' => 'stale'])
                ->withHeaders(['ETag' => $current]);
        }

        $body = JsonBody::parsed($request);
        if (!array_key_exists('template_id', $body)) {
            return Response::error("'template_id' is required: a template id, or null to unbind", 422);
        }
        $templateId = $body['template_id'];
        if (is_string($templateId) && ctype_digit($templateId)) {
            $templateId = (int) $templateId;
        }

        if ($templateId === null) {
            unset($bindings[$slot->key]);
        } else {
            if (!is_int($templateId) || $templateId <= 0) {
                return Response::error("'template_id' must be a template id, or null to unbind", 422);
            }
            // 404 rather than 403 on a miss: a 403 would confirm the template exists.
            $template = $this->sources->visibleRow($templateId, $tenantId, $callerId);
            if ($template === null) {
                return Response::error('Template not found', 404);
            }
            $problem = self::misfit($slot, $template);
            if ($problem !== null) {
                return Response::error($problem['message'], 422, ['code' => $problem['code']]);
            }
            $bindings[$slot->key] = $templateId;
        }

        $this->settings->setTenant($tenantId, SettingsRegistry::DOCUMENTS_SLOT_BINDINGS, SlotBindings::encode($bindings));
        $saved = $this->bindings($tenantId);

        return Response::json(['data' => $this->present($slot, $saved[$slot->key] ?? null, $tenantId, $callerId)])
            ->withHeaders(['ETag' => SlotBindings::etag($saved)]);
    }

    /**
     * Why a template can't fill a slot, or null when it fits. Checked when it
     * is bound and again when a document is issued from the slot, since the
     * template can change in between.
     *
     * @param array<string, mixed> $template a document_sources row
     * @return array{code: string, message: string}|null
     */
    public static function misfit(TemplateSlot $slot, array $template): ?array
    {
        $kind = (string) ($template['kind'] ?? '');
        if (!in_array($kind, $slot->kinds, true)) {
            return ['code' => 'kind_mismatch', 'message' => "This slot takes a " . implode(' or ', $slot->kinds) . ", not a {$kind}"];
        }
        if (($template['record_type'] ?? null) !== $slot->recordType) {
            return ['code' => 'record_type_mismatch', 'message' => "This slot's documents are for {$slot->recordType}; the template is for "
                . (is_string($template['record_type'] ?? null) ? $template['record_type'] : 'no record')];
        }
        if (($template['scope'] ?? null) === 'personal') {
            return ['code' => 'personal_template', 'message' => 'A personal template can\'t fill a slot; share it with the workspace first'];
        }
        if (($template['published_version'] ?? null) === null) {
            return ['code' => 'unpublished', 'message' => 'The template has no published version yet'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TemplateSlot $slot, ?int $templateId, int $tenantId, int $callerId): array
    {
        $binding = null;
        if ($templateId !== null) {
            $template = $this->sources->visibleRow($templateId, $tenantId, $callerId);
            $problem = $template !== null ? self::misfit($slot, $template) : ['code' => 'template_missing', 'message' => 'The bound template is gone or hidden from you'];
            $binding = [
                'template_id' => $templateId,
                'title' => $template !== null ? (string) $template['title'] : null,
                'published_version' => $template !== null && $template['published_version'] !== null ? (int) $template['published_version'] : null,
                'problem' => $problem,
            ];
        }

        return $slot->toArray() + ['binding' => $binding];
    }

    /** @return array<string, int> */
    private function bindings(int $tenantId): array
    {
        $effective = $this->settings->effective($tenantId);

        return SlotBindings::parse((string) ($effective[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''));
    }

    /** @return array{0: int, 1: int}|Response */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }
}
