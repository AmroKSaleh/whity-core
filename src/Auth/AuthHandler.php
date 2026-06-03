<?php

namespace Whity\Auth;

use Whity\Core\Request;
use Whity\Core\Response;
use PDO;
use PDOStatement;

/**
 * Simple adapter to make PDO compatible with Database interface
 * Used internally for BackupCodesService instantiation
 */
class DatabaseQueryWrapper
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function getPdo(): PDO
    {
        return $this->pdo;
    }
}

/**
 * Authentication handler for login and token endpoints
 *
 * Handles login, token refresh, logout, and session validation endpoints.
 * Uses HTTP-only cookies for token storage and implements token revocation
 * for secure logout.
 */
class AuthHandler
{
    private PDO $db;
    private JwtParser $jwtParser;
    private TokenValidator $tokenValidator;
    private ?TotpService $totpService = null;
    private ?BackupCodesService $backupCodesService = null;
    private ?object $databaseWrapper = null;

    /**
     * Constructor
     *
     * @param PDO $db Database connection
     * @param JwtParser $jwtParser JWT parser for token creation
     * @param TokenValidator $tokenValidator Token validator for token validation (optional)
     * @param object $databaseWrapper Optional Database wrapper for BackupCodesService (for testing)
     */
    public function __construct(PDO $db, JwtParser $jwtParser, ?TokenValidator $tokenValidator = null, ?object $databaseWrapper = null)
    {
        $this->db = $db;
        $this->jwtParser = $jwtParser;
        $this->tokenValidator = $tokenValidator ?? new TokenValidator($jwtParser, $db);
        $this->databaseWrapper = $databaseWrapper;
    }

