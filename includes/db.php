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
        // Kunin ang mga values mula sa Railway environment variables
        $this->host = getenv('DB_HOST') ?: 'altaria.proxy.rlwy.net';
        $this->db_name = getenv('DB_NAME') ?: 'ldb_fras';
        $this->username = getenv('DB_USER') ?: 'root';
        $this->password = getenv('DB_PASSWORD') ?: 'uULVyzmjeIfdrBjviQeCXvsQrqaJlNdm';
        $this->port = getenv('DB_PORT') ?: '25294';
    }

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";port=" . $this->port . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false
            );

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }

        return $this->conn;
    }

    public function closeConnection() {
        $this->conn = null;
    }
}