<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RecipientProfiles;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;

/**
 * Real-engine tests for {@see NotificationPreferenceRepository} +
 * {@see NotificationPreferenceResolver}: the (tenant, profile)-scoped upsert/
 * list/delete, and the resolver's channel filtering — opt-out default, exact
 * type winning over a '*' channel-wide toggle, transactional bypass, and null
 * recipient bypass.
 */
final class NotificationPreferenceResolverRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const PROFILE = 101;

    private PDO $pdo;
    private NotificationPreferenceRepository $repo;
    private NotificationPreferenceResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        // The recipients these fixtures address must exist: #751 gave
        // notifications.recipient_profile_id a real foreign key to profiles.
        RecipientProfiles::seed($this->pdo);
        $this->repo = new NotificationPreferenceRepository($this->pdo);
        $this->resolver = new NotificationPreferenceResolver($this->repo);
    }

    // ---- repository ----

    public function testSetIsAnIdempotentUpsertAndListRoundTrips(): void
    {
        $this->repo->set(self::TENANT_A, self::PROFILE, 'user.invited', 'email', false);
        $this->repo->set(self::TENANT_A, self::PROFILE, 'user.invited', 'email', true); // upsert same key
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'push', false);

        $rows = $this->repo->listForProfile(self::TENANT_A, self::PROFILE);
        self::assertCount(2, $rows, 'the repeated (type, channel) upserted, not duplicated');
        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r['type'] . '|' . $r['channel']] = $r['enabled'];
        }
        self::assertTrue($byKey['user.invited|email'], 'the second upsert won');
        self::assertFalse($byKey['*|push']);
    }

    public function testDeleteRemovesOnlyTheGivenToggle(): void
    {
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'email', false);
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'push', false);

        self::assertTrue($this->repo->delete(self::TENANT_A, self::PROFILE, '*', 'email'));
        self::assertFalse($this->repo->delete(self::TENANT_A, self::PROFILE, '*', 'email'), 'already gone');
        self::assertCount(1, $this->repo->listForProfile(self::TENANT_A, self::PROFILE));
    }

    public function testListIsTenantAndProfileScoped(): void
    {
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'email', false);

        self::assertCount(1, $this->repo->listForProfile(self::TENANT_A, self::PROFILE));
        self::assertCount(0, $this->repo->listForProfile(2, self::PROFILE), 'another tenant sees nothing');
        self::assertCount(0, $this->repo->listForProfile(self::TENANT_A, 999), 'another profile sees nothing');
    }

    // ---- resolver ----

    public function testNoPrefsKeepsAllChannels(): void
    {
        self::assertSame(
            ['in_app', 'email'],
            $this->resolver->filterChannels(self::TENANT_A, self::PROFILE, 'marketing.digest', ['in_app', 'email'])
        );
    }

    public function testWildcardChannelToggleDisablesThatChannel(): void
    {
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'email', false);

        self::assertSame(
            ['in_app'],
            $this->resolver->filterChannels(self::TENANT_A, self::PROFILE, 'marketing.digest', ['in_app', 'email']),
            'the channel-wide email toggle removes email for every non-transactional type'
        );
    }

    public function testExactTypeWinsOverWildcard(): void
    {
        // Disable email globally, but RE-ENABLE it for one specific type.
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'email', false);
        $this->repo->set(self::TENANT_A, self::PROFILE, 'project.mention', 'email', true);

        self::assertSame(
            ['email'],
            $this->resolver->filterChannels(self::TENANT_A, self::PROFILE, 'project.mention', ['email']),
            'the exact-type re-enable overrides the wildcard disable'
        );
        self::assertSame(
            [],
            $this->resolver->filterChannels(self::TENANT_A, self::PROFILE, 'other.type', ['email']),
            'a different type still falls through to the wildcard disable'
        );
    }

    public function testTransactionalTypeBypassesAllPreferences(): void
    {
        // Disable EVERY channel for everything.
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'email', false);
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'in_app', false);

        self::assertSame(
            ['in_app', 'email'],
            $this->resolver->filterChannels(self::TENANT_A, self::PROFILE, 'security.login_alert', ['in_app', 'email']),
            'a transactional (security.*) type ignores opt-outs entirely'
        );
        self::assertTrue($this->resolver->isTransactional('password.reset'));
        self::assertTrue($this->resolver->isTransactional('account.deleted'));
        self::assertFalse($this->resolver->isTransactional('marketing.promo'));
    }

    public function testNullRecipientBypassesFiltering(): void
    {
        $this->repo->set(self::TENANT_A, self::PROFILE, '*', 'email', false);

        self::assertSame(
            ['email'],
            $this->resolver->filterChannels(self::TENANT_A, null, 'marketing.digest', ['email']),
            'no profile (e.g. email to a non-member) => no prefs to apply'
        );
    }
}
