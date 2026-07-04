<?php
// =============================================================================
// FILE: core/Auth.php
// PURPOSE: Bearer token middleware and OTP utilities.
//
// SECURITY DESIGN:
//   - Raw tokens are never stored. SHA-256 of the raw token is stored in
//     user_sessions. Even if the sessions table leaks, tokens cannot be
//     reconstructed (unlike storing raw tokens or weak hashes).
//   - Token generation uses random_bytes(32) → 256 bits of entropy, making
//     brute-force search computationally impossible.
//   - OTP codes use random_int() (CSPRNG) not rand() or mt_rand().
//   - requireAuth() is called at the top of every protected endpoint and
//     terminates with 401 if invalid — no risk of forgetting to check.
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/Response.php';

class Auth
{
    /** Token lifetime in seconds (8 hours for a field shift) */
    private const TOKEN_TTL = 28800;

    /** OTP lifetime in seconds (10 minutes) */
    private const OTP_TTL = 600;

    /** Max consecutive failed logins before lockout */
    private const MAX_FAILURES = 5;

    /** Lockout duration in minutes */
    private const LOCKOUT_MINUTES = 15;

    // -------------------------------------------------------------------------
    // TOKEN MANAGEMENT
    // -------------------------------------------------------------------------

    /**
     * Generate a cryptographically secure raw bearer token,
     * store its SHA-256 hash in user_sessions, and return the raw token
     * to be sent to the client once.
     */
    public static function createSession(int $userId, string $ip, string $ua): string
    {
        $rawToken  = bin2hex(random_bytes(32)); // 64-char hex string
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + self::TOKEN_TTL);

        $pdo = Database::getConnection();

        // Invalidate any existing active sessions for this user
        // (single-session policy: prevents token accumulation)
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')
            ->execute([$userId]);

        $stmt = $pdo->prepare(
            'INSERT INTO user_sessions (user_id, token_hash, expires_at, ip_address, user_agent)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $tokenHash, $expiresAt, $ip, substr($ua, 0, 255)]);

        return $rawToken;
    }

    /**
     * Validate the Bearer token from the Authorization header.
     * Returns the authenticated user row on success.
     * Terminates with 401 on any failure.
     *
     * EFFICIENCY: Single indexed lookup on token_hash (UNIQUE index).
     * The JOIN to users fetches all needed fields in one query.
     */
    public static function requireAuth(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!str_starts_with($header, 'Bearer ')) {
            Response::error('Missing or malformed Authorization header.', 401);
        }

        $rawToken  = substr($header, 7);
        $tokenHash = hash('sha256', $rawToken);

        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT s.user_id, s.expires_at,
                    u.username, u.full_name, u.employee_id,
                    u.is_active, u.role_id,
                    r.code  AS role_code,
                    r.access_level,
                    u.scope_id
             FROM   user_sessions  s
             INNER JOIN users      u ON u.id = s.user_id
             INNER JOIN roles      r ON r.id = u.role_id
             WHERE  s.token_hash = ?
             LIMIT  1'
        );
        $stmt->execute([$tokenHash]);
        $session = $stmt->fetch();

        if (!$session) {
            Response::error('Invalid or expired session token.', 401);
        }

        if (strtotime($session['expires_at']) < time()) {
            // Clean up expired token
            $pdo->prepare('DELETE FROM user_sessions WHERE token_hash = ?')
                ->execute([$tokenHash]);
            Response::error('Session has expired. Please log in again.', 401);
        }

        if (!$session['is_active']) {
            Response::error('Your account has been deactivated. Contact your administrator.', 401);
        }

        return $session;
    }

    /**
     * Deletes the session row matching the given raw bearer token.
     * Used by logout.php. Idempotent: deleting a token that's already
     * gone (expired, already logged out elsewhere) is not an error —
     * the end state ("no active session for this token") is achieved
     * either way, matching standard logout semantics.
     */
    public static function destroySession(string $rawToken): void
    {
        $tokenHash = hash('sha256', $rawToken);
        $pdo = Database::getConnection();
        $pdo->prepare('DELETE FROM user_sessions WHERE token_hash = ?')
            ->execute([$tokenHash]);
    }

    // -------------------------------------------------------------------------
    // LOGIN FAILURE TRACKING (brute-force protection at DB layer)
    // -------------------------------------------------------------------------

    /**
     * Record a failed login attempt. After MAX_FAILURES, lock the account
     * for LOCKOUT_MINUTES. All done in a single UPDATE — no SELECT needed.
     */
    public static function recordFailedLogin(int $userId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            'UPDATE users
             SET    failed_login_attempts = failed_login_attempts + 1,
                    locked_until = CASE
                        WHEN failed_login_attempts + 1 >= :max
                        THEN DATE_ADD(NOW(), INTERVAL :lockout MINUTE)
                        ELSE locked_until
                    END
             WHERE  id = :id'
        )->execute([
            ':max'     => self::MAX_FAILURES,
            ':lockout' => self::LOCKOUT_MINUTES,
            ':id'      => $userId,
        ]);
    }

    /**
     * Reset failure counter on successful login.
     */
    public static function clearFailedLogins(int $userId): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare(
            'UPDATE users
             SET failed_login_attempts = 0,
                 locked_until          = NULL,
                 last_login_at         = NOW()
             WHERE id = ?'
        )->execute([$userId]);
    }

    // -------------------------------------------------------------------------
    // OTP MANAGEMENT
    // -------------------------------------------------------------------------

    /**
     * Generate a 6-digit OTP, persist it, and return the plain code.
     * Uses random_int() (CSPRNG) for cryptographically secure generation.
     *
     * Previous unused OTPs for this user are invalidated first to prevent
     * accumulation of valid codes.
     */
    public static function generateOtp(int $userId): string
    {
        // Zero-padded to always produce 6 digits (e.g. 000123)
        $code      = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + self::OTP_TTL);

        $pdo = Database::getConnection();

        // Invalidate any unused pending OTPs for this user
        $pdo->prepare('UPDATE otp_tokens SET is_used = 1 WHERE user_id = ? AND is_used = 0')
            ->execute([$userId]);

        $pdo->prepare(
            'INSERT INTO otp_tokens (user_id, otp_code, expires_at) VALUES (?, ?, ?)'
        )->execute([$userId, $code, $expiresAt]);

        return $code;
    }

    /**
     * Verify an OTP code for a given user.
     * Returns true if valid, false if invalid/expired.
     *
     * Uses a single indexed SELECT (idx_otp_user_active) + constant-time
     * comparison to prevent timing attacks.
     */
    public static function verifyOtp(int $userId, string $submittedCode): bool
    {
        $pdo  = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT id, otp_code
             FROM   otp_tokens
             WHERE  user_id    = ?
               AND  is_used    = 0
               AND  expires_at > NOW()
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $token = $stmt->fetch();

        if (!$token) {
            return false;
        }

        // hash_equals: constant-time string comparison (prevents timing attacks)
        if (!hash_equals($token['otp_code'], $submittedCode)) {
            return false;
        }

        // Mark OTP as consumed
        $pdo->prepare('UPDATE otp_tokens SET is_used = 1 WHERE id = ?')
            ->execute([$token['id']]);

        return true;
    }
}
