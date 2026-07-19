<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PersonsApiHandler;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) regression test for the `search` filter on
 * {@see PersonsApiHandler::list()}.
 *
 * WC-537 (audit follow-up to WC-167): at runtime FrankenPHP strips the query
 * string from the path (Request::fromGlobals() keeps the path only), so a
 * filter read via parse_url($request->getPath(), PHP_URL_QUERY) alone is DEAD
 * in production — only the $_GET superglobal carries the real query there.
 * PersonsApiHandler::queryParam() was missed by the original WC-167 pass
 * (DelegationsApiHandler / AuditLogApiHandler / PaginationParams were fixed);
 * this locks in the fix.
 */
final class PersonsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        TenantContext::setTenantId(1);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $_GET = [];
    }

    private function handler(): PersonsApiHandler
    {
        return new PersonsApiHandler(new PersonRepository($this->pdo), new RelationRepository($this->pdo));
    }

    private function seedPerson(string $displayName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO persons (tenant_id, display_name) VALUES (1, ?)'
        );
        $stmt->execute([$displayName]);
    }

    /**
     * The documented `search` filter must apply when it arrives via $_GET (the
     * runtime shape) — the path-query form below only ever existed in tests.
     */
    public function testSearchFilterIsReadFromTheGetSuperglobal(): void
    {
        $this->seedPerson('Alice Example');
        $this->seedPerson('Bob Other');

        $_GET = ['search' => 'Alice'];
        $response = $this->handler()->list(new Request('GET', '/api/persons'));

        $body = json_decode($response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $body['data'] ?? []);
        self::assertSame('Alice Example', $body['data'][0]['displayName'] ?? null);
    }

    /**
     * With no search term at all (neither $_GET nor path), every person in the
     * tenant is returned — proves the filter is optional, not just silently broken.
     */
    public function testNoSearchTermReturnsAllTenantPersons(): void
    {
        $this->seedPerson('Alice Example');
        $this->seedPerson('Bob Other');

        $response = $this->handler()->list(new Request('GET', '/api/persons'));

        $body = json_decode($response->getBody(), true);
        self::assertCount(2, $body['data'] ?? []);
    }

    /**
     * A search term that matches nothing returns an empty list, not an error —
     * distinguishes "filter applied, no match" from "filter silently ignored".
     */
    public function testSearchFilterExcludesNonMatchingPersons(): void
    {
        $this->seedPerson('Alice Example');

        $_GET = ['search' => 'Zzz-no-match'];
        $response = $this->handler()->list(new Request('GET', '/api/persons'));

        $body = json_decode($response->getBody(), true);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([], $body['data'] ?? null);
    }
}
