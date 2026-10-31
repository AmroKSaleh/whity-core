# Document automation: plugins and integrations create documents from templates (grounding pass)

Status: **approved by the owner on 31 Oct 2026** (WHIT-740), with the answers in section 6. Whity Render, which makes every PDF here, is a closed, paid service (owner, 31 Oct 2026): core resolves templates, merge data and themes and calls it; batches and label sheets spend its meters. A sibling of `DOCUMENT-THEMES.md` (WHIT-731); nothing here is built yet. Written 31 Oct 2026 against `origin/develop` at `0b3e2d4e` (M4b merged, M4c not yet started on its branch).

The owner's ask: "we also want an sdk to enable plugins to trigger and create documents from templates etc for custom flows". This document covers how a plugin, an integration or an agent lists and picks templates, creates a document from one with merge-field data, renders it through Whity Render (single, label sheet and CSV batch), and starts from or reacts to document lifecycle events. It designs against `document_sources`, as M4c moves creation, rerender and QR onto them.

## Summary

- **One issuing service.** Today there are three ways to make a stored PDF and they disagree (section 1.2). Everything that issues a document goes through one core service, `DocumentIssuing`, behind `POST /api/documents`, the publishing `render` route and the SDK. Every issued document links to its source and version and gets its QR code.
- **Template slots.** A plugin doesn't hard-code a template id. It declares a slot ("Acme invoice", record type `acme.invoice`), ships a starter for it, and the workspace chooses which published template fills it. This is WHIT-683's "chosen per document type", made concrete.
- **Plugins add field providers.** A plugin's records become merge-field sources through the SDK (today only core can register a `DocumentFieldProvider`).
- **Merge data** is a record reference plus a small map of values. Values fill a template's own fields and anything the record doesn't resolve; they can't overwrite a value the record resolved (no forged totals).
- **Batches are jobs.** Many documents or a large label sheet run as a `document_batches` job with per-item results, progress and refunds of the render meter for failures.
- **Lifecycle events** (`document.issuing` with veto, `document.issued`, `document.rendered`, `document_source.published`, `document_batch.completed`) go through the existing in-process hooks and outbox. Outbound webhooks wait for WHIT-F3 (WHIT-302, WHIT-430).
- **Issuing has its own permission**, `documents:issue`, separate from `documents:render` (preview).
- **Idempotency** at two levels: a permanent per-tenant `origin_key` on documents ("issue the invoice for order 123 once"), and an `Idempotency-Key` header for retried requests.
- **Who acts:** in a request, the person's permissions, never widened by the plugin. In the background, only if the workspace has allowed that plugin to issue documents without a person, only for its own slots, and recorded as the plugin acting for the person who caused the event when there is one.

## 1. What exists today

### 1.1 Templates and merge data

- Templates are `document_sources` rows of kind `template`, `letter`, `report` or `label`; `GET /api/document-sources?kind=` filters them. Only the published version renders (`POST …/render` on a source with nothing published is 409).
- Merge values come from `DocumentFieldRegistry::resolve()` (`src/Core/Document/Fields/DocumentFieldRegistry.php:119`), which merges the record's provider with the shared providers. Only core providers are registered (`public/index.php:3105-3108`: IssuedDocument, Company, Today). **Plugins can't add a provider**, and `DocumentFieldProvider` isn't in the SDK.
- A record is addressed as `type:id`. A template's own fields (`template.<key>`, migration 163) can only be filled from a label CSV today.

### 1.2 Three ways to make a stored PDF

| Path | Links to | QR | Merge data | Notes |
| --- | --- | --- | --- | --- |
| `POST /api/documents` (`DocumentsApiHandler::create`, line 270) | old `document_templates` by `document_template_id` | yes | `dataRows` (flat string maps) | Raises the record, mints QR, then tries to render; always 201 with `render.{attempted, stored, reason}`. **M4c re-points this to sources.** |
| `POST /api/document-sources/{id}/render` with `persist` (`DocumentPublishingApiHandler.php:217-325`) | **nothing** (`document_template_id` null, no source id) | **no** | one record only, no free values | Spends the render meter; without `persist` it streams a PDF and stores nothing |
| SDK `Whity\Sdk\Render\DocumentRenderer::issue(FlowDocument, $title)` (SDK 1.41) | nothing | yes | none: the plugin builds the whole `FlowDocument` | Registered in the HTTP entry point only (`public/index.php:3229-3237`); throws in workers and CLI. This is how plugins "assemble PDFs themselves" (WHIT-683). |

