<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\TotpService;
use Whity\Auth\BackupCodesService;
use Whity\Auth\TokenValidator;
use Whity\Auth\CookieManager;
use Whity\Api\TwoFactorHandler;
use Whity\Core\Request;
use PDO;
use PDOStatement;
use OTPHP\TOTP;

/**
 * Integration tests for 2FA API flow
 *
 * Tests the 5 2FA API endpoints:
 * 1. POST /api/auth/2fa/setup - Generate secret and QR code
 * 2. POST /api/auth/2fa/confirm - Validate code and save secret
 * 3. POST /api/auth/2fa/disable - Disable 2FA
 * 4. POST /api/auth/2fa/regenerate-codes - Generate new backup codes
 * 5. GET /api/auth/2fa/status - Check 2FA status
 *
 * Tests verify:
 * - All endpoints require valid access token
 * - Setup generates valid secret and QR URL
 * - Confirm validates TOTP code and stores secret
 * - Disable disables 2FA
 * - Regenerate marks old codes as used
 * - Status returns correct enabled state
 */
class TwoFactorFlowTest extends TestCase
{
    private JwtParser $jwtParser;
    private TotpService $totpService;
    private const TEST_SECRET_KEY = 'test-secret-key-for-2fa-tests-padded-for-hs256-min-32-byte-key';
    private const TEST_PROFILE_ID = 1;
    private const TEST_USER_EMAIL = 'testuser@example.com';
    private const TEST_TENANT_ID = 1;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::TEST_SECRET_KEY);
        $this->totpService = new TotpService(self::TEST_SECRET_KEY);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token']);
        unset($_COOKIE['refresh_token']);
    }

    /**
     * Create a valid access token in the cookies.
     *
     * Post-cutover (WC-idcut-E): the token carries ONLY the {profile_id,
     * active_tenant_id} pair — no legacy {user_id, tenant_id}. The membership
     * SELECT is satisfied by the mock PDO so ActiveTenantMembershipGuard passes.
     */
    private function createAccessToken(): void
    {
        $token = $this->jwtParser->create([
            'profile_id'       => self::TEST_PROFILE_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email'            => self::TEST_USER_EMAIL,
            'type'             => 'access',
        ], 3600);

        $_COOKIE['access_token'] = $token;
    }

    /**
     * Create a mock PDO with PROFILE 2FA data.
     *
     * Post-cutover (WC-idcut-E): TwoFactorHandler reads and writes ONLY the
     * profiles / profile_emails / backup_codes tables — never `users`. The mock
     * models the profile row keyed on TEST_PROFILE_ID.
     *
     * @param array<string, mixed> $profileOverrides
     */
    private function createMockPdo(array $profileOverrides = []): PDO
    {
        $profileData = array_merge([
            'id' => self::TEST_PROFILE_ID,
            'email' => self::TEST_USER_EMAIL,
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_backup_codes_version' => 0,
        ], $profileOverrides);

        $mockPdo = $this->createMock(PDO::class);

        // Track state changes
        $profileState = [self::TEST_PROFILE_ID => $profileData];
        $backupCodeState = [];

        $mockPdo->method('prepare')
            ->willReturnCallback(function($sql) use (&$profileState, &$backupCodeState) {
                $stmt = $this->createMock(PDOStatement::class);

                $stmt->method('execute')
                    ->willReturnCallback(function($params = []) use ($sql, &$profileState, &$backupCodeState) {
                        // UPDATE profiles: enable path (secret + version = 1).
                        if (strpos($sql, 'UPDATE profiles') !== false
                            && strpos($sql, 'two_factor_secret') !== false
                            && strpos($sql, 'two_factor_backup_codes_version') !== false
                        ) {
                            $secret = $params[0] ?? null;
                            $enabled = (bool) ($params[1] ?? 0);
                            $version = (int) ($params[2] ?? 0);
                            $profileId = $params[3] ?? self::TEST_PROFILE_ID;
                            if (isset($profileState[$profileId])) {
                                $profileState[$profileId]['two_factor_secret'] = $secret;
                                $profileState[$profileId]['two_factor_enabled'] = $enabled;
                                $profileState[$profileId]['two_factor_backup_codes_version'] = $version;
                            }
                        }
                        // UPDATE profiles: disable path (secret + enabled, no version col).
                        elseif (strpos($sql, 'UPDATE profiles') !== false
                            && strpos($sql, 'two_factor_secret') !== false
                        ) {
                            $secret = $params[0] ?? null;
                            $enabled = (bool) ($params[1] ?? 0);
                            $profileId = $params[2] ?? self::TEST_PROFILE_ID;
                            if (isset($profileState[$profileId])) {
                                $profileState[$profileId]['two_factor_secret'] = $secret;
                                $profileState[$profileId]['two_factor_enabled'] = $enabled;
                            }
                        }
                        // UPDATE profiles: version-only bump (regenerate).
                        elseif (strpos($sql, 'UPDATE profiles') !== false
                            && strpos($sql, 'two_factor_backup_codes_version = ?') !== false
                        ) {
                            $version = (int) ($params[0] ?? 1);
                            $profileId = $params[1] ?? self::TEST_PROFILE_ID;
                            if (isset($profileState[$profileId])) {
                                $profileState[$profileId]['two_factor_backup_codes_version'] = $version;
                            }
                        }
                        // UPDATE backup_codes to invalidate (keyed on profile_id).
                        elseif (strpos($sql, 'UPDATE backup_codes') !== false && strpos($sql, 'used = true') !== false) {
                            $profileId = $params[0] ?? self::TEST_PROFILE_ID;
                            $oldVersion = $params[1] ?? 1;
                            if (isset($backupCodeState[$profileId])) {
                                foreach ($backupCodeState[$profileId] as &$code) {
                                    if ($code['version'] === $oldVersion) {
                                        $code['used'] = true;
                                    }
                                }
                            }
                        }
                        // INSERT backup_codes (keyed on profile_id, migration 038).
                        elseif (strpos($sql, 'INSERT INTO backup_codes') === 0) {
                            $profileId = $params[0] ?? self::TEST_PROFILE_ID;
                            $code = $params[1] ?? '';
                            $version = $params[2] ?? 1;

                            if (!isset($backupCodeState[$profileId])) {
                                $backupCodeState[$profileId] = [];
                            }

                            $backupCodeState[$profileId][] = [
                                'profile_id' => $profileId,
                                'code'       => $code,
                                'version'    => $version,
                                'used'       => false,
                            ];
                        }

                        return true;
                    });

                $stmt->method('fetch')
                    ->willReturnCallback(function() use ($sql, &$profileState) {
                        // readIdentityRow: SELECT ... FROM profiles JOIN profile_emails
                        if (strpos($sql, 'FROM profiles') !== false) {
                            return $profileState[self::TEST_PROFILE_ID] ?? null;
                        }
                        return null;
                    });

                $stmt->method('fetchColumn')
                    ->willReturnCallback(function() use ($sql) {
                        // token_epoch epoch-check against profiles (TokenValidator)
                        if (strpos($sql, 'token_epoch') !== false && strpos($sql, 'FROM profiles') !== false) {
                            return 0;  // epoch 0 — token is always current
                        }
                        // jti revocation check (revoked_tokens)
                        if (strpos($sql, 'revoked_tokens') !== false) {
                            return false;  // token not revoked
                        }
                        // membership guard (ActiveTenantMembershipGuard)
                        if (strpos($sql, 'FROM memberships') !== false) {
                            return 1;  // active membership exists
                        }
                        return false;
                    });

                return $stmt;
            });

        return $mockPdo;
    }

    /**
     * Test 1: Setup endpoint returns secret and QR code
     */
    public function testSetupGeneratesSecretAndQrCode(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo();
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $request = new Request('POST', '/api/auth/2fa/setup');
        $response = $handler->setup($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('secret', $data);
        $this->assertArrayHasKey('qrCodeUrl', $data);
        $this->assertNotEmpty($data['secret']);
        $this->assertStringContainsString('otpauth://', $data['qrCodeUrl']);
    }

    /**
     * Test 2: Confirm validates code and enables 2FA
     */
    public function testConfirmValidatesCodeAndEnables2fa(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo();
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        // Generate valid secret and code
        $secret = $this->totpService->generateSecret();
        $totp = TOTP::create($secret);
        $code = $totp->now();

        // Mock backup codes generation
        $backupCodesService->method('generateCodes')
            ->willReturn(['CODE1', 'CODE2', 'CODE3']);
        $backupCodesService->method('hashCode')
            ->willReturnCallback(fn($c) => 'hashed_' . $c);

        $body = json_encode(['code' => $code, 'secret' => $secret]);
        $request = new Request('POST', '/api/auth/2fa/confirm', [], $body);
        $response = $handler->confirm($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('backup_codes', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertStringContainsString('enabled', $data['message']);
    }

    /**
     * Test 3: Confirm rejects invalid code
     */
    public function testConfirmRejectsInvalidCode(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo();
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $secret = $this->totpService->generateSecret();
        $body = json_encode(['code' => '000000', 'secret' => $secret]);
        $request = new Request('POST', '/api/auth/2fa/confirm', [], $body);
        $response = $handler->confirm($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid', $response->getBody());
    }

    /**
     * Test 4: Disable disables 2FA
     */
    public function testDisableDisables2fa(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo(['two_factor_enabled' => true]);
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $request = new Request('POST', '/api/auth/2fa/disable');
        $response = $handler->disable($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertStringContainsString('disabled', $data['message']);
    }

    /**
     * Test 5: Regenerate codes invalidates old ones
     */
    public function testRegenerateCodesInvalidatesOldCodes(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo(['two_factor_enabled' => true, 'two_factor_backup_codes_version' => 1]);
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $backupCodesService->method('generateCodes')
            ->willReturn(['NEW1', 'NEW2', 'NEW3']);
        $backupCodesService->method('hashCode')
            ->willReturnCallback(fn($c) => 'hashed_' . $c);
        $backupCodesService->method('invalidateOldCodes');

        $request = new Request('POST', '/api/auth/2fa/regenerate-codes');
        $response = $handler->regenerateCodes($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('backup_codes', $data);
        $this->assertStringContainsString('regenerated', $data['message']);
    }

    /**
     * Test 6: Status returns correct status
     */
    public function testStatusReturnsCorrectStatus(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo([
            'two_factor_enabled' => true,
            'two_factor_backup_codes_version' => 1
        ]);
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);

        $backupCodesService = $this->createMock(BackupCodesService::class);
        $backupCodesService->method('getAvailableCodeCount')->willReturn(10);

        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $request = new Request('GET', '/api/auth/2fa/status');
        $response = $handler->status($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('backup_codes_available', $data);
        $this->assertTrue($data['enabled']);
        $this->assertSame(10, $data['backup_codes_available']);
    }

    /**
     * Test 7: All endpoints require valid token
     */
    public function testEndpointsRequireValidToken(): void
    {
        unset($_COOKIE['access_token']);
        $mockPdo = $this->createMockPdo();
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $request = new Request('POST', '/api/auth/2fa/setup');
        $response = $handler->setup($request);

        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Test 8: Setup rejects if 2FA already enabled
     */
    public function testSetupRejectsIfAlreadyEnabled(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo(['two_factor_enabled' => true]);
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $request = new Request('POST', '/api/auth/2fa/setup');
        $response = $handler->setup($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('already enabled', $response->getBody());
    }

    /**
     * Test 9: Regenerate rejects if 2FA not enabled
     */
    public function testRegenerateRejectsIfNotEnabled(): void
    {
        $this->createAccessToken();
        $mockPdo = $this->createMockPdo(['two_factor_enabled' => false]);
        $tokenValidator = new TokenValidator($this->jwtParser, $mockPdo);
        $backupCodesService = $this->createMock(BackupCodesService::class);
        $handler = new TwoFactorHandler($mockPdo, $this->totpService, $backupCodesService, $tokenValidator);

        $request = new Request('POST', '/api/auth/2fa/regenerate-codes');
        $response = $handler->regenerateCodes($request);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('not enabled', $response->getBody());
    }
}
