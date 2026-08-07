<?php

declare(strict_types=1);

namespace Tests\Integration\Notification;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Notification\DatabaseNotificationRenderer;
use Whity\Core\Notification\NotificationTemplateRepository;

/**
 * Real-engine tests for {@see NotificationTemplateRepository} +
 * {@see DatabaseNotificationRenderer}: resolution precedence (tenant override >
 * global default; exact locale > default locale), CRUD, HTML-escaped
 * interpolation, and the fallback-to-inline when no template exists.
 */
final class NotificationTemplateRendererRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const GLOBAL = 0;

    private PDO $pdo;
    private NotificationTemplateRepository $repo;
    private DatabaseNotificationRenderer $renderer;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (0,'system','system'),(1,'a','a'),(2,'b','b') ON CONFLICT (id) DO NOTHING");
        $this->repo = new NotificationTemplateRepository($this->pdo);
        $this->renderer = new DatabaseNotificationRenderer($this->repo);
    }

    // ---- resolution precedence ----

    public function testGlobalDefaultResolvesWhenNoTenantOverride(): void
    {
        $this->repo->upsert(self::GLOBAL, 'account.welcome', 'email', '', ['subject' => 'Global welcome']);

        $t = $this->repo->resolve(self::TENANT, 'account.welcome', 'email', null);
        self::assertNotNull($t);
        self::assertSame(self::GLOBAL, $t['tenant_id']);
        self::assertSame('Global welcome', $t['subject']);
    }

    public function testTenantOverrideWinsOverGlobal(): void
    {
        $this->repo->upsert(self::GLOBAL, 'account.welcome', 'email', '', ['subject' => 'Global']);
        $this->repo->upsert(self::TENANT, 'account.welcome', 'email', '', ['subject' => 'Tenant override']);

        $t = $this->repo->resolve(self::TENANT, 'account.welcome', 'email', null);
        self::assertNotNull($t);
        self::assertSame(self::TENANT, $t['tenant_id']);
        self::assertSame('Tenant override', $t['subject']);
    }

    public function testExactLocaleWinsOverDefaultLocale(): void
    {
        $this->repo->upsert(self::TENANT, 'account.welcome', 'email', '', ['subject' => 'Default locale']);
        $this->repo->upsert(self::TENANT, 'account.welcome', 'email', 'fr', ['subject' => 'Bienvenue']);

        $fr = $this->repo->resolve(self::TENANT, 'account.welcome', 'email', 'fr');
        self::assertNotNull($fr);
        self::assertSame('Bienvenue', $fr['subject']);
        // A locale with no exact row falls back to the default-locale template.
        $de = $this->repo->resolve(self::TENANT, 'account.welcome', 'email', 'de');
        self::assertNotNull($de);
        self::assertSame('Default locale', $de['subject']);
    }

    public function testResolveReturnsNullWhenNoMatch(): void
    {
        self::assertNull($this->repo->resolve(self::TENANT, 'no.such.type', 'email', null));
    }

    // ---- CRUD ----

    public function testUpsertIsIdempotentAndFindDeleteRoundTrip(): void
    {
        $this->repo->upsert(self::TENANT, 'x', 'email', '', ['subject' => 'v1']);
        $this->repo->upsert(self::TENANT, 'x', 'email', '', ['subject' => 'v2']); // same key
        self::assertCount(1, $this->repo->listForTenant(self::TENANT));
        self::assertSame('v2', $this->repo->find(self::TENANT, 'x', 'email', '')['subject'] ?? null);

        self::assertTrue($this->repo->delete(self::TENANT, 'x', 'email', ''));
        self::assertNull($this->repo->find(self::TENANT, 'x', 'email', ''));
    }

    // ---- renderer ----

    public function testRendersResolvedTemplateWithInterpolation(): void
    {
        $this->repo->upsert(self::TENANT, 'account.welcome', 'email', '', [
            'subject'   => 'Welcome, {{name}}',
            'body_text' => 'Hi {{name}}',
            'body_html' => '<p>Hi {{name}}</p>',
        ]);

        $r = $this->renderer->render(self::TENANT, 'account.welcome', 'email', null, ['data' => ['name' => 'Alice']]);
        self::assertSame('Welcome, Alice', $r->subject);
        self::assertSame('Hi Alice', $r->body);
        self::assertSame('<p>Hi Alice</p>', $r->bodyHtml);
    }

    public function testHtmlBodyEscapesDataButTextDoesNot(): void
    {
        $this->repo->upsert(self::TENANT, 'x', 'email', '', [
            'subject'   => '{{v}}',
            'body_text' => 'text: {{v}}',
            'body_html' => '<p>{{v}}</p>',
        ]);

        $r = $this->renderer->render(self::TENANT, 'x', 'email', null, ['data' => ['v' => '<b>hi</b>&"']]);
        // Subject + plain text are verbatim (not HTML); the HTML body is escaped.
        self::assertSame('<b>hi</b>&"', $r->subject);
        self::assertSame('text: <b>hi</b>&"', $r->body);
        self::assertSame('<p>&lt;b&gt;hi&lt;/b&gt;&amp;&quot;</p>', $r->bodyHtml, 'data is HTML-escaped in the HTML body (XSS-safe)');
    }

    public function testFallsBackToInlineWhenNoTemplate(): void
    {
        $r = $this->renderer->render(self::TENANT, 'unregistered.type', 'email', null, [
            'subject' => 'Inline {{n}}',
            'body'    => 'Inline body',
            'data'    => ['n' => 'X'],
        ]);
        self::assertSame('Inline X', $r->subject, 'no template => caller-supplied inline content renders (passthrough)');
        self::assertSame('Inline body', $r->body);
    }
}
