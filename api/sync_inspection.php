<?php
// =============================================================================
// FILE: api/sync_inspection.php
// METHOD: POST
// PURPOSE: Accept, validate, idempotency-check, and persist a single
//          field inspection record from the mobile app.
//
// IDEMPOTENCY MECHANISM:
//   The DB has a UNIQUE KEY on (meter_id, submitted_by, inspection_date).
//   We use INSERT IGNORE + row-count check instead of SELECT-then-INSERT
//   to eliminate the TOCTOU race condition that would exist if two
//   offline devices synced the same record simultaneously.
//
// REQUEST BODY (JSON):
//   {
//     "reference_no":     "REF-2025-00142",
//     "meter_id":         "MTR-LHR-2024-00987",
//     "consumer_account": "LHR-04-2200-1429",
//     "inspection_datetime": "2025-06-14T10:30:00",
//     "readings": { "kwh": 12345.67, "kvarh": 3456.78, "mdi": 250.00 },
//     "tou_readings": { "peak": 8000.0, "off_peak": 4345.67, "day": null, "night": null },
//     "infrastructure": { "seal_condition": "INTACT", "ctpt_box_status": "SECURED" },
//     "load_details": "Normal load observed.",
//     "image_paths": ["uploads/abc.jpg"],   // max 12 entries
//     "client_device_id": "uuid-from-device"
//   }
//
// SUCCESS RESPONSE (200 — new record inserted):
//   { "status": "success", "data": { "inspection_id": 101, "message": "..." } }
//
// ALREADY SYNCED (409 — duplicate, not an error for the client to crash on):
//   { "status": "error", "message": "Inspection already synced.", "code": "DUPLICATE" }
//
// ERROR RESPONSES:
//   400  Validation failure (missing fields, invalid values)
//   401  Invalid token
//   409  Duplicate submission (idempotency conflict)
// =============================================================================

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../core/Response.php';
require_once __DIR__ . '/../core/Auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Method not allowed. Use POST.', 405);
}

// ── Authenticate ──────────────────────────────────────────────────────────────
$authUser = Auth::requireAuth();

// ── Parse body ────────────────────────────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    Response::error('Request body must be valid JSON.', 400);
}

// =============================================================================
// SECTION 1: INPUT SANITIZATION & VALIDATION
// All validation is done before any DB interaction to fail fast and cheaply.
// =============================================================================

$errors = [];

// ── Scalar string fields ──────────────────────────────────────────────────────
$referenceNo     = trim((string) ($body['reference_no']     ?? ''));
$meterId         = trim((string) ($body['meter_id']         ?? ''));
$consumerAccount = trim((string) ($body['consumer_account'] ?? ''));
$inspDt          = trim((string) ($body['inspection_datetime'] ?? ''));
$loadDetails     = trim((string) ($body['load_details']     ?? ''));
$clientDeviceId  = trim((string) ($body['client_device_id'] ?? ''));

if ($referenceNo === '')     $errors[] = 'reference_no is required.';
if ($meterId === '')         $errors[] = 'meter_id is required.';
if ($consumerAccount === '') $errors[] = 'consumer_account is required.';
if ($inspDt === '')          $errors[] = 'inspection_datetime is required.';

// Length guards (match DB column sizes)
if (strlen($referenceNo)     > 30)  $errors[] = 'reference_no too long (max 30).';
if (strlen($meterId)         > 40)  $errors[] = 'meter_id too long (max 40).';
if (strlen($consumerAccount) > 30)  $errors[] = 'consumer_account too long (max 30).';
if (strlen($clientDeviceId)  > 40)  $errors[] = 'client_device_id too long (max 40).';

// ── Datetime parsing ──────────────────────────────────────────────────────────
$inspectionDatetime = null;
$inspectionDate     = null;

