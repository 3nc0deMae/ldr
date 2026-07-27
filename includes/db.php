<?php
/**
 * LDB-FRAS - Database Connection
 * PDO-based MySQL connection with error handling
 */

class Database {
    private $host = 'localhost';
    private $db_name = 'ldb_fras';
    private $username = 'root';
    private $password = '';
    private $charset = 'utf8mb4';
    private $conn = null;

    /**
     * Get database connection
     * @return PDO
     */
    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=" . $this->charset;
            $options = array(
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            );

            $this->conn = new PDO($dsn, $this->username, $this->password, $options);

        } catch (PDOException $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check your configuration.");
        }

        return $this->conn;
    }

    /**
     * Close database connection
     */
    public function closeConnection() {
        $this->conn = null;
    }
}

// Global database instance
$database = new Database();
$db = $database->getConnection();
