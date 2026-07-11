<?php

declare(strict_types=1);

namespace Tests\Integration;

use OTPHP\TOTP;
use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Auth\AuthHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\CookieManager;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Auth\TotpService;

/**
 * Regression tests for WC-95: 2FA login always fails due to TOTP encryption key mismatch.
 *
 * The bug: the store/confirm path (TwoFactorHandler's TotpService, built in index.php) and the
 * login-validation path (AuthHandler::getTotpService()) used divergent `??` fallback literals for
 * the encryption key. A secret encrypted under one key could never be decrypted under the other,
 * so every TOTP code was rejected with "Invalid 2FA code".
 *
 * These tests prove the encryption key is a single source of truth across all paths:
 * a secret encrypted on the store/confirm path decrypts and validates on the login path.
 */
class TwoFactorKeyConsistencyTest extends TestCase
{
    private const TEST_SECRET_KEY = 'test-secret-key-for-integration-tests';
    private const TEST_PROFILE_ID = 7;
    private const TEST_USER_EMAIL = 'twofa@example.com';
    private const TEST_TENANT_ID = 1;
    private const TEST_ROLE_NAME = 'user';

    private JwtParser $jwtParser;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::TEST_SECRET_KEY);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['temp_auth_token']);
        unset($_COOKIE['access_token']);
        unset($_COOKIE['refresh_token']);
        unset($_ENV['ENCRYPTION_KEY']);
        unset($_ENV['APP_ENV']);
    }

    /**
     * The store/confirm TotpService and the login-path TotpService must derive the SAME key so a
     * secret encrypted on one validates on the other end-to-end. This is the test that would have
     * caught the bug (divergent 'dev_secret' vs 'default-encryption-key' literals).
     */
    public function testSecretStoredOnConfirmPathValidatesOnLoginPath(): void
    {
        // ENCRYPTION_KEY unset on purpose: this reproduces the production environment in which the
        // bug manifested. Both paths must fall back to the SAME default key.
        unset($_ENV['ENCRYPTION_KEY']);
        $_ENV['APP_ENV'] = 'development';

        // --- Store/confirm path: build the service exactly as index.php does for TwoFactorHandler. ---
        $storeKey = TotpService::resolveEncryptionKey();
        $storeTotpService = new TotpService($storeKey);

        $plainSecret = $storeTotpService->generateSecret();
        $encryptedSecret = $storeTotpService->encryptSecret($plainSecret);

        // Generate a TOTP code from the user's authenticator app (the plain secret).
        $code = TOTP::create($plainSecret)->now();

        // --- Login path: AuthHandler must validate the stored secret with the SAME key. ---
        $tempToken = $this->jwtParser->create([
            'profile_id' => self::TEST_PROFILE_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
        ], 300, 'temp');
        $_COOKIE['temp_auth_token'] = $tempToken;

        $mockDb = $this->createMockDb($encryptedSecret);

        // Construct AuthHandler WITHOUT injecting a TotpService so it falls back to the shared
        // accessor. If the fallback diverges from the store path, validation fails and 2FA breaks.
        $authHandler = new AuthHandler($mockDb, $this->jwtParser);

        $request = new \Whity\Core\Request('POST', '/api/login/2fa', [], json_encode(['code' => $code]));
        $response = $authHandler->handle2fa($request);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'TOTP code must validate on the login path using the same key it was encrypted with'
        );

        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('user', $data);
        $this->assertSame(self::TEST_USER_EMAIL, $data['user']['email']);
    }

    /**
     * The same end-to-end key consistency must also hold when an explicit ENCRYPTION_KEY is set.
     */
    public function testSecretValidatesEndToEndWithExplicitKey(): void
    {
        $_ENV['ENCRYPTION_KEY'] = 'an-explicit-production-grade-key';
        $_ENV['APP_ENV'] = 'production';

        $storeTotpService = new TotpService(TotpService::resolveEncryptionKey());
        $plainSecret = $storeTotpService->generateSecret();
        $encryptedSecret = $storeTotpService->encryptSecret($plainSecret);
        $code = TOTP::create($plainSecret)->now();

        $tempToken = $this->jwtParser->create([
            'profile_id' => self::TEST_PROFILE_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
        ], 300, 'temp');
        $_COOKIE['temp_auth_token'] = $tempToken;

        $mockDb = $this->createMockDb($encryptedSecret);
        $authHandler = new AuthHandler($mockDb, $this->jwtParser);

        $request = new \Whity\Core\Request('POST', '/api/login/2fa', [], json_encode(['code' => $code]));
        $response = $authHandler->handle2fa($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A user enrolled in 2FA must be able to log in with a valid backup code via the login path.
     */
    public function testBackupCodeValidatesOnLoginPath(): void
    {
        unset($_ENV['ENCRYPTION_KEY']);
        $_ENV['APP_ENV'] = 'development';

        $storeTotpService = new TotpService(TotpService::resolveEncryptionKey());
        $plainSecret = $storeTotpService->generateSecret();
        $encryptedSecret = $storeTotpService->encryptSecret($plainSecret);

        $backupCode = 'ABCD-EFGH-IJKL';
        $hashedBackupCode = password_hash($backupCode, PASSWORD_BCRYPT);

        $tempToken = $this->jwtParser->create([
            'profile_id' => self::TEST_PROFILE_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
        ], 300, 'temp');
        $_COOKIE['temp_auth_token'] = $tempToken;

        // backup_codes_version > 0 so the backup-code branch is exercised; provide a matching code.
        $mockDb = $this->createMockDb($encryptedSecret, 1, $hashedBackupCode);
        $authHandler = new AuthHandler($mockDb, $this->jwtParser);

        // An invalid TOTP code forces fallthrough to backup-code validation.
        $request = new \Whity\Core\Request('POST', '/api/login/2fa', [], json_encode(['code' => $backupCode]));
        $response = $authHandler->handle2fa($request);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'A valid backup code must complete login on the login path'
        );
    }

    /**
     * Build a mock PDO that answers the queries AuthHandler::handle2fa() and the backup-code path issue.
     *
     * @param string      $encryptedSecret  The encrypted TOTP secret stored for the user.
     * @param int         $backupVersion    The user's backup-codes version.
     * @param string|null $hashedBackupCode A bcrypt-hashed backup code to return for the backup-code lookup.
     */
    private function createMockDb(
        string $encryptedSecret,
        int $backupVersion = 1,
        ?string $hashedBackupCode = null
    ): PDO {
        $roleName = self::TEST_ROLE_NAME;
        $profileId = self::TEST_PROFILE_ID;

        $mockDb = $this->createMock(PDO::class);
        $mockDb->method('prepare')->willReturnCallback(
            function (string $sql) use ($encryptedSecret, $backupVersion, $hashedBackupCode, $roleName, $profileId) {
                $stmt = $this->createMock(PDOStatement::class);
                $stmt->method('execute')->willReturn(true);

                if (str_contains($sql, 'FROM profiles') && str_contains($sql, 'two_factor_secret')) {
                    // handle2fa: 2FA-secret lookup from the PROFILE (ADR 0005 §1).
                    $stmt->method('fetch')->willReturn([
                        'two_factor_secret' => $encryptedSecret,
                        'two_factor_backup_codes_version' => $backupVersion,
                    ]);
                    $stmt->method('fetchColumn')->willReturn($profileId);
                } elseif (str_contains($sql, 'FROM memberships')) {
                    // issueSessionForProfile: role resolve from the active membership,
                    // and the ActiveTenantMembershipGuard membership existence check.
                    $stmt->method('fetch')->willReturn(['role' => $roleName]);
                    $stmt->method('fetchColumn')->willReturn(1);
                } elseif (str_contains($sql, 'UPDATE backup_codes')) {
                    // The atomic single-use burn (WHERE id AND used = false): the
                    // row is flipped, so exactly one row is affected.
                    $stmt->method('rowCount')->willReturn(1);
                } elseif (str_contains($sql, 'FROM backup_codes')) {
                    // BackupCodesService::validateCode fetches ALL unused rows and
                    // password_verify()s the submitted code against each.
                    $rows = $hashedBackupCode !== null ? [['id' => 1, 'code' => $hashedBackupCode]] : [];
                    $stmt->method('fetch')->willReturn($rows[0] ?? false);
                    $stmt->method('fetchAll')->willReturn($rows);
                    $stmt->method('fetchColumn')->willReturn(false);
                } elseif (str_contains($sql, 'FROM profiles') && str_contains($sql, 'token_epoch')) {
                    // currentProfileTokenEpoch / epoch check.
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchColumn')->willReturn(0);
                } else {
                    $stmt->method('fetch')->willReturn(false);
                    $stmt->method('fetchColumn')->willReturn(false);
                }

                return $stmt;
            }
        );

        return $mockDb;
    }
}
