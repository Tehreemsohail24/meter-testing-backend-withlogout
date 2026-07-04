<?php
// =============================================================================
// FILE: config/database.php
// PURPOSE: PDO connection singleton.
//
// EFFICIENCY CHOICES:
//   - Singleton pattern: one PDO instance is reused per request lifecycle.
//     Creating a new PDO connection per query (a common beginner mistake)
//     costs ~5–10ms of TCP handshake + auth round-trip per call.
//   - ATTR_EMULATE_PREPARES = false: forces real server-side prepared
//     statements. Emulated prepares send the full query twice; real prepares
//     send the plan once and data once — critical for repeated queries.
//   - ATTR_STRINGIFY_FETCHES = false: returns native PHP types (int, float)
//     instead of strings for numeric columns. No casting overhead in API code.
//   - MYSQL_ATTR_INIT_COMMAND: sets charset once at connect time rather than
//     requiring SET NAMES on every new connection.
//   - ERRMODE_EXCEPTION: throws PDOException on errors — caught by
//     try/catch in each endpoint, never silently ignored.
// =============================================================================

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    // Prevent instantiation and cloning
    private function __construct() {}
    private function __clone() {}

    /**
     * Returns the shared PDO instance, creating it on first call.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $host    = $_ENV['DB_HOST']    ?? 'sql206.infinityfree.com';
            $port    = $_ENV['DB_PORT']    ?? '3306';
            $dbname  = $_ENV['DB_NAME']    ?? 'if0_42240607_metertesting';
            $user    = $_ENV['DB_USER']    ?? 'if0_42240607';
            $pass    = $_ENV['DB_PASS']    ?? 'Nf03066463662';

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host, $port, $dbname
            );

            $options = [
                // Throw exceptions — never return silent false
                PDO::ATTR_ERRMODE                  => PDO::ERRMODE_EXCEPTION,
                // Return associative arrays by default
                PDO::ATTR_DEFAULT_FETCH_MODE       => PDO::FETCH_ASSOC,
                // Real server-side prepares (critical for security + performance)
                PDO::ATTR_EMULATE_PREPARES         => false,
                // Return native PHP types, not all-strings
                PDO::ATTR_STRINGIFY_FETCHES        => false,
                // Force charset at the protocol level (safer than SET NAMES)
                PDO::MYSQL_ATTR_INIT_COMMAND       => "SET NAMES 'utf8mb4' COLLATE 'utf8mb4_unicode_ci', time_zone='+05:00'",
                // Persistent connections: reuse TCP socket across PHP-FPM workers
                // (only safe with a connection pooler like ProxySQL in production)
                // PDO::ATTR_PERSISTENT => true,
            ];

            self::$instance = new PDO($dsn, $user, $pass, $options);
        }

        return self::$instance;
    }
}