if ($inspDt !== '') {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $inspDt);
    if ($dt === false) {
        // Try ISO with timezone offset
        $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $inspDt);
    }
    if ($dt === false) {
        $errors[] = 'inspection_datetime must be ISO 8601 format (e.g. 2025-06-14T10:30:00).';
    } else {
        // Reject future-dated inspections (clock drift tolerance: 5 minutes)
        if ($dt->getTimestamp() > time() + 300) {
            $errors[] = 'inspection_datetime cannot be in the future.';
        }
        $inspectionDatetime = $dt->format('Y-m-d H:i:s');
        $inspectionDate     = $dt->format('Y-m-d');
    }
}

// ── Readings ──────────────────────────────────────────────────────────────────
$readings = $body['readings'] ?? [];

if (!is_array($readings)) {
    $errors[] = 'readings must be an object.';
} else {
    foreach (['kwh', 'kvarh', 'mdi'] as $field) {
        if (!isset($readings[$field])) {
            $errors[] = "readings.{$field} is required.";
        } elseif (!is_numeric($readings[$field])) {
            $errors[] = "readings.{$field} must be a numeric value.";
        } elseif ((float) $readings[$field] < 0) {
            $errors[] = "readings.{$field} cannot be negative.";
        }
    }
}

$kwh   = isset($readings['kwh'])   ? round((float) $readings['kwh'],   3) : null;
$kvarh = isset($readings['kvarh']) ? round((float) $readings['kvarh'], 3) : null;
$mdi   = isset($readings['mdi'])   ? round((float) $readings['mdi'],   3) : null;

// ── TOU readings (nullable) ───────────────────────────────────────────────────
$tou = $body['tou_readings'] ?? [];
$touFields = [];

foreach (['peak', 'off_peak', 'day', 'night'] as $touField) {
    $val = $tou[$touField] ?? null;
    if ($val !== null && !is_numeric($val)) {
        $errors[] = "tou_readings.{$touField} must be numeric or null.";
        $touFields[$touField] = null;
    } else {
        $touFields[$touField] = ($val !== null) ? round((float) $val, 3) : null;
    }
}

// ── Infrastructure ENUMs ──────────────────────────────────────────────────────
$infra = $body['infrastructure'] ?? [];

$validSealConditions  = ['INTACT', 'BROKEN', 'TAMPERED', 'MISSING'];
$validCtPtStatuses    = ['SECURED', 'ACCESSIBLE', 'TAMPERED', 'DAMAGED'];

$sealCondition = strtoupper(trim((string) ($infra['seal_condition']  ?? '')));
$ctPtStatus    = strtoupper(trim((string) ($infra['ctpt_box_status'] ?? '')));

if (!in_array($sealCondition, $validSealConditions, true)) {
    $errors[] = 'infrastructure.seal_condition must be one of: '
              . implode(', ', $validSealConditions) . '.';
}
if (!in_array($ctPtStatus, $validCtPtStatuses, true)) {
    $errors[] = 'infrastructure.ctpt_box_status must be one of: '
              . implode(', ', $validCtPtStatuses) . '.';
}

// ── Image paths (max 12, JSON-encoded array) ──────────────────────────────────
$imagePaths    = $body['image_paths'] ?? null;
$imagePathsJson = null;

if ($imagePaths !== null) {
    if (!is_array($imagePaths)) {
        $errors[] = 'image_paths must be an array.';
    } elseif (count($imagePaths) > 12) {
        $errors[] = 'image_paths cannot contain more than 12 entries.';
    } else {
        // Validate each path is a non-empty string with safe characters
        foreach ($imagePaths as $i => $path) {
            if (!is_string($path) || $path === '') {
                $errors[] = "image_paths[{$i}] must be a non-empty string.";
            } elseif (!preg_match('#^[a-zA-Z0-9/_\-\.]+$#', $path)) {
                $errors[] = "image_paths[{$i}] contains invalid characters.";
            }
        }
        if (empty($errors)) {
            $imagePathsJson = json_encode($imagePaths, JSON_UNESCAPED_SLASHES);
        }
    }
}

// ── Return all validation errors at once (don't make client fix one at a time)
if (!empty($errors)) {
    Response::error('Validation failed.', 400, ['validation_errors' => $errors]);
}

