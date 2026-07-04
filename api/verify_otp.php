<?php
// =============================================================================
// FILE: api/verify_otp.php
// METHOD: POST
// PURPOSE: Verify the 6-digit OTP for first-time login. On success, marks
//          the user as no longer first-time and issues a full session token.
//
// REQUEST BODY (JSON):
//   { "user_id": 42, "otp_code": "123456" }
//
// SUCCESS RESPONSE (200):
//   {
//     "status": "success",
//     "data": {
//       "token": "abc123...",
//       "user": { ... full user payload ... }
//     }
//   }
//
// ERROR RESPONSES:
//   400  Missing/invalid fields
//   401  Wrong OTP or expired OTP
//   404  User not found
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed. Use POST.', 405);
}

$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    Response::error('Request body must be valid JSON.', 400);
}

$userId  = filter_var($body['user_id'] ?? null, FILTER_VALIDATE_INT);
$otpCode = trim((string) ($body['otp_code'] ?? ''));

if ($userId === false || $userId === null || $userId <= 0) {
    Response::error('A valid user_id is required.', 400);
}

// OTP must be exactly 6 numeric digits
if (!preg_match('/^\d{6}$/', $otpCode)) {
    Response::error('OTP must be a 6-digit numeric code.', 400);
}

try {
    $pdo = Database::getConnection();

    // ── Verify user exists and is still in first-login state ─────────────────
    $stmt = $pdo->prepare(
        'SELECT u.id, u.employee_id, u.username, u.full_name,
                u.contact_masked, u.is_first_login, u.is_active, u.scope_id,
                r.code  AS role_code,
                r.label AS role_label,
                r.access_level,
                gs.scope_type,
                gs.scope_name
         FROM   users u
         INNER JOIN roles             r  ON r.id  = u.role_id
         LEFT  JOIN geographic_scopes gs ON gs.id = u.scope_id
         WHERE  u.id = ?
         LIMIT  1'
    );
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        Response::error('User not found.', 404);
    }

    if (!$user['is_active']) {
        Response::error('Account is deactivated.', 401);
    }

    if (!(bool) $user['is_first_login']) {
        // OTP screen should only ever be hit during first login.
        // If the user is returning, they already completed OTP — block this call.
        Response::error('OTP verification is only required on first login.', 400);
    }

    // ── Verify OTP code (constant-time, single indexed query) ─────────────────
    if (!Auth::verifyOtp($user['id'], $otpCode)) {
        Response::error('Invalid or expired PIN. Please request a new one.', 401);
    }

    // ── OTP valid — promote account out of first-login state ──────────────────
    // Single UPDATE: atomic, no race condition possible
    $pdo->prepare('UPDATE users SET is_first_login = 0 WHERE id = ?')
        ->execute([$user['id']]);

    // ── Issue full session token ───────────────────────────────────────────────
    $ip    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $token = Auth::createSession($user['id'], $ip, $ua);

    Response::success([
        'token' => $token,
        'user'  => [
            'employee_id'    => $user['employee_id'],
            'full_name'      => $user['full_name'],
            'username'       => $user['username'],
            'role_code'      => $user['role_code'],
            'role_label'     => $user['role_label'],
            'access_level'   => $user['access_level'],
            'scope_code'     => $user['scope_type'],
            'scope_name'     => $user['scope_name'] ?? 'National (All)',
            'contact_masked' => $user['contact_masked'],
            'is_first_login' => false,
        ],
    ]);

} catch (PDOException $e) {
    error_log('[verify_otp.php] DB Error: ' . $e->getMessage());
    Response::error('A server error occurred. Please try again.', 500);
}
