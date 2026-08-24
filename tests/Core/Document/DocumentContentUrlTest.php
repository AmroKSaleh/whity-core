<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Router;
use Whity\Sdk\Http\Request;

/**
 * `content_url` must address a route the API actually serves (#1016).
 *
 * WHAT WENT WRONG, AND WHY NOTHING CAUGHT IT
 * ------------------------------------------
 * Both builders named their route the way it is REGISTERED — bare, as every
 * route in this codebase is written — and the Router inserts '/v1' at
 * registration. But `content_url` is a URL the BROWSER fetches, so the emitter
 * has to apply that same insertion itself. It did not, so every content link
 * pointed at '/api/documents/…' while the server served '/api/v1/documents/…'
 * and the viewer's fetch 404'd on every document that had a file to show.
 *
 * The reason it survived is the shape of test this class deliberately is NOT.
 * Asserting the literal string these methods return would have passed just as
 * happily before the fix as after, because the string was self-consistent — it
 * was only wrong RELATIVE TO THE ROUTER. So the assertion that matters is not
 * "does it equal this text" but "does the router resolve it", which is a
 * statement about two files agreeing and cannot be satisfied by a typo in one.
 *
 * {@see \Tests\OpenAPI\RequestSchemaContractTest::testEveryReferenceResolvesToAPathTheSpecServes}
 * is the same guard for `x-whity-reference`, and is the precedent for this one.
 */
final class DocumentContentUrlTest extends TestCase
{
    /**
     * The presenter's URLs resolve against the routes index.php really registers.
     *
     * Registered here through the REAL Router, at its real default prefix, from
     * the path literals really present in public/index.php — so a version bump,
     * a renamed segment or a dropped prefix on either side breaks this.
     */
    public function testEveryContentUrlResolvesToARouteTheApiServes(): void
    {
        $router = new Router('/v1');
        $registered = $this->registerDocumentContentRoutes($router);

        // A scraper that has silently stopped matching is a guard that passes
        // while measuring nothing — the exact failure this file exists to
        // prevent — so the extraction asserts its own yield before being used.
        $this->assertSame(
            2,
            $registered,
            'Expected to find exactly 2 document content routes in public/index.php; '
            . 'the extraction pattern has drifted and this guard is measuring nothing.'
        );

        foreach ([
            'document content_url'  => DocumentPresenter::documentContentUrl(6),
            'artifact content_url'  => DocumentPresenter::artifactContentUrl(6, 7),
        ] as $label => $url) {
            $this->assertNotNull(
                $router->match(new Request('GET', $url)),
                "{$label} '{$url}' is not a GET route the API serves — a client "
                . 'fetching it gets a 404. Emitted URLs must carry the version '
                . 'prefix the Router adds at registration.'
            );
        }
    }

    /**
     * The prefix is DERIVED, not hardcoded, so the links move with the routes.
     *
     * A Router built at a different version has to produce content URLs at that
     * version; if this fails, someone has written '/api/v1/' as a literal and a
     * future version bump will leave every content link pointing at a version
     * that is no longer served.
     */
    public function testContentUrlsCarryTheRoutersOwnPrefixRatherThanALiteral(): void
    {
        $this->assertSame(
            '/api/v1/documents/6/content',
            DocumentPresenter::documentContentUrl(6),
            'document content_url must carry the /v1 the router serves it at'
        );
        $this->assertSame(
            '/api/v1/documents/6/artifacts/7/content',
            DocumentPresenter::artifactContentUrl(6, 7),
            'artifact content_url must carry the /v1 the router serves it at'
        );

        // The transformation itself, at a prefix that is not the default, is
        // what proves the '/v1' above was inserted rather than typed.
        $this->assertSame(
            '/api/v9/documents/6/content',
            (new Router('/v9'))->versionedPath('/api/documents/6/content'),
            'Router::versionedPath() must insert the prefix it was built with'
        );
        $this->assertSame(
            '/api/documents/6/content',
            (new Router(''))->versionedPath('/api/documents/6/content'),
            'An empty prefix must pass the path through untouched'
        );
    }

    /**
     * Register the document content routes exactly as public/index.php does.
     *
     * Reads the path literals out of the live route table rather than restating
     * them, so this guard compares the presenter against the routes the
     * application really has instead of against a second copy that can drift.
     *
     * @return int How many content routes were found and registered.
     */
    private function registerDocumentContentRoutes(Router $router): int
    {
        $indexPhp = file_get_contents(__DIR__ . '/../../../public/index.php');
        self::assertIsString($indexPhp, 'public/index.php is unreadable');

        // Matches: $router->register('GET', '/api/documents/{id:\d+}/content', ...
        $pattern = '/\$router->register\(\s*[\'"]GET[\'"]\s*,\s*[\'"]'
            . '(\/api\/documents\/\{id:[^}]+\}(?:\/artifacts\/\{artifactId:[^}]+\})?\/content)'
            . '[\'"]/';

        $found = preg_match_all($pattern, $indexPhp, $matches);
        if ($found === false || $found === 0) {
            return 0;
        }

        foreach ($matches[1] as $path) {
            $router->register('GET', $path, static fn (): string => 'ok');
        }

        return $found;
    }
}