// =============================================================================
// SECTION 2: DB WRITE WITH IDEMPOTENCY CHECK
// =============================================================================

try {
    $pdo = Database::getConnection();

    // ── Verify meter exists and reference_no matches (integrity check) ─────────
    // Uses the uq_meters_ref_no index — O(log n) lookup.
    $meterStmt = $pdo->prepare(
        'SELECT id FROM meters
         WHERE reference_no = ? AND meter_id = ? AND is_active = 1
         LIMIT 1'
    );
    $meterStmt->execute([$referenceNo, $meterId]);

    if (!$meterStmt->fetch()) {
        Response::error(
            'Reference number and Meter ID combination not found or inactive.',
            400
        );
    }

    // ── INSERT IGNORE — the idempotency core ──────────────────────────────────
    //
    // INSERT IGNORE silently skips the insert if the UNIQUE KEY
    // (meter_id, submitted_by, inspection_date) already exists.
    // PDO::rowCount() returns 0 on skip, 1 on success.
    //
    // WHY NOT SELECT-THEN-INSERT:
    //   SELECT + INSERT has a window between the two statements where a
    //   concurrent request (same offline device syncing twice simultaneously)
    //   can pass both checks and insert two rows. INSERT IGNORE is atomic.
    //
    $stmt = $pdo->prepare(
        'INSERT IGNORE INTO inspections (
            reference_no, meter_id, consumer_account,
            submitted_by, scope_id,
            inspection_date, inspection_datetime,
            kwh, kvarh, mdi,
            tou_peak, tou_off_peak, tou_day, tou_night,
            seal_condition, ctpt_box_status,
            load_details, image_paths,
            client_device_id, sync_status
        ) VALUES (
            :reference_no, :meter_id, :consumer_account,
            :submitted_by, :scope_id,
            :inspection_date, :inspection_datetime,
            :kwh, :kvarh, :mdi,
            :tou_peak, :tou_off_peak, :tou_day, :tou_night,
            :seal_condition, :ctpt_box_status,
            :load_details, :image_paths,
            :client_device_id, :sync_status
        )'
    );

    $stmt->execute([
        ':reference_no'      => $referenceNo,
        ':meter_id'          => $meterId,
        ':consumer_account'  => $consumerAccount,
        ':submitted_by'      => $authUser['user_id'],
        ':scope_id'          => $authUser['scope_id'],
        ':inspection_date'   => $inspectionDate,
        ':inspection_datetime' => $inspectionDatetime,
        ':kwh'               => $kwh,
        ':kvarh'             => $kvarh,
        ':mdi'               => $mdi,
        ':tou_peak'          => $touFields['peak'],
        ':tou_off_peak'      => $touFields['off_peak'],
        ':tou_day'           => $touFields['day'],
        ':tou_night'         => $touFields['night'],
        ':seal_condition'    => $sealCondition,
        ':ctpt_box_status'   => $ctPtStatus,
        ':load_details'      => $loadDetails !== '' ? $loadDetails : null,
        ':image_paths'       => $imagePathsJson,
        ':client_device_id'  => $clientDeviceId !== '' ? $clientDeviceId : null,
        ':sync_status'       => 'SYNCED',
    ]);

    // ── Check if a row was actually inserted ──────────────────────────────────
    if ($stmt->rowCount() === 0) {
        // IGNORE fired: this exact inspection was already in the DB.
        // Return 409 so the mobile client can mark the local record as synced
        // without treating it as a hard error.
        Response::error(
            'This inspection has already been synced. No duplicate created.',
            409,
            ['code' => 'DUPLICATE']
        );
    }

    $newId = (int) $pdo->lastInsertId();

    Response::success([
        'inspection_id' => $newId,
        'message'       => 'Inspection record synced successfully.',
        'synced_at'     => date('Y-m-d H:i:s'),
    ]);

} catch (PDOException $e) {
    error_log('[sync_inspection.php] DB Error: ' . $e->getMessage());
    Response::error('A server error occurred. Please try again.', 500);
}
