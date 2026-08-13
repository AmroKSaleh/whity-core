<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SettingsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\PluginSettingsRegistry;
use Whity\Core\Settings\SettingsCatalog;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * #713 item 1, end to end against the real schema: a plugin-declared key stored
 * in CORE'S OWN settings tables, resolved through CORE'S OWN chain.
 *
 * This is the claim the whole change rests on, and it is not provable from unit
 * tests: the adopter's goal is to DELETE their private `tenant_settings`
 * look-alike and store here instead, which only works if a namespaced plugin key
 * behaves in `app_settings`/`tenant_settings` exactly as `site_name` does —
 * same tables, same
 *
 *     per-tenant override ?? global default ?? declared default
 *
 * precedence, same write validation, same clearing semantics.
 *
 * Everything below runs the REAL {@see SettingsService} and repositories against
 * the full migration-built schema (in-memory SQLite locally, real PostgreSQL on
 * the postgres-integration CI job), and asserts what is actually IN the tables
 * rather than what the service says it did.
 */
final class PluginSettingsResolutionRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const SYSTEM_TENANT = 0;

    /** Holds all three settings permissions. */
    private const USER_FULL = 10;

    /** The canonical keys the fixture plugin declares. */
    private const KEY_INTERVAL = 'democatalog:sync_interval';
    private const KEY_MODE = 'democatalog:mode';
    private const KEY_CURSOR = 'democatalog:internal_cursor';

    private PDO $pdo;
    private SettingsService $settings;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
            $this->catalog()
        );
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== the resolution chain ====================

    public function testAnUndisturbedKeyResolvesToItsDeclaredDefault(): void
    {
        // Nothing was written at registration — no seeding, no migration — so a
        // fresh install resolves the declaration itself. This is also what makes
        // uninstalling a plugin clean: there is no row to orphan.
        self::assertSame('300', $this->settings->effective(self::TENANT_A)[self::KEY_INTERVAL]);
        self::assertSame(0, $this->countRows(self::KEY_INTERVAL));
    }

    public function testAGlobalDefaultShadowsTheDeclaredDefault(): void
    {
        $this->settings->setGlobal(self::KEY_INTERVAL, '900');

        self::assertSame('900', $this->settings->effective(self::TENANT_A)[self::KEY_INTERVAL]);
        self::assertSame('900', $this->settings->effective(self::TENANT_B)[self::KEY_INTERVAL]);
        // …and it really is in core's own global table.
        self::assertSame('900', $this->storedGlobal(self::KEY_INTERVAL));
    }

    public function testATenantOverrideShadowsTheGlobalDefault(): void
    {
        $this->settings->setGlobal(self::KEY_INTERVAL, '900');
        $this->settings->setTenant(self::TENANT_A, self::KEY_INTERVAL, '120');

        self::assertSame('120', $this->settings->effective(self::TENANT_A)[self::KEY_INTERVAL]);
        // The other tenant is untouched — the isolation property the shared
        // table has to preserve.
        self::assertSame('900', $this->settings->effective(self::TENANT_B)[self::KEY_INTERVAL]);
        self::assertSame('120', $this->storedTenant(self::TENANT_A, self::KEY_INTERVAL));
        self::assertNull($this->storedTenant(self::TENANT_B, self::KEY_INTERVAL));
    }

    public function testClearingFallsBackOneLayerAtATime(): void
    {
        $this->settings->setGlobal(self::KEY_INTERVAL, '900');
        $this->settings->setTenant(self::TENANT_A, self::KEY_INTERVAL, '120');

        $this->settings->setTenant(self::TENANT_A, self::KEY_INTERVAL, null);
        self::assertSame('900', $this->settings->effective(self::TENANT_A)[self::KEY_INTERVAL]);
        self::assertNull($this->storedTenant(self::TENANT_A, self::KEY_INTERVAL), 'the row is removed, not blanked');

        $this->settings->setGlobal(self::KEY_INTERVAL, null);
        self::assertSame('300', $this->settings->effective(self::TENANT_A)[self::KEY_INTERVAL]);
        self::assertNull($this->storedGlobal(self::KEY_INTERVAL));
    }

    public function testTheSystemTenantResolvesFromGlobalsOnly(): void
    {
        $this->settings->setGlobal(self::KEY_MODE, 'live');

        self::assertSame('live', $this->settings->effective(self::SYSTEM_TENANT)[self::KEY_MODE]);

        // …and cannot be given a per-tenant override, exactly as for a core key.
        $this->expectException(SettingsValidationException::class);
        $this->settings->setTenant(self::SYSTEM_TENANT, self::KEY_MODE, 'off');
    }

    public function testOverriddenKeysReportsPluginKeysToo(): void
    {
        $this->settings->setTenant(self::TENANT_A, self::KEY_MODE, 'live');

        self::assertContains(self::KEY_MODE, $this->settings->overriddenKeys(self::TENANT_A));
        self::assertNotContains(self::KEY_INTERVAL, $this->settings->overriddenKeys(self::TENANT_A));
    }

    // ==================== validation on the real write path ====================

    public function testAnInvalidValueIsRefusedAndNothingIsPersisted(): void
    {
        try {
            $this->settings->setTenant(self::TENANT_A, self::KEY_INTERVAL, '5');
            self::fail('Expected the declared minimum to be enforced');
        } catch (SettingsValidationException $e) {
            self::assertSame(self::KEY_INTERVAL, $e->settingKey());
        }

        // The whole point of a declared key: a bad value FAILS instead of
        // becoming a row.
        self::assertSame(0, $this->countRows(self::KEY_INTERVAL));
        self::assertSame('300', $this->settings->effective(self::TENANT_A)[self::KEY_INTERVAL]);
    }

    public function testATypoWritesNothingRatherThanANewInvisibleRow(): void
    {
        // The exact failure the adopter's private table has, restated as a test:
        // there, `democatalog:sync_intervall` would quietly become a new row
        // nothing ever reads.
        try {
            $this->settings->setTenant(self::TENANT_A, 'democatalog:sync_intervall', '600');
            self::fail('Expected an unknown key to be refused');
        } catch (SettingsValidationException $e) {
            self::assertStringContainsString('Unknown setting key', $e->reason());
        }

        self::assertSame(0, $this->countRows('democatalog:sync_intervall'));
    }

    public function testValuesAreNormalisedBeforeTheyReachStorage(): void
    {
        $this->settings->setGlobal(self::KEY_CURSOR, '  abc  ');

        self::assertSame('abc', $this->storedGlobal(self::KEY_CURSOR));
    }

    public function testCoreKeysStillResolveIdenticallyWithAPluginLoaded(): void
    {
        $effective = $this->settings->effective(self::TENANT_A);

        self::assertSame('Whity', $effective['site_name']);
        self::assertSame('UTC', $effective['timezone']);

        $this->settings->setTenant(self::TENANT_A, 'site_name', '  Acme  ');
        self::assertSame('Acme', $this->settings->effective(self::TENANT_A)['site_name']);
        self::assertSame('Acme', $this->storedTenant(self::TENANT_A, 'site_name'));
    }

    // ==================== storage shape ====================

    /**
     * The concrete promise to the adopter: no new table. A plugin key is a row
     * in the same two tables core has always used, so there is one settings
     * store, one backup, one restore, one admin screen.
     */
    public function testPluginValuesLandInCoresOwnTablesBesideCoreKeys(): void
    {
        $this->settings->setGlobal(self::KEY_MODE, 'live');
        $this->settings->setGlobal('site_name', 'Acme');
        $this->settings->setTenant(self::TENANT_A, self::KEY_INTERVAL, '600');
        $this->settings->setTenant(self::TENANT_A, 'timezone', 'Asia/Riyadh');

        self::assertSame(
            ['democatalog:mode', 'site_name'],
            $this->keysIn('SELECT setting_key FROM app_settings ORDER BY setting_key')
        );
        self::assertSame(
            ['democatalog:sync_interval', 'timezone'],
            $this->keysIn('SELECT setting_key FROM tenant_settings WHERE tenant_id = 1 ORDER BY setting_key')
        );
    }

    public function testAKeyIsUpsertedRatherThanDuplicatedOnRepeatedWrites(): void
    {
        $this->settings->setTenant(self::TENANT_A, self::KEY_MODE, 'live');
        $this->settings->setTenant(self::TENANT_A, self::KEY_MODE, 'off');
        $this->settings->setTenant(self::TENANT_A, self::KEY_MODE, 'live');

        self::assertSame(1, $this->countRows(self::KEY_MODE));
        self::assertSame('live', $this->settings->effective(self::TENANT_A)[self::KEY_MODE]);
    }

    // ==================== the admin API surface ====================

    public function testAnOptedInPluginKeyIsReadableAndWritableOnTheSettingsApi(): void
    {
        $handler = $this->makeHandler();
        TenantContext::setTenantId(self::TENANT_A);

        $response = $handler->patch($this->req('PATCH', '/api/settings', [
            'settings' => [self::KEY_MODE => 'live'],
        ]));
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decode($handler->get($this->req('GET', '/api/settings')));
        self::assertSame('live', $body['data']['effective'][self::KEY_MODE] ?? null);
        self::assertContains(self::KEY_MODE, $body['data']['overridden'] ?? []);

        // The descriptor a client renders from carries the plugin's own typing
        // and its attribution, so an operator knows who owns the key.
        $descriptors = [];
        foreach ($body['data']['registry'] ?? [] as $entry) {
            $descriptors[$entry['key']] = $entry;
        }
        self::assertSame('enum', $descriptors[self::KEY_MODE]['type'] ?? null);
        self::assertSame(['off', 'live'], $descriptors[self::KEY_MODE]['options'] ?? null);
        self::assertSame('DemoCatalog', $descriptors[self::KEY_MODE]['source'] ?? null);
    }

    public function testTheApiRefusesAValueThePluginsDeclarationForbids(): void
    {
        $handler = $this->makeHandler();
        TenantContext::setTenantId(self::TENANT_A);

        $response = $handler->patch($this->req('PATCH', '/api/settings', [
            'settings' => [self::KEY_MODE => 'sideways'],
        ]));

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertArrayHasKey(self::KEY_MODE, $body['details'] ?? []);
        self::assertSame(0, $this->countRows(self::KEY_MODE));
    }

    /**
     * The opt-in, enforced on BOTH halves of the surface. A key that is writable
     * here but invisible here would be a value you can set and cannot see —
     * precisely the failure this change removes, reintroduced one layer up.
     */
    public function testAKeyThatDidNotOptInIsAbsentFromReadsAndRefusedOnWrites(): void
    {
        $handler = $this->makeHandler();
        TenantContext::setTenantId(self::TENANT_A);

        $body = $this->decode($handler->get($this->req('GET', '/api/settings')));
        self::assertArrayNotHasKey(self::KEY_CURSOR, $body['data']['effective'] ?? []);
        self::assertNotContains(self::KEY_CURSOR, array_column($body['data']['registry'] ?? [], 'key'));

        $response = $handler->patch($this->req('PATCH', '/api/settings', [
            'settings' => [self::KEY_CURSOR => 'x'],
        ]));
        self::assertSame(422, $response->getStatusCode());
        $details = $this->decode($response)['details'] ?? [];
        // The refusal names the owner, so the caller learns which surface does
        // manage the key rather than concluding it does not exist.
        self::assertStringContainsString('DemoCatalog', $details[self::KEY_CURSOR] ?? '');

        // …and the plugin's own server-side path still works perfectly.
        $this->settings->setTenant(self::TENANT_A, self::KEY_CURSOR, 'x');
        self::assertSame('x', $this->settings->effective(self::TENANT_A)[self::KEY_CURSOR]);
    }

    public function testCoreKeysAreUnaffectedOnTheApiSurface(): void
    {
        $handler = $this->makeHandler();
        TenantContext::setTenantId(self::TENANT_A);

        $body = $this->decode($handler->get($this->req('GET', '/api/settings')));
        $keys = array_column($body['data']['registry'] ?? [], 'key');

        // Core's tenant-overridable text keys are all still published, in order,
        // ahead of anything a plugin added.
        self::assertSame(
            \Whity\Core\Settings\SettingsRegistry::tenantTextKeys(),
            array_slice($keys, 0, count(\Whity\Core\Settings\SettingsRegistry::tenantTextKeys()))
        );
        self::assertSame('Whity', $body['data']['effective']['site_name'] ?? null);
    }

    // ==================== helpers ====================

    private function catalog(): SettingsCatalog
    {
        $plugins = new PluginSettingsRegistry();
        // Exactly what the loader would register for a plugin named DemoCatalog.
        $plugins->register('DemoCatalog', [
            'sync_interval' => ['type' => 'int', 'default' => 300, 'min' => 60, 'max' => 86400, 'admin' => true],
            'mode' => ['type' => 'enum', 'options' => ['off', 'live'], 'default' => 'off', 'admin' => true],
            'internal_cursor' => ['type' => 'string', 'default' => ''],
        ]);

        return new SettingsCatalog($plugins);
    }

    private function makeHandler(): SettingsApiHandler
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();

        return new SettingsApiHandler($this->settings, new RoleChecker($this->databaseFor($this->pdo), $registry));
    }

    /**
     * @return list<string>
     */
    private function keysIn(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return array_map(strval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function storedGlobal(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT value FROM app_settings WHERE setting_key = :k');
        $stmt->execute([':k' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function storedTenant(int $tenantId, string $key): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT value FROM tenant_settings WHERE tenant_id = :t AND setting_key = :k'
        );
        $stmt->execute([':t' => $tenantId, ':k' => $key]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function countRows(string $key): int
    {
        $global = $this->pdo->prepare('SELECT COUNT(*) FROM app_settings WHERE setting_key = :k');
        $global->execute([':k' => $key]);
        $tenant = $this->pdo->prepare('SELECT COUNT(*) FROM tenant_settings WHERE setting_key = :k');
        $tenant->execute([':k' => $key]);

        return (int) $global->fetchColumn() + (int) $tenant->fetchColumn();
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => self::USER_FULL, 'active_tenant_id' => self::TENANT_A];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (10, 1, 1, 'active', datetime('now')),
                (10, 0, 1, 'active', datetime('now'))
        ");

        return $pdo;
    }
}