`POST /api/document-sources/{id}/print-labels` (label kind, `{records}` up to 500 or `{csv, columns}` up to 5,000 rows and 2 MB, parsed by `LabelCsv`) streams one PDF and writes **no** `documents` row; the CSV isn't kept.

`documents` (migration 108) has no status by design (lifecycle comes from routing), no number (WHIT-684; `SequenceAllocator` exists in the SDK), and no source columns.

### 1.3 Events, jobs, identity

- Events: `HookManager::dispatch()` for in-request listeners and `dispatchAsync()` for the outbox (`domain_events` + `event_outbox`, relayed by `events:relay`, at-least-once). Names are `entity.verb` and `entity.verb.async`. The only document events are `document.routed` and `document.route_acted`. Plugin listener errors are swallowed by `wrapHookCallback`, so a failing `.async` plugin listener is never retried. The relay doesn't restore the event's `actor_user_id` into `AuditContext`.
- Outbound webhooks don't exist (ADR 0008 defers them; WHIT-302, WHIT-430). Inbound plugin webhooks exist (`/api/<slug>/webhooks/<name>`, signature checked by the plugin).
- Jobs: `jobs` table with `idempotency_key` (unique per tenant while live), retries with backoff, `progress` column that nothing writes before completion. Plugins declare jobs (`PluginJobsInterface`) but have no SDK way to dispatch one (`\Whity\app(QueueService::class)` is a core class). No render runs as a job.
- Identity: plugin code acts with the calling profile's permissions; there's no plugin actor and no actor kind in `audit_log`; provenance goes in `metadata._origin`. The only service principal is the CLI's.
- No `Idempotency-Key` header support on the API.

## 2. Consistency with M4c

M4c moves creation, rerender and QR onto sources and deletes the old designer. **The one issuing service (3.1) is built inside M4c** (owner, 31 Oct 2026). This design extends M4c's routes rather than adding parallel ones, and M4c lands:

1. `documents.document_source_id` and `documents.document_source_version`, replacing `document_template_id`; rerender prints the pinned version.
2. `POST /api/documents` takes `document_source_id` (published version by default).
3. The QR code is minted at issue time and follows only the tenant's `documents.qr_enabled` (the owner dropped the per-template switch).

As agreed with the M4c session on 31 Oct 2026: M4c's `SourceDocumentIssuer` becomes `src/Core/Document/Issuing/DocumentIssuing` (migration 165 adds the source columns and drops the old designer's tables). A correction (`POST /api/documents/{id}/render`, re-rendering the pinned version with the stored values) goes through it too, firing `document.rendered` only and skipping the `origin_key` check. "Edit before issuing" is `POST /api/document-sources/{id}/drafts` (3.4), a personal letter from the template's published version.

4. `DocumentIssuing` (3.1) with `origin_key`, the `documents:issue` permission and the `document.*` events, used by `POST /api/documents`, render with `persist` and the SDK's `DocumentRenderer::issue()`.

The details are agreed with the editor session running M4c; slots, batches, idempotency headers and background issuing follow after M4c.

## 3. Design

### 3.1 One issuing service

`src/Core/Document/Issuing/DocumentIssuing` (core) with one entry point:

```php
public function issue(IssueRequest $request, ActingAs $actor): IssueResult;
```

