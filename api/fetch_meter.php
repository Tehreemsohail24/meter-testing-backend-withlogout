<?php
// =============================================================================
// FILE: api/fetch_meter.php
// METHOD: GET
// PURPOSE: Return pre-populated meter and consumer details for a given
//          reference number. Powers the "Auto-Fetch Data" button in the app.
//
// REQUEST:
//   GET /api/fetch_meter.php?reference_no=REF-2025-00142
//   Header: Authorization: Bearer <token>
//
// SUCCESS RESPONSE (200):
//   {
//     "status": "success",
//     "data": {
//       "meter_id":         "MTR-LHR-2024-00987",
//       "consumer_account": "LHR-04-2200-1429",
//       "consumer_name":    "Haji Textile Mills (Pvt) Ltd.",
//       "consumer_address": "Plot 14-B, SITE Area, Lahore",
//       "tariff_category":  "Industrial B-2",
//       "sanctioned_load":  "250 kW",
//       "scope_name":       "Multan North Sub-Division"
//     }
//   }
//
// ERROR RESPONSES:
//   400  Missing reference_no parameter
//   401  Invalid/missing token
//   404  Reference number not found
//
// EFFICIENCY NOTE:
//   This endpoint executes a SINGLE SELECT on uq_meters_ref_no (UNIQUE index).
//   MySQL resolves this in one B-tree traversal — effectively O(1) regardless
//   of how many millions of meters are in the table. No full-table scan ever.
//   No caching layer is needed for this access pattern.
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Response::error('Method not allowed. Use GET.', 405);
}

// ── Authenticate ──────────────────────────────────────────────────────────────
$authUser = Auth::requireAuth();

// ── Validate query parameter ──────────────────────────────────────────────────
$referenceNo = trim($_GET['reference_no'] ?? '');

if ($referenceNo === '') {
    Response::error('Query parameter reference_no is required.', 400);
}

// Enforce maximum input length before hitting the DB
if (strlen($referenceNo) > 30) {
    Response::error('reference_no exceeds maximum allowed length of 30 characters.', 400);
}

// Validate format: only alphanumeric, hyphens, underscores
// This blocks any attempt to inject characters into the query parameter
if (!preg_match('/^[A-Z0-9\-_]+$/i', $referenceNo)) {
    Response::error('reference_no contains invalid characters.', 400);
}

try {
    $pdo = Database::getConnection();

    // ── Single indexed SELECT — the entire purpose of uq_meters_ref_no ────────
    // The LEFT JOIN to geographic_scopes adds scope context with zero extra
    // round-trips. Selecting only the columns the mobile app needs (not SELECT *)
    // reduces network payload and avoids sending sensitive internal fields.
    $stmt = $pdo->prepare(
        'SELECT m.meter_id,
                m.consumer_account,
                m.consumer_name,
                m.consumer_address,
                m.tariff_category,
                m.sanctioned_load,
                gs.scope_name
         FROM   meters              m
         LEFT  JOIN geographic_scopes gs ON gs.id = m.scope_id
         WHERE  m.reference_no = ?
           AND  m.is_active     = 1
         LIMIT  1'
    );
    $stmt->execute([$referenceNo]);
    $meter = $stmt->fetch();

    if (!$meter) {
        Response::error('No active meter found for the provided reference number.', 404);
    }

    // ── Scope enforcement: field workers only see meters in their scope ────────
    // Admin (access_level 4) and SE/XEN see all; MT/SDO only see their scope.
    if (
        $authUser['access_level'] < 2           // below XEN
        && $authUser['scope_id'] !== null        // scoped user (not national)
        && $meter['scope_name'] !== null
    ) {
        // Re-query to check scope match efficiently
        $scopeStmt = $pdo->prepare(
            'SELECT 1 FROM meters m
             INNER JOIN geographic_scopes gs ON gs.id = m.scope_id
             WHERE  m.reference_no = ?
               AND  m.scope_id     = ?
             LIMIT  1'
        );
        $scopeStmt->execute([$referenceNo, $authUser['scope_id']]);

        if (!$scopeStmt->fetch()) {
            // Return 404 (not 403): don't reveal that the meter exists
            // but is outside the user's scope
            Response::error('No active meter found for the provided reference number.', 404);
        }
    }

    Response::success([
        'meter_id'         => $meter['meter_id'],
        'consumer_account' => $meter['consumer_account'],
        'consumer_name'    => $meter['consumer_name'],
        'consumer_address' => $meter['consumer_address'],
        'tariff_category'  => $meter['tariff_category'],
        'sanctioned_load'  => $meter['sanctioned_load'],
        'scope_name'       => $meter['scope_name'],
        // Convenience field: the app uses this to pre-fill the read-only display
        'formatted_details' => sprintf(
            '%s | %s | %s | Load: %s',
            $meter['consumer_name'],
            $meter['consumer_account'],
            $meter['tariff_category'],
            $meter['sanctioned_load']
        ),
    ]);

} catch (PDOException $e) {
    error_log('[fetch_meter.php] DB Error: ' . $e->getMessage());
    Response::error('A server error occurred. Please try again.', 500);
}
