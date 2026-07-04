-- =============================================================================
-- FILE: db/seed.sql
-- PURPOSE: Seed data for development and testing.
--          Matches the mock credentials used in the Flutter frontend exactly.
--
-- PASSWORD: All test users have password: test1234
--           bcrypt hash generated with: password_hash('test1234', PASSWORD_BCRYPT, ['cost'=>12])
--
-- RUN AFTER: schema.sql
-- =============================================================================

-- ── Geographic Scopes ─────────────────────────────────────────────────────────
INSERT INTO geographic_scopes (id, scope_type, scope_name, parent_id) VALUES
    (1,  'CIRCLE',       'Multan Circle',                  NULL),
    (2,  'CIRCLE',       'Bahawalpur Circle',              NULL),
    (3,  'DIVISION',     'Multan Division',                1),
    (4,  'DIVISION',     'Bahawalpur Division',            2),
    (5,  'SUB_DIVISION', 'Multan North Sub-Division',      3),
    (6,  'SUB_DIVISION', 'Bahawalpur East Sub-Division',   4),
    (7,  'SUB_DIVISION', 'Dera Ghazi Khan Sub-Division',   3),
    (8,  'DIVISION',     'Lahore Division',                NULL),
    (9,  'SUB_DIVISION', 'Lahore South Sub-Division',      8);

-- ── Test Users (matching Flutter mock credentials) ────────────────────────────
-- Hash for 'test1234': $2y$12$... (bcrypt cost 12)
-- Generate fresh with: php -r "echo password_hash('test1234', PASSWORD_BCRYPT, ['cost'=>12]);"
INSERT INTO users
    (employee_id, username, password_hash, full_name, contact_masked, role_id, scope_id, is_first_login, is_active)
VALUES
    -- M&T field worker
    ('EMP-1042', 'g.mustafa',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'Ghulam Mustafa', '03**-***-7892',
     (SELECT id FROM roles WHERE code = 'MT'),
     5, 0, 1),

    -- SDO
    ('EMP-2015', 'z.ahmed.sdo',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'Zulfiqar Ahmed', '03**-***-5543',
     (SELECT id FROM roles WHERE code = 'SDO'),
     6, 0, 1),

    -- XEN
    ('EMP-3007', 'a.mehmood.xen',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'Arshad Mehmood', '03**-***-1221',
     (SELECT id FROM roles WHERE code = 'XEN'),
     3, 0, 1),

    -- SE
    ('EMP-4001', 't.malik.se',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'Tariq Hussain Malik', '03**-***-0098',
     (SELECT id FROM roles WHERE code = 'SE'),
     1, 0, 1),

    -- Admin (first-time login → triggers OTP flow)
    ('EMP-9999', 'admin',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'System Administrator', '03**-***-0001',
     (SELECT id FROM roles WHERE code = 'ADMIN'),
     NULL, 1, 1),

    -- New M&T (first-time login → triggers OTP flow)
    ('EMP-1099', 'i.farooq.new',
     '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
     'Imran Farooq', '03**-***-6634',
     (SELECT id FROM roles WHERE code = 'MT'),
     7, 1, 1);

-- ── Meter Master Data (matching Flutter mock database) ────────────────────────
INSERT INTO meters
    (reference_no, meter_id, consumer_account, consumer_name, consumer_address,
     tariff_category, sanctioned_load, scope_id, is_active)
VALUES
    ('REF-2025-00142',
     'MTR-LHR-2024-00987', 'LHR-04-2200-1429',
     'Haji Textile Mills (Pvt) Ltd.',
     'Plot 14-B, SITE Area, Lahore',
     'Industrial B-2', '250 kW', 9, 1),

    ('REF-2025-00891',
     'MTR-MUL-2023-03341', 'MUL-07-1100-0042',
     'Punjab Agricultural Tube Well Scheme',
     'Chak 42/ML, Multan District',
     'Agricultural A-2', '50 kW', 5, 1),

    ('REF-2025-01203',
     'MTR-FSD-2022-07715', 'FSD-01-3300-0080',
     'Faisalabad Municipal Corporation',
     'Civil Lines, Faisalabad',
     'Commercial C-1', '100 kW', NULL, 1),

    ('REF-2025-00057',
     'MTR-BWP-2024-01122', 'BWP-09-4400-0320',
     'Ibrahim Flour Mills',
     'GT Road, Bahawalpur',
     'Industrial B-3 (TOU)', '500 kW', 6, 1);