`IssueRequest`: template (source id, or slot key), version (default published), merge data, title (default the template's), `originKey` (optional), `render` (default true), `lang` (optional, for bilingual templates). Steps, in one place:

1. Resolve the template: slot binding → source id → published version. 409 if unpublished or archived.
2. Check access: the actor can read the template (`DocumentAccessPolicy`) and holds `documents:issue`; for a plugin in the background, the grant in 3.7.
3. Check `originKey`: if a document with that key exists, return it (200, `replayed: true`), no second render.
4. Resolve merge data (3.3); 422 listing missing required and fiscal fields (E.27).
5. Dispatch `document.issuing` (sync, veto allowed).
6. Raise the record (source id and version, title, values, origin key, actor columns), mint the QR, allocate the number when WHIT-684 lands.
7. Render through Whity Render with the version's frozen theme (`DOCUMENT-THEMES.md` 4.3) and store the artifact. Spend one render unit; refund on failure.
8. Dispatch `document.issued` (and `document.rendered` when bytes were stored), sync and `.async`, after commit.

`POST /api/documents`, `POST /api/document-sources/{id}/render` with `persist`, the SDK and the batch job all call it. The render route without `persist` stays a preview that stores nothing. The SDK's `DocumentRenderer::issue(FlowDocument)` is **deprecated** (owner, 31 Oct 2026, no-legacy rule): from M4c it routes through `DocumentIssuing` (steps 3, 5, 6 and 8, with no source), is marked `@deprecated` in SDK 1.47.0 with a pointer to `Documents::issue()`, logs a deprecation warning per plugin, and is **removed in SDK 2.0.0**. `DocumentRenderer::render()` (an unstored preview) stays.

### 3.2 Template slots

A slot is a named place a plugin (or core) needs a template.

| Field | Example | Notes |
| --- | --- | --- |
| `key` | `acme_invoicing.invoice` | `<plugin>.<key>`; core slots use `core.<key>` |
| `name`, `nameAr` | "Invoice", "فاتورة" | Shown in the binding screen |
| `recordType` | `acme.invoice` | Must be a record type a field provider serves |
| `kinds` | `template`, `label` | Which document kinds can fill it |
| `starter` | `acme_invoicing.invoice_standard` | The plugin's starter (WHIT-687), seeded as a `document_sources` row with `starter_key`; the default binding |
| `numbering` | `per_year` · `none` | When WHIT-684 lands |

Bindings are tenant settings (`documents.slot_bindings`, a map of slot key → source id), set by holders of `documents:publish`. The binding API refuses a template whose record type or kind doesn't match, or which isn't published. An unbound slot uses its starter; a slot whose plugin is off can't be issued from (409 naming the plugin), matching E.19.

### 3.3 Merge data

```json
{
  "record": "acme.invoice:4812",
  "values": { "template.note": "Thank you for your order", "customer.po_number": "PO-77" },
  "rows":   { "lines": [ { "item": "Sourdough", "qty": "12", "amount": "18.000" } ] }
}
```

- `record` resolves through the field registry, with the record's own read permission checked for the actor.
- `values` may fill a template's own fields (`template.*`) and any field path the record didn't resolve. A value for a path the record **did** resolve is a 422 `field_conflict`, so a caller can't overwrite an amount, a tax number or any other fiscal field.
- `rows` fills repeating table fields when the template has them (the repeater model is part of WHIT-593; until then, 422 if sent).
- Every path must exist in the template's field set; unknown paths are a 422 listing them (as `POST /api/documents` does today).
- What is stored in `documents.variable_data` is the resolved set that printed, so a reprint shows what was printed.

### 3.4 REST API (extending M4c's routes)

| Route | Permission | Change |
| --- | --- | --- |
| `GET /api/document-sources?kind=&recordType=&slot=&published=true` | `documents:read` | Adds `recordType`, `slot` and `published` filters: "templates I can issue for this record" |
| `GET /api/document-sources/{id}/fields` | `documents:read` | The template's field set with required and fiscal flags, own fields and repeaters, so a caller can build merge data without the editor's picker |
| `GET /api/document-template-slots`, `PUT /api/document-template-slots/{key}` | `documents:read`, `documents:publish` | List slots with their binding; bind a template (If-Match on the settings version) |
| `POST /api/documents` | `documents:issue` | Body gains `slot` (instead of a source id), `record`, `values`, `rows`, `origin_key`; `dataRows` and `document_template_id` go (no-legacy). Honours `Idempotency-Key`. Response as today plus `replayed` |
| `POST /api/document-sources/{id}/drafts` | `documents:write` | "Start from a template": a new `letter` (or the template's kind) `document_sources` row for a person to edit before issuing (WHIT-679), with fields filled from the merge data or left as fields |
| `POST /api/document-sources/{id}/render` | `documents:render`; `documents:issue` with `persist` | Preview only when `persist` is false; `persist: true` becomes a call to `DocumentIssuing` (QR and source link included) |
| `POST /api/document-batches` | `documents:issue` | 202 with the batch (3.5). Honours `Idempotency-Key` |
| `GET /api/document-batches`, `GET /api/document-batches/{id}`, `GET …/{id}/items` | `documents:read` | Status, progress, per-item results |
| `POST /api/document-batches/{id}/cancel` | `documents:issue` | Stops pending items; finished items stay |
| `POST /api/document-files` (or the existing source files route) | `documents:issue` | Upload a CSV for a batch; 2 MB and 5,000 rows as today |

Everything is declared in `CoreApiSchemas` with new components (`IssueDocumentRequest`, `MergeData`, `DocumentTemplateSlot`, `DocumentBatch`, `DocumentBatchItem`, `DocumentFieldSet`), `openapi.json` and both typed clients regenerated, and every body property described (WHIT-711 guard).

### 3.5 Batches and label sheets

Tables (next migrations after M4c's; tenant-scoped, in `CoreTables.php` and `CrossTenantRejectionRealEngineTest`):

- `document_batches`: `source_id`, `source_version`, `slot` null, `mode` (`documents` · `labels`), `input` (`records` · `csv`), `csv_file_id` null, `columns` JSONB, `shared_values` JSONB, `total`, `done`, `failed`, `status` (`queued` · `running` · `completed` · `completed_with_errors` · `failed` · `cancelled`), `job_id`, `origin_key` null, `output_document_id` null, actor columns, timestamps.
- `document_batch_items`: `batch_id`, `position`, `record` null, `values` JSONB, `status` (`pending` · `issued` · `failed` · `skipped`), `document_id` null, `error` (typed code plus field list), `attempts`.

Behaviour:

- `mode: documents` issues one document per item through `DocumentIssuing`, each with its own QR. Each item's origin key is `<batch origin key>:<position>` when the batch has one, so a retried or redelivered job never issues an item twice.
- `mode: labels` (E.43) renders one label sheet PDF from all items and stores it as one issued document (the sheet), with the CSV kept as a `document_files` row so the sheet can be reprinted. Today's synchronous `print-labels` stays for small runs (≤ 500 records) as a preview; anything stored goes through a batch.
- Validation runs up front for CSV and records: the whole batch is refused with the first 50 problems (as today) unless `skipInvalid: true`, which marks those items `skipped`.
- The render meter is reserved for `total` units when the batch starts and refunded for failed, skipped and cancelled items. If the reservation fails, 429 before anything runs.
- The job (`documents:batch`, core) processes items in chunks, writes `done` and `failed` after each chunk, and sets `jobs.progress`. That needs a small addition to the job runtime: `JobContext::progress(int)` exposed to handlers (SDK too).
- **`SdkDocumentRenderer` and the issuing service must be registered in the worker and relay bootstrap**, not only in `public/index.php`; today they throw outside HTTP.
- When the batch ends: `document_batch.completed` (or `.failed`), and a notification to the person who started it.

### 3.6 Events

| Event | When | Sync veto | Payload (ids only, no merge values) |
| --- | --- | --- | --- |
| `document.issuing` | Before the record is raised | yes (`HookVetoException` → 409 with the plugin's message) | `source_id`, `version`, `slot`, `record`, `origin_key`, `actor` |
| `document.issued` (+ `.async`) | After commit | no | `id`, `source_id`, `version`, `slot`, `record`, `origin_key`, `batch_id`, `actor`, `on_behalf_of` |
| `document.rendered` (+ `.async`) | An artifact was stored (first render or rerender) | no | `id`, `artifact_id`, `pages` |
| `document.qr_revoked` (+ `.async`) | QR revoked | no | `id` |
| `document_source.published` (+ `.async`) | A template was published | no | `id`, `version`, `kind`, `slots` bound to it |
| `document_batch.completed` / `.failed` (+ `.async`) | A batch ended | no | `id`, `total`, `done`, `failed`, `output_document_id` |

- Constants in `sdk/src/Hooks/Events.php` so plugins subscribe without string typos; payloads listed in the event catalogue when WHIT-167 lands JSON Schemas.
- Payloads carry ids, not values: a listener reads the document through the API or SDK with its own permissions, so an event never leaks fields to a plugin that couldn't read them.
- **Reacting reliably.** Because plugin `.async` listener errors are swallowed, the guidance (and the SDK docblock) is: a listener that must not lose work dispatches a job with an idempotency key derived from the event id, and does the work there. The SDK gains `Jobs::dispatch()` for this (today plugins can only reach the core `QueueService`).
- **Starting a flow from an event.** A plugin listens to its own event (`acme_invoicing:order.paid.async`) and calls `Documents::issue()` with `originKey: "order:<id>:invoice"`. Redelivery returns the same document.
- **External flows** (n8n, Zapier, another system): the same events become webhook topics when WHIT-F3 (WHIT-302 and WHIT-430) builds outbound webhooks, which the owner chose to wait for; nothing document-specific is needed beyond marking these events as external in the registry. Until then, external systems poll `GET /api/documents?updated_since=` or use MCP.

### 3.7 Who acts, permissions and scopes

| Context | Whose rights | Recorded as |
| --- | --- | --- |
| A person in a plugin screen (plugin route handler) | The person's: `documents:issue`, the template's visibility, the record's read permission. The plugin can't widen them. | The person; `_origin: plugin:<slug>` in audit metadata |
| A person's API token or MCP client | The token's profile, narrowed by MCP scopes | The person (or agent, once actor columns exist) |
| Background: relay listener, job, schedule | Only if the workspace allowed the plugin to issue documents without a person (`documents.background_issuing`, a per-plugin allow-list set by holders of `documents:publish`), only for **the plugin's own slots**, and only templates bound to them | Actor kind `system`, `_origin: plugin:<slug>`, `on_behalf_of` the profile that caused the triggering event when the event has one |

- Plugins declare the capability (`PluginDocumentsInterface::issuesInBackground(): bool`); it's off for the tenant until an admin allows it, shown on the plugin's page with the slots it covers.
- The relay starts restoring `actor_user_id` from `domain_events` into `AuditContext` so `on_behalf_of` is known (a small fix in `EventRelay`).
- Actor columns (`actor_kind`, `actor_profile_id`, `agent_principal_id`, `on_behalf_of_profile_id`) are the ones IMPLEMENTATION-NOTES 3.1 already proposes for M6; this work adds them to `documents` and `document_batches` first.
- One new permission, **`documents:issue`** (owner, 31 Oct 2026): create issued documents and batches. `documents:render` stays for previews. Grant migration in the 156 pattern to roles that hold `documents:render` today, so nobody loses what they can do; built in M4c with the issuing service. The background allow-list is managed by `documents:publish` holders (owner, 31 Oct 2026).
- Quotas: every issued document and every batch item spends the existing render meters (`DOCUMENTS_RENDER_PER_DAY` and `PER_MONTH`).

### 3.8 Idempotency

- **`documents.origin_key`** VARCHAR(160), unique per tenant where not null. Permanent business idempotency: "this order's invoice" exists once. A second issue with the same key returns the existing document (200, `replayed: true`) if the template and record match, and 409 `origin_key_conflict` if they don't.
- **`Idempotency-Key` header** on `POST /api/documents`, `POST /api/document-batches` and `POST …/drafts`: a general middleware with an `idempotency_keys` table (tenant, key, route, request hash, status, response, expires after 24 hours). Same key and body → the stored response; same key, different body → 422; still running → 409. Useful well beyond documents, so it's built as a core capability any route can opt into in its schema (`'idempotent' => true`).
- Batch items and event-driven issues derive their keys as above, so at-least-once delivery never double-issues.

### 3.9 Plugin SDK

PHP, additive (`whity/plugin-sdk` minor):

```php
interface PluginDocumentsInterface
{
    /** @return list<TemplateSlot> */
    public function getTemplateSlots(): array;

    /** @return list<DocumentFieldProvider> (SDK interface, mirrors core's) */
    public function getDocumentFieldProviders(): array;

    /** @return list<StarterDefinition> starters seeded per tenant (WHIT-687) */
    public function getDocumentStarters(): array;

    public function issuesInBackground(): bool;
}

interface Documents   // resolved with \Whity\app(Documents::class) in HTTP, jobs and the relay
{
    /** @return list<TemplateSummary> */
    public function templates(TemplateQuery $query): array;      // by kind, recordType, slot; published only
    public function fields(TemplateRef $template): FieldSet;
    public function preview(TemplateRef $template, MergeData $data): RenderedDocument; // not stored
    public function issue(IssueRequest $request): IssuedDocument;
    public function startDraft(TemplateRef $template, MergeData $data, ?string $title = null): DraftRef;
    public function batch(BatchRequest $request): BatchRef;
    public function batchStatus(string $batchId): BatchStatus;
}
```

- `TemplateRef` is `TemplateRef::slot('acme_invoicing.invoice')` or `TemplateRef::source(42)`; plugins should use slots so the workspace can swap the template.
- Typed errors (`TemplateNotPublished`, `MissingFields`, `FieldConflict`, `IssuingVetoed`, `QuotaExceeded`, `PluginNotAllowedInBackground`) instead of HTTP codes.
- `Jobs::dispatch(name, payload, idempotencyKey)` and `JobContext::progress()` come with it (3.5, 3.6).
- Loader validation: slots must name a record type served by a provider the plugin or core registers; a bad slot is dropped with a logged reason and the plugin keeps loading. Covered by the conformance kit (WHIT-272) when it lands.
- Front end: a `TemplatePicker` in `packages/ui` (kit-first rule) for plugin screens: templates for a record type, with preview. The typed API client covers the routes.

### 3.10 MCP and agents

- Read routes (templates, fields, slots, batches, preview values) derive as tools with read-only annotations.
- Agents follow the register's scope grant (owner, 31 Oct 2026; `MCP-TOOL-SURFACE.md` 5.11): an `ai-agent` principal acts for the person who granted it, each permission is Off, Ask first or Allowed, and it never exceeds that person's RBAC. Ask first stores an `agent_proposals` row that runs for that person when approved (WHIT-753, WHIT-784). Every write records its actor in the four actor columns. **Issuing can't be undone**: an issued document is numbered, QR-coded and, for JoFotara invoices, reported, so it can only be corrected with a credit note. So `POST /api/documents` and batches are always Ask first for agents, even if a person sets the permission to Allowed (confirmed: no undo window means always Ask first). M6 adds an authored `document_issue_propose` tool.
- `POST …/drafts` is safe for agents in the sense of E.4 only if the draft is theirs to suggest into; proposed: agents may start a draft in **personal** scope for the person they act for, recorded with both.
- MCP token scopes narrow by permission name as today; no new scope mechanism.
- `agentPolicy` is a **ceiling** on top of the grant: `allow` lets the grant decide, `propose` always means Ask first, `suggest-only` (E.4) and `refuse` as named; enforced at the route, so REST and MCP agree. Here: `POST /api/documents`, batches and slot binding are `propose` (a person could otherwise set those permissions to Allowed); `POST …/drafts` is `allow` within the personal-scope rule above. Reads go in the `core.documents.automation` toolset (visibility `discoverable`), reached on the bare `/mcp` through `search_operations` and `call_operation`; `get_api_documents` and `get_api_documents_id` are among the always-on core tools.
- Plugins contribute slots, field providers and starters as data; they don't add MCP tools for documents. Core's tools expose what they declare.

### 3.11 Declarative use (no-code builder)

The no-code plugin builder (`WHITY-BUILDER.md`, separate session) needs three things, all covered:

- **Declarative data.** Slots and starters have a canonical JSON form validated by JSON Schema, loadable from `resources/document-slots/*.json` and `resources/document-starters/*.json` without plugin PHP. A starter is a `WhityDocument` (plus `slot` and `theme.ref`). Field providers are the exception: they read records, so a builder-made plugin gets them from the records it declares (its data types), not from code it writes.
- **A render step in a flow.** An automation step "issue from slot X for this record" is a call to `DocumentIssuing` with merge data and an `originKey` derived from the flow run and step, under the acting principal's permissions; in the background it needs the allow-list in 3.7.
- **Export.** A workspace template exports as starter JSON (blocks, page, own fields, theme ref, slot) and a theme as theme JSON, so builder-made pieces move into a code plugin unchanged.

## 4. Phasing

| Phase | What | Depends on |
| --- | --- | --- |
| A | `DocumentIssuing`, source link and QR on every persisted render, `origin_key`, `documents:issue`, `document.*` events, `DocumentRenderer::issue()` deprecated | **Inside M4c** |
| B | SDK `Documents` and `PluginDocumentsInterface` (slots, field providers, starters), slot bindings, worker registration | A |
| C | Batches and stored label sheets, job progress, CSV kept | A |
| D | `Idempotency-Key` middleware | none (general) |
| E | Background issuing grant, actor columns on documents, relay restores the actor | A; aligns with M6's actor columns |
| F | Outbound webhooks for these events | WHIT-F3 (WHIT-302, WHIT-430); not designed here |

## 5. Risks

- **Two issuing paths survive.** If M4c re-points `POST /api/documents` but leaves `render … persist` separate, issued documents keep diverging (no QR, no source). Phase A exists to close that; better if M4c does it.
- **Background issuing is a privilege.** A plugin issuing numbered, QR-verified documents without a person is powerful; hence the explicit per-plugin allow-list, own-slots-only rule and actor recording.
- **Batches and Whity Render capacity.** 5,000 items are 5,000 renders; chunking and the meters bound it, and render-service concurrency needs a ceiling per tenant.
- **Swallowed listener errors** make "react to an event" lossy unless plugins follow the job pattern; the SDK should make the job pattern the easy one.
- **Field conflicts** need a precise list of which fields a record resolved; providers must report resolved paths, not just values.

## 6. Owner's answers (31 Oct 2026)

| Q | Answer | What it changes |
| --- | --- | --- |
| 1 | Model approved: one issuing service, template slots with workspace bindings, plugin field providers, merge data can't overwrite what the record resolved | As designed |
| 2 | The one issuing service goes **inside M4c** | Section 2, phase A; coordinated with the editor session |
| 3 | **Deprecate, then remove** `DocumentRenderer::issue(FlowDocument)`; route it through the issuing service meanwhile | Deprecated in SDK 1.47.0, removed in SDK 2.0.0 (3.1) |
| 4 | Background issuing: a per-plugin allow-list managed by `documents:publish` holders | As designed (3.7) |
| 5 | Issuing gets its own **`documents:issue`** permission, separate from `documents:render` | Sections 3.1, 3.4, 3.7 |
| 6 | Outbound webhooks **wait for WHIT-F3** (WHIT-302, WHIT-430); in-process document events until then | Section 3.6, phase F |
| 7 | Label-sheet batches are **stored** as issued documents, keeping the CSV | As designed (3.5) |
| 8 | Answered by the MCP agent decisions (31 Oct 2026): an operation with no undo window, such as issuing a numbered document, is **always Ask first** for agents. New grants start with reads Allowed and writes Ask first; proposals expire after 7 days and only the granting person approves them; no agent credentials are issued until proposals exist | `agentPolicy: propose` stays on issuing, batches and slot binding (3.10) |
