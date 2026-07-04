# Meter Testing System — PHP Backend
### Native PHP + MySQL REST API | Modules 1 & 2

---

## File Structure

```
meter_testing_backend/
├── db/
│   ├── schema.sql          ← DDL: all tables, indexes, constraints, view
│   └── seed.sql            ← Test data matching Flutter mock users/meters
├── config/
│   ├── database.php        ← PDO singleton
│   ├── env.php             ← Environment variable guide
│   └── .htaccess           ← Block web access to credentials
├── core/
│   ├── Response.php        ← Centralised JSON response helpers
│   └── Auth.php            ← Token generation, validation, OTP, lockout
└── api/
    ├── login.php           ← POST /api/login
    ├── verify_otp.php      ← POST /api/verify_otp
    ├── fetch_meter.php     ← GET  /api/fetch_meter?reference_no=XYZ
    ├── sync_inspection.php ← POST /api/sync_inspection
    ├── logout.php          ← POST /api/logout
    └── .htaccess           ← CORS headers, directory security
```

---

## Server Requirements

- PHP 8.1+ (uses `readonly`, `never` return type, `str_starts_with`, `match`)
- MySQL 8.0+ (JSON column type, `REGEXP_LIKE`, window functions in views)
- Apache with `mod_rewrite` + `mod_headers` enabled, or Nginx equivalent
- PDO extension with MySQL driver (`php8.x-mysql`)

---

## Setup

### 1. Database
```sql
CREATE DATABASE meter_testing_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

CREATE USER 'meter_api_user'@'localhost'
    IDENTIFIED BY 'your_secure_password_here';

GRANT SELECT, INSERT, UPDATE, DELETE
    ON meter_testing_db.* TO 'meter_api_user'@'localhost';

FLUSH PRIVILEGES;
```

Then run in order:
```bash
mysql -u root -p meter_testing_db < db/schema.sql
mysql -u root -p meter_testing_db < db/seed.sql
```

### 2. Environment
Set in PHP-FPM pool config (`/etc/php/8.x/fpm/pool.d/www.conf`):
```ini
env[DB_HOST] = localhost
env[DB_PORT] = 3306
env[DB_NAME] = meter_testing_db
env[DB_USER] = meter_api_user
env[DB_PASS] = your_secure_password_here
```

### 3. Apache VirtualHost
```apache
<VirtualHost *:443>
    ServerName api.metertesting.gov.pk
    DocumentRoot /var/www/meter_testing_backend

    <Directory /var/www/meter_testing_backend>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/your_cert.crt
    SSLCertificateKeyFile /etc/ssl/private/your_key.key
</VirtualHost>
```

---

## API Contract

### POST /api/login
**Request:**
```json
{ "username": "g.mustafa", "password": "test1234" }
```
**Returning user response (200):**
```json
{
  "status": "success",
  "data": {
    "is_first_login": false,
    "token": "raw_bearer_token_here",
    "user": {
      "employee_id": "EMP-1042",
      "full_name": "Ghulam Mustafa",
      "role_code": "MT",
      "scope_code": "SUB_DIVISION",
      "scope_name": "Multan North Sub-Division"
    }
  }
}
```
**First-time login response (200):**
```json
{
  "status": "success",
  "data": {
    "is_first_login": true,
    "temp_user_id": 5,
    "contact_masked": "03**-***-0001"
  }
}
```

---

### POST /api/verify_otp
**Request:**
```json
{ "user_id": 5, "otp_code": "123456" }
```
**Response (200):** Same as returning user login (token + user payload).

---

### GET /api/fetch_meter?reference_no=REF-2025-00142
**Header:** `Authorization: Bearer <token>`

**Response (200):**
```json
{
  "status": "success",
  "data": {
    "meter_id": "MTR-LHR-2024-00987",
    "consumer_account": "LHR-04-2200-1429",
    "consumer_name": "Haji Textile Mills (Pvt) Ltd.",
    "consumer_address": "Plot 14-B, SITE Area, Lahore",
    "tariff_category": "Industrial B-2",
    "sanctioned_load": "250 kW",
    "formatted_details": "Haji Textile Mills (Pvt) Ltd. | LHR-04-2200-1429 | Industrial B-2 | Load: 250 kW"
  }
}
```

---

### POST /api/sync_inspection
**Header:** `Authorization: Bearer <token>`

**Request:**
```json
{
  "reference_no": "REF-2025-00142",
  "meter_id": "MTR-LHR-2024-00987",
  "consumer_account": "LHR-04-2200-1429",
  "inspection_datetime": "2025-06-14T10:30:00",
  "readings": { "kwh": 12345.67, "kvarh": 3456.78, "mdi": 250.00 },
  "tou_readings": { "peak": 8000.0, "off_peak": 4345.67, "day": null, "night": null },
  "infrastructure": { "seal_condition": "INTACT", "ctpt_box_status": "SECURED" },
  "load_details": "Normal load observed.",
  "image_paths": ["uploads/img1.jpg"],
  "client_device_id": "device-uuid-here"
}
```

**Success (200):**
```json
{ "status": "success", "data": { "inspection_id": 101, "synced_at": "..." } }
```

**Duplicate (409):**
```json
{ "status": "error", "message": "Already synced.", "code": "DUPLICATE" }
```

---

## Wiring into Flutter (replacing mock data)

### 1. login.php → AuthBloc._mockLogin()
```dart
// In auth_bloc.dart, replace _mockLogin() with:
final response = await http.post(
  Uri.parse('$baseUrl/api/login.php'),
  headers: {'Content-Type': 'application/json'},
  body: jsonEncode({'username': username, 'password': password}),
);
final data = jsonDecode(response.body);
if (response.statusCode == 200 && data['data']['is_first_login'] == false) {
  return UserModel.fromJson(data['data']['user'])
    ..token = data['data']['token'];
}
```

### 2. fetch_meter.php → InspectionBloc._mockFetchConsumer()
```dart
final response = await http.get(
  Uri.parse('$baseUrl/api/fetch_meter.php?reference_no=$ref'),
  headers: {'Authorization': 'Bearer $token'},
);
final data = jsonDecode(response.body);
return ConsumerFetchResult.fromJson(data['data']);
```

### 3. sync_inspection.php → InspectionBloc._mockSubmitInspection()
```dart
final response = await http.post(
  Uri.parse('$baseUrl/api/sync_inspection.php'),
  headers: {
    'Content-Type': 'application/json',
    'Authorization': 'Bearer $token',
  },
  body: jsonEncode(payload.toJson()),
);
if (response.statusCode == 409) {
  // Already synced — treat as success on the client side
  return;
}
if (response.statusCode != 200) throw ApiException(response.body);
```

---

## Index Reference

| Table | Index Name | Columns | Type | Purpose |
|-------|-----------|---------|------|---------|
| users | uq_users_username | username | UNIQUE | Login lookup |
| users | idx_users_role | role_id | BTREE | Role joins |
| meters | uq_meters_ref_no | reference_no | UNIQUE | Auto-fetch |
| meters | uq_meters_meter_id | meter_id | UNIQUE | Integrity check |
| inspections | uq_inspection_idempotency | meter_id, submitted_by, inspection_date | UNIQUE | Duplicate prevention |
| inspections | idx_insp_scope_date | scope_id, inspection_date | BTREE | Report queries |
| otp_tokens | idx_otp_user_active | user_id, is_used, expires_at | BTREE | OTP verification |
| user_sessions | uq_session_token | token_hash | UNIQUE | Auth on every request |
