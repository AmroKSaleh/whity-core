<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Sdk\Http\Response;

/**
 * #1078: a route parameter reaches its handler as the identifier that was
 * REQUESTED, and a handler that looks a record up by it finds the right one.
 *
 * WHY A SECOND TEST FILE, BESIDE {@see RouterTest}. RouterTest pins the
 * mechanism — `match()` decodes, once, with `rawurldecode`. This pins the
 * CONSEQUENCE, which is the thing anybody actually cares about and the thing
 * that was wrong: ask for a record, get that record. The two are worth
 * separating because the mechanism has an obvious-looking fix that does not
 * deliver the consequence, and vice versa:
 *
 *  - decoding with `urldecode` passes every "is it decoded?" assertion and
 *    still hands the handler the wrong identifier whenever one contains a `+`;
 *  - decoding TWICE passes them too, and turns an identifier that legitimately
 *    contains `%20` into a different one;
 *  - and a "fix" that makes the lookup miss LOUDLY — 404 instead of the wrong
 *    row — would satisfy "no longer returns the wrong record" while being no
 *    use at all. That is why {@see testAnIdentifierThatGenuinelyDoesNotExistIsStillNotFound}
 *    sits next to the positive cases rather than in place of them.
 *
 * THE SHAPE OF THE ORIGINAL FAILURE, since a passing test does not carry it.
 * `Request::fromGlobals()` takes the path from `REQUEST_URI` through
 * `parse_url()`, which does not decode, and nothing downstream decoded either —
 * there was no `rawurldecode` anywhere in `src/`, `sdk/src/` or
 * `public/index.php`. So a handler received `%D8%B7%D8%A7%D9%84%D8%A8` where the
 * record is called `طالب`, missed, and — because a lookup that misses usually
 * falls back to something rather than failing — answered 200 with a DIFFERENT
 * record. Measured on the in-tree reference plugin before the fix:
 *
 *     GET /api/v1/uikit/demo/rows/Bjorn%20Larsen
 *       params  {"name":"Bjorn%20Larsen"}
 *       returns name='Anika Patel'
 *
 * An Arabic identifier percent-encodes ENTIRELY, so on a platform whose domains
 * are fully bilingual this was not an edge case about spaces in names — it was
 * the default for every institution whose records are named in Arabic.
 */
final class PathParameterDecodingTest extends TestCase
{
    /**
     * A record store keyed by identifiers as a person would type them — Arabic,
     * accented Latin, a space, a plus, and a literal percent sequence.
     *
     * Deliberately NOT keyed by anything encoded: the whole point is that a
     * handler stores and looks up real identifiers and never has to know how the
     * transport spelled them.
     *
     * @return array<string, string>
     */
    private static function records(): array
    {
        return [
            "\u{0637}\u{0627}\u{0644}\u{0628}" => 'record-arabic-word',
            "\u{0642}\u{0633}\u{0645} \u{0627}\u{0644}\u{0644}\u{063A}\u{0629}" => 'record-arabic-phrase',
            'Ada Lovelace' => 'record-space',
            "Ren\u{00E9} Char" => 'record-accented',
            'C++' => 'record-plus',
            'literal%20percent' => 'record-literal-percent',
            'plain' => 'record-plain',
        ];
    }

    /**
     * A router carrying one lookup route whose handler answers 404 on a miss.
     *
     * The 404 is the part that matters: the reference plugin's fixture falls
     * back to a default record instead, which is exactly why the bug was
     * invisible for so long. A handler that fails honestly makes the difference
     * between "found the right one" and "found nothing" observable.
     */
    private function routerWithLookupRoute(): Router
    {
        $router = new Router('');
        $router->register(
            'GET',
            '/api/records/{name}',
            static function (Request $request, array $params = []): Response {
                $records = self::records();
                $name = $params['name'] ?? '';

                if (!\array_key_exists($name, $records)) {
                    return Response::json(['error' => 'Not Found'], 404);
                }

                return Response::json(['data' => ['id' => $records[$name], 'name' => $name]]);
            }
        );

        return $router;
    }

    /**
     * Dispatch the URL a client actually sends for `$identifier` and return the
     * handler's answer.
     *
     * `rawurlencode` is the encoding side of the same pair the router decodes
     * with, and it is what every client here already does: `resolveContextPath`
     * and `FormProvider` both `encodeURIComponent` a token's value, which agrees
     * with `rawurlencode` for every character either can produce.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function get(string $identifier): array
    {
        $router = $this->routerWithLookupRoute();
        $path = '/api/records/' . rawurlencode($identifier);

        $matched = $router->match(new Request('GET', $path));
        $this->assertNotNull($matched, "the route must match '{$path}'");

        // The kernel passes `$matchedRoute['params']` to the handler untouched,
        // so invoking it directly with them is the same call it would make.
        $response = ($matched['handler'])(new Request('GET', $path), $matched['params']);

        /** @var array<string, mixed> $body */
        $body = json_decode($response->getBody(), true) ?? [];

