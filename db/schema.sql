-- =============================================================================
-- FILE: db/schema.sql
-- PURPOSE: Complete DDL for the Meter Testing System backend.
--
-- EFFICIENCY PHILOSOPHY:
--   1. Data types are chosen for the smallest possible storage footprint
--      (e.g., TINYINT for booleans, ENUM for fixed-set columns).
--   2. Every column used in WHERE, JOIN, or ORDER BY has an explicit index.
--   3. Composite indexes are ordered by selectivity (most selective first).
--   4. The inspections table carries a composite UNIQUE index for idempotency,
--      making duplicate-detection a zero-cost index scan, not a table scan.
--   5. InnoDB is mandatory on every table for row-level locking, foreign key
--      enforcement, and crash-safe ACID transactions.
--   6. utf8mb4 handles all Unicode including Urdu characters.
-- =============================================================================

SET SQL_MODE   = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';
SET time_zone  = '+05:00'; -- Pakistan Standard Time

-- ---------------------------------------------------------------------------
-- Drop in reverse dependency order so FK constraints don't block the drop
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS inspection_images;
DROP TABLE IF EXISTS inspections;
DROP TABLE IF EXISTS otp_tokens;
DROP TABLE IF EXISTS user_sessions;
DROP TABLE IF EXISTS meters;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS geographic_scopes;
DROP TABLE IF EXISTS roles;

