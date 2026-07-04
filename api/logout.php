<?php
// =============================================================================
// FILE: api/logout.php
// METHOD: POST
// PURPOSE: Invalidate the caller's current session token server-side.
//
// REQUEST:
//   POST /api/logout.php
//   Header: Authorization: Bearer <token>
//   (no body required)
//
// SUCCESS RESPONSE (200):
//   { "status": "success", "data": { "message": "Logged out successfully." } }
//
// ERROR RESPONSES:
//   400  Method not allowed
//   401  Missing/malformed Authorization header
//
// NOTES:
//   This endpoint is intentionally forgiving: it does not first validate
//   that the token is still active (unlike fetch_meter.php / sync_inspection.php
//   which call Auth::requireAuth()). An already-expired or already-logged-out
//   token still returns 200 — the caller's intent ("this token should no
//   longer work") is satisfied either way. This avoids the client getting
//   a confusing 401 when tapping "Logout" on a session that already expired.
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed. Use POST.', 405);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

if (!str_starts_with($header, 'Bearer ')) {
    Response::error('Missing or malformed Authorization header.', 401);
}

$rawToken = substr($header, 7);

if ($rawToken === '') {
    Response::error('Missing or malformed Authorization header.', 401);
}

try {
    Auth::destroySession($rawToken);
    Response::success(['message' => 'Logged out successfully.']);
} catch (PDOException $e) {
    error_log('[logout.php] DB Error: ' . $e->getMessage());
    Response::error('A server error occurred. Please try again.', 500);
}
