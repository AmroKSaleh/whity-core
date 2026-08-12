<?php

declare(strict_types=1);

namespace Tests\Core\i18n;

use PHPUnit\Framework\TestCase;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\i18n\TranslationDomain;

/**
 * The translation-domain naming convention.
 *
 * The rule these tests pin down: core domains are BARE (`auth`), a plugin's are
 * `<source-slug>:<slug>` (`acme:catalog`). Getting this wrong is expensive
 * later — a domain is written into every seeded row, so a convention changed
 * after a thousand keys exist is a data migration, not an edit.
 */
final class TranslationDomainTest extends TestCase
{
    public function testBareSlugsAreValidCoreDomains(): void
    {
        foreach (['auth', 'common', 'errors', 'email', 'documents', 'a', 'a1', 'two_words'] as $domain) {
            $this->assertTrue(TranslationDomain::isValid($domain), "'{$domain}' should be a valid core domain.");
        }
    }

    public function testPluginNamespacedDomainsAreValid(): void
    {
        foreach (['acme:catalog', 'demo_catalog:record', 'hello:a1'] as $domain) {
            $this->assertTrue(TranslationDomain::isValid($domain), "'{$domain}' should be a valid plugin domain.");
        }
    }

    /**
     * @dataProvider malformedDomainProvider
     */
    public function testMalformedDomainsAreRefused(string $domain): void
    {
        $this->assertFalse(TranslationDomain::isValid($domain), "'{$domain}' must be refused.");
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedDomainProvider(): array
    {
        return [
            'empty' => [''],
            'leading digit' => ['1auth'],
            'uppercase' => ['Auth'],
            'hyphen' => ['auth-screen'],
            'dot' => ['auth.login'],
            'space' => ['auth login'],
            'trailing separator' => ['acme:'],
            'leading separator' => [':catalog'],
            'two separators' => ['acme:sub:catalog'],
            'slash (path traversal shape)' => ['../etc'],
        ];
    }

    /**
     * Core keeps the reserved unprefixed namespace — its domains are stored
     * bare, exactly as `common`/`email`/`errors` already are, so namespacing
     * plugins never rewrites data already seeded.
     */
    public function testCoreDomainsStayBare(): void
    {
        $this->assertSame('auth', TranslationDomain::canonical(TranslationDomain::CORE_SOURCE, 'auth'));
        $this->assertSame(TranslationDomain::CORE_SOURCE, TranslationDomain::namespaceOf('auth'));
    }

    /**
     * A plugin's domain is derived from the SOURCE the loader supplies, never
     * from the plugin's own data — so a plugin cannot mint a bare key and
     * shadow a core domain, nor collide with another plugin's same-named one.
     */
    public function testPluginDomainsAreNamespacedByTheirSource(): void
    {
        $this->assertSame('acme:catalog', TranslationDomain::canonical('Acme', 'catalog'));
        $this->assertSame('widgets:catalog', TranslationDomain::canonical('Vendor\\Widgets', 'catalog'));

        $this->assertNotSame(
            TranslationDomain::canonical('Acme', 'catalog'),
            TranslationDomain::canonical('Other', 'catalog'),
            'Two plugins declaring the same slug must not share a bundle.'
        );

        $this->assertNotSame(
            'common',
            TranslationDomain::canonical('Acme', 'common'),
            'A plugin cannot shadow a core domain.'
        );

        $this->assertSame('acme', TranslationDomain::namespaceOf('acme:catalog'));
    }

    /**
     * One namespacing shape across the platform: the separator here is the same
     * character the resource-type registry uses for `acme:record`. A reader who
     * has learned one has learned the other.
     */
    public function testSeparatorMatchesTheResourceTypeRegistry(): void
    {
        $this->assertSame(
            ResourceTypeRegistry::NAMESPACE_SEPARATOR,
            TranslationDomain::NAMESPACE_SEPARATOR
        );
    }

    /**
     * Both registries reduce a plugin name to a slug the SAME way (they share
     * SourceSlug), so one cannot call a plugin `acme` while the other calls it
     * `acme_widgets`.
     */
    public function testSourceSlugAgreesWithTheResourceTypeRegistry(): void
    {
        $this->assertSame(
            ResourceTypeRegistry::canonicalKey('Vendor\\Widgets', 'record'),
            TranslationDomain::canonical('Vendor\\Widgets', 'record')
        );
    }
}
