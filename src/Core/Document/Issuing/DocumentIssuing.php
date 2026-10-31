<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use PDOException;
use Throwable;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Print\WhityDocumentPrinter;
use Whity\Core\Document\Qr\DocumentQrPolicy;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Qr\DocumentQrStamp;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Sdk\Hooks\Events;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Storage\StorageException;

/**
 * Issues documents from editor templates (editor M4c), and corrects them: the
 * ONE issuing service of DOCUMENT-AUTOMATION.md (phase A, the owner's call on
 * 31 Oct). `POST /api/documents`, the editor's render route with `persist`, a
 * correction and the SDK's renderer all issue through it, so every issued
 * document has its source link, its QR code and its events.
 *
 * A document is raised from a template's PUBLISHED version (E.9) and keeps
 * which version and which values it printed (`document_source_id`,
 * `document_source_version`, `variable_data`), so a correction re-renders the
 * same version with the same values; records and charts are read again, for
 * the person correcting it, and frozen into the new PDF (E.16).
 *
 * The record is committed BEFORE the render, as `POST /api/documents` always
 * did: the verification QR (6.7) needs the document's id, and a render that
 * fails must not lose a document somebody just raised. The response says
 * whether a PDF was stored and why not, in the closed vocabulary
 * `declined | disabled | persist_disabled | rejected | unavailable |
 * storage_unavailable | limit`.
 *
 * The verification QR is automatic: the tenant's documents.qr_enabled
 * decides (the owner's call on 31 Oct; the old per-template switch is gone).
 *
 * An ORIGIN KEY is the caller's own name for what a document is ("the
 * invoice for order 123"): issuing under a key that already names a document
 * returns that document (`replayed`), so a retried request or a redelivered
 * event never issues twice; under a key naming a document from another
 * template it is refused (DocumentOriginKeyConflictException).
 *
 * EVENTS (sdk Hooks\Events): `document.issuing` before the record is raised,
 * where a listener may veto (HookVetoException); `document.issued` once the
 * record exists, and `document.rendered` whenever a PDF is stored, each also
 * as `.async` onto the outbox. Payloads carry ids, never field values: a
 * listener reads the document with its own permissions.
 */
final class DocumentIssuing
{
    /** The limits one rendered document spends. */
    public const RENDER_METERS = [
        EntitlementRegistry::DOCUMENTS_RENDER_PER_DAY,
        EntitlementRegistry::DOCUMENTS_RENDER_PER_MONTH,
    ];

    public function __construct(
        private readonly SourceDocumentPrinter $print,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly DocumentIssuer $issuer,
        private readonly SettingsService $settings,
        private readonly ?FlowDocumentRenderer $renderer = null,
        private readonly ?DocumentQrService $qr = null,
        private readonly ?MeterService $meters = null,
        private readonly ?HookManager $hooks = null,
        private readonly ?DocumentRepository $documents = null,
        private readonly ?DocumentArtifactRepository $artifacts = null,
    ) {
    }

    /**
     * A template's published version (E.9), or null before its first publish.
     *
     * @param array<string, mixed> $source The `document_sources` row.
     * @return array<string, mixed>|null
     */
    public function published(int $tenantId, array $source): ?array
    {
        return ($source['published_version'] ?? null) !== null
            ? $this->versions->find((int) $source['id'], $tenantId, (int) $source['published_version'])
            : null;
    }

    /**
     * A one-off document's draft (a letter, a report), frozen as a version so
     * the issued document records exactly what it said and a correction
     * reprints it, with the theme it printed in (editor M4d). A draft that
     * matches its last version, in the same resolved theme, is not frozen
     * again. Null when the draft moved on while this ran.
     *
     * @param array<string, mixed> $source The `document_sources` row, with blocks.
     * @return array<string, mixed>|null
     */
    public function freeze(int $tenantId, int $callerId, array $source): ?array
    {
        $last = $this->published($tenantId, $source);
        $theme = $this->print->theme($tenantId, $source['theme'] ?? null, (string) $source['kind']);
        // Compared without key order: PostgreSQL's JSONB stores object keys in its own order.
        if ($last !== null
            && $last['title'] === (string) $source['title']
            && self::canonical($last['page']) === self::canonical($source['page'])
            && self::canonical($last['blocks']) === self::canonical($source['blocks'])
            && self::canonical($last['own_fields']) === self::canonical($source['own_fields'] ?? [])
            && ($last['theme_resolved']['hash'] ?? null) === $theme['hash']) {
            return $last;
        }
        $version = $this->versions->publish((int) $source['id'], $tenantId, (int) $source['draft_version'], $callerId, $theme);

        return $version === null ? null : $this->versions->find((int) $source['id'], $tenantId, $version);
    }

