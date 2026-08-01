<?php

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $port;
    private $charset = 'utf8mb4';
    private $conn = null;

    public function __construct() {
        $this->resolveFromEnv();
    }

    /**
     * Resolve connection settings from environment variables.
     *
     * Priority (highest first):
     *   1. DATABASE_URL / MYSQL_DATABASE_URL — injected by Railway when a
     *      MySQL plugin/database is attached (full connection string).
     *   2. MYSQLHOST / MYSQLPORT / MYSQLUSER / MYSQLPASSWORD / MYSQLDATABASE
     *      — Railway MySQL plugin variables (private proxy + internal port).
     *   3. DB_HOST / DB_PORT / DB_USER / DB_PASSWORD / DB_NAME
     *      — generic overrides (set these manually if the DB lives in a
     *      different project and you want to use the public proxy).
     *   4. Hard-coded defaults (local XAMPP development).
     */
    private function resolveFromEnv() {
        // 1. Full connection string: mysql://user:pass@host:port/dbname
        $databaseUrl = getenv('DATABASE_URL') ?: getenv('MYSQL_DATABASE_URL') ?: '';
        if ($databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            if ($parts && !empty($parts['host'])) {
                $this->host     = $parts['host'];
                $this->db_name  = isset($parts['path']) ? ltrim($parts['path'], '/') : '';
                $this->username = $parts['user'] ?? '';
                $this->password = isset($parts['pass']) ? urldecode($parts['pass']) : '';
                $this->port     = $parts['port'] ?? '3306';
                return;
            }
        }

        // 2. Railway MySQL plugin variables, 3. generic overrides, 4. defaults
        $this->host     = getenv('MYSQLHOST')     ?: getenv('DB_HOST')     ?: 'altaria.proxy.rlwy.net';
        $this->port     = getenv('MYSQLPORT')     ?: getenv('DB_PORT')     ?: '25294';
        $this->username = getenv('MYSQLUSER')     ?: getenv('DB_USER')     ?: 'root';
        $this->password = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: 'uULVyzmjeIfdrBjviQeCXvsQrqaJlNdm';
        $this->db_name  = getenv('MYSQLDATABASE') ?: getenv('DB_NAME')     ?: 'railway';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                // Fail fast on a slow/unreachable database instead of hanging
                // the request indefinitely (Railway's proxy gives up and
                // reports "Application failed to respond").
                PDO::ATTR_TIMEOUT            => 5,
                PDO::MYSQL_ATTR_CONNECT_TIMEOUT => 5
            );

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            $message = "Database connection failed. Please check your configuration.";
            // Show the real error during development so it is easy to diagnose.
            if (($GLOBALS['environment'] ?? '') === 'development') {
                $message .= ' (' . $e->getMessage() . ')';
            }
            die($message);
        }

        return $this->conn;
    }

    public function closeConnection() {
        $this->conn = null;
    }
}
