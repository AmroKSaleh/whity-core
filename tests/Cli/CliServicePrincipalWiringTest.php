<?php

declare(strict_types=1);

namespace Whity\Tests\Cli;

use PHPUnit\Framework\TestCase;

/**
 * #928: the CLI kernel must authorize as a real principal, at tenant 0, and
 * must register every tenant route its own commands call.
 *
 * The principal and its protections are covered against a real engine by
 * {@see \Tests\Integration\ServicePrincipalRealEngineTest}. What that cannot
 * reach is the WIRING: {@see \Whity\Cli\Commands\BaseCommand::setupKernel()}
 * opens a live database connection and loads every plugin, so a unit test cannot
 * run it — the same reason {@see CliAuditWiringTest} scans source, and the same
 * conventions these entry points keep drifting on (#717, #724, #727).
 *
 * The drift this pins is not hypothetical. The token kept its pre-cutover claim
 * shape for seven weeks after the identity cutover made it invalid, and nothing
 * failed: every command test mocks `callApi()`, so the suite never built a
 * kernel or minted a token, and the entire CLI API surface answered 401 in
 * silence.
 */
final class CliServicePrincipalWiringTest extends TestCase
{
    private function baseCommand(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/Cli/Commands/BaseCommand.php'
        );
    }

    /**
     * The regression itself: without `profile_id`, RbacMiddleware fails closed.
     */
    public function testTheTokenCarriesAProfileIdClaim(): void
    {
        self::assertMatchesRegularExpression(
            "/'profile_id'\s*=>/",
            $this->baseCommand(),
            'RbacMiddleware requires an integer profile_id and returns 401 Invalid token payload '
            . 'without one. This is the claim whose absence killed every gated CLI route.'
        );
    }

    /**
     * And it must be a REAL id, not a constant.
     *
     * The middleware checks the role against the authoritative store, so a
     * literal would fail the role check instead of the claim check — the same
     * 401 one line later, which is a worse outcome than the original because it
     * looks like a permissions problem.
     */
    public function testTheProfileIdIsResolvedFromTheStoreRatherThanHardcoded(): void
    {
        $source = $this->baseCommand();

        self::assertMatchesRegularExpression(
            "/'profile_id'\s*=>\s*self::servicePrincipalId\(/",
            $source,
            'The id must be looked up, not written down.'
        );
        self::assertMatchesRegularExpression(
            "/auth_method = '\" \. AuthMethod::SERVICE \. \"'/",
            $source,
            'And looked up by the held fact, so no second convention (a fixed id, an email) has '
            . 'to be kept in step with migration 107.'
        );
    }

    /**
     * The pre-cutover claims must be gone, not merely joined.
     *
     * Leaving `user_id`/`role` beside `profile_id` would keep a second, stale
     * account of who the caller is — and the next person to read the token would
     * have two answers with nothing saying which is authoritative.
     */
    public function testThePreCutoverClaimsAreRemoved(): void
    {
        $source = $this->baseCommand();

        self::assertStringNotContainsString("'user_id' => 0", $source);
        self::assertStringNotContainsString("'role' => 'admin'", $source);
    }

    /**
     * Tenant 0, because EnforceTenantIsolation pins the request to it.
     *
     * With `tenant_id: 1` the isolation middleware refuses any path-declared
     * tenant that is not 1, so `tenant update 5` would 403 even with the 401
     * fixed — a second failure hiding behind the first.
     */
    public function testTheTokenIsScopedToTheSystemTenant(): void
    {
        self::assertMatchesRegularExpression(
            "/'tenant_id'\s*=>\s*0\b/",
            $this->baseCommand(),
            'Platform-wide commands need the platform-wide scope.'
        );
        self::assertDoesNotMatchRegularExpression(
            "/'tenant_id'\s*=>\s*1\b/",
            $this->baseCommand()
        );
    }

    /**
     * A missing principal must be loud.
     *
     * Falling back to a token with no profile would reproduce the original 401
     * with a more confusing message; falling back to another identity would
     * attribute operator commands to a real person. Neither is better than
     * saying the database has not been migrated.
     */
    public function testAMissingPrincipalIsFatalAndSaysWhatToDo(): void
    {
        $source = $this->baseCommand();

        self::assertMatchesRegularExpression('/throw new \\\\RuntimeException/', $source);
        self::assertStringContainsString('migrate', $source);
    }

    /**
     * The tenant routes the CLI's own commands call must all be registered.
     *
     * `tenant create/update/delete` were never in this router, so they answered
     * 405 — an independent defect the 401 hid completely, because nothing
     * reached routing to discover it.
     *
     * @dataProvider tenantRoutes
     */
    public function testEveryTenantRouteTheCliCallsIsRegistered(string $method, string $path): void
    {
        self::assertMatchesRegularExpression(
            "/register\(\s*'" . $method . "'\s*,\s*'" . preg_quote($path, '/') . "'/",
            $this->baseCommand(),
            "`{$method} {$path}` is called by a documented CLI command; unregistered it answers 405."
        );
    }

    /** @return array<string, array{string, string}> */
    public static function tenantRoutes(): array
    {
        return [
            'list'   => ['GET', '/api/tenants'],
            'create' => ['POST', '/api/tenants'],
            'update' => ['PATCH', '/api/tenants/{id}'],
            'delete' => ['DELETE', '/api/tenants/{id}'],
        ];
    }
}
