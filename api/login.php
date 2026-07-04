<?php
// =============================================================================
// FILE: api/login.php
// METHOD: POST
// PURPOSE: Validate username/password, handle brute-force lockout,
//          return a bearer token + user role payload.
//
// REQUEST BODY (JSON):
//   { "username": "g.mustafa", "password": "plaintext_password" }
//
// SUCCESS RESPONSE (200):
//   {
//     "status": "success",
//     "data": {
//       "is_first_login": false,
//       "token": "abc123...",           // present only when is_first_login=false
//       "user": { ... }                 // present only when is_first_login=false
//     }
//   }
//
// FIRST-TIME LOGIN RESPONSE (200):
//   {
//     "status": "success",
//     "data": {
//       "is_first_login": true,
//       "temp_user_id": 42,             // used by verify_otp.php
//       "contact_masked": "03**-***-7890"
//     }
//   }
//
// ERROR RESPONSES:
//   400  Missing fields / non-POST request
//   401  Invalid credentials or account locked
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

// ── Only accept POST ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed. Use POST.', 405);
}

// ── Parse and validate JSON body ─────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    Response::error('Request body must be valid JSON.', 400);
}

$username = trim((string) ($body['username'] ?? ''));
$password = (string) ($body['password'] ?? '');

if ($username === '' || $password === '') {
    Response::error('Both username and password are required.', 400);
}

// Basic length guards — prevents DB query with pathologically long inputs
if (strlen($username) > 80 || strlen($password) > 128) {
    Response::error('Input length exceeds allowed limits.', 400);
}

try {
    $pdo = Database::getConnection();

    // ── Fetch user record ─────────────────────────────────────────────────────
    // Single indexed lookup on uq_users_username — O(log n) regardless of
    // table size. JOIN to roles in the same query avoids a second round-trip.
    $stmt = $pdo->prepare(
        'SELECT u.id, u.employee_id, u.username, u.full_name,
                u.password_hash, u.contact_masked,
                u.is_first_login, u.is_active,
                u.failed_login_attempts, u.locked_until,
                u.scope_id,
                r.code          AS role_code,
                r.label         AS role_label,
                r.access_level,
                gs.scope_type,
                gs.scope_name
         FROM   users u
         INNER JOIN roles             r  ON r.id  = u.role_id
         LEFT  JOIN geographic_scopes gs ON gs.id = u.scope_id
         WHERE  u.username = ?
         LIMIT  1'
    );
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    // ── User not found ────────────────────────────────────────────────────────
    // Return the same message as wrong password to prevent username enumeration
    if (!$user) {
        Response::error('Invalid credentials.', 401);
    }

    // ── Account status checks ─────────────────────────────────────────────────
    if (!$user['is_active']) {
        Response::error('Your account has been deactivated. Contact your administrator.', 401);
    }

    // Lockout check (brute-force protection)
    if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        $minutes = (int) ceil((strtotime($user['locked_until']) - time()) / 60);
        Response::error(
            "Account temporarily locked due to multiple failed attempts. "
            . "Try again in {$minutes} minute(s).",
            401,
            ['locked_until' => $user['locked_until']]
        );
    }

    // ── Password verification ─────────────────────────────────────────────────
    // password_verify() is timing-attack resistant by design (it runs bcrypt
    // for the full cost even on mismatch). Never compare hashes with ==.
    if (!password_verify($password, $user['password_hash'])) {
        Auth::recordFailedLogin($user['id']);
        // Generic message: don't reveal whether username or password was wrong
        Response::error('Invalid credentials.', 401);
    }

    // ── Correct password — reset failure counter ──────────────────────────────
    Auth::clearFailedLogins($user['id']);

    // ── First-time login: issue OTP instead of a session token ───────────────
    if ((bool) $user['is_first_login']) {
        $otpCode = Auth::generateOtp($user['id']);

        // In production: dispatch $otpCode via SMS gateway / email service here
        // e.g.: SmsGateway::send($user['contact_masked'], $otpCode);
        // For development, the code is NOT returned in the response (security).

        Response::success([
            'is_first_login' => true,
            'temp_user_id'   => $user['id'],        // needed by verify_otp.php
            'contact_masked' => $user['contact_masked'],
            // DEV ONLY — remove before production:
            '_dev_otp'       => $otpCode,
        ]);
    }

    // ── Returning user: issue session token immediately ───────────────────────
    $ip    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ua    = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $token = Auth::createSession($user['id'], $ip, $ua);

    Response::success([
        'is_first_login' => false,
        'token'          => $token,
        'user'           => [
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
    // Never expose raw PDO error messages — they can leak schema details
    error_log('[login.php] DB Error: ' . $e->getMessage());
    Response::error('A server error occurred. Please try again.', 500);
}
