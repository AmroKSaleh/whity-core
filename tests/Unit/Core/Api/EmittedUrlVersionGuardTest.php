<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Api;

use PHPUnit\Framework\TestCase;
use Whity\Core\Api\EmittedUrlVersionGuard;

/**
 * Detection-logic tests for the emitted-URL version guard (#1046).
 *
 * BOTH DIRECTIONS, BEFORE MERGE, because #1046 asks for exactly that and the
 * reason is sound: a rule that fires on today's defect might fire on
 * everything, and one that ignores a correct line might ignore everything. Only
 * the pair says which.
 *
 * The negative cases had to be SYNTHESISED. There is no correctly-versioned
 * emission in the tree that this narrow rule would see — the backend hardly ever
 * emits client-ready URLs at all, which is precisely why the one place that does
 * got it wrong (#1016) and went a whole release unnoticed.
 */
final class EmittedUrlVersionGuardTest extends TestCase
{
    private EmittedUrlVersionGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new EmittedUrlVersionGuard();
    }

    /** @return list<string> the offending literals */
    private function scan(string $php): array
    {
        return array_column($this->guard->scanSource("<?php\n" . $php), 'literal');
    }

    // ── FIRES: a bare /api/ path handed to a client ──────────────────────────

    public function testFlagsABareLiteralReturnedDirectly(): void
    {
        self::assertSame(
            ['/api/documents/5/content'],
            $this->scan('function u() { return "/api/documents/5/content"; }')
        );
    }

    public function testFlagsASprintfBuiltPath(): void
    {
        self::assertSame(
            ['/api/branding/asset/%d/%s'],
            $this->scan('function u(int $t, string $n) { return sprintf("/api/branding/asset/%d/%s", $t, $n); }')
        );
    }

    public function testFlagsAConcatenatedPath(): void
    {
        self::assertSame(
            ['/api/auth/sso/'],
            $this->scan('function u(string $k) { return rtrim($this->appUrl, "/") . "/api/auth/sso/" . $k; }')
        );
    }

    public function testFlagsAUrlKeyEvenWhenNotReturned(): void
    {
        // The `return` rule alone would miss this: the emission is an array
        // entry the presenter hands back later.
        self::assertSame(
            ['/api/documents/5/content'],
            $this->scan('$payload = ["id" => 5, "content_url" => "/api/documents/5/content"];')
        );
    }

    public function testFlagsAnInterpolatedPath(): void
    {
        self::assertSame(
            ['/api/documents/{$id}/content'],
            $this->scan('function u(int $id) { return "/api/documents/{$id}/content"; }')
        );
    }

    // ── SILENT: the same shapes, done correctly ──────────────────────────────

    public function testIgnoresAnAlreadyVersionedLiteral(): void
    {
        self::assertSame([], $this->scan('function u() { return "/api/v1/documents/5/content"; }'));
    }

    public function testIgnoresAVersionedSprintf(): void
    {
        self::assertSame(
            [],
            $this->scan('function u(int $t, string $n) { return sprintf("/api/v1/branding/asset/%d/%s", $t, $n); }')
        );
    }

    public function testIgnoresAVersionedUrlKey(): void
    {
        self::assertSame([], $this->scan('$p = ["content_url" => "/api/v1/documents/5/content"];'));
    }

    public function testIgnoresAFutureMajorVersion(): void
    {
        // A version bump must not require editing this guard.
        self::assertSame([], $this->scan('function u() { return "/api/v2/documents/5/content"; }'));
    }

    public function testIgnoresALiteralPassedThroughAVersioningHelper(): void
    {
        // The real DocumentPresenter shape. Flagging this would force a
        // suppression at every correct call site.
        self::assertSame([], $this->scan('function u(int $id) { return self::apiPath("/api/documents/{$id}/content"); }'));
        self::assertSame([], $this->scan('function u(string $p) { return $this->router->versionedPath("/api/things"); }'));
    }

    // ── SILENT: declarations, which are correct BARE ─────────────────────────

    public function testIgnoresARouteDeclaration(): void
    {
        // `Router::versionPrefix()` adds /v1 at registration, so a declaration
        // written bare is not merely tolerated — it is the required form.
        self::assertSame([], $this->scan('$router->register("GET", "/api/users", $h, null, null, "users:read");'));
        self::assertSame([], $this->scan('$route = ["method" => "GET", "path" => "/api/users"];'));
    }

    public function testIgnoresProseThatMentionsThePrefix(): void
    {
        // The three $drop() messages in PluginLoader describe the rule. Reading
        // them as breaches of it is how a guard starts crying wolf.
        self::assertSame(
            [],
            $this->scan('function u() { return $drop("resource.basePath must be a string starting with \'/api/\'", $id); }')
        );
    }

    public function testIgnoresComments(): void
    {
        // Including this guard's own documentation, and the `@return string The
        // prefixed path (e.g. '/api/v1/users')` docblocks in Router.
        self::assertSame([], $this->scan("// return \"/api/documents/5\";\nfunction u() { return 1; }"));
        self::assertSame([], $this->scan("/** e.g. '/api/users' becomes '/api/v1/users' */\nfunction u() { return 1; }"));
    }

    // ── The scanner itself ───────────────────────────────────────────────────

    public function testReportsTheLineTheLiteralIsOn(): void
    {
        // Comments are blanked LENGTH-PRESERVING so offsets still map to source
        // lines; a naive strip would shift every report upward.
        $violations = $this->guard->scanSource(
            "<?php\n/* a\n   multi-line\n   comment */\nfunction u() { return \"/api/x\"; }\n"
        );

        self::assertCount(1, $violations);
        self::assertSame(5, $violations[0]['line']);
    }

    public function testTheRealTreeIsClean(): void
    {
        // The guard has to pass on src/ the day it lands, or it is not a gate —
        // it is a backlog. #1020 removed the last emitter that would have fired.
        $violations = $this->guard->scanDirectory(dirname(__DIR__, 4) . '/src');

        self::assertSame(
            [],
            array_map(
                static fn (array $v): string => sprintf('%s:%d %s', basename($v['file']), $v['line'], $v['literal']),
                $violations
            )
        );
    }
}