    private static function canonical(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::canonical(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * The required fields a version uses that these values don't have
     * (E.27); empty when it can be issued.
     *
     * @return list<string>
     */
    public function missing(IssueRequest $request, ActingAs $actor): array
    {
        return $this->printer($actor, $request->source, $request->values)
            ->missingRequired(is_array($request->version['blocks'] ?? null) ? $request->version['blocks'] : []);
    }

    /**
     * Raise a document from a version, then try to render it.
     *
     * @return array{document: array<string, mixed>, artifacts: list<array<string, mixed>>,
     *               render: array{attempted: bool, stored: bool, reason: string|null}, replayed: bool}
     * @throws HookVetoException When a `document.issuing` listener refuses; nothing is written.
     * @throws DocumentOriginKeyConflictException When the origin key names a document from another template.
     * @throws \Throwable When the record itself can't be written.
     */
    public function issue(IssueRequest $request, ActingAs $actor): array
    {
        [$tenantId, $callerId] = [$actor->tenantId, $actor->profileId];
        [$source, $version, $values, $originKey] = [$request->source, $request->version, $request->values, $request->originKey];
        if ($originKey !== null) {
            $existing = $this->documents?->findByOriginKey($originKey, $tenantId);
            if ($existing !== null) {
                return $this->replay($tenantId, $existing, $source);
            }
        }

        $event = [
            'tenant_id' => $tenantId,
            'source_id' => (int) $source['id'],
            'version' => (int) $version['version'],
            'record' => $request->record,
            'origin_key' => $originKey,
            'actor' => $callerId,
        ];
        $this->veto(Events::DOCUMENT_ISSUING, $event);

        try {
            $document = $this->issuer->raise(
                $tenantId,
                $callerId,
                [
                    'name' => (string) $version['title'],
                    'source_id' => (int) $source['id'],
                    'source_version' => (int) $version['version'],
                    'origin_key' => $originKey,
                ],
                mb_substr($request->title, 0, 255),
                // What printed, as the one data row it was raised with; a missing optional value printed blank.
                [array_map(static fn (?string $v): string => $v ?? '', $values)],
            );
        } catch (PDOException $e) {
            // Two requests with the same origin key at once: the unique index
            // let one through, and the other answers with that one's document.
            $existing = $originKey !== null ? $this->documents?->findByOriginKey($originKey, $tenantId) : null;
            if ($existing === null) {
                throw $e;
            }

            return $this->replay($tenantId, $existing, $source);
        }
        $event = ['id' => (int) $document['id']] + $event;
        $this->announce(Events::DOCUMENT_ISSUED, $event);

        // The verification code is minted now, with the record, whether or not
        // a PDF follows: the record page shows it, and a later render (or a
        // correction) prints the same one.
        $this->stamp($tenantId, $callerId, (int) $document['id'], $this->settings->effective($tenantId));

        if ($request->render === false) {
            return ['document' => $document, 'artifacts' => [], 'render' => ['attempted' => false, 'stored' => false, 'reason' => 'declined'], 'replayed' => false];
        }
        [$outcome, $artifact] = $this->renderAndStore($actor, $source, $version, $values, $request->recordLabel, $document);

        return ['document' => $document, 'artifacts' => $artifact !== null ? [$artifact] : [], 'render' => $outcome, 'replayed' => false];
    }

    /**
     * The record for a document a plugin assembled in code (the SDK's
     * deprecated `DocumentRenderer::issue()`): no template and no version, but
     * the same `document.issuing` veto and `document.issued` event as every
     * other issue, and its origin key, so a redelivered event can't issue
     * twice. The caller mints the QR code and renders.
     *
     * @return array{document: array<string, mixed>, replayed: bool}
     * @throws HookVetoException When a listener refuses; nothing is written.
     * @throws DocumentOriginKeyConflictException When the key names a document issued from a template.
     */
    public function raiseBuilt(int $tenantId, ?int $actorId, string $title, ?string $originKey = null): array
    {
        if ($originKey !== null) {
            $existing = $this->documents?->findByOriginKey($originKey, $tenantId);
            if ($existing !== null) {
                if (($existing['document_source_id'] ?? null) !== null) {
                    throw new DocumentOriginKeyConflictException((int) $existing['id']);
                }

                return ['document' => $existing, 'replayed' => true];
            }
        }
        $event = ['tenant_id' => $tenantId, 'source_id' => null, 'version' => null, 'record' => null, 'origin_key' => $originKey, 'actor' => $actorId];
        $this->veto(Events::DOCUMENT_ISSUING, $event);
        $document = $this->issuer->raise($tenantId, $actorId, ['name' => '', 'origin_key' => $originKey], $title);
        $this->announce(Events::DOCUMENT_ISSUED, ['id' => (int) $document['id']] + $event);

        return ['document' => $document, 'replayed' => false];
    }

    /**
     * A PDF stored for a document assembled in code: `document.rendered`.
     *
     * @param array<string, mixed> $artifact
     */
    public function storedBuilt(int $tenantId, int $documentId, array $artifact): void
    {
        $this->announce(Events::DOCUMENT_RENDERED, [
            'id' => $documentId,
            'tenant_id' => $tenantId,
            'artifact_id' => (int) $artifact['id'],
            'source_id' => null,
            'version' => null,
        ]);
    }

    /**
     * The document an origin key already names, as an issue's result.
     *
     * @param array<string, mixed> $existing The `documents` row.
     * @param array<string, mixed> $source   The template asked for now.
     * @return array{document: array<string, mixed>, artifacts: list<array<string, mixed>>,
     *               render: array{attempted: bool, stored: bool, reason: string|null}, replayed: bool}
     */
    private function replay(int $tenantId, array $existing, array $source): array
    {
        if (($existing['document_source_id'] ?? null) !== (int) $source['id']) {
            throw new DocumentOriginKeyConflictException((int) $existing['id']);
        }
        $artifacts = $this->artifacts?->listForDocument((int) $existing['id'], $tenantId) ?? [];

        return [
            'document' => $existing,
            'artifacts' => $artifacts,
            'render' => ['attempted' => false, 'stored' => $artifacts !== [], 'reason' => null],
            'replayed' => true,
        ];
    }

    /**
     * A synchronous event a listener may veto (HookVetoException goes to the
     * caller; any other failure of a listener is logged and doesn't stop it).
     *
     * @param array<string, mixed> $payload
     */
    private function veto(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (HookVetoException $e) {
            throw $e;
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * An event after the fact: synchronously for listeners, and as `.async`
     * onto the outbox for the relay. Two try blocks, so a listener that throws
     * can't cost the durable append (the shape DocumentRouter::broadcast uses).
     *
     * @param array<string, mixed> $payload
     */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }
        try {
            $this->hooks->dispatch($event, $payload);
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] a listener on ' . $event . ' failed: ' . $e->getMessage());
        }
        try {
            $this->hooks->dispatchAsync($event . '.async', $payload);
        } catch (Throwable $e) {
            error_log('[DocumentIssuing] recording ' . $event . '.async failed: ' . $e->getMessage());
        }
    }

    /**
     * Re-render a document from the version and values it was issued with,
     * and append the PDF. The same verification code goes on it, so paper
     * already in circulation stays valid.
     *
     * A correction appends to the same document: it fires `document.rendered`
     * only, never `document.issuing` or `document.issued`, and skips the origin
     * key (DOCUMENT-AUTOMATION.md).
     *
     * @param array<string, mixed>   $source   The `document_sources` row.
     * @param array<string, mixed>   $document The `documents` row, with variable_data.
     * @return array{attempted: bool, stored: bool, reason: string|null}|null Null when the version is gone.
     */
    public function rerender(ActingAs $actor, array $source, array $document): ?array
    {
        $tenantId = $actor->tenantId;
        $version = $this->versions->find((int) $source['id'], $tenantId, (int) $document['document_source_version']);
        if ($version === null) {
            return null;
        }
        $rows = is_array($document['variable_data'] ?? null) ? $document['variable_data'] : [];
        $values = [];
        foreach (is_array($rows[0] ?? null) ? $rows[0] : [] as $path => $value) {
            $values[(string) $path] = is_scalar($value) ? (string) $value : null;
        }

        return $this->renderAndStore($actor, $source, $version, $values, null, $document)[0];
    }

    /**
     * @param array<string, mixed>   $source
     * @param array<string, mixed>   $version
     * @param array<string, ?string> $values
     * @param array<string, mixed>   $document
     * @return array{0: array{attempted: bool, stored: bool, reason: string|null}, 1: array<string, mixed>|null} What happened, and the stored artifact.
     */
    private function renderAndStore(
        ActingAs $actor,
        array $source,
        array $version,
        array $values,
        ?string $recordLabel,
        array $document,
    ): array {
        [$tenantId, $callerId] = [$actor->tenantId, $actor->profileId];
        $effective = $this->settings->effective($tenantId);
        if ($this->renderer === null || !$this->renderer->isEnabled($tenantId)) {
            return [['attempted' => false, 'stored' => false, 'reason' => 'disabled'], null];
        }
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            return [['attempted' => false, 'stored' => false, 'reason' => 'persist_disabled'], null];
        }

        $printer = $this->printer($actor, $source, $values);
        $payload = $this->print->payload(
            $printer,
            $tenantId,
            ['title' => (string) $version['title'], 'lang' => (string) $source['lang'], 'page' => $version['page'], 'blocks' => $version['blocks']],
            $recordLabel,
            $values,
            // The theme frozen with the version: an issue and a correction print alike.
            $this->print->versionTheme($tenantId, $version, (string) $source['kind']),
        );
        $stamp = $this->stamp($tenantId, $callerId, (int) $document['id'], $effective);
        if ($stamp !== null) {
            $payload['content'][] = ['type' => 'qr', 'value' => $stamp->url, 'reference' => $stamp->reference];
        }

        $metered = false;
        if ($this->meters !== null) {
            if (!$this->meters->consume($tenantId, self::RENDER_METERS)->allowed) {
                return [['attempted' => false, 'stored' => false, 'reason' => 'limit'], null];
            }
            $metered = true;
        }
        try {
            $pdf = $this->renderer->render($tenantId, $payload)->bytes;
        } catch (DocumentRenderRejectedException $e) {
            $this->refund($tenantId, $metered);
            error_log('[DocumentIssuing] the render was refused: ' . $e->clientMessage);

            return [['attempted' => true, 'stored' => false, 'reason' => 'rejected'], null];
        } catch (RenderServiceUnavailableException $e) {
            $this->refund($tenantId, $metered);
            error_log('[DocumentIssuing] the render failed: ' . $e->getMessage());

            return [['attempted' => true, 'stored' => false, 'reason' => 'unavailable'], null];
        }

        try {
            $artifact = $this->issuer->appendArtifact($tenantId, $callerId, $document, $pdf);
        } catch (StorageException $e) {
            error_log('[DocumentIssuing] storing the PDF failed: ' . $e->getMessage());

            return [['attempted' => true, 'stored' => false, 'reason' => 'storage_unavailable'], null];
        }

        $this->announce(Events::DOCUMENT_RENDERED, [
            'id' => (int) $document['id'],
            'tenant_id' => $tenantId,
            'artifact_id' => (int) $artifact['id'],
            'source_id' => (int) $source['id'],
            'version' => (int) $version['version'],
        ]);

        return [['attempted' => true, 'stored' => true, 'reason' => null], $artifact];
    }

