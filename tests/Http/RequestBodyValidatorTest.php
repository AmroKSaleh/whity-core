<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Http\JsonBody;
use Whity\Http\Middleware\RequestBodyValidator;

/**
 * WC-189 centralized request-body validation layer.
 *
 * A single pipeline middleware enforces the request-body ENVELOPE uniformly for
 * body-carrying methods (POST/PUT/PATCH): a sane size cap and well-formed JSON
 * OBJECT shape. The `Content-Type` header is INTENTIONALLY NOT enforced — CSRF
 * is handled via `X-Requested-With` (WC-160) and the platform's API clients post
 * JSON bodies without always labeling them `application/json`, so the contract is
 * the body shape/size, not its declared media type. Every rejection collapses to
 * a GENERIC 400 that never leaks parser/exception text, mirroring the WC-186
 * no-leakage rule. Valid envelopes are stashed for the handler (no re-decode) and
 * the request proceeds untouched.
 */
class RequestBodyValidatorTest extends TestCase
{
    private RequestBodyValidator $validator;

    protected function setUp(): void
    {
        // Construct with an explicit small cap so the size test does not depend
        // on the multi-megabyte default (and runs cheaply).
        $this->validator = new RequestBodyValidator(64);
    }

    /**
     * @return callable(Request): Response
     */
    private function nextHandler(bool &$reached, ?Request &$seen = null): callable
    {
        return static function (Request $req) use (&$reached, &$seen): Response {
            $reached = true;
            $seen = $req;
            return new Response(200, 'ok');
        };
    }

    /**
     * Assert a rejection is a generic 400 whose body leaks no parser detail.
     */
    private function assertGenericRejection(Response $response): void
    {
        $this->assertSame(400, $response->getStatusCode());

        $body = $response->getBody();
        /** @var array<string, mixed> $data */
        $data = json_decode($body, true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('error', $data);

        // No raw json_decode/exception text must ever surface to the client.
        $haystack = strtolower($body);
        foreach (['syntax error', 'malformed', 'json_decode', 'unexpected', 'control character', 'exception', 'stack trace'] as $needle) {
            $this->assertStringNotContainsString($needle, $haystack, "Response must not leak parser detail: {$needle}");
        }
    }

    // ---- size ------------------------------------------------------------

    public function testOversizedBodyRejectedWith400(): void
    {
        $payload = json_encode(['blob' => str_repeat('x', 200)]);
        self::assertIsString($payload);
        $request = new Request('POST', '/api/users', ['Content-Type' => 'application/json'], $payload);

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached, 'Oversized body must not reach the handler');
        $this->assertGenericRejection($response);
    }

    public function testBodyAtExactLimitPasses(): void
    {
        // A JSON object whose serialized length is <= the 64-byte cap.
        $payload = '{"k":"' . str_repeat('a', 50) . '"}';
        self::assertLessThanOrEqual(64, strlen($payload));
        $request = new Request('POST', '/api/users', ['Content-Type' => 'application/json'], $payload);

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
    }

    // ---- content type (intentionally NOT enforced) -----------------------

    /**
     * Regression guard (WC-189 e2e fix): a well-formed JSON OBJECT body must be
     * ACCEPTED even when the request carries a NON-json content type. The
     * platform's API clients (web/lib/api/client.ts, web/lib/api-client.ts) send
     * a string `fetch` body — which defaults to `text/plain;charset=UTF-8` — and
     * rely on `X-Requested-With` (WC-160), not Content-Type, for CSRF defense.
     * Rejecting on the header here broke OU/role CRUD writes; it must not return.
     */
    public function testAcceptsJsonObjectBodyWithoutApplicationJsonContentType(): void
    {
        $request = new Request(
            'POST',
            '/api/ous',
            ['Content-Type' => 'text/plain;charset=UTF-8', 'X-Requested-With' => 'XMLHttpRequest'],
            '{"name":"Engineering"}'
        );

        $reached = false;
        $seen = null;
        $response = $this->validator->handle($request, $this->nextHandler($reached, $seen));

        $this->assertTrue($reached, 'A JSON object body must pass regardless of Content-Type');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(Request::class, $seen);
        // The body is still decoded and stashed for the handler.
        $this->assertSame(['name' => 'Engineering'], JsonBody::parsed($seen));
    }

