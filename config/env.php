<?php
// =============================================================================
// FILE: config/env.php
// PURPOSE: Load environment variables from a .env file or server config.
//          Copy this file's pattern to your actual server's environment
//          (php-fpm pool config, Apache SetEnv, or a .env loader library).
//
// NEVER commit actual credentials to version control.
// Add .env to .gitignore.
// =============================================================================

// If using a .env file (install vlucas/phpdotenv via Composer):
// require_once __DIR__ . '/../vendor/autoload.php';
// $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
// $dotenv->load();

// For plain PHP without Composer, set these as actual environment variables
// in your PHP-FPM pool (www.conf) or Apache VirtualHost:
//
//   ; /etc/php/8.x/fpm/pool.d/www.conf
//   env[DB_HOST] = localhost
//   env[DB_PORT] = 3306
//   env[DB_NAME] = meter_testing_db
//   env[DB_USER] = meter_api_user
//   env[DB_PASS] = your_secure_password_here
//
// For development only, you can temporarily hardcode here:
// putenv('DB_HOST=localhost');
// putenv('DB_NAME=meter_testing_db');
// putenv('DB_USER=meter_api_user');
// putenv('DB_PASS=your_dev_password');