    /**
     * @param array<string, mixed>   $source
     * @param array<string, ?string> $values
     */
    private function printer(ActingAs $actor, array $source, array $values): WhityDocumentPrinter
    {
        $type = isset($source['record_type']) && $source['record_type'] !== null ? (string) $source['record_type'] : null;

        return $this->print->printer($actor->tenantId, $actor->profileId, $actor->hasPermission, (int) $source['id'], $values, $type);
    }

    /**
     * The verification code, minted once per document and never rotated here.
     * A mint that fails leaves the PDF without a code rather than losing it;
     * the record page says so and the code can be minted there.
     *
     * @param array<string, string> $effective
     */
    private function stamp(int $tenantId, int $callerId, int $documentId, array $effective): ?DocumentQrStamp
    {
        if ($this->qr === null || !DocumentQrPolicy::enabledForTenant($effective)) {
            return null;
        }
        try {
            $token = $this->qr->ensure($tenantId, $documentId, $callerId);
        } catch (\Throwable $e) {
            error_log('[DocumentIssuing] minting the verification code failed: ' . $e->getMessage());

            return null;
        }

        return $token === null ? null : DocumentQrStamp::forToken($this->qr, (string) $token['token']);
    }

    private function refund(int $tenantId, bool $metered): void
    {
        if ($metered && $this->meters !== null) {
            $this->meters->refund($tenantId, self::RENDER_METERS);
        }
    }
}
