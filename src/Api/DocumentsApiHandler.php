<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentOrder;
use Whity\Core\Document\Organizer\DocumentSortField;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentViewContext;
use Whity\Core\Document\Organizer\DocumentViewPresenter;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssuing;
use Whity\Core\Document\Issuing\DocumentOriginKeyConflictException;
use Whity\Core\Document\Issuing\IssueRequest;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Core\Ou\OuSubtree;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\RecordSectionRequirement;
use Whity\Core\RBAC\RecordSectionResolver;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Storage\StorageException;

/**
 * Issued documents (#947 item 1) and the organizer that browses them
 * (#947 item 5, via #978):
 *
 *   POST /api/documents                                    (documents:render)
 *   GET  /api/documents                                    (documents:read)
 *   GET  /api/documents/views                              (documents:read)
 *   GET  /api/documents/{id}                               (documents:read)
 *   GET  /api/documents/{id}/content                       (documents:read)
 *   GET  /api/documents/{id}/artifacts/{artifactId}/content (documents:read)
 *   POST /api/documents/{id}/render                        (documents:render)
 *
 * A document is the record; its artifacts are the immutable files that were
 * issued from it. Every route here is tenant-scoped by the repositories and
 * row-filtered by {@see DocumentVisibilityPolicy} on top — the route
 * permission is the baseline, never the whole answer.
 *
 * THE BROWSER IS A QUERY SURFACE, NOT A TREE (#978)
 * -------------------------------------------------
 * When item 1 shipped, this list was deliberately the plain one and this
 * docblock said so: inventing folder filters before the facts they read existed
 * would have left item 5 a half-shaped surface to unpick. #978 is item 5, and
 * the shape it arrives with is the one that argument implied — the list is
 * unchanged for a caller who names no view, and a `?view=` selects a folder from
 * {@see DocumentViewRegistry}.
 *
 * A folder is a QUERY. Nothing stores where a document lives, because a document
 * raised centrally and needed by fifteen units has no single home and any stored
 * answer has to be maintained as the organisation changes. The only stored thing
 * is a person's own filing — collections, migration 114 — which claims nothing
 * about the document.
 *
 * {@see views()} is what makes that honest from the outside: it returns the
 * folders this installation can actually COMPUTE. All six of item 5's are built,
 * and three of them — "awaiting me", "acted on by me", "passed through my unit"
 * — are absent on an installation that has not run migration 112, because the
 * routing facts they read are not recorded there. Nothing in this handler tests
 * for that; it asks {@see DocumentViewRegistry} what exists and reports it. An
 * empty "Awaiting me" would state *"nothing awaits you"*, which is false and
 * which the reader cannot check, so the folder is not offered at all.
 *
 * What IS here beyond reading is the re-render, because it is the observable
 * half of the immutability guarantee: {@see rerender()} appends a NEW artifact
 * and the previous one stays fetchable at its own URL, forever. A subsystem
 * that promised immutability with no way to supersede anything would never
 * have the promise tested.
 *
 * CREATING ONE (#947 item 1, the half that was missing)
 * ---------------------------------------------------
 * Until now nothing in this API could bring a document into existence. The list
 * was a list, the re-render corrected something that already existed, and the
 * only create path in the whole subsystem was `POST
 * /api/document-templates/{id}/render` with `persist: true` — a document as a
 * SIDE EFFECT of rendering a template, on the template's own resource, and
 * unreachable on a default install because `documents.render_enabled` is false.
 * Every document anybody had ever seen came from the demo seeder writing rows.
 *
 * {@see create()} is the front door: name an editor template (editor M4c),
 * the record it is for and any of the template's own fields, get a document. Four decisions in it are worth stating here
 * because each had a plausible alternative:
 *
 *  1. IT IS GATED ON `documents:render`, and no new slug was minted. Migration
 *     113 already made this exact argument when it chose who may ROUTE a
 *     document: *"`documents:render` is what gates `persist: true` on the render
 *     routes, so a role holding it is precisely a role that can bring a document
 *     into existence"*. That sentence is either true, in which case this route
 *     belongs behind the same slug, or it was wrong then. A `documents:create`
 *     would be a second answer to one question — and, on every existing
 *     install, a slug NOBODY HOLDS, which is a lockout wearing the costume of a
 *     permission check.
 *
 *  2. THE RECORD IS THE DELIVERABLE; THE ARTIFACT IS OPPORTUNISTIC. Rendering is
 *     attempted when the instance can do it and skipped when it cannot, and
 *     either way the document exists. See {@see create()} for why the opposite
 *     (refuse to create anything without bytes) would make this route dead on
 *     every fresh install.
 *
 *  3. THE VALUES ARE PERSISTED, on the row, by migration 118, with the
 *     template's published version (migration 165). They are the only content
 *     an unrendered document has, and a correction months later re-renders
 *     that version with those values rather than whatever the record says now.
 *
 *  4. THE TEMPLATE'S OWN VISIBILITY IS RE-CHECKED, through the editor's own
 *     {@see DocumentSourcesApiHandler::visibleRow()}. Creating from a template
 *     you cannot SEE would make this route a way to read a gated template's
 *     contents by rendering it.
 *
 * NO 403s ON A MISS
 * -----------------
 * A document the caller may not see is reported as missing, not as forbidden.
 * A 403 confirms the id exists, which for an enumerable integer id leaks the
 * shape of the tenant's activity — the same reasoning the template handlers
 * already apply. A view key naming a folder this installation cannot compute is
 * reported the same way, and for the same reason: from outside, it does not
 * exist.
 */