    public function testAcceptsJsonObjectBodyWithMissingContentType(): void
    {
        // No Content-Type header at all: still accepted as long as the body is a
        // well-formed JSON object. (Content-Type is not part of the contract.)
        $request = new Request('POST', '/api/users', [], '{"email":"a@b.c"}');

        $reached = false;
        $seen = null;
        $response = $this->validator->handle($request, $this->nextHandler($reached, $seen));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(Request::class, $seen);
        $this->assertSame(['email' => 'a@b.c'], JsonBody::parsed($seen));
    }

    public function testJsonContentTypeWithCharsetParamAccepted(): void
    {
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json; charset=utf-8'],
            '{"email":"a@b.c"}'
        );

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testNonJsonContentTypeWithMalformedBodyStillRejected(): void
    {
        // The shape check still applies regardless of Content-Type: a non-json
        // content type does NOT exempt a malformed/non-object body from rejection.
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'text/plain'],
            'email=a@b.c&password=secret'
        );

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached, 'A non-object body must still be rejected on shape');
        $this->assertGenericRejection($response);
    }

    // ---- shape -----------------------------------------------------------

    public function testMalformedJsonRejectedWithGeneric400(): void
    {
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            '{"email": "a@b.c"' // missing closing brace
        );

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached, 'Malformed JSON must not reach the handler');
        $this->assertGenericRejection($response);
    }

    public function testJsonArrayBodyRejected(): void
    {
        // Well-formed JSON, but a top-level array is not an accepted object body.
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            '[1,2,3]'
        );

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached);
        $this->assertGenericRejection($response);
    }

    public function testJsonScalarBodyRejected(): void
    {
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            '"just a string"'
        );

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached);
        $this->assertGenericRejection($response);
    }

    public function testEmptyJsonObjectAccepted(): void
    {
        // `{}` is a legitimate body for an all-optional payload (and PHP decodes
        // both `{}` and `[]` to []); it must pass and yield an empty map.
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            '{}'
        );

        $reached = false;
        $seen = null;
        $response = $this->validator->handle($request, $this->nextHandler($reached, $seen));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(Request::class, $seen);
        $this->assertSame([], JsonBody::parsed($seen));
    }

    public function testValidObjectBodyPassesAndIsStashed(): void
    {
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            '{"email":"a@b.c","password":"secret"}'
        );

        $reached = false;
        $seen = null;
        $response = $this->validator->handle($request, $this->nextHandler($reached, $seen));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());

        // The middleware stashes the validated body so handlers read it back
        // without re-decoding php://input (read-once).
        $this->assertInstanceOf(Request::class, $seen);
        $this->assertSame(['email' => 'a@b.c', 'password' => 'secret'], JsonBody::parsed($seen));
    }

    // ---- empty body / non-body methods -----------------------------------

    public function testEmptyBodyOnPatchPasses(): void
    {
        // PATCH/DELETE with no body is legitimate (e.g. a no-op update). An empty
        // body must not be forced through content-type / shape checks.
        $request = new Request('PATCH', '/api/users/7', [], '');

        $reached = false;
        $seen = null;
        $response = $this->validator->handle($request, $this->nextHandler($reached, $seen));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertInstanceOf(Request::class, $seen);
        // An absent body parses to an empty array for the handler.
        $this->assertSame([], JsonBody::parsed($seen));
    }

    public function testGetWithBodyIsIgnored(): void
    {
        // Reads are never body-validated; even a malformed body on a GET passes
        // through untouched (the route simply ignores any body).
        $request = new Request('GET', '/api/users', ['Content-Type' => 'application/json'], 'not json');

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeleteWithMalformedBodyRejected(): void
    {
        // DELETE is state-changing; a PRESENT body must still be a valid envelope.
        $request = new Request(
            'DELETE',
            '/api/users/7',
            ['Content-Type' => 'application/json'],
            '{bad'
        );

        $reached = false;
        $response = $this->validator->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached);
        $this->assertGenericRejection($response);
    }

    // ---- JsonBody fallback ------------------------------------------------

    public function testJsonBodyFallsBackToDecodeWhenNotStashed(): void
    {
        // A request that never passed through the middleware (e.g. a unit test
        // exercising a handler directly) must still yield a usable array.
        $request = new Request(
            'POST',
            '/api/users',
            ['Content-Type' => 'application/json'],
            '{"email":"a@b.c"}'
        );

        $this->assertSame(['email' => 'a@b.c'], JsonBody::parsed($request));
    }

    public function testJsonBodyFallbackReturnsEmptyArrayForJunk(): void
    {
        $request = new Request('POST', '/api/users', ['Content-Type' => 'application/json'], 'garbage');

        $this->assertSame([], JsonBody::parsed($request));
    }
}
