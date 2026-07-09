<?php

declare(strict_types=1);

namespace Tests\Integration;

use Firebase\JWT\JWT;
use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SsoAuthHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\JwksProvider;
use Whity\Auth\Oidc\OidcEngine;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\Http\HttpClient;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\FederatedIdentityLinker;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Real-engine tests for the federated sign-in flow (WC-ae16, A5c). Exercises
 * start() (authorize redirect) and callback() end-to-end with a stubbed HTTP
 * client + a real RSA-signed ID token, against the migration-built schema.
 *
 * Focus: the security-critical callback behaviour — state (CSRF) validation,
 * flow-cookie requirement, login only for an already-LINKED identity, and the
 * happy-path session mint → dashboard redirect.
 */
final class SsoAuthHandlerRealEngineTest extends TestCase
{
    private const APP_URL = 'https://app.test';
    private const ISSUER = 'https://accounts.google.com';
    private const CLIENT_ID = 'client-abc.apps.googleusercontent.com';
    private const SUBJECT = 'google-sub-777';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private SsoAuthHandler $handler;
    private string $privatePem;
    /** @var array<string, mixed> */
    private array $jwks;
    private string $kid = 'kid-a';
    private StubOidcHttp $http;
    private int $tenantId;
    private int $profileId;
    private int $providerId;        // GLOBAL-TRUST provider (system tenant 0) — default flow
    private int $tenantProviderId;  // TENANT-TRUST provider (Acme's own IdP)
    private \Whity\Core\Settings\SettingsService $settings;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->jwtParser = new JwtParser('internal_hs256_secret_min_32_chars_xxxx');