-- =============================================================================
-- TABLE: roles
-- Master list of system roles. Stored separately so new roles can be added
-- without altering the users table or deploying code changes.
-- =============================================================================
CREATE TABLE roles (
    id          TINYINT UNSIGNED    NOT NULL AUTO_INCREMENT,
    code        VARCHAR(20)         NOT NULL,   -- 'MT', 'SDO', 'XEN', 'SE', 'ADMIN'
    label       VARCHAR(80)         NOT NULL,   -- Human-readable display name
    access_level TINYINT UNSIGNED   NOT NULL DEFAULT 0,
                                               -- 0=MT, 1=SDO, 2=XEN, 3=SE, 4=ADMIN
    created_at  TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Unique index: role codes are looked up on every login response
    UNIQUE  KEY uq_roles_code        (code)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Master role definitions';

-- Seed role data
INSERT INTO roles (code, label, access_level) VALUES
    ('MT',    'Meter Tester (M&T)',        0),
    ('SDO',   'Sub-Divisional Officer',    1),
    ('XEN',   'Executive Engineer',        2),
    ('SE',    'Superintending Engineer',   3),
    ('ADMIN', 'System Administrator',      4);

-- =============================================================================
-- TABLE: geographic_scopes
-- Normalised lookup for geographic assignments. Avoids repeating long strings
-- in the users table; JOIN cost is trivial compared to storage and scan savings.
-- =============================================================================
CREATE TABLE geographic_scopes (
    id          SMALLINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    scope_type  ENUM(
                    'SUB_DIVISION',
                    'DIVISION',
                    'CIRCLE',
                    'REGION',
                    'NATIONAL'
                )                   NOT NULL,
    scope_name  VARCHAR(120)        NOT NULL,   -- 'Multan North Sub-Division'
    parent_id   SMALLINT UNSIGNED       NULL,   -- Self-referential: sub-division → division → circle
    created_at  TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE  KEY uq_scope_name        (scope_type, scope_name),
    -- Parent lookup for hierarchy traversal
    KEY         idx_scope_parent     (parent_id),
    KEY         idx_scope_type       (scope_type),

    CONSTRAINT fk_scope_parent
        FOREIGN KEY (parent_id) REFERENCES geographic_scopes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Geographic hierarchy: sub-divisions, divisions, circles';

-- =============================================================================
-- TABLE: users
-- Core authentication table.
--
-- SECURITY DESIGN:
--   - password_hash stores bcrypt output (always 60 chars, stored as CHAR).
--   - otp_secret is stored separately in otp_tokens, not here, to allow
--     expiry and rotation without touching user rows.
--   - failed_login_attempts + locked_until implement brute-force lockout
--     entirely at the DB layer, avoiding race conditions in PHP.
--   - is_first_login drives the OTP screen flag returned on login.
--
-- INDEX STRATEGY:
--   - username is the primary login lookup key → unique index, prefix none
--     (short enough for full index coverage).
--   - employee_id is shown in the UI and referenced in audit trails → unique.
--   - scope_id foreign key gets its own index for joining on geographic filter.
-- =============================================================================
CREATE TABLE users (
    id                      INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    employee_id             VARCHAR(30)         NOT NULL,
    username                VARCHAR(80)         NOT NULL,   -- login identifier
    password_hash           CHAR(60)            NOT NULL,   -- bcrypt hash
    full_name               VARCHAR(120)        NOT NULL,
    contact_masked          VARCHAR(20)             NULL,   -- '03**-***-7890'
    role_id                 TINYINT UNSIGNED    NOT NULL,
    scope_id                SMALLINT UNSIGNED       NULL,   -- NULL = national
    is_first_login          TINYINT(1)          NOT NULL DEFAULT 1,
    is_active               TINYINT(1)          NOT NULL DEFAULT 1,
    failed_login_attempts   TINYINT UNSIGNED    NOT NULL DEFAULT 0,
    locked_until            DATETIME                NULL,   -- NULL = not locked
    last_login_at           DATETIME                NULL,
    created_at              TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                    ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Login path: username lookup is the hottest query in the system
    UNIQUE  KEY uq_users_username     (username),
    UNIQUE  KEY uq_users_employee_id  (employee_id),
    -- Role and scope joins
    KEY         idx_users_role        (role_id),
    KEY         idx_users_scope       (scope_id),
    KEY         idx_users_active      (is_active),

    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_users_scope
        FOREIGN KEY (scope_id) REFERENCES geographic_scopes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='User accounts, credentials, and role assignments';

-- =============================================================================
-- TABLE: otp_tokens
-- Decoupled OTP storage — separate from users to allow clean expiry handling.
--
-- WHY SEPARATE: If stored in users, every OTP expiry requires an UPDATE on
-- the users row, which in InnoDB writes a new row version and impacts the
-- buffer pool. A separate small table keeps the hot users table cold.
-- =============================================================================
CREATE TABLE otp_tokens (
    id          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED        NOT NULL,
    otp_code    CHAR(6)             NOT NULL,   -- 6-digit numeric PIN
    expires_at  DATETIME            NOT NULL,   -- short TTL (10 min)
    is_used     TINYINT(1)          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Verification path: user_id + unused + not expired
    KEY         idx_otp_user_active  (user_id, is_used, expires_at),

    CONSTRAINT fk_otp_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Short-lived OTP tokens for first-time login verification';

-- =============================================================================
-- TABLE: user_sessions
-- Bearer token store. Stateless JWT is an alternative but requires a token
-- blacklist for logout anyway — a DB session table is simpler and auditable.
-- =============================================================================
CREATE TABLE user_sessions (
    id          INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED        NOT NULL,
    token_hash  CHAR(64)            NOT NULL,   -- SHA-256 of the raw bearer token
    expires_at  DATETIME            NOT NULL,
    ip_address  VARCHAR(45)             NULL,   -- IPv6-safe length
    user_agent  VARCHAR(255)            NULL,
    created_at  TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- Token validation: hash lookup on every authenticated request
    UNIQUE  KEY uq_session_token     (token_hash),
    KEY         idx_session_user     (user_id, expires_at),

    CONSTRAINT fk_session_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Active bearer token sessions';

-- =============================================================================
-- TABLE: meters
-- Pre-loaded meter & consumer master data used by the auto-fetch engine.
--
-- CRITICAL INDEX:
--   reference_no is the only lookup key — must be UNIQUE and fully indexed.
--   Using VARCHAR(30) with a full unique index gives O(log n) lookup via
--   B-tree; no full-table scan ever needed regardless of table size.
--
-- DATA LOADING:
--   This table is populated by batch import from WAPDA/DISCO billing systems,
--   not by field workers. Kept INSERT-cold, SELECT-hot.
-- =============================================================================
CREATE TABLE meters (
    id                  INT UNSIGNED        NOT NULL AUTO_INCREMENT,
    reference_no        VARCHAR(30)         NOT NULL,   -- 'REF-2025-00142'
    meter_id            VARCHAR(40)         NOT NULL,   -- 'MTR-LHR-2024-00987'
    consumer_account    VARCHAR(30)         NOT NULL,   -- 'LHR-04-2200-1429'
    consumer_name       VARCHAR(150)        NOT NULL,
    consumer_address    VARCHAR(255)        NOT NULL,
    tariff_category     VARCHAR(40)         NOT NULL,   -- 'Industrial B-2'
    sanctioned_load     VARCHAR(20)         NOT NULL,   -- '250 kW'
    scope_id            SMALLINT UNSIGNED       NULL,   -- geographic assignment
    is_active           TINYINT(1)          NOT NULL DEFAULT 1,
    created_at          TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP
                                                ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    -- The single hottest query: fetch by reference_no
    UNIQUE  KEY uq_meters_ref_no      (reference_no),
    -- Secondary lookups by meter_id and consumer account
    UNIQUE  KEY uq_meters_meter_id    (meter_id),
    KEY         idx_meters_account    (consumer_account),
    KEY         idx_meters_scope      (scope_id),
    KEY         idx_meters_active     (is_active),

    CONSTRAINT fk_meters_scope
        FOREIGN KEY (scope_id) REFERENCES geographic_scopes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Pre-loaded meter and consumer master data for auto-fetch';

-- =============================================================================
-- TABLE: inspections
-- The core data table for submitted field inspection forms.
--
-- IDEMPOTENCY / DUPLICATE PREVENTION:
--   The composite UNIQUE KEY (meter_id, submitted_by, inspection_date) is the
--   idempotency key. It means:
--     - One meter can be inspected once per calendar day per inspector.
--     - An offline device that submits the same inspection twice will trigger
--       a 409 Conflict at the DB layer (duplicate key error), not a silent
--       double-insert.
--   This is enforced at the DB level — PHP catches the error, not a SELECT
--   before INSERT race condition.
--
-- TOU COLUMNS:
--   Nullable DECIMAL — meters without TOU tariff leave these NULL.
--
-- IMAGE PATHS:
--   Up to 12 image URLs stored as a JSON array in a single TEXT column.
--   Avoids a separate inspection_images child table for the common case of
--   <12 images; the child table inspection_images handles overflow/metadata.
-- =============================================================================
CREATE TABLE inspections (
    id                  BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    reference_no        VARCHAR(30)         NOT NULL,
    meter_id            VARCHAR(40)         NOT NULL,
    consumer_account    VARCHAR(30)         NOT NULL,
    submitted_by        INT UNSIGNED        NOT NULL,   -- FK → users.id
    scope_id            SMALLINT UNSIGNED       NULL,

    -- Timestamps
    inspection_date     DATE                NOT NULL,   -- date portion for idempotency key
    inspection_datetime DATETIME            NOT NULL,   -- full datetime for records
    synced_at           TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Technical readings  (DECIMAL(12,3): up to 999,999,999.999 kWh — adequate for large consumers)
    kwh                 DECIMAL(12, 3)      NOT NULL,
    kvarh               DECIMAL(12, 3)      NOT NULL,
    mdi                 DECIMAL(12, 3)      NOT NULL,

    -- TOU readings (nullable — not all tariff categories have TOU)
    tou_peak            DECIMAL(12, 3)          NULL,
    tou_off_peak        DECIMAL(12, 3)          NULL,
    tou_day             DECIMAL(12, 3)          NULL,
    tou_night           DECIMAL(12, 3)          NULL,

    -- Infrastructure status (ENUM: DB enforces valid values, storage is 1 byte)
    seal_condition      ENUM(
                            'INTACT',
                            'BROKEN',
                            'TAMPERED',
                            'MISSING'
                        )                   NOT NULL,
    ctpt_box_status     ENUM(
                            'SECURED',
                            'ACCESSIBLE',
                            'TAMPERED',
                            'DAMAGED'
                        )                   NOT NULL,

    -- Free-text observations
    load_details        TEXT                    NULL,

    -- Image paths: JSON array of relative paths, e.g. ["uploads/abc.jpg","uploads/xyz.jpg"]
    -- PHP validates max 12 entries before insert
    image_paths         JSON                    NULL,

    -- Sync metadata
    client_device_id    VARCHAR(40)             NULL,   -- UUID from the mobile device
    sync_status         ENUM('PENDING','SYNCED','FAILED')
                                            NOT NULL DEFAULT 'SYNCED',

    PRIMARY KEY (id),

    -- ─────────────────────────────────────────────────────────────────────────
    -- IDEMPOTENCY KEY: prevents duplicate submissions for the same inspection.
    -- One inspector can inspect one meter once per day.
    -- The DB enforces this — no SELECT-before-INSERT race condition possible.
    -- ─────────────────────────────────────────────────────────────────────────
    UNIQUE  KEY uq_inspection_idempotency (meter_id, submitted_by, inspection_date),

    -- Lookup indexes for reporting and history screens
    KEY         idx_insp_ref_no       (reference_no),
    KEY         idx_insp_submitted_by (submitted_by),
    KEY         idx_insp_scope        (scope_id),
    KEY         idx_insp_date         (inspection_date),
    -- Composite for the most common report query: by scope + date range
    KEY         idx_insp_scope_date   (scope_id, inspection_date),

    CONSTRAINT fk_insp_submitted_by
        FOREIGN KEY (submitted_by) REFERENCES users (id)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_insp_scope
        FOREIGN KEY (scope_id) REFERENCES geographic_scopes (id)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Submitted field inspection records from the Smart Form';

-- =============================================================================
-- TABLE: inspection_images
-- Normalized child table for image metadata when richer data is needed
-- (e.g., GPS coordinates per image, upload status, mime type).
-- The parent inspections.image_paths JSON handles the simple path-only case.
-- =============================================================================
CREATE TABLE inspection_images (
    id              BIGINT UNSIGNED     NOT NULL AUTO_INCREMENT,
    inspection_id   BIGINT UNSIGNED     NOT NULL,
    file_path       VARCHAR(255)        NOT NULL,
    mime_type       VARCHAR(40)         NOT NULL DEFAULT 'image/jpeg',
    file_size_kb    SMALLINT UNSIGNED       NULL,
    gps_lat         DECIMAL(10, 7)          NULL,
    gps_lng         DECIMAL(10, 7)          NULL,
    captured_at     DATETIME                NULL,
    sort_order      TINYINT UNSIGNED    NOT NULL DEFAULT 0,

    PRIMARY KEY (id),
    KEY idx_img_inspection (inspection_id),

    CONSTRAINT fk_img_inspection
        FOREIGN KEY (inspection_id) REFERENCES inspections (id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Normalized image metadata for inspection photographs';

-- =============================================================================
-- VIEWS (query helpers, not materialized — zero storage cost)
-- =============================================================================

-- View used by reporting endpoints: joins everything needed for a report row
CREATE OR REPLACE VIEW v_inspection_summary AS
SELECT
    i.id,
    i.reference_no,
    i.meter_id,
    i.consumer_account,
    m.consumer_name,
    m.tariff_category,
    u.full_name          AS inspector_name,
    u.employee_id        AS inspector_id,
    r.code               AS inspector_role,
    gs.scope_name        AS geographic_scope,
    i.inspection_datetime,
    i.kwh,
    i.kvarh,
    i.mdi,
    i.tou_peak,
    i.tou_off_peak,
    i.seal_condition,
    i.ctpt_box_status,
    i.sync_status
FROM       inspections    i
INNER JOIN users          u  ON u.id  = i.submitted_by
INNER JOIN roles          r  ON r.id  = u.role_id
LEFT  JOIN meters         m  ON m.meter_id = i.meter_id
LEFT  JOIN geographic_scopes gs ON gs.id  = i.scope_id;