        return ['status' => $response->getStatusCode(), 'body' => $body];
    }

    /**
     * Ask for a record, get THAT record.
     *
     * @param string $identifier The identifier as stored, and as a person types it.
     * @param string $expectedId The record it must resolve to.
     */
    #[DataProvider('identifiers')]
    public function testAnIdentifierResolvesToItsOwnRecord(string $identifier, string $expectedId): void
    {
        $result = $this->get($identifier);

        $this->assertSame(
            200,
            $result['status'],
            "'{$identifier}' is in the store, so the lookup must find it"
        );
        $this->assertSame(
            $expectedId,
            $result['body']['data']['id'] ?? null,
            "'{$identifier}' must resolve to its OWN record — before #1078 the lookup missed and a "
            . 'handler with a fallback answered 200 with a different one'
        );
        $this->assertSame(
            $identifier,
            $result['body']['data']['name'] ?? null,
            'and the handler must have been handed the identifier as requested, not as transmitted'
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function identifiers(): array
    {
        return [
            // The headline case. Every byte escapes, so this is not a subset of
            // "names with spaces" — it is every Arabic identifier there is.
            'arabic word' => ["\u{0637}\u{0627}\u{0644}\u{0628}", 'record-arabic-word'],
            'arabic phrase with a space' => [
                "\u{0642}\u{0633}\u{0645} \u{0627}\u{0644}\u{0644}\u{063A}\u{0629}",
                'record-arabic-phrase',
            ],
            'latin with a space' => ['Ada Lovelace', 'record-space'],
            'accented latin' => ["Ren\u{00E9} Char", 'record-accented'],
            // `urldecode` would turn the transmitted `C%2B%2B` into 'C  ' and
            // miss; `rawurldecode` is the only correct choice for a path.
            'plus sign' => ['C++', 'record-plus'],
            // Decoding twice would turn the transmitted `literal%2520percent`
            // into 'literal percent' and miss.
            'literal percent sequence' => ['literal%20percent', 'record-literal-percent'],
            // Nothing to decode: proof the ordinary case is untouched.
            'nothing to decode' => ['plain', 'record-plain'],
        ];
    }

    /**
     * A DECODE THAT TURNS "WRONG RECORD" INTO "ALWAYS 404" IS NOT A FIX.
     *
     * So the negative case is pinned beside the positive ones: an identifier
     * that genuinely is not in the store must still be reported as absent. With
     * this and {@see testAnIdentifierResolvesToItsOwnRecord} together, a fix
     * that over-decoded, under-decoded, or decoded with the wrong function
     * cannot pass — each of those makes a real identifier miss, which shows up
     * here as a 200 that should have been a 404, or there as a 404 that should
     * have been a 200.
     *
     * @param string $absent An identifier no record uses.
     */
    #[DataProvider('absentIdentifiers')]
    public function testAnIdentifierThatGenuinelyDoesNotExistIsStillNotFound(string $absent): void
    {
        $result = $this->get($absent);

        $this->assertSame(
            404,
            $result['status'],
            "'{$absent}' is in no record, and must be reported absent rather than resolved to a neighbour"
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function absentIdentifiers(): array
    {
        return [
            'unknown arabic word' => ["\u{0645}\u{0639}\u{0644}\u{0645}"],
            'unknown latin' => ['Nobody At All'],
            // The DECODED form of a stored key must not be confused with the key
            // itself: 'literal%20percent' is stored, 'literal percent' is not.
            'the over-decoded form of a stored key' => ['literal percent'],
            // Nor the ENCODED form: a handler that received raw input before the
            // fix would have found this and nothing else.
            'the still-encoded form of a stored key' => ['Ada%20Lovelace'],
            'empty-ish' => ['-'],
        ];
    }

    /**
     * Two identifiers that differ only by encoding are still two identifiers.
     *
     * `Ada Lovelace` is stored; the literal text `Ada%20Lovelace` is not. If
     * decoding ran twice — or if a handler decoded again on top of the router —
     * the second would collapse onto the first and one record would answer for
     * both, which is the same class of wrongness as the original bug pointing
     * the other way.
     */
    public function testEncodingIsNotIdentity(): void
    {
        $this->assertSame(200, $this->get('Ada Lovelace')['status']);
        $this->assertSame(404, $this->get('Ada%20Lovelace')['status']);
    }
}