        // RSA keypair + JWKS.
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($res === false) {
            self::fail('key gen failed');
        }
        openssl_pkey_export($res, $priv);
        $this->privatePem = $priv;
        $d = openssl_pkey_get_details($res);
        if ($d === false) {
            self::fail('key details failed');
        }
        $this->jwks = ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $this->kid,
            'n' => self::b64url($d['rsa']['n']), 'e' => self::b64url($d['rsa']['e']),
        ]]];

        $this->http = new StubOidcHttp();
        $this->http->discovery = [
            'issuer' => self::ISSUER,
            'authorization_endpoint' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_endpoint' => 'https://oauth2.googleapis.com/token',
            'jwks_uri' => 'https://www.googleapis.com/oauth2/v3/certs',
        ];

        $engine = new OidcEngine(
            $this->http,
            new JwksProvider(fn(string $uri): array => $this->jwks, 3600),
            $this->jwtParser
        );

        $auth = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            null,
            null,
            null,
            new NullLogger(),
            new AuditLogger($this->pdo, new NullLogger()),
            null
        );

        $extRepo = new ExternalIdentityRepository($this->pdo);
        $emailRepo = new ProfileEmailRepository($this->pdo);
        $this->settings = new \Whity\Core\Settings\SettingsService(
            new \Whity\Core\Settings\GlobalSettingsRepository($this->pdo),
            new \Whity\Core\Settings\TenantSettingsRepository($this->pdo)
        );
        $this->handler = new SsoAuthHandler(
            $engine,
            new IdentityProviderRepository($this->pdo),
            $emailRepo,
            new HostResolver(new TenantHostRepository($this->pdo), 'example.test'),
            $this->jwtParser,
            new EncryptedSecretStore(['v1' => 'sso_test_key_0123456789abcdef0123456789'], 'v1'),
            $auth,
            new FederatedIdentityLinker($this->pdo, $extRepo, $emailRepo, new MembershipRepository($this->pdo)),
            $this->settings,
            self::APP_URL
        );

        $this->seed();
        $_GET = [];
        $_COOKIE = [];
    }

    protected function tearDown(): void
    {
        $_GET = [];
        $_COOKIE = [];
    }

    private function seed(): void
    {
        // Tenant (id 0 exists from migration 010; create tenant 1 as the SSO tenant).
        $this->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Acme', 'acme', NOW())");
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $roleId = (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');

        // Profile + primary verified email.
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Alice', '" . password_hash('x', PASSWORD_BCRYPT) . "', false, NULL, 0, 0, NOW(), NOW())");
        $this->profileId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($this->profileId, 'alice@acme.test', true, true);

        // Active membership so completeFederatedLogin can mint a session.
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (:p, :t, :r, NULL, 'active', NOW())")
            ->execute([':p' => $this->profileId, ':t' => $this->tenantId, ':r' => $roleId]);

        // GLOBAL-TRUST provider: operator-configured at the system tenant (0). The
        // default flow uses this, so the person-centric behaviours (verified-email
        // link, provisioning) are exercised on the tier that permits them.
        $providers = new IdentityProviderRepository($this->pdo);
        $this->providerId = $providers->insert(0, [
            'provider_key' => 'google',
            'display_name' => 'Google',
            'client_id' => self::CLIENT_ID,
            'client_secret_encrypted' => null,
            'issuer' => self::ISSUER,
            'discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration',
            'scopes' => 'openid email profile',
            'enabled' => true,
        ]);

        // TENANT-TRUST provider: Acme's own bring-your-own IdP.
        $this->tenantProviderId = $providers->insert($this->tenantId, [
            'provider_key' => 'google',
            'display_name' => 'Acme Google',
            'client_id' => self::CLIENT_ID,
            'client_secret_encrypted' => null,
            'issuer' => self::ISSUER,
            'discovery_url' => 'https://accounts.google.com/.well-known/openid-configuration',
            'scopes' => 'openid email profile',
            'enabled' => true,
        ]);
    }

    private function exec(string $sql): void
    {
        if ($this->pdo->exec($sql) === false) {
            self::fail("exec failed: {$sql}");
        }
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }
        return $stmt->fetchColumn();
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private function linkIdentity(): void
    {
        (new ExternalIdentityRepository($this->pdo))->link($this->profileId, 'google', self::ISSUER, self::SUBJECT, 'alice@acme.test');
    }

    /**
     * Build a valid oidc_flow cookie + matching $_GET, and a signed id_token in
     * the stubbed token response.
     */
    private function primeFlow(
        string $state = 'state-1',
        string $nonce = 'nonce-1',
        string $sub = self::SUBJECT,
        string $email = 'alice@acme.test',
        bool $emailVerified = true,
        ?int $providerId = null,
        ?int $tenantId = null
    ): void {
        // Default flow is GLOBAL-TRUST (operator provider @ system tenant 0).
        $flow = $this->jwtParser->create([
            'state' => $state,
            'nonce' => $nonce,
            'code_verifier' => 'verifier-1',
            'provider_id' => $providerId ?? $this->providerId,
            'tenant_id' => $tenantId ?? 0,
            'provider_key' => 'google',
        ], 600, 'oidc_flow');
        $_COOKIE['sso_flow_token'] = $flow;

        $now = time();
        $idToken = JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => $sub,
            'email' => $email, 'email_verified' => $emailVerified, 'name' => 'Alice',
            'nonce' => $nonce, 'iat' => $now, 'exp' => $now + 3600,
        ], $this->privatePem, 'RS256', $this->kid);
        $this->http->tokenResponse = ['id_token' => $idToken, 'access_token' => 'at'];
    }

    private function runCallback(): \Whity\Sdk\Http\Response
    {
        return $this->handler->callback(new Request('GET', '/api/v1/auth/sso/google/callback', [], ''), ['provider' => 'google']);
    }

    private function location(\Whity\Sdk\Http\Response $res): string
    {
        return (string) ($res->getHeaders()['location'] ?? $res->getHeaders()['Location'] ?? '');
    }

    // ── start ─────────────────────────────────────────────────────────────────

    public function testStartRedirectsToAuthorizeEndpointWithPkceAndState(): void
    {
        // No Host header → tenant 0; the seeded GLOBAL-TRUST provider resolves.
        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertSame(302, $res->getStatusCode());
        $loc = $this->location($res);
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $loc);
        self::assertStringContainsString('code_challenge_method=S256', $loc);
        self::assertStringContainsString('state=', $loc);
        self::assertStringContainsString('access_type=offline', $loc);
    }

    public function testStartBouncesWhenSsoDisabledGlobally(): void
    {
        // Operator kill-switch (WC-28fb2e19): SSO off instance-wide.
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::SSO_ENABLED, 'false');

        $res = $this->handler->start(new Request('GET', '/api/v1/auth/sso/google/start', [], ''), ['provider' => 'google']);
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('/login?sso_error=sso_disabled', $this->location($res));
    }

    public function testCallbackBouncesWhenSsoDisabledGlobally(): void
    {
        $this->linkIdentity();
        $this->primeFlow();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        $this->settings->setGlobal(\Whity\Core\Settings\SettingsRegistry::SSO_ENABLED, 'false');

        $res = $this->runCallback();
        // Even a valid linked flow is refused when SSO is globally disabled, and no
        // session is minted.
        self::assertStringContainsString('/login?sso_error=sso_disabled', $this->location($res));
    }

    public function testStartUnknownProviderBounces(): void
    {
        $res = $this->handler->start(new Request('GET', '/x', [], ''), ['provider' => 'nope']);
        self::assertSame(302, $res->getStatusCode());
        self::assertStringContainsString('/login?sso_error=provider_unavailable', $this->location($res));
    }

    // ── callback ────────────────────────────────────────────────────────────────

    public function testCallbackLinkedIdentityLogsInAndRedirectsToDashboard(): void
    {
        $this->linkIdentity();
        $this->primeFlow();
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(302, $res->getStatusCode(), $res->getBody());
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res));

        // The link's last_login_at was stamped.
        self::assertNotNull(
            $this->col("SELECT last_login_at FROM external_identities WHERE subject = '" . self::SUBJECT . "'"),
            'a successful federated login stamps last_login_at'
        );
    }

    public function testCallbackVerifiedEmailMatchAutoLinksExistingProfileAndLogsIn(): void
    {
        // No prior link, but the verified IdP email matches Alice's VERIFIED
        // primary email → link-by-verified-email → login (WC-f3b17bd2).
        $this->primeFlow(email: 'alice@acme.test', emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        // A link to Alice's profile now exists.
        self::assertSame(
            (string) $this->profileId,
            (string) $this->col("SELECT profile_id FROM external_identities WHERE subject = '" . self::SUBJECT . "'")
        );
    }

    public function testCallbackUnverifiedEmailIsRefused(): void
    {
        // Verified-match would link, but the IdP says the email is NOT verified →
        // never link/provision by an unproven address (anti-takeover).
        $this->primeFlow(email: 'alice@acme.test', emailVerified: false);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=email_unverified', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE subject = '" . self::SUBJECT . "'"));
    }

    public function testCallbackNewVerifiedIdentityProvisionsAPasswordlessProfile(): void
    {
        // A verified identity whose email matches NO local profile → provision a
        // new passwordless profile + verified email + link. It has no membership,
        // so login bounces with no_membership (JIT membership is WC-635, next PR),
        // but the account is provisioned and linked.
        $this->primeFlow(sub: 'brand-new-sub', email: 'newperson@fresh.test', emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=no_membership', $this->location($res));

        // A passwordless profile + verified email + link were created.
        $pid = (int) $this->col("SELECT profile_id FROM external_identities WHERE subject = 'brand-new-sub'");
        self::assertGreaterThan(0, $pid);
        self::assertSame('', (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$pid}"));
        self::assertContains(
            (string) $this->col("SELECT verified FROM profile_emails WHERE email = 'newperson@fresh.test'"),
            ['1', 't', 'true']
        );
    }

    public function testCallbackUnverifiedLocalEmailConflictIsRefused(): void
    {
        // A local profile_email for the IdP's email exists but is UNVERIFIED →
        // refuse (a half-registered local account must not be seizable via SSO).
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Pending', 'x', false, NULL, 0, 0, NOW(), NOW())");
        $pendingId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($pendingId, 'pending@acme.test', false, true);

        $this->primeFlow(sub: 'conflict-sub', email: 'pending@acme.test', emailVerified: true);
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=link_conflict', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE subject = 'conflict-sub'"));
    }

    public function testCallbackTenantTrustLinksActiveMemberAndConfinesSession(): void
    {
        // Acme's own IdP (tenant-trust) asserts a verified email belonging to an
        // ACTIVE MEMBER of Acme → link in the tenant namespace + session into Acme.
        $this->primeFlow(
            sub: 'tenant-sub',
            email: 'alice@acme.test',
            emailVerified: true,
            providerId: $this->tenantProviderId,
            tenantId: $this->tenantId,
        );
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertSame(self::APP_URL . '/dashboard', $this->location($res), $res->getBody());
        // Linked in the TENANT namespace (provider_id = Acme's provider), so it can
        // never collide with or spoof the global (issuer, subject) namespace.
        self::assertSame(
            (string) $this->tenantProviderId,
            (string) $this->col("SELECT provider_id FROM external_identities WHERE subject = 'tenant-sub'")
        );
    }

    public function testCallbackTenantTrustRefusesNonMember(): void
    {
        // A DIFFERENT tenant's member (Bob) — Acme's IdP asserts his verified email.
        // Bob is NOT a member of Acme, so the tenant-trust IdP must not reach him
        // (the cross-tenant takeover the review flagged). Refused, no link.
        $this->exec("INSERT INTO tenants (name, slug, created_at) VALUES ('Other', 'other', NOW())");
        $otherTenant = (int) $this->pdo->lastInsertId();
        $roleId = (int) $this->col('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        $this->exec("INSERT INTO profiles
            (display_name, password_hash, two_factor_enabled, two_factor_secret,
             two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES ('Bob', '" . password_hash('x', PASSWORD_BCRYPT) . "', false, NULL, 0, 0, NOW(), NOW())");
        $bobId = (int) $this->pdo->lastInsertId();
        (new ProfileEmailRepository($this->pdo))->insert($bobId, 'bob@other.test', true, true);
        $this->pdo->prepare("INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
            VALUES (:p, :t, :r, NULL, 'active', NOW())")
            ->execute([':p' => $bobId, ':t' => $otherTenant, ':r' => $roleId]);

        $this->primeFlow(
            sub: 'bob-sub',
            email: 'bob@other.test',
            emailVerified: true,
            providerId: $this->tenantProviderId,
            tenantId: $this->tenantId,
        );
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=no_account', $this->location($res));
        self::assertSame(0, (int) $this->col("SELECT COUNT(*) FROM external_identities WHERE subject = 'bob-sub'"));
    }

    public function testCallbackStateMismatchIsRejected(): void
    {
        $this->linkIdentity();
        $this->primeFlow('real-state');
        $_GET = ['code' => 'authcode', 'state' => 'forged-state'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=state_mismatch', $this->location($res));
    }

    public function testCallbackMissingFlowCookieIsRejected(): void
    {
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];
        // No $_COOKIE['sso_flow_token'].
        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=expired', $this->location($res));
    }

    public function testCallbackProviderErrorIsRejected(): void
    {
        $this->primeFlow();
        $_GET = ['error' => 'access_denied', 'state' => 'state-1'];
        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=denied', $this->location($res));
    }

    public function testCallbackNonceMismatchFailsVerification(): void
    {
        $this->linkIdentity();
        // Flow cookie nonce differs from the id_token nonce → verifyIdToken rejects.
        $this->primeFlow('state-1', 'cookie-nonce');
        // Re-sign the id_token with a DIFFERENT nonce.
        $now = time();
        $this->http->tokenResponse = ['id_token' => JWT::encode([
            'iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'sub' => self::SUBJECT,
            'nonce' => 'attacker-nonce', 'iat' => $now, 'exp' => $now + 3600,
        ], $this->privatePem, 'RS256', $this->kid)];
        $_GET = ['code' => 'authcode', 'state' => 'state-1'];

        $res = $this->runCallback();
        self::assertStringContainsString('/login?sso_error=failed', $this->location($res));
    }
}

/**
 * Stub OIDC HTTP client: canned discovery (getJson) + token response (postForm).
 */
final class StubOidcHttp implements HttpClient
{
    /** @var array<string, mixed>|null */
    public ?array $discovery = null;
    /** @var array<string, mixed>|null */
    public ?array $tokenResponse = null;

    public function getJson(string $url): ?array
    {
        return $this->discovery;
    }

    public function postForm(string $url, array $params): ?array
    {
        return $this->tokenResponse;
    }
}
