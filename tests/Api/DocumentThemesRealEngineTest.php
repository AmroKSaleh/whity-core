<?php

declare(strict_types=1);

namespace Tests\Api;

use Database\Migrations\MovePrintColorIntoWorkspaceTheme;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Api\DocumentThemesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Branding\TenantTheme;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Theme\DocumentThemeRepository;
use Whity\Core\Document\Theme\DocumentThemeResolver;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Document themes (editor M4d; E.44, DOCUMENT-THEMES.md): the resolver, the
 * `/api/document-themes` handler, a document's own theme, freezing at
 * publish, and the print colour moving into the workspace theme. Runs on
 * SQLite by default and on PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class DocumentThemesRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const OWNER = 10;
    private const FOREIGN = 20;

    /** Example Bakery's orange, readable on white. */
    private const ORANGE = '#b74c05';

    private PDO $pdo;
    private SettingsService $settings;
    private DocumentThemeRepository $themes;
    private DocumentThemeResolver $resolver;
    private DocumentThemesApiHandler $handler;
    private DocumentSourcesApiHandler $sources;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->themes = new DocumentThemeRepository($this->pdo);
        $this->resolver = new DocumentThemeResolver($this->settings, $this->themes);
        $this->handler = new DocumentThemesApiHandler($this->themes, $this->resolver, $this->settings);
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $this->sources = new DocumentSourcesApiHandler(
            new DocumentSourceRepository($this->pdo),
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
            null,
            new DocumentSourceVersionRepository($this->pdo),
            $this->resolver,
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── resolution ──────────────────────────────────────────────────────────

    public function testWithNothingSetADocumentPrintsInWhityBlueWithTheStandardLetterhead(): void
    {
        $theme = $this->resolver->resolve(self::TENANT);

        self::assertSame('whity.standard', $theme['ref']);
        self::assertSame(0, $theme['version']);
        self::assertSame(TenantTheme::whityBlue(), $theme['tokens']['accent']);
        self::assertSame('title', $theme['tokens']['accentUse']);
        self::assertSame(['logo' => 'wide', 'lines' => ['address', 'taxId']], $theme['tokens']['letterhead']);
        self::assertSame('whity.standard', $theme['sources']['accent']);
        self::assertSame([], $theme['adjustments']);
        self::assertGreaterThanOrEqual(4.5, TenantTheme::contrast('#ffffff', $theme['tokens']['accent']));
    }

    public function testTheWorkspaceMainColourIsTheDefaultAccent(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BRANDING_MAIN_COLOR, '#B74C05');

        $theme = $this->resolver->resolve(self::TENANT);

        self::assertSame(self::ORANGE, $theme['tokens']['accent']);
        self::assertSame('branding.main_color', $theme['sources']['accent']);
        self::assertSame(TenantTheme::onDark(self::ORANGE), $theme['tokens']['accentOnDark'], 'derived for the sheet on dark (2.8b)');
    }

    public function testAnAccentThatCantBeReadOnWhiteIsDarkenedAndSaysSo(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BRANDING_MAIN_COLOR, '#ffd84d');

        $theme = $this->resolver->resolve(self::TENANT);

        self::assertNotSame('#ffd84d', $theme['tokens']['accent']);
        self::assertGreaterThanOrEqual(4.5, TenantTheme::contrast('#ffffff', $theme['tokens']['accent']));
        self::assertSame('accent_darkened', $theme['adjustments'][0]['code']);
        self::assertSame('#ffd84d', $theme['adjustments'][0]['from']);
        self::assertSame($theme['tokens']['accent'], $theme['adjustments'][0]['to']);
    }

    public function testLayersWinInOrderWorkspaceThemeThenTheDocument(): void
    {
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BRANDING_MAIN_COLOR, '#2b6cd2');
        $this->themes->create(self::TENANT, 'bakery', 'Bakery', 'المخبز', 'whity.standard', [
            'accent' => self::ORANGE,
            'accentUse' => 'title-and-headings',
            'letterhead' => ['logo' => 'square'],
        ], self::OWNER);

        $theme = $this->resolver->resolve(self::TENANT, [
            'ref' => 'tenant.bakery',
            'overrides' => ['letterhead' => ['lines' => ['taxId']]],
        ]);

        self::assertSame('tenant.bakery', $theme['ref']);
        self::assertSame(1, $theme['version']);
        self::assertSame(self::ORANGE, $theme['tokens']['accent']);
        self::assertSame('title-and-headings', $theme['tokens']['accentUse']);
        self::assertSame(['logo' => 'square', 'lines' => ['taxId']], $theme['tokens']['letterhead']);
        self::assertSame('tenant.bakery', $theme['sources']['accent']);
        self::assertSame('tenant.bakery', $theme['sources']['letterhead.logo']);
        self::assertSame('document', $theme['sources']['letterhead.lines']);
    }

    public function testADocumentsOwnPrintColourGetsAMatchingTitleOnDark(): void
    {
        $this->themes->create(self::TENANT, 'house', 'House', 'الدار', null, [
            'accent' => self::ORANGE,
            'accentOnDark' => '#f0b48a',
        ], self::OWNER);

        $theirs = $this->resolver->resolve(self::TENANT, ['ref' => 'tenant.house']);
        self::assertSame('#f0b48a', $theirs['tokens']['accentOnDark'], "the theme's own dark title stands with its own accent");

        $mine = $this->resolver->resolve(self::TENANT, ['ref' => 'tenant.house', 'overrides' => ['accent' => '#1f6f43']]);
        self::assertSame(TenantTheme::onDark('#1f6f43'), $mine['tokens']['accentOnDark'], "derived again from the document's colour");
        self::assertSame('document', $mine['sources']['accentOnDark']);
    }

    public function testADocumentWithoutARefUsesTheDefaultForItsKindThenTheWorkspaceDefault(): void
    {
        $this->themes->create(self::TENANT, 'labels', 'Labels', 'الملصقات', null, ['accent' => '#1f6f43'], self::OWNER);
        $this->themes->create(self::TENANT, 'house', 'House', 'الدار', null, ['accent' => self::ORANGE], self::OWNER);
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_THEME, 'tenant.house');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_THEME_BY_KIND, '{"label":"tenant.labels"}');

        self::assertSame('tenant.labels', $this->resolver->resolve(self::TENANT, null, 'label')['ref']);
        self::assertSame('tenant.house', $this->resolver->resolve(self::TENANT, null, 'letter')['ref']);
        self::assertSame('whity.standard', $this->resolver->resolve(self::TENANT, ['ref' => 'whity.standard'], 'label')['ref']);
    }

    public function testAThemeTheWorkspaceDoesntHaveFallsBackAndSaysSo(): void
    {
        $this->themes->create(self::OTHER_TENANT, 'secret', 'Secret', 'سري', null, ['accent' => '#000000'], self::FOREIGN);

        $theme = $this->resolver->resolve(self::TENANT, ['ref' => 'tenant.secret']);

        self::assertSame('whity.standard', $theme['ref'], "another workspace's theme never resolves here");
        self::assertSame('theme_missing', $theme['adjustments'][0]['code']);
        self::assertSame('tenant.secret', $theme['adjustments'][0]['from']);
    }

    public function testTheHashMovesOnlyWhenTheResolvedThemeDoes(): void
    {
        $this->themes->create(self::TENANT, 'house', 'House', 'الدار', null, ['accent' => self::ORANGE], self::OWNER);
        $first = $this->resolver->resolve(self::TENANT, ['ref' => 'tenant.house']);

        self::assertSame($first['hash'], $this->resolver->resolve(self::TENANT, ['ref' => 'tenant.house'])['hash']);

        $this->themes->update(self::TENANT, 'house', 1, ['tokens' => ['accent' => '#1f6f43']], self::OWNER);
        self::assertNotSame($first['hash'], $this->resolver->resolve(self::TENANT, ['ref' => 'tenant.house'])['hash']);
    }

    // ── the API ─────────────────────────────────────────────────────────────

    public function testAWorkspaceThemeIsCreatedListedAndShownWithItsResolution(): void
    {
        $created = $this->call('create', 'POST', '/api/document-themes', [
            'key' => 'bakery', 'name' => 'Bakery', 'name_ar' => 'المخبز', 'tokens' => ['accent' => '#B74C05'],
        ]);
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        self::assertSame('"1"', self::header($created, 'ETag'));
        self::assertSame('tenant.bakery', $this->data($created)['ref']);
        self::assertSame(self::ORANGE, $this->data($created)['tokens']['accent'], 'colours are stored in lower case');

        $list = $this->data($this->call('list', 'GET', '/api/document-themes'));
        self::assertSame(['whity.standard', 'tenant.bakery'], array_column($list, 'ref'));
        self::assertSame(['workspace'], $list[0]['default_for'], "Whity's standard theme is the default until one is chosen");

        $shown = $this->call('show', 'GET', '/api/document-themes/tenant.bakery', null, ['ref' => 'tenant.bakery']);
        self::assertSame(200, $shown->getStatusCode(), $shown->getBody());
        self::assertSame(self::ORANGE, $this->data($shown)['resolved']['tokens']['accent']);

        self::assertSame(409, $this->call('create', 'POST', '/api/document-themes', [
            'key' => 'bakery', 'name' => 'Again', 'name_ar' => 'مرة أخرى',
        ])->getStatusCode(), 'a key is unique in its workspace');
    }

    public function testAThemeNeedsBothNamesAndOnlyTheClosedTokens(): void
    {
        $noArabic = $this->call('create', 'POST', '/api/document-themes', ['key' => 'x', 'name' => 'X']);
        self::assertSame(422, $noArabic->getStatusCode());

        $css = $this->call('create', 'POST', '/api/document-themes', [
            'key' => 'x', 'name' => 'X', 'name_ar' => 'س', 'tokens' => ['fontSize' => '20px'],
        ]);
        self::assertSame(422, $css->getStatusCode());
        self::assertStringContainsString('fontSize', $css->getBody());

        $lines = $this->call('create', 'POST', '/api/document-themes', [
            'key' => 'x', 'name' => 'X', 'name_ar' => 'س', 'tokens' => ['letterhead' => ['lines' => ['address', 'address']]],
        ]);
        self::assertSame(422, $lines->getStatusCode(), 'a line listed twice');

        $plugin = $this->call('create', 'POST', '/api/document-themes', [
            'key' => 'x', 'name' => 'X', 'name_ar' => 'س', 'extends' => 'acme.classic',
        ]);
        self::assertSame(422, $plugin->getStatusCode(), 'plugin themes come with M7');
    }

    public function testChangingAThemeNeedsItsVersionAndAStaleOneIsRefused(): void
    {
        $this->call('create', 'POST', '/api/document-themes', ['key' => 'bakery', 'name' => 'Bakery', 'name_ar' => 'المخبز']);
        $params = ['ref' => 'tenant.bakery'];
        $body = ['tokens' => ['accent' => '#1f6f43']];

        self::assertSame(428, $this->call('update', 'PATCH', '/api/document-themes/tenant.bakery', $body, $params)->getStatusCode());

        $saved = $this->call('update', 'PATCH', '/api/document-themes/tenant.bakery', $body, $params, ['If-Match' => '"1"']);
        self::assertSame(200, $saved->getStatusCode(), $saved->getBody());
        self::assertSame(2, $this->data($saved)['version']);
        self::assertSame('"2"', self::header($saved, 'ETag'));

        $stale = $this->call('update', 'PATCH', '/api/document-themes/tenant.bakery', ['name' => 'Late'], $params, ['If-Match' => '"1"']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertStringContainsString('"version":2', $stale->getBody());

        self::assertSame(409, $this->call('update', 'PATCH', '/api/document-themes/whity.standard', $body, ['ref' => 'whity.standard'], ['If-Match' => '"0"'])->getStatusCode());
    }

    public function testAThemeInUseCantBeDeletedButCanBeArchived(): void
    {
        $this->call('create', 'POST', '/api/document-themes', ['key' => 'house', 'name' => 'House', 'name_ar' => 'الدار']);
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_THEME, 'tenant.house');
        $params = ['ref' => 'tenant.house'];

        $refused = $this->call('delete', 'DELETE', '/api/document-themes/tenant.house', null, $params);
        self::assertSame(409, $refused->getStatusCode());
        self::assertStringContainsString('"defaults":["workspace"]', $refused->getBody());

        $archived = $this->call('archive', 'POST', '/api/document-themes/tenant.house/archive', null, $params);
        self::assertTrue($this->data($archived)['archived']);
        $warned = json_decode($archived->getBody(), true);
        self::assertIsArray($warned);
        self::assertArrayHasKey('warning', $warned, 'archiving a default is allowed, with a warning');
        self::assertSame(['workspace'], $warned['usage']['defaults']);
        self::assertSame(['whity.standard'], array_column($this->data($this->call('list', 'GET', '/api/document-themes')), 'ref'));
        self::assertSame('tenant.house', $this->resolver->resolve(self::TENANT)['ref'], 'an archived theme still prints what names it');

        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_THEME, null);
        self::assertSame(204, $this->call('delete', 'DELETE', '/api/document-themes/tenant.house', null, $params)->getStatusCode());
    }

    public function testAnotherWorkspacesThemeIsNotFound(): void
    {
        $this->themes->create(self::OTHER_TENANT, 'secret', 'Secret', 'سري', null, [], self::FOREIGN);
        $params = ['ref' => 'tenant.secret'];

        self::assertSame(404, $this->call('show', 'GET', '/api/document-themes/tenant.secret', null, $params)->getStatusCode());
        self::assertSame(404, $this->call('update', 'PATCH', '/api/document-themes/tenant.secret', ['name' => 'Mine'], $params, ['If-Match' => '"1"'])->getStatusCode());
        self::assertSame(404, $this->call('delete', 'DELETE', '/api/document-themes/tenant.secret', null, $params)->getStatusCode());
        self::assertNotNull($this->themes->find(self::OTHER_TENANT, 'secret'));
    }

    public function testResolveIsAPreviewThatValidatesOverrides(): void
    {
        $ok = $this->call('resolve', 'POST', '/api/document-themes/resolve', ['overrides' => ['accent' => '#B74C05']]);
        self::assertSame(200, $ok->getStatusCode(), $ok->getBody());
        self::assertSame(self::ORANGE, $this->data($ok)['tokens']['accent']);
        self::assertSame('document', $this->data($ok)['sources']['accent']);

        $bad = $this->call('resolve', 'POST', '/api/document-themes/resolve', ['overrides' => ['accentUse' => 'title-and-headings']]);
        self::assertSame(422, $bad->getStatusCode(), 'a document overrides only the print colour and letterhead');
    }

    // ── a document's theme, and publishing ──────────────────────────────────

    public function testADraftSavesItsThemeAndShowsWithTheResolvedOne(): void
    {
        $this->themes->create(self::TENANT, 'house', 'House', 'الدار', null, ['accent' => self::ORANGE], self::OWNER);
        $id = $this->newTemplate();

        $saved = $this->saveDraft($id, 1, ['theme' => ['ref' => 'tenant.house', 'overrides' => ['letterhead' => ['logo' => 'none']]]]);
        self::assertSame(200, $saved->getStatusCode(), $saved->getBody());
        self::assertSame('tenant.house', $this->data($saved)['theme']['ref']);
        self::assertSame(self::ORANGE, $this->data($saved)['resolvedTheme']['tokens']['accent']);
        self::assertSame('none', $this->data($saved)['resolvedTheme']['tokens']['letterhead']['logo']);

        $bad = $this->saveDraft($id, 2, ['theme' => ['ref' => 'tenant.house', 'overrides' => ['accentUse' => 'title-and-headings']]]);
        self::assertSame(422, $bad->getStatusCode(), 'a document overrides only the print colour and letterhead');
        self::assertSame(422, $this->saveDraft($id, 2, ['theme' => ['ref' => 'Not A Ref']])->getStatusCode());
        self::assertSame(422, $this->saveDraft($id, 2, ['theme' => ['css' => 'body{}']])->getStatusCode());
    }

    public function testAPublishedVersionKeepsItsThemeUntilItIsPublishedAgain(): void
    {
        $this->themes->create(self::TENANT, 'house', 'House', 'الدار', null, ['accent' => self::ORANGE], self::OWNER);
        $id = $this->newTemplate();
        $this->saveDraft($id, 1, ['theme' => ['ref' => 'tenant.house']]);

        $printer = new SourceDocumentPrinter(new DocumentFieldRegistry(), new DocumentEmbedRegistry(), $this->settings, null, null, null, $this->themes);
        $versions = new DocumentSourceVersionRepository($this->pdo);
        $draft = (new DocumentSourceRepository($this->pdo))->findById($id, self::TENANT);
        self::assertNotNull($draft);
        $version = $versions->publish($id, self::TENANT, 2, self::OWNER, $printer->theme(self::TENANT, $draft['theme'], 'template'));
        self::assertSame(1, $version);

        $this->themes->update(self::TENANT, 'house', 1, ['tokens' => ['accent' => '#1f6f43']], self::OWNER);

        $published = $versions->find($id, self::TENANT, 1);
        self::assertNotNull($published);
        self::assertSame(['ref' => 'tenant.house'], $published['theme']);
        self::assertSame(self::ORANGE, $printer->versionTheme(self::TENANT, $published, 'template')['tokens']['accent'], 'frozen at publish');
        self::assertSame('#1f6f43', $printer->theme(self::TENANT, $draft['theme'], 'template')['tokens']['accent'], 'the draft follows the theme');

        $usage = $this->data($this->call('usage', 'GET', '/api/document-themes/tenant.house/usage', null, ['ref' => 'tenant.house']));
        self::assertSame([$id], array_column($usage['drafts'], 'id'));
        self::assertSame([$id], array_column($usage['published'], 'id'));
    }

    public function testAVersionFromBeforeThemesPrintsWithTodaysResolution(): void
    {
        $printer = new SourceDocumentPrinter(new DocumentFieldRegistry(), new DocumentEmbedRegistry(), $this->settings, null, null, null, $this->themes);
        $this->settings->setTenant(self::TENANT, SettingsRegistry::BRANDING_MAIN_COLOR, '#B74C05');

        $theme = $printer->versionTheme(self::TENANT, ['theme' => null, 'theme_resolved' => null], 'template');

        self::assertSame(self::ORANGE, $theme['tokens']['accent']);
    }

    // ── the print colour moves into a theme (migration 173) ─────────────────

    public function testThePrintColourBecomesTheWorkspaceThemeAndTheSettingGoes(): void
    {
        $db = $this->wrap($this->pdo);
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (1, 'documents.print_color', '#B74C05', CURRENT_TIMESTAMP)");
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (2, 'documents.print_color', '', CURRENT_TIMESTAMP)");

        MovePrintColorIntoWorkspaceTheme::up($db);

        $theme = $this->themes->find(self::TENANT, 'workspace');
        self::assertNotNull($theme);
        self::assertSame(['accent' => self::ORANGE], $theme['tokens']);
        self::assertSame('مساحة العمل', $theme['name_ar']);
        self::assertSame('tenant.workspace', $this->settings->effective(self::TENANT)[SettingsRegistry::DOCUMENTS_THEME]);
        self::assertSame(self::ORANGE, $this->resolver->resolve(self::TENANT)['tokens']['accent'], 'documents print as before');
        self::assertNull($this->themes->find(self::OTHER_TENANT, 'workspace'), 'no print colour, no theme');
        self::assertSame(0, (int) $this->column("SELECT COUNT(*) FROM tenant_settings WHERE setting_key = 'documents.print_color'"));

        MovePrintColorIntoWorkspaceTheme::up($db);
        self::assertSame(1, (int) $this->column('SELECT COUNT(*) FROM document_themes'), 'idempotent');

        MovePrintColorIntoWorkspaceTheme::down($db);
        self::assertSame(0, (int) $this->column('SELECT COUNT(*) FROM document_themes'));
        self::assertSame(self::ORANGE, $this->column("SELECT value FROM tenant_settings WHERE tenant_id = 1 AND setting_key = 'documents.print_color'"));
        self::assertSame(0, (int) $this->column("SELECT COUNT(*) FROM tenant_settings WHERE setting_key = 'documents.theme'"));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function newTemplate(): int
    {
        $res = $this->sources->create($this->request('POST', '/api/document-sources', (string) json_encode([
            'kind' => 'template', 'title' => 'Invoice', 'blocks' => [],
        ])));
        self::assertSame(201, $res->getStatusCode(), $res->getBody());

        return (int) $this->data($res)['id'];
    }

    /** @param array<string, mixed> $body */
    private function saveDraft(int $id, int $base, array $body): Response
    {
        return $this->sources->saveDraft(
            $this->request('PUT', "/api/document-sources/{$id}/draft", (string) json_encode($body), ['If-Match' => '"' . $base . '"']),
            ['id' => (string) $id],
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $params
     * @param array<string, string> $headers
     */
    private function call(string $method, string $verb, string $path, ?array $body = null, array $params = [], array $headers = []): Response
    {
        $request = $this->request($verb, $path, $body === null ? '' : (string) json_encode((object) $body), $headers);

        return $params === [] ? $this->handler->{$method}($request) : $this->handler->{$method}($request, $params);
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $req = new Request($method, $path, $headers, $body);
        $req->user = (object) ['profile_id' => self::OWNER, 'active_tenant_id' => self::TENANT];

        return $req;
    }

    private static function header(Response $res, string $name): ?string
    {
        foreach ($res->getHeaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /** The first column of a query's first row. */
    private function column(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertInstanceOf(\PDOStatement::class, $stmt);

        return $stmt->fetchColumn();
    }

    /** @return array<int|string, mixed> */
    private function data(Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());

        return $decoded['data'];
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner',   'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'foreign', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1, 'active', datetime('now')),
                (2000, 20, 2, 1, 'active', datetime('now'))
        ");

        return $pdo;
    }

    private function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
