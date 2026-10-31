<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\CompanyFieldProvider;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Fields\IssuedDocumentFieldProvider;
use Whity\Core\Document\Fields\TodayFieldProvider;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceClientInterface;
use Whity\Core\Document\Issuing\DocumentIssuing;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The editor-template half of issuing documents (editor M4c), wired the way
 * public/index.php wires it, for the real-engine tests of
 * {@see \Whity\Api\DocumentsApiHandler}.
 *
 * `POST /api/documents` and a correction both go through these three
 * collaborators — the editor's own visibility ({@see DocumentSourcesApiHandler::visibleRow()}),
 * the merge-field registry and {@see DocumentIssuing} — so every test file
 * that raises documents builds them identically here rather than spelling the
 * wiring out five times and letting the copies drift.
 *
 * The field registry carries what production registers in core: issued
 * documents as a record type (`document:<id>`), Company and Today. Today is
 * pinned so a payload can be asserted literally.
 *
 * Templates are written through the repositories, not the editor's routes, so
 * a test can place one at a unit or tag it with a permission without also
 * having to hold documents:publish; publishing is the repository's own
 * draft-to-version copy, the same one the Publish route runs after its check.
 */
final class EditorTemplateFixture
{
    public readonly DocumentSourceRepository $sourceRepo;
    public readonly DocumentSourceVersionRepository $versions;
    public readonly DocumentSourcesApiHandler $sources;
    public readonly DocumentFieldRegistry $fields;
    public readonly DocumentIssuing $issuing;
    /** The issuing service's events; a test listens here. */
    public readonly HookManager $hooks;

    private int $blockSeq = 0;

    public function __construct(
        PDO $pdo,
        SettingsService $settings,
        RoleChecker $roleChecker,
        RenderServiceClientInterface $render,
        DocumentIssuer $issuer,
        DocumentRepository $documents,
        DocumentVisibilityPolicy $visibility,
        ?DocumentQrService $qr = null,
    ) {
        $this->sourceRepo = new DocumentSourceRepository($pdo);
        $this->versions = new DocumentSourceVersionRepository($pdo);
        $this->sources = new DocumentSourcesApiHandler(
            $this->sourceRepo,
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($pdo, new ResourceRoleAssignmentRepository($pdo, new ResourceTypeRegistry())),
        );

        $this->fields = new DocumentFieldRegistry();
        $this->fields->add(new IssuedDocumentFieldProvider($documents, $visibility));
        $this->fields->add(new CompanyFieldProvider($settings));
        $this->fields->add(new TodayFieldProvider(null, static fn (): \DateTimeImmutable => new \DateTimeImmutable('2026-10-29 10:00:00')));

        $this->hooks = new HookManager();
        $this->issuing = new DocumentIssuing(
            new SourceDocumentPrinter($this->fields, new DocumentEmbedRegistry(), $settings),
            $this->versions,
            $issuer,
            $settings,
            new FlowDocumentRenderer($settings, $render),
            $qr,
            null,
            $this->hooks,
            $documents,
            new DocumentArtifactRepository($pdo),
        );
    }

    /**
     * An editor template, as a draft (not published).
     *
     * Defaults to tenant-wide and unplaced, so visibility is not the variable
     * in a test that is not about it.
     *
     * @param list<array<string, mixed>>                                                  $blocks
     * @param array{scope?: string, required_permission?: ?string, owner_ou_id?: ?int,
     *              record_type?: ?string, own_fields?: ?list<array<string, string>>,
     *              kind?: string, page?: ?array<string, mixed>} $opts
     */
    public function draft(int $tenantId, int $createdBy, string $title, array $blocks = [], array $opts = []): int
    {
        return $this->sourceRepo->create($tenantId, [
            'kind'                => $opts['kind'] ?? 'template',
            'title'               => $title,
            'lang'                => 'en',
            'default_view'        => 'page',
            'page'                => $opts['page'] ?? null,
            'record_type'         => $opts['record_type'] ?? null,
            'blocks'              => $blocks !== [] ? $blocks : [$this->para($title)],
            'own_fields'          => $opts['own_fields'] ?? null,
            'scope'               => $opts['scope'] ?? DocumentAccessPolicy::SCOPE_TENANT,
            'required_permission' => $opts['required_permission'] ?? null,
            'owner_ou_id'         => $opts['owner_ou_id'] ?? null,
            'created_by'          => $createdBy,
        ]);
    }

    /** Publish the template's current draft; returns the new version number. */
    public function publish(int $tenantId, int $sourceId, int $by): int
    {
        $row = $this->sourceRepo->findById($sourceId, $tenantId);
        if ($row === null) {
            throw new \LogicException("fixture: no template {$sourceId}");
        }
        $version = $this->versions->publish($sourceId, $tenantId, (int) $row['draft_version'], $by);
        if ($version === null) {
            throw new \LogicException("fixture: publishing template {$sourceId} failed");
        }

        return $version;
    }

    /**
     * A template with its first version published.
     *
     * @param list<array<string, mixed>> $blocks
     * @param array{scope?: string, required_permission?: ?string, owner_ou_id?: ?int,
     *              record_type?: ?string, own_fields?: ?list<array<string, string>>,
     *              kind?: string, page?: ?array<string, mixed>} $opts
     */
    public function published(int $tenantId, int $createdBy, string $title, array $blocks = [], array $opts = []): int
    {
        $id = $this->draft($tenantId, $createdBy, $title, $blocks, $opts);
        $this->publish($tenantId, $id, $createdBy);

        return $id;
    }

    /**
     * Save a new draft over a template (no version check): a new title, new
     * blocks. Published versions are untouched until the next publish.
     *
     * @param array{title?: string, blocks?: list<array<string, mixed>>} $fields
     */
    public function redraft(int $tenantId, int $sourceId, array $fields, int $by): void
    {
        $row = $this->sourceRepo->findById($sourceId, $tenantId);
        if ($row === null || $this->sourceRepo->saveDraft($sourceId, $tenantId, (int) $row['draft_version'], $fields, $by) === null) {
            throw new \LogicException("fixture: saving template {$sourceId} failed");
        }
    }

    /** @return array<string, mixed> A merge field run. */
    public function field(string $path): array
    {
        return ['type' => 'field', 'path' => $path, 'label' => $path];
    }

    /**
     * A paragraph block; strings become text runs.
     *
     * @return array<string, mixed>
     */
    public function para(mixed ...$runs): array
    {
        $this->blockSeq++;

        return ['id' => 'para' . $this->blockSeq, 'type' => 'paragraph', 'content' => array_map(
            static fn (mixed $r): array => is_string($r) ? ['type' => 'text', 'text' => $r] : (array) $r,
            $runs,
        )];
    }

    /**
     * The flowing payload's text blocks, in order, skipping the letterhead and
     * anything without text — what a reader of the PDF would read.
     *
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function texts(array $payload): array
    {
        $out = [];
        foreach (is_array($payload['content'] ?? null) ? $payload['content'] : [] as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $out[] = $block['text'];
            }
        }

        return $out;
    }

    /**
     * The `{type:'qr'}` blocks in a flowing payload.
     *
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    public static function qrBlocks(array $payload): array
    {
        $out = [];
        foreach (is_array($payload['content'] ?? null) ? $payload['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'qr') {
                $out[] = $block;
            }
        }

        return $out;
    }
}
