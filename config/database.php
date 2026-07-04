<?php
// =============================================================================
// FILE: config/database.php
// PURPOSE: PDO connection singleton for Railway MySQL
// =============================================================================

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}
    private function __clone() {}

    /**
     * Returns the shared PDO instance, creating it on first call.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {

            // Railway MySQL Environment Variables
            $host = $_ENV['MYSQLHOST'] ?? getenv('MYSQLHOST');
            $port = $_ENV['MYSQLPORT'] ?? getenv('MYSQLPORT');
            $dbname = $_ENV['MYSQLDATABASE'] ?? getenv('MYSQLDATABASE');
            $user = $_ENV['MYSQLUSER'] ?? getenv('MYSQLUSER');
            $pass = $_ENV['MYSQLPASSWORD'] ?? getenv('MYSQLPASSWORD');

            // Stop immediately if any variable is missing
            if (!$host || !$port || !$dbname || !$user || !$pass) {
                throw new Exception("Railway MySQL environment variables are missing.");
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $host,
                $port,
                $dbname
            );

            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND =>
                    "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];

            self::$instance = new PDO($dsn, $user, $pass, $options);
        }

        return self::$instance;
    }
}