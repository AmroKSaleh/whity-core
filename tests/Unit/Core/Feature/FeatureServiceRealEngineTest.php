<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Feature;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Feature\FeatureRegistry;
use Whity\Core\Feature\FeatureService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Whether a major subsystem is available to a tenant.
 *
 * THE POINT OF THE LAYER is that "off" is not one condition. An operator can
 * switch a subsystem off for the whole instance or for one tenant; a tenant's
 * plan can fail to include it. Those are different failures, owned by different
 * people, and a single boolean sends whoever is looking to the wrong place — so
 * the two halves are resolved separately and reported separately.
 *
 * IT KEEPS NO STATE. Every answer comes from the settings and entitlements that
 * already existed, and a feature reads the SAME key its subsystem has always
 * read. The tests below lean on that: they set `documents.render_enabled` and
 * expect the `documents.render` feature to move, because a flag that could
 * disagree with the switch its subsystem consults would be worse than no flag.
 */
final class FeatureServiceRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private PDO $pdo;
    private SettingsService $settings;
    private EntitlementService $entitlements;
    private FeatureService $features;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'one', 'one')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'two', 'two')");

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->features = new FeatureService($this->settings, $this->entitlements);
    }

    // ── the catalogue ────────────────────────────────────────────────────────

    /**
     * Every feature must name a settings key that EXISTS, or the switch it
     * claims to read is not a switch at all — it would resolve to "absent",
     * report the subsystem off, and be unfixable from any admin screen.
     */
    public function testEveryFeatureNamesAKnownSetting(): void
    {
        foreach (FeatureRegistry::keys() as $key) {
            $setting = FeatureRegistry::settingFor($key);
            self::assertTrue(
                SettingsRegistry::isKnown($setting),
                "Feature {$key} points at unknown setting {$setting}"
            );
        }
    }

    /** Same for the commercial gate, where one is declared. */
    public function testEveryDeclaredEntitlementIsKnown(): void
    {
        foreach (FeatureRegistry::keys() as $key) {
            $entitlement = FeatureRegistry::entitlementFor($key);
            if ($entitlement === null) {
                continue;
            }
            self::assertTrue(
                EntitlementRegistry::isKnown($entitlement),
                "Feature {$key} points at unknown entitlement {$entitlement}"
            );
        }
    }

    public function testAnUnknownFeatureIsRefusedRatherThanAnsweredFalse(): void
    {
        // Loud on purpose. A typo answering "false" would silently disable a
        // subsystem; one answering "true" would silently expose one.
        $this->expectException(\InvalidArgumentException::class);
        $this->features->isEnabled('documents.rendr', self::TENANT);
    }

    // ── the operator half ────────────────────────────────────────────────────

    public function testAFeatureFollowsItsOwnSubsystemSwitch(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');
        self::assertTrue($this->features->isEnabled(FeatureRegistry::DOCUMENTS_RENDER, self::TENANT));

        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'false');
        self::assertFalse($this->features->isEnabled(FeatureRegistry::DOCUMENTS_RENDER, self::TENANT));
    }

    public function testATenantOverrideBeatsTheGlobalSwitch(): void
    {
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'false');
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'true');

        self::assertTrue($this->features->isEnabled(FeatureRegistry::DOCUMENTS_RENDER, self::TENANT));
        // And only for that tenant.
        self::assertFalse($this->features->isEnabled(FeatureRegistry::DOCUMENTS_RENDER, self::OTHER_TENANT));
    }

    /**
     * The settings layer will not STORE a non-boolean, so the feature layer
     * never meets one through the supported path. Worth pinning: it is the
     * reason the check below is a defence rather than the guard.
     */
    public function testTheSettingsLayerRefusesANonBooleanSwitch(): void
    {
        $this->expectException(\Whity\Core\Settings\SettingsValidationException::class);
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, 'yes');
    }

    /**
     * And a value that got past validation is still off.
     *
     * Written straight to the repository, which is how such a value actually
     * arrives — a migration, a data fix, a hand-run UPDATE. Comparing against
     * the literal 'true' rather than casting means a stray '1' reads as OFF
     * here and off in the subsystem's own code, instead of the two disagreeing
     * about a value neither expected.
     */
    public function testAValueWrittenPastValidationStillReadsAsOff(): void
    {
        $raw = new GlobalSettingsRepository($this->pdo);

        foreach (['1', 'yes', 'TRUE', '', 'on'] as $value) {
            $raw->set(SettingsRegistry::DOCUMENTS_RENDER_ENABLED, $value);
            self::assertFalse(
                $this->features->isEnabled(FeatureRegistry::DOCUMENTS_RENDER, self::TENANT),
                "value {$value} must not enable a feature"
            );
        }
    }

    // ── the commercial half ──────────────────────────────────────────────────

    /**
     * BOTH halves must say yes. SSO declares an entitlement, so an operator
     * switching it on is not enough for a tenant whose plan does not include it.
     */
    public function testAnEntitledFeatureNeedsTheOperatorSwitchAndThePlan(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SSO_ENABLED, 'true');
        self::assertFalse(
            $this->features->isEnabled(FeatureRegistry::SSO, self::TENANT),
            'the operator switch alone must not grant an entitled feature'
        );

        $this->entitlements->set(self::TENANT, EntitlementRegistry::SSO_TENANT_IDP, 'true');
        self::assertTrue($this->features->isEnabled(FeatureRegistry::SSO, self::TENANT));
    }

    /**
     * And the operator keeps the kill switch. A plan that could override it
     * would mean a paid subsystem an operator cannot turn off during an
     * incident.
     */
    public function testTheEntitlementCannotOverrideTheOperatorSwitch(): void
    {
        $this->entitlements->set(self::TENANT, EntitlementRegistry::SSO_TENANT_IDP, 'true');
        $this->settings->setGlobal(SettingsRegistry::SSO_ENABLED, 'false');

        self::assertFalse($this->features->isEnabled(FeatureRegistry::SSO, self::TENANT));
    }

    // ── the operator listing ─────────────────────────────────────────────────

    public function testTheListingCoversEveryFeature(): void
    {
        $all = $this->features->all(self::TENANT);
        self::assertCount(count(FeatureRegistry::keys()), $all);
        self::assertSame(FeatureRegistry::keys(), array_column($all, 'key'));
    }

    /**
     * THE REASON THE LISTING REPORTS THREE BOOLEANS. A subsystem the operator
     * disabled and one the tenant's plan does not include both read "off", and
     * they need different actions from different people.
     */
    public function testTheListingSaysWHICHHalfIsMissing(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SSO_ENABLED, 'true');
        // Operator: yes. Plan: no.
        $row = $this->rowFor($this->features->all(self::TENANT), FeatureRegistry::SSO);

        self::assertFalse($row['enabled']);
        self::assertTrue($row['operator_enabled'], 'the operator half is on and must say so');
        self::assertFalse($row['entitled']);
        self::assertSame(EntitlementRegistry::SSO_TENANT_IDP, $row['entitlement']);
    }

    public function testAFeatureWithNoCommercialGateIsAlwaysEntitled(): void
    {
        $row = $this->rowFor($this->features->all(self::TENANT), FeatureRegistry::MCP);

        self::assertNull($row['entitlement']);
        self::assertTrue($row['entitled'], 'a feature with no entitlement must not read as unentitled');
    }

    public function testTheListingCarriesSomethingAScreenCanShow(): void
    {
        foreach ($this->features->all(self::TENANT) as $row) {
            self::assertNotSame('', $row['label'], "{$row['key']} has no label");
            self::assertNotSame('', $row['description'], "{$row['key']} has no description");
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function rowFor(array $rows, string $key): array
    {
        foreach ($rows as $row) {
            if ($row['key'] === $key) {
                return $row;
            }
        }
        self::fail("No listing row for {$key}");
    }
}