final class DocumentsApiHandler
{
    /**
     * How many unrecognised field names a 422 will list before it stops.
     *
     * A fixed ceiling rather than a setting, deliberately: it is not a limit on
     * what a caller may SEND (the render ceilings in
     * {@see \Whity\Core\Settings\SettingsRegistry} are, and those are
     * per-tenant overridable), it is how long one error message is allowed to
     * get. Nothing about a tenant makes a different answer right, and an
     * operator asked to tune it would have no basis to choose. Ten is past the
     * point where a reader is still reading and well short of a response bigger
     * than the request.
     */
    private const UNKNOWN_FIELDS_REPORTED = 10;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentArtifactRepository $artifacts,
        private readonly DocumentArtifactStore $store,
        private readonly DocumentVisibilityPolicy $visibility,
        // ── editor M4c: documents are issued from editor templates ──────────
        private readonly DocumentSourcesApiHandler $sources,
        private readonly DocumentFieldRegistry $fields,
        private readonly DocumentIssuing $issuing,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
        // ── #978: the organizer ──────────────────────────────────────────────
        private readonly DocumentViewRegistry $views,
        private readonly DocumentSubstrateRegistry $substrates,
        private readonly DocumentCollectionRepository $collections,
        // The connection, for the two OU-tree questions the organizer asks:
        // {@see PrimaryMembershipOu} (which unit is the caller in) and
        // {@see OuSubtree} (what lies beneath a unit). Both arrived with #947
        // item 3 as shared statics precisely so a second copy of either cannot
        // drift from the first, so this handler uses them rather than wrapping
        // them in collaborators of its own.
        private readonly PDO $db,
        // ── #993: the record page's per-region authorization ─────────────────
        //
        // All three OPTIONAL, and all three defaulting to "absent" rather than
        // to a stub, following {@see RolesApiHandler}'s own optional resolver.
        // A host built without them makes no region claims at all — `show()`
        // omits `sections` entirely and the response is byte-identical to what
        // it was before this PR — which is what keeps the two existing handler
        // tests, and any embedder that wires documents without routing, working
        // unchanged. It also means the client's fail-closed rule (no verdicts ⇒
        // every region hidden) is the ONLY reading of an absent key, so a
        // half-wired deployment degrades to "nothing to show" rather than to
        // "everything editable".
        private readonly ?RecordSectionResolver $sectionResolver = null,
        // The two routing reads the record page's record-scoped predicates need.
        // Documents already depend on routing for visibility (a recipient may
        // read what was sent to them — see DocumentVisibilityPolicy), so this is
        // an existing edge, not a new one. Null fails the predicates CLOSED:
        // "you may not act" and "there is no trail" are the safe answers when
        // the host cannot tell.
        private readonly ?RouteEventRepository $routeEvents = null,
        private readonly ?RouteRecipientRepository $routeRecipients = null,
        // ── #1036: the document's QR verification code ───────────────────────
        //
        // OPTIONAL, following the three above and for the same reason: a host
        // that does not wire it has no QR at all — no code minted, no code
        // placed, no `qr` region on the record page and no QR routes registered
        // — which is a TOTAL and therefore visible absence rather than a partial
        // and silent one. The failure #1036 forbids is the switch being ON and
        // the document quietly carrying nothing; that cannot happen here,
        // because a host without this collaborator has no switch to turn on in
        // its documents UI either.
        private readonly ?DocumentQrService $qr = null,
        // ── WHIT-742: issuing by template slot ───────────────────────────────
        // Without it, `slot` in the body is refused and callers name a template.
        private readonly ?TemplateSlotRegistry $slots = null,
    ) {
    }

    /**
     * POST /api/documents — raise a document from an editor template.
     *
     * The body names the template (`document_source_id`), the record it is for
     * (`record`, "type:id", when the template has a record type) and any of its
     * own fields (`values`, {"template.<key>": "…"}). A template's PUBLISHED
     * version is issued (E.9); a letter or report (a one-off copy, "Edit before
     * issuing") is issued as it is now, frozen as a version first. A required
     * field with no value refuses it (E.27).
     *
     * WHAT IT WRITES, AND IN WHICH ORDER
     * ----------------------------------
     *  1. The record, committed: the template and version it came from, a
     *     `template_name` snapshot, title, origin unit, and the values it
     *     printed. This is the deliverable.
     *  2. THEN, only if this instance can render and the caller did not opt out,
     *     the PDF with its verification QR, appended as an artifact.
     *
     * Step 2 failing does not undo step 1, and that is the decision this route
     * turns on. `documents.render_enabled` DEFAULTS TO FALSE: the render tier is
     * a separate headless-Chromium container that a sovereign deployment may
     * never run. If a document could not exist without one, this route would be
     * a 503 on every fresh install.
     *
     * The response therefore reports the render OUTCOME as a sibling of `data`
     * rather than encoding it in the status code. 201 means "the document
     * exists"; `render.stored` means "and here is whether it has bytes yet".
     *
     * THE ONE CASE THAT IS AN ERROR RATHER THAN AN OUTCOME is a caller who
     * EXPLICITLY asked to render (`"render": true`) on an instance that cannot.
     * That is a 503, before anything is written.
     */
    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);

        // A plugin names its slot (WHIT-742) and the workspace decides which
        // template fills it; anyone else names the template.
        $slotKey = $body['slot'] ?? null;
        if ($slotKey !== null) {
            if (array_key_exists('document_source_id', $body)) {
                return Response::error("Send 'slot' or 'document_source_id', not both", 422);
            }
            $source = $this->slotSource($slotKey, $tenantId, $callerId);
            if ($source instanceof Response) {
                return $source;
            }
        } else {
            $sourceId = $body['document_source_id'] ?? null;
            // A string id is accepted: JSON from a form-driven client routinely
            // carries numbers as strings.
            if (is_string($sourceId) && ctype_digit($sourceId)) {
                $sourceId = (int) $sourceId;
            }
            if (!is_int($sourceId) || $sourceId <= 0) {
                return Response::error("'document_source_id' must be the id of a template to issue this document from, or send 'slot'", 422);
            }

            // 404 rather than 403 on a miss: a 403 would confirm the template exists.
            $source = $this->sources->visibleRow($sourceId, $tenantId, $callerId);
            if ($source === null) {
                return Response::error('Template not found', 404);
            }
        }
        if ($source['kind'] === 'template') {
            $version = $this->issuing->published($tenantId, $source);
            if ($version === null) {
                return Response::error('This template has no published version yet', 409);
            }
        } elseif (in_array($source['kind'], ['letter', 'report'], true)) {
            // A one-off document ("Edit before issuing"): what it says now is frozen as a version.
            $version = $this->issuing->freeze($tenantId, $callerId, $source);
            if ($version === null) {
                return Response::error('The document changed while it was being issued; try again', 409);
            }
        } else {
            return Response::error('Only templates, letters and reports issue documents; a label sheet prints labels', 422);
        }

        $hasPermission = $this->permissionResolver($callerId, $tenantId);
        $type = $source['record_type'] !== null ? (string) $source['record_type'] : null;
        $record = self::parseRecord($body['record'] ?? null);
        if ($type !== null && ($record === null || $record[0] !== $type)) {
            return Response::error('record must be "' . $type . ':<id>"', 422);
        }
        $resolved = $this->fields->resolve($tenantId, $callerId, $hasPermission, $type, $record[1] ?? null);
        if ($resolved === null) {
            return Response::error('Record not found', 404);
        }

        $given = self::givenValues(
            $body['values'] ?? null,
            $version['own_fields'] ?? null,
            $this->fields->paths($tenantId, $type),
            $resolved['values'],
        );
        if ($given instanceof Response) {
            return $given;
        }
        $values = $given + $resolved['values'];

        $actor = new ActingAs($tenantId, $callerId, $hasPermission);
        $missing = $this->issuing->missing(new IssueRequest($source, $version, $values, ''), $actor);
        if ($missing !== []) {
            return Response::error('Required fields have no value for this record', 422, ['missing' => $missing]);
        }

        // The caller's own name for what this document is: issuing it again returns it.
        $originKey = $body['origin_key'] ?? null;
        if ($originKey !== null && (!is_string($originKey) || preg_match('/^[A-Za-z0-9:._\/-]{1,160}$/', $originKey) !== 1)) {
            return Response::error("'origin_key' must be 1 to 160 letters, digits or : . _ / -", 422);
        }

        $title = $body['title'] ?? null;
        $title = is_string($title) && trim($title) !== ''
            ? trim($title)
            : ($resolved['label'] !== '' ? $version['title'] . ' · ' . $resolved['label'] : (string) $version['title']);

        $effective = $this->settings->effective($tenantId);
        $renderable = ($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') === 'true';
        // The artifact half only: an operator who capped storage can still raise documents.
        $persistable = ($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') === 'true';

        // Tri-state: true = "I require an artifact"; false = "record only";
        // absent = "render if this instance can".
        $requested = $body['render'] ?? null;
        if ($requested === true && !$renderable) {
            return Response::error('Server-side document rendering is disabled on this instance', 503);
        }
        if ($requested === true && !$persistable) {
            return Response::error('Persisting rendered documents is disabled on this instance', 503);
        }

        try {
            $issued = $this->issuing->issue(new IssueRequest(
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
            return Response::error($e->reason(), 409, ['code' => 'vetoed', 'reason' => 'blocked_by_plugin']);
        } catch (DocumentOriginKeyConflictException $e) {
            return Response::error('This origin key already names another document', 409, ['code' => 'origin_key_conflict', 'document_id' => $e->documentId]);
        } catch (\Throwable $e) {
            error_log('[DocumentsApiHandler] raising the document failed: ' . $e->getMessage());
            return Response::error('The document could not be created', 503);
        }

        // A replay is the document the origin key already named: 200, not 201.
        return Response::json([
            'data' => DocumentPresenter::document($issued['document'], $issued['artifacts']),
            'render' => $issued['render'],
            'replayed' => $issued['replayed'],
        ], $issued['replayed'] ? 200 : 201);
    }

    /**
     * The template bound to a slot, as the caller may see it, or why it
     * can't be issued from (WHIT-742, DOCUMENT-AUTOMATION.md 3.2). A slot
     * whose plugin is off, or that nothing fills, is a 409 the plugin can show;
     * a bound template that no longer fits (unpublished, made personal, a
     * different record type) is a 409 naming why, not a silent fallback.
     *
     * @return array<string, mixed>|Response
     */
    private function slotSource(mixed $key, int $tenantId, int $callerId): array|Response
    {
        if (!is_string($key) || $key === '') {
            return Response::error("'slot' must be a slot key such as bakery.order_confirmation", 422);
        }
        $slot = $this->slots?->find($tenantId, $key);
        if ($slot === null) {
            $plugin = strstr($key, '.', true);

            return Response::error('This slot is unknown here, or its plugin is off', 409, [
                'code' => 'slot_unavailable',
                'plugin' => $plugin !== false ? $plugin : $key,
            ]);
        }
        $effective = $this->settings->effective($tenantId);
        $templateId = SlotBindings::parse((string) ($effective[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS] ?? ''))[$slot->key] ?? null;
        if ($templateId === null) {
            return Response::error('No template is bound to this slot yet', 409, ['code' => 'slot_unbound', 'slot' => $slot->key]);
        }
        // 404 rather than 403 on a miss: a 403 would confirm the template exists.
        $source = $this->sources->visibleRow($templateId, $tenantId, $callerId);
        if ($source === null) {
            return Response::error('Template not found', 404);
        }
        $problem = DocumentTemplateSlotsApiHandler::misfit($slot, $source);
        if ($problem !== null) {
            return Response::error('The template bound to this slot no longer fits it: ' . $problem['message'], 409, [
                'code' => 'slot_misfit',
                'slot' => $slot->key,
                'reason' => $problem['code'],
            ]);
        }

        return $source;
    }

    /**
     * The values a request filled, or why they are refused (merge data,
     * DOCUMENT-AUTOMATION.md 3.3). A value may fill a template's own field
     * (`template.<key>`) or a field the record left empty; a value for a field
     * the record RESOLVED is a 422 `field_conflict`, so a caller can't
     * overwrite an amount, a tax number or any other value the record owns.
     * An unknown name is echoed (up to a few) so a developer can see the typo
     * rather than compare two JSON blobs by eye.
     *
     * @param array<string, true>         $paths    The fields the template is offered.
     * @param array<string, string|null> $resolved What the record resolved.
     * @return array<string, string>|Response
     */
    private static function givenValues(mixed $values, mixed $ownFields, array $paths, array $resolved): array|Response
    {
        if ($values === null) {
            return [];
        }
        if (!is_array($values) || ($values !== [] && array_is_list($values))) {
            return Response::error("'values' must be an object of field paths and their text", 422);
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
                if (count($unknown) < self::UNKNOWN_FIELDS_REPORTED) {
                    $unknown[] = $path;
                }
                continue;
            }
            if (($resolved[$path] ?? null) !== null && $resolved[$path] !== '') {
                $conflicts[] = $path;
                continue;
            }
            if (!is_string($value) || mb_strlen($value) > 1000) {
                return Response::error("'values." . $path . "' must be text of at most 1000 characters", 422);
            }
            $out[$path] = $value;
        }
        if ($unknown !== []) {
            return Response::error('These are not fields of this template: ' . implode(', ', $unknown), 422);
        }
        if ($conflicts !== []) {
            return Response::error(
                'These fields come from the record and can\'t be given a value: ' . implode(', ', array_slice($conflicts, 0, self::UNKNOWN_FIELDS_REPORTED)),
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
    private static function parseRecord(mixed $value): ?array
    {
        if (!is_string($value) || preg_match('/^([A-Za-z0-9._-]{1,160}):([A-Za-z0-9._-]{1,64})$/', $value, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /**
     * GET /api/documents/views — the folders this installation can compute.
     *
     * The response has two halves and they answer two different questions.
     * `views` is what the rail renders, each carrying whether THIS caller can
     * anchor it and, when not, why (#951: disabled with a reason, never
     * hidden). `unavailable_substrates` is what this installation does not
     * record and what would supply it — the answer to "why is there no inbox
     * here", which otherwise has no answer at all from outside.
     *
     * A view with a REQUIRED parameter is a template rather than a folder: the
     * client instantiates it (one rail entry per collection) rather than opening
     * it bare, so it is reported without a caller-level resolution instead of
     * being resolved with a missing parameter and reported unavailable — which
     * would be a true statement about the wrong thing.
     */
    public function views(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $viewContext = $this->viewContext($request, $tenantId, $callerId, null);
        if ($viewContext instanceof Response) {
            return $viewContext;
        }

        $data = [];
        foreach ($this->views->available() as $view) {
            $data[] = DocumentViewPresenter::view(
                $view,
                $view->requiredParameters() === [] ? $view->resolve($viewContext) : null
            );
        }

        return Response::json([
            'data' => $data,
            // The sections, in the order the rail should render them. Sent so a
            // client stops deciding which groups exist and what they are called:
            // it used to admit two names and silently drop everything else, so a
            // folder in a third group reached the payload and never the screen.
            'groups' => array_map(
                static fn ($group): array => DocumentViewPresenter::group($group),
                $this->views->groups()
            ),
            'unavailable_substrates' => array_map(
                static fn ($substrate): array => DocumentViewPresenter::substrate($substrate),
                $this->substrates->unavailable()
            ),
        ]);
    }

    /**
     * GET /api/documents — the caller's visible documents, newest first,
     * optionally narrowed to one of the organizer's folders.
     *
     * `?view=` names a folder, `?ou_id=` anchors the unit ones, `?collection_id=`
     * opens one of the caller's collections and `?q=` filters by title. A
     * request naming none of them is the plain item-1 list it always was.
     */
    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $query = self::query($request);
        $viewKey = self::stringParam($query, 'view') ?? CoreDocumentViews::ALL;

        // Null covers "no such key" AND "registered, but this installation
        // cannot compute it". Both are 404 — from outside, a folder whose facts
        // are not recorded here does not exist, and reporting it any other way
        // invites a client to render it as a real-but-unavailable folder, which
        // is the empty-inbox lie one step removed.
        $view = $this->views->get($viewKey);
        if ($view === null) {
            return Response::error('Unknown document view', 404);
        }

        foreach ($view->requiredParameters() as $required) {
            if (self::stringParam($query, $required) === null) {
                return Response::error("This view requires a '{$required}' parameter", 400);
            }
        }

        // Ownership is established HERE, before any view resolves, and a
        // collection that is not the caller's is reported missing rather than
        // forbidden. Without this check a caller could pass a colleague's
        // collection id and read back which of the documents they can already
        // see that colleague had filed — the row-visibility predicate would
        // still apply, so nothing new becomes readable, but WHO FILED WHAT is
        // itself private and would leak. Collection ids are enumerable
        // integers, so it would leak by walking them.
        $collectionId = self::intParam($query, 'collection_id');
        if ($collectionId !== null
            && $this->collections->findOwned($collectionId, $tenantId, $callerId) === null) {
            return Response::error('Collection not found', 404);
        }

        $viewContext = $this->viewContext($request, $tenantId, $callerId, $collectionId);
        if ($viewContext instanceof Response) {
            return $viewContext;
        }

        $resolution = $view->resolve($viewContext);
        if ($resolution->criteria === null) {
            // The view is real and this caller cannot anchor it. 422, not 404:
            // the folder exists, the client was right to ask, and the reason is
            // about the caller rather than about the view — a 404 here would be
            // indistinguishable from the unbuilt case above.
            return Response::error(
                $resolution->unavailableReason ?? 'This view is not available to you',
                422
            );
        }

        // Resolved ONCE and pushed into the query: see
        // DocumentVisibilityPolicy::restrictToCreator() for why this is a
        // predicate rather than a post-filter over the fetched page. Applied
        // AFTER the view resolves, so a view can never widen it.
        $criteria = $resolution->criteria->withRequestScope(
            $this->visibility->restrictToCreator($callerId, $this->permissionResolver($callerId, $tenantId)),
            self::stringParam($query, 'q')
        );

        // Parsed AFTER the view resolves so a bad `sort` on an unanchorable
        // folder still reports the folder's reason: the caller's first problem
        // is the one worth telling them about, and 422-then-400 would have them
        // fix a sort they cannot use yet.
        $order = self::order($query);
        if ($order instanceof Response) {
            return $order;
        }

        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->documents->countForCriteria($tenantId, $criteria);
        $rows = $this->documents->listForCriteria($tenantId, $criteria, $p->perPage, $p->offset, $order);

        // The artifact list is fetched per document rather than in one join:
        // the join returns a document once per artifact and has to be
        // re-collapsed in PHP, and a page is at most PaginationParams::MAX_PER_PAGE
        // rows.
        //
        // The FILING, by contrast, is one query for the whole page — the star
        // and the "filed in" badge are on every row, so the per-row form is the
        // textbook N+1 that only bites once a tenant has volume.
        $filing = $this->collections->collectionIdsForDocuments(
            $tenantId,
            $callerId,
            array_map(static fn (array $row): int => (int) $row['id'], $rows)
        );
        $starredId = $viewContext->starredCollectionId;

        $data = array_map(
            fn (array $row): array => DocumentPresenter::document(
                $row,
                $this->artifacts->listForDocument((int) $row['id'], $tenantId),
                $filing[(int) $row['id']] ?? [],
                $starredId
            ),
            $rows
        );

        return Response::json([
            'data' => $data,
            'pagination' => $p->meta($total),
            // Echoed so a client rendering a rail can tell which entry is
            // selected without re-parsing its own URL, and so the anchor the
            // server actually used (the caller's own unit, when none was
            // supplied) is visible rather than guessed at.
            'view' => [
                'key' => $view->key,
                'ou_id' => $viewContext->effectiveOuId(),
                'collection_id' => $collectionId,
            ],
            // The order APPLIED, not the order asked for. `direction` has a
            // per-field default (A→Z for text, newest-first for a date), so a
            // client that assumed one would be right half the time and would
            // draw its arrow the wrong way round the rest. `field: null` is the
            // recorded order — see DocumentRepository::orderSql() for why that
            // is not `created_at` under another name.
            'sort' => [
                'field' => $order?->field->value,
                'direction' => $order === null ? 'desc' : $order->direction(),
            ],
        ]);
    }

    /**
     * GET /api/documents/{id} — one document with its full artifact history.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $documentId = (int) $document['id'];
        $filing = $this->collections->collectionIdsForDocuments($tenantId, $callerId, [$documentId]);
        $starred = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );

        $data = DocumentPresenter::document(
            $document,
            $this->artifacts->listForDocument($documentId, $tenantId),
            $filing[$documentId] ?? [],
            $starred === null ? null : (int) $starred['id']
        );

        // #993: the record page's per-region verdicts. Only `show()` carries
        // them — the LIST deliberately does not, and that asymmetry is the same
        // one `collection_ids` already has: a verdict is an answer about ONE
        // record and a caller, and computing 25 of them per page to render a
        // table that gates nothing would be work nobody reads.
        $verdicts = $this->resolveRecordSections($tenantId, $callerId, $document);
        if ($verdicts !== null) {
            $data['sections'] = $verdicts;
        }

        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/documents/{id}/content — the CURRENT artifact's bytes.
     *
     * @param array<string, string> $params
     */
    public function content(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $artifact = $this->artifacts->findLatestForDocument((int) $document['id'], $tenantId);
        if ($artifact === null) {
            // A record with no stored bytes is not a state the issuer can
            // produce (it rolls back rather than leave one), so this is the
            // "restored from a partial backup" case rather than a routine miss.
            return Response::error('Document has no stored content', 404);
        }

        return $this->streamArtifact($document, $artifact);
    }

    /**
     * GET /api/documents/{id}/artifacts/{artifactId}/content — ONE artifact's
     * bytes, superseded or not.
     *
     * This is the route that makes immutability checkable from the outside: a
     * URL handed out in March still resolves to the March bytes in December,
     * and the `checksum_sha256` on the record proves it.
     *
     * @param array<string, string> $params
     */
    public function artifactContent(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $artifactId = (int) ($params['artifactId'] ?? 0);
        // Bound to the document as well as the tenant: an artifact id alone is
        // never a capability, so a guessed id cannot be walked across the
        // tenant's documents.
        $artifact = $this->artifacts->findById($artifactId, (int) $document['id'], $tenantId);
        if ($artifact === null) {
            return Response::error('Artifact not found', 404);
        }

        return $this->streamArtifact($document, $artifact);
    }

    /**
     * POST /api/documents/{id}/render — append a corrected artifact.
     *
     * Re-renders the template VERSION the document was issued from, with the
     * values it printed (editor M4c), and appends the result. Records and
     * charts are read again, for the caller, and frozen into the new PDF
     * (E.16). The document row is untouched, so its id — and every route,
     * recipient and trail event hanging off it — keeps pointing at the same
     * record, and the same verification code goes on it.
     *
     * Refused with 409 when there is nothing to render from: a document issued
     * from the old Document designer (its PDFs stand; issue a new one from the
     * converted template, whose id the error carries), a template since
     * deleted or hidden from the caller, or a document raised by a form or a
     * plugin.
     *
     * @param array<string, string> $params
     */
    public function rerender(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
            return Response::error('Server-side document rendering is disabled on this instance', 503);
        }
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            return Response::error('Persisting rendered documents is disabled on this instance', 503);
        }

        $source = $this->issuedFrom($tenantId, $callerId, $document);
        if ($source === null) {
            if ($document['document_template_id'] !== null || ($document['document_source_id'] ?? null) !== null) {
                return Response::error(
                    'This document was issued from the old Document designer and can\'t be re-rendered; issue a new one from its converted template',
                    409,
                    ['document_source_id' => $document['document_source_id'] ?? null],
                );
            }

            return Response::error('The template this document was issued from is no longer available', 409);
        }

        $outcome = $this->issuing->rerender(new ActingAs($tenantId, $callerId, $this->permissionResolver($callerId, $tenantId)), $source, $document);
        if ($outcome === null) {
            return Response::error('The template version this document was issued from is no longer available', 409);
        }
        if (!$outcome['stored']) {
            return match ($outcome['reason']) {
                'rejected' => Response::error('The template could not be rendered with these values', 422),
                'limit'    => Response::error('The document render limit has been reached', 429),
                'storage_unavailable' => Response::error('Storing the rendered document is temporarily unavailable', 503),
                default    => Response::error('Document rendering is temporarily unavailable', 503),
            };
        }

        // The whole document, so the caller sees the new artifact ALONGSIDE the
        // ones it supersedes rather than in place of them.
        return Response::json([
            'data' => DocumentPresenter::document(
                $document,
                $this->artifacts->listForDocument((int) $document['id'], $tenantId)
            ),
        ], 201);
    }

    /**
     * The editor template a document can be re-rendered from: issued from a
     * published version (not converted from the old designer), and still
     * visible to the caller. Null otherwise.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private function issuedFrom(int $tenantId, int $callerId, array $document): ?array
    {
        $sourceId = $document['document_source_id'] ?? null;
        if (!is_int($sourceId) || ($document['document_source_version'] ?? null) === null) {
            return null;
        }

        return $this->sources->visibleRow($sourceId, $tenantId, $callerId);
    }

    // ── #993: the record page's regions ─────────────────────────────────────

    /**
     * The three regions of the document record page, declared once (#910/#975).
     *
     * A document record answers three different questions and the operator's
     * requirement — *"some parts have permissions, not always everything is
     * allowed"* — is the reason they are three declarations rather than one:
     * reading the document, reading what has happened to it, and being the
     * person it is currently with are separate facts with separate answers.
     *
     * WHY ALL THREE `readPermission`s ARE NULL, which is the decision most worth
     * arguing. It is tempting to gate the trail on `audit:read` and the pending
     * queue on `documents:route`, and both would read as tighter. Both would
     * also be gates with a BYPASS ONE PATH SEGMENT AWAY: `GET /{id}/trail` and
     * `GET /{id}/recipients` are registered on `documents:read`, so a caller
     * refused the region would simply call the route and be served. The
     * resolver's own {@see RecordSectionResolver::mayRead()} docblock names that
     * failure exactly, and #975's fifth invariant is that a region's dedicated
     * route must carry the same gate as the region. Narrowing those two routes
     * is a real policy change — it would take the trail away from a recipient
     * who was sent the document, which is precisely the audience that most needs
     * to see where it has been — and it belongs to whoever owns routing, not to
     * a record page. So `null` here is the honest, documented statement the
     * field is for: *the route that served the record is the only read gate*,
     * and that route is `documents:read` plus {@see DocumentVisibilityPolicy}.
     *
     * The consequence is that a caller who gets a payload at all sees all three
     * regions, and the mechanism does its work on the WRITE side, where the
     * three answers genuinely differ:
     *
     *  - `document`   — `documents:render`, plus a record predicate. Correcting
     *                   an issued document is the one write it HAS.
     *  - `trail`      — no permission (appending a note is open to anyone who
     *                   can read the document — see DocumentRouter::act, where
     *                   `noted` is handled before the recipient check), plus a
     *                   record predicate: there must be a route to append to.
     *  - `recipients` — no permission either. Being a recipient IS the
     *                   authorization, which is a fact about the RECORD and the
     *                   caller, so it is a record predicate and not a slug. This
     *                   is the one region whose verdict a grant cannot change,
     *                   and telling it apart from a permission refusal is what
     *                   {@see RecordSectionResolver::CODE_RECORD} exists for.
     *
     * @return list<RecordSectionRequirement>
     */
    private static function recordSections(): array
    {
        return [
            new RecordSectionRequirement(
                key: 'document',
                readPermission: null,
                writePermission: CorePermissions::DOCUMENTS_ISSUE,
                recordScoped: true,
                deniedReason: 'You can read this document. Issuing a corrected version of it '
                    . 'is not something your account can do.',
            ),
            new RecordSectionRequirement(
                key: 'trail',
                readPermission: null,
                writePermission: null,
                recordScoped: true,
                // No `deniedReason`: with no write permission declared, a
                // PERMISSION refusal is unreachable for this region, and copy
                // for a branch that cannot be taken is copy nobody can check.
                // The record refusal supplies the sentence instead.
            ),
            new RecordSectionRequirement(
                key: 'recipients',
                readPermission: null,
                writePermission: null,
                recordScoped: true,
            ),
            // #1036. READ is null for the reason the three above are: the
            // region's dedicated route (`GET /api/documents/{id}/qr`) is
            // registered on `documents:read` plus DocumentVisibilityPolicy, and
            // declaring a tighter read here would be a gate with a bypass one
            // path segment away.
            //
            // That also settles who sees the SCAN HISTORY, which is a real
            // question rather than a fallout: the audience is exactly the
            // audience for the routing trail, which already names the people who
            // acted on this document. A scan is a strictly smaller disclosure
            // than an act, so giving it a narrower audience would create a
            // second, subtly different visibility rule over the same record —
            // the shape this subsystem argues against everywhere else — for no
            // gain. Anonymous scans name nobody at all, because nothing about
            // them is stored (migration 122).
            //
            // WRITE is `documents:issue`: rotating or withdrawing a code
            // changes what every printed copy in the world asserts, which is the
            // same kind of authority as issuing a corrected version, and it is
            // held by exactly the people who can already do that.
            new RecordSectionRequirement(
                key: 'qr',
                readPermission: null,
                writePermission: CorePermissions::DOCUMENTS_ISSUE,
                recordScoped: true,
                deniedReason: 'You can see this document\'s verification code. Issuing a new one, '
                    . 'or withdrawing it, is not something your account can do.',
            ),
        ];
    }

    /**
     * The audience-safe sentence for each region's RECORD refusal.
     *
     * One per region because the three refusals are three different facts, and
     * {@see RecordSectionResolver::resolve()} takes one sentence per call — see
     * {@see self::resolveRecordSections()} for why that is called three times.
     *
     * Written for the person reading the screen, naming no identifier and no
     * slug: a `record` refusal cannot be fixed by a grant, so there is nothing
     * operator-grade to add (which is why the resolver sends `detail: null` for
     * this code).
     */
    private const RECORD_DENIED_REASONS = [
        'document' => 'The template this document was issued from is no longer available, '
            . 'so no corrected version can be issued. The versions already issued are unaffected.',
        'trail' => 'This document has not been put into circulation, so there is no trail to add to.',
        'recipients' => 'This document is not awaiting you. You are reading it as a record '
            . 'rather than as something to act on.',
        // The SWITCH branch only. `documentCarriesQr()` says no for three
        // different reasons and this sentence is true of one of them, so it is
        // chosen between by {@see self::qrRecordDeniedReason()} rather than read
        // straight out of this table. Blaming the tenant setting on an instance
        // with no APP_URL sends an administrator to a settings page where the
        // switch is already on, with nothing to tell them they are in the wrong
        // place.
        'qr' => 'This document does not carry a verification code, and cannot be given one '
            . 'while QR verification is switched off for this template or this organisation.',
    ];

    /**
     * Why the `qr` region has nothing to change, told apart by CAUSE.
     *
     * The three causes are genuinely different problems with genuinely different
     * fixes, and only one of them is a setting anybody can reach:
     *
     *   - this installation wires no QR service at all — an embedder's build,
     *     and no grant and no setting anywhere would make a code appear;
     *   - the instance has no public address, so a code would encode a link that
     *     leads nowhere — an operator fixes it with APP_URL;
     *   - the switch is off for this template or this organisation.
     *
     * Only consulted when the predicate already said no, so it does not need to
     * re-derive the switch: reaching the last line IS the switch being off.
     *
     * The web client overrides all three with one cause-NEUTRAL sentence and
     * lets its panel name the cause in the reader's own language, because the
     * panel holds `configured` and `enabled` and this method's output is prose.
     * That is not a duplicate: this is what a client without that panel — the
     * desktop shell, an integrator's own UI — is told, and it was previously
     * told something false in two cases out of three.
     */
    private function qrRecordDeniedReason(): string
    {
        if ($this->qr === null) {
            return 'This installation does not issue verification codes, so no document here '
                . 'can carry one.';
        }
        if (!$this->qr->isConfigured()) {
            return 'This installation has not been told its own public address, so a verification '
                . 'code issued here would encode a link that leads nowhere. Nothing about your '
                . 'account or this document is in the way.';
        }

        return self::RECORD_DENIED_REASONS['qr'];
    }

    /**
     * The per-region verdicts for this caller and this document, or null when
     * this host does not resolve regions at all (#910/#975).
     *
     * WHY THIS CALLS `resolve()` ONCE PER REGION. The resolver takes ONE record
     * predicate and ONE record sentence per call, which is right for a role —
     * `roleManageableByTenant()` is a single fact about the row. A document has
     * three independent ones: whether it can be re-issued at all, whether it
     * has a trail, and whether it is with this caller. The alternative was
     * widening `resolve()` to take a predicate MAP, which would have changed a
     * class shared with the roles record page to suit one caller and forced
     * every existing site to pass a map to say the one thing it means. Calling a
     * pure function three times costs three cheap reads and leaves the shared
     * contract alone; the requirement LIST is still declared once, in
     * {@see self::recordSections()}, so "which regions does this record have"
     * remains answerable in one place.
     *
     * The two absences are different and both are meaningful, exactly as in
     * {@see RolesApiHandler}: `null` means this host makes no region claims, so
     * the response carries no `sections` key and behaves as it did before. An
     * empty ARRAY means regions WERE resolved and this caller was granted none —
     * a record with a header and no body.
     *
     * @param array<string, mixed> $document
     * @return array<string, array{state: string, denial: array{code: string, reason: string,
     *         detail: string|null}|null}>|null
     */
    private function resolveRecordSections(
        int $tenantId,
        int $callerId,
        array $document
    ): ?array {
        if ($this->sectionResolver === null) {
            return null;
        }

        // The operator-grade half of a denial names a permission SLUG, and
        // `permissions:read` is the permission that governs seeing permission
        // slugs at all — the same gate {@see RolesApiHandler} chose, for the
        // same reason rather than by analogy. A caller without it being told
        // "this needs 'documents:render'" would be reading the RBAC catalogue
        // through a denial message.
        $includeDetail = $this->roleChecker->hasPermissionForProfile(
            $callerId,
            CorePermissions::PERMISSIONS_READ,
            $tenantId
        );

        $predicates = [
            'document' => $this->documentIsReissuable($tenantId, $callerId, $document),
            'trail' => $this->documentHasTrail($tenantId, $document),
            'recipients' => $this->documentIsAwaiting($tenantId, $callerId, $document),
            'qr' => $this->documentCarriesQr($tenantId, $document),
        ];

        // One region's record sentence is chosen rather than looked up — see
        // qrRecordDeniedReason(). The other three have exactly one cause each.
        $reasons = self::RECORD_DENIED_REASONS;
        $reasons['qr'] = $this->qrRecordDeniedReason();

        $verdicts = [];
        foreach (self::recordSections() as $requirement) {
            $verdicts += $this->sectionResolver->resolve(
                [$requirement],
                $callerId,
                $tenantId,
                $predicates[$requirement->key] ?? false,
                $reasons[$requirement->key] ?? null,
                $includeDetail
            );
        }

        return $verdicts;
    }

    /**
     * Whether a corrected version of this document could be issued at all.
     *
     * The same three conditions {@see self::rerender()} enforces, asked in
     * advance rather than restated: rendering enabled, persisting enabled, and
     * a template still readable by this caller. Deliberately the same three, so
     * the page cannot offer a control the route would refuse with a 409 or a
     * 503 — which is the whole point of a record predicate, and the reason this
     * is not simply `true`.
     *
     * It is NOT a permission question. A caller holding `documents:render` on a
     * document whose template was deleted is refused by the record, and #975
     * separates the two codes precisely because an operator told "you lack a
     * permission" would go looking for a grant that could not have helped.
     *
     * @param array<string, mixed> $document
     */
    private function documentIsReissuable(int $tenantId, int $callerId, array $document): bool
    {
        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
            return false;
        }
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            return false;
        }

        return $this->issuedFrom($tenantId, $callerId, $document) !== null;
    }

    /**
     * Whether this document has a trail to append to.
     *
     * Asked of the EVENTS rather than of the routes, because an event is what a
     * note becomes and `issue()` always appends one — so "has a route" and "has
     * at least one event" cannot disagree, and the events table is the one the
     * region actually renders.
     *
     * This is the predicate behind the empty state the page must not fake. A
     * document nobody has circulated has no trail, and a region that showed an
     * empty list would be stating *"nothing has happened to this"* — true only
     * by accident, and indistinguishable from a trail that failed to load.
     *
     * @param array<string, mixed> $document
     */
    private function documentHasTrail(int $tenantId, array $document): bool
    {
        // Fail closed on an unwired host: "there is no trail" is the safe answer
        // when this deployment cannot tell, and it renders as a region that
        // explains itself rather than as one offering a write that would fail.
        return $this->routeEvents !== null
            && $this->routeEvents->countForDocument((int) $document['id'], $tenantId) > 0;
    }

    /**
     * Whether this document is currently awaiting THIS caller.
     *
     * An OPEN recipient row — `closed_by_event_id IS NULL`, migration 112's
     * partial unique index — for this profile on this document. A closed row is
     * something already done, so counting it would tell a reader the document is
     * with them when they have already dealt with it.
     *
     * Filtered here rather than through a new repository method on purpose: a
     * document's recipient list is bounded by its own fan-out, the region
     * renders the same rows anyway, and adding a query to a routing repository
     * for a documents-side question is how two nearly-identical "is it open"
     * predicates end up in the codebase disagreeing about the same index.
     *
     * @param array<string, mixed> $document
     */
    private function documentIsAwaiting(int $tenantId, int $callerId, array $document): bool
    {
        if ($this->routeRecipients === null) {
            return false;
        }

        foreach ($this->routeRecipients->listForDocument((int) $document['id'], $tenantId) as $recipient) {
            if ($recipient['closed_by_event_id'] === null
                && (int) $recipient['profile_id'] === $callerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the `qr` region has anything to show for this document (#1036).
     *
     * TRUE in two cases, and the second is the one worth stating: the document
     * ALREADY has a code, or it is currently eligible for one. The first matters
     * because a code that was minted while the feature was on must stay visible
     * — and revocable — after somebody switches it off, which is exactly when an
     * operator most wants to reach it. Hiding the region on the switch alone
     * would take the withdraw button away at the moment it is needed.
     *
     * Eligibility re-reads the template, because scope 2 lives in the template's
     * own JSON and a document whose template was deleted
     * (`document_template_id → SET NULL`) inherits only scope 1.
     *
     * @param array<string, mixed> $document
     */
    private function documentCarriesQr(int $tenantId, array $document): bool
    {
        if ($this->qr === null) {
            return false;
        }

        if ($this->qr->active($tenantId, (int) $document['id']) !== null) {
            return true;
        }

        if (!$this->qr->isConfigured()) {
            return false;
        }

        // The tenant decides (the per-template switch went with the old designer).
        return DocumentQrPolicy::enabledForTenant($this->settings->effective($tenantId));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Stream an artifact's bytes.
     *
     * `inline` rather than `attachment`: this is what the viewer (#947 item 4)
     * embeds, and a browser told to download cannot render it in a frame. The
     * filename is still supplied, so an explicit "save as" names the file after
     * the document rather than after its numeric id.
     *
     * A storage read failure is a 503, not a 404: the record is real and the
     * bytes were written, so "it does not exist" would be a lie that sends the
     * caller looking in the wrong place.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $artifact
     */
    private function streamArtifact(array $document, array $artifact): Response
    {
        try {
            $bytes = $this->store->get((string) $artifact['storage_key']);
        } catch (StorageException $e) {
            error_log('[DocumentsApiHandler] reading a stored artifact failed: ' . $e->getMessage());
            return Response::error('Document content is temporarily unavailable', 503);
        }

        $filename = DocumentPresenter::slugify((string) $document['title']) . '.pdf';

        return new Response(200, $bytes, [
            'Content-Type' => (string) $artifact['content_type'],
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            // An artifact is immutable, so its ETag is its content hash and it
            // can be cached hard. `private` because the bytes are RBAC-gated —
            // a shared cache must never serve them to the next caller.
            'ETag' => '"' . $artifact['checksum_sha256'] . '"',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolve the tenant, the caller, and a document the caller may see.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveVisibleDocument(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $document = $this->documents->findById((int) ($params['id'] ?? 0), $tenantId);
        if ($document === null
            || !$this->visibility->canView($document, $callerId, $this->permissionResolver($callerId, $tenantId))) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $document];
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response, as the
     * other document handlers do.
     *
     * @return array{0: int, 1: int}|Response
     */
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

    /**
     * Build the context a view resolves against.
     *
     * The picked anchor is validated to be a unit IN THIS TENANT and otherwise
     * left alone: row visibility is enforced on every result, so anchoring at a
     * unit the caller has no standing in returns what they could already see and
     * nothing more. Refusing it as well would report "forbidden" for a query
     * whose real answer is "nothing", which tells an outsider the unit is busy.
     * An id that is not a unit here is a 400 rather than a silent fallback to
     * the caller's own unit — a folder quietly answering about a different unit
     * than the one on screen is worse than an error.
     *
     * @return DocumentViewContext|Response
     */
    private function viewContext(Request $request, int $tenantId, int $callerId, ?int $collectionId): DocumentViewContext|Response
    {
        $anchorOuId = self::intParam(self::query($request), 'ou_id');
        if ($anchorOuId !== null && !$this->ouExistsInTenant($tenantId, $anchorOuId)) {
            return Response::error('ou_id does not reference an organizational unit in this tenant', 400);
        }

        $starred = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );

        return new DocumentViewContext(
            $tenantId,
            $callerId,
            PrimaryMembershipOu::forProfile($this->db, $tenantId, $callerId),
            $anchorOuId,
            $collectionId,
            $starred === null ? null : (int) $starred['id'],
            // A NARROW, pre-bound subtree capability rather than the connection:
            // the tenant is closed over here, so a view — including a plugin's —
            // can ask what is beneath a unit and cannot ask anything else, and
            // cannot ask it of another tenant. See DocumentViewContext for why
            // handing views raw access would be the wrong shape.
            fn (int $anchor): array => OuSubtree::descendantIds($this->db, $tenantId, [$anchor]),
        );
    }

    /**
     * Whether a picked anchor is a unit in this tenant.
     *
     * A one-line literal read with its tenant predicate spelled out, mirroring
     * {@see \Whity\Api\TwoFactorPoliciesApiHandler}'s. {@see OuSubtree} would
     * answer it as a side effect — an unknown root contributes nothing — but
     * only as SILENCE, and an anchor that is quietly ignored means the folder
     * answers about a different unit than the one on screen. That is worse than
     * an error, so the error is explicit.
     */
    private function ouExistsInTenant(int $tenantId, int $ouId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM organizational_units WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $ouId, ':tenant_id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The request's query parameters.
     *
     * Reads `$_GET` first and overlays anything embedded in the path, mirroring
     * {@see PaginationParams::fromPath()} — a handler test builds a Request with
     * the query string in the path and no superglobal, and a reader that only
     * consulted one of the two would work in exactly one of the two places.
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        $params = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $params[$key] = $value;
            }
        }

        $queryString = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $parsed);
            foreach ($parsed as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * A non-empty string parameter, or null. An empty value is null rather than
     * `''`: `?q=` is what a cleared search box sends, and treating it as a term
     * would filter every title down to the ones containing nothing.
     *
     * @param array<string, string> $query
     */
    private static function stringParam(array $query, string $name): ?string
    {
        $value = trim($query[$name] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * The order the caller asked for, null for the recorded default, or a 400.
     *
     * WHY AN UNKNOWN `sort` IS REFUSED RATHER THAN IGNORED
     * ---------------------------------------------------
     * Ignoring it returns 200 with a page ordered by something else, and the
     * client draws a sort indicator on a column the rows are not sorted by. The
     * reader then trusts a claim the response did not make: "these are the
     * oldest", when they are the most recently recorded. A typo in a hand-built
     * URL, or a client written against a later build that has a fourth sortable
     * column, both land there — and neither leaves a trace anybody can find.
     * So an unknown field is a 400 that NAMES the vocabulary, which is also the
     * only way a caller can discover it: the openapi spec enumerates it, but an
     * error a human reads should not require fetching a schema.
     *
     * WHY `direction` ALONE IS ALSO A 400
     * ----------------------------------
     * `?direction=asc` with no field asks to reverse the default order, and the
     * default is `id DESC` — a surrogate key this vocabulary deliberately does
     * not expose ({@see DocumentSortField}). Honouring it would publish `id` as
     * a sortable column through the back door; ignoring it would silently return
     * newest-first to somebody who asked for oldest-first.
     *
     * @param array<string, string> $query
     */
    private static function order(array $query): DocumentOrder|Response|null
    {
        $field = self::stringParam($query, 'sort');
        $direction = self::stringParam($query, 'direction');

        if ($field === null) {
            if ($direction !== null) {
                return Response::error(
                    "direction needs a sort: name one of '"
                    . implode("', '", array_column(DocumentSortField::cases(), 'value'))
                    . "'",
                    400
                );
            }

            return null;
        }

        $parsed = DocumentSortField::tryFrom($field);
        if ($parsed === null) {
            return Response::error(
                "Documents cannot be sorted by '{$field}'. Sortable: '"
                . implode("', '", array_column(DocumentSortField::cases(), 'value'))
                . "'",
                400
            );
        }

        if ($direction === null) {
            return DocumentOrder::forField($parsed);
        }
        if ($direction !== 'asc' && $direction !== 'desc') {
            return Response::error("direction must be 'asc' or 'desc'", 400);
        }

        return new DocumentOrder($parsed, $direction === 'desc');
    }

    /**
     * @param array<string, string> $query
     */
    private static function intParam(array $query, string $name): ?int
    {
        $value = $query[$name] ?? '';

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