    /**
     * Handle login request (POST /api/login)
     *
     * Processes login requests by:
     * 1. Extracting email and password from request body
     * 2. Querying users table by email (globally unique)
     * 3. Verifying password using password_verify()
     * 4. Creating access and refresh JWT tokens
     * 5. Setting tokens in HTTP-only cookies
     * 6. Returning only user data (no token in JSON body)
     *
     * @param Request $request HTTP request with email and password in JSON body
     * @return Response HTTP response with user data (200) or error (401)
     */
    public function handle(Request $request, array $params = []): Response
    {
        // Parse request body
        $body = json_decode($request->getBody(), true);

        // Validate request has email and password
        if (!isset($body['email']) || !isset($body['password'])) {
            return Response::error('Email and password are required', 401);
        }

        $email = $body['email'];
        $password = $body['password'];

        // Query user by email with 2FA fields (globally unique)
        $stmt = $this->db->prepare('
            SELECT id, email, password, role_id, tenant_id, two_factor_enabled, two_factor_secret, two_factor_backup_codes_version
            FROM users
            WHERE email = ?
        ');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // User not found
        if (!$user) {
            return Response::error('Invalid credentials', 401);
        }

        // Verify password
        if (!password_verify($password, $user['password'])) {
            return Response::error('Invalid credentials', 401);
        }

        // Check if 2FA is enabled
        if (!empty($user['two_factor_enabled'])) {
            // Create temporary token (5 minutes) for 2FA verification
            $tempToken = $this->jwtParser->create([
                'user_id' => $user['id'],
                'tenant_id' => $user['tenant_id'],
                'email' => $user['email']
            ], 300, 'temp'); // 5 minutes

            // Set temporary token cookie
            CookieManager::setTempToken($tempToken, 300);

            // Return 202 Accepted with requires_2fa flag
            return Response::json([
                'requires_2fa' => true
            ], 202);
        }

        // Get role name
        $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = ?');
        $roleStmt->execute([$user['role_id']]);
        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$roleData) {
            return Response::error('Role not found', 500);
        }

        $roleName = $roleData['name'];

        // Create access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $roleName
        ], 900, 'access'); // 15 minutes

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => $user['id'],
            'tenant_id' => $user['tenant_id'],
            'email' => $user['email'],
            'role' => $roleName
        ], 604800, 'refresh'); // 7 days

        // Set cookies
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        // Return success response with user data only (no token in body)
        return Response::json([
            'user' => [
                'id' => $user['id'],
                'email' => $user['email'],
                'role' => $roleName
            ]
        ], 200);
    }

    /**
     * Handle GET /api/me - Get current user session
     *
     * Returns the current authenticated user's data by validating the
     * access token from cookies.
     *
     * @param Request $request HTTP request
     * @return Response User data on success (200) or 401 on auth failure
     */
    public function handleMe(Request $request, array $params = []): Response
    {
        // Validate access token
        $claims = $this->tokenValidator->validateAccessToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Return user data from token claims
        return Response::json([
            'user' => [
                'id' => $claims['user_id'],
                'email' => $claims['email'],
                'role' => $claims['role']
            ]
        ], 200);
    }

    /**
     * Handle POST /api/auth/refresh - Refresh access token
     *
     * Issues a new access token when the refresh token is valid and not revoked.
     * Validates the refresh token from cookies and creates a new access token.
     *
     * @param Request $request HTTP request
     * @return Response Success response with new access token set in cookie (200) or 401 on failure
     */
    public function handleRefresh(Request $request, array $params = []): Response
    {
        // Validate refresh token
        $claims = $this->tokenValidator->validateRefreshToken();

        if ($claims === null) {
            return Response::error('Unauthorized', 401);
        }

        // Create new access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $claims['user_id'],
            'tenant_id' => $claims['tenant_id'],
            'email' => $claims['email'],
            'role' => $claims['role']
        ], 900, 'access'); // 15 minutes

        // Set new access token cookie
        CookieManager::setAccessToken($accessToken, 900);

        // Return success response
        return Response::json([
            'status' => 'success'
        ], 200);
    }

    /**
     * Handle POST /api/auth/logout - Logout and revoke tokens
     *
     * Revokes the refresh token by adding its JTI to the revoked_tokens table,
     * and clears both access and refresh token cookies.
     * This endpoint is idempotent - returns 200 even if no refresh token is present.
     *
     * @param Request $request HTTP request
     * @return Response Logout confirmation (200) on success, even if no token
     */
    public function handleLogout(Request $request, array $params = []): Response
    {
        // Get refresh token from cookie (optional - logout is idempotent)
        $refreshToken = CookieManager::getRefreshToken();

        if ($refreshToken !== null) {
            // Parse the refresh token to get claims
            $claims = $this->jwtParser->parse($refreshToken);

            if ($claims !== null) {
                // Get the jti and exp from token claims
                $jti = $claims['jti'] ?? null;
                $exp = $claims['exp'] ?? null;

                if ($jti !== null && $exp !== null) {
                    // Insert into revoked_tokens table
                    try {
                        $stmt = $this->db->prepare('
                            INSERT INTO revoked_tokens (jti, expires_at)
                            VALUES (?, to_timestamp(?))
                        ');
                        $stmt->execute([$jti, $exp]);
                    } catch (\Exception) {
                        // Silently fail if revocation fails, still clear cookies
                    }
                }
            }
        }

        // Clear both cookies
        CookieManager::clearAccessToken();
        CookieManager::clearRefreshToken();

        // Return success response
        return Response::json([
            'status' => 'logged out'
        ], 200);
    }

    /**
     * Handle POST /api/login/2fa - Validate 2FA code and complete login
     *
     * Processes the second step of two-factor authentication by validating
     * a TOTP code or backup code provided by the user.
     *
     * Flow:
     * 1. Get temporary token from cookie
     * 2. Parse temp token to extract user_id
     * 3. Fetch user's 2FA secret and backup codes version
     * 4. Try TOTP validation first, then backup code validation
     * 5. If either valid: call completeTwoFaLogin() to create access/refresh tokens
     * 6. If both invalid: return 401
     *
     * @param Request $request HTTP request with 2FA code in JSON body
     * @return Response User data on success (200) or error (401)
     */
    public function handle2fa(Request $request, array $params = []): Response
    {
        // Get temporary token from cookie
        $tempToken = CookieManager::getTempToken();

        if ($tempToken === null) {
            return Response::error('Invalid or expired temporary token', 401);
        }

        // Parse temp token to extract user_id
        $claims = $this->jwtParser->parse($tempToken);

        if ($claims === null) {
            return Response::error('Invalid or expired temporary token', 401);
        }

        // Extract user_id from claims
        $userId = $claims['user_id'] ?? null;

        if ($userId === null) {
            return Response::error('Invalid temporary token', 401);
        }

        // Parse request body to get 2FA code
        $body = json_decode($request->getBody(), true);

        if (!isset($body['code']) || empty($body['code'])) {
            return Response::error('2FA code is required', 401);
        }

        $code = $body['code'];

        // Fetch user's 2FA secret and backup codes version
        $stmt = $this->db->prepare('
            SELECT id, email, role_id, tenant_id, two_factor_secret, two_factor_backup_codes_version
            FROM users
            WHERE id = ?
        ');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            return Response::error('User not found', 401);
        }

        // Try TOTP validation first
        $isValid = false;

        if ($user['two_factor_secret']) {
            try {
                $totpService = $this->getTotpService();
                if ($totpService->validateCode($user['two_factor_secret'], $code)) {
                    $isValid = true;
                }
            } catch (\Exception) {
                // Continue to backup code validation
            }
        }

        // Try backup code validation if TOTP failed
        if (!$isValid && $user['two_factor_backup_codes_version'] > 0) {
            try {
                $backupCodesService = $this->getBackupCodesService();
                if ($backupCodesService->validateCode($userId, $code, $user['two_factor_backup_codes_version'])) {
                    $isValid = true;
                }
            } catch (\Exception) {
                // Both validations failed
            }
        }

        if (!$isValid) {
            return Response::error('Invalid 2FA code', 401);
        }

        // Both validations failed
        return $this->completeTwoFaLogin($claims);
    }

    /**
     * Complete 2FA login by creating access and refresh tokens
     *
     * Called after successful 2FA code validation. Creates access and refresh tokens,
     * clears the temporary token cookie, and returns user data.
     *
     * @param array $claims Token claims from temporary token
     * @return Response User data with tokens set in cookies (200)
     */
    private function completeTwoFaLogin(array $claims): Response
    {
        // Clear temporary token cookie
        CookieManager::clearTempToken();

        // Extract user info
        $userId = $claims['user_id'];
        $tenantId = $claims['tenant_id'];
        $email = $claims['email'];

        // Get role name
        $roleStmt = $this->db->prepare('SELECT name FROM roles WHERE id = (SELECT role_id FROM users WHERE id = ?)');
        $roleStmt->execute([$userId]);
        $roleData = $roleStmt->fetch(PDO::FETCH_ASSOC);

        if (!$roleData) {
            return Response::error('Role not found', 500);
        }

        $roleName = $roleData['name'];

        // Create access token (15 minutes)
        $accessToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $roleName
        ], 900, 'access'); // 15 minutes

        // Create refresh token (7 days)
        $refreshToken = $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $roleName
        ], 604800, 'refresh'); // 7 days

        // Set cookies
        CookieManager::setAccessToken($accessToken, 900);
        CookieManager::setRefreshToken($refreshToken, 604800);

        // Return success response with user data
        return Response::json([
            'user' => [
                'id' => $userId,
                'email' => $email,
                'role' => $roleName
            ]
        ], 200);
    }

    /**
     * Get or instantiate TotpService
     *
     * @return TotpService
     */
    private function getTotpService(): TotpService
    {
        if ($this->totpService === null) {
            $encryptionKey = $_ENV['ENCRYPTION_KEY'] ?? 'default-encryption-key';
            $this->totpService = new TotpService($encryptionKey);
        }
        return $this->totpService;
    }

    /**
     * Get or instantiate BackupCodesService
     *
     * @return BackupCodesService
     */
    private function getBackupCodesService(): BackupCodesService
    {
        if ($this->backupCodesService === null) {
            // Use provided database wrapper (for testing) or create a new one from PDO
            $dbWrapper = $this->databaseWrapper ?? new DatabaseQueryWrapper($this->db);
            $this->backupCodesService = new BackupCodesService($dbWrapper);
        }
        return $this->backupCodesService;
    }
}
