<?php
// Database Connection Configuration
// File: config/database.php

class Database {
    private $host = 'localhost';
    private $db_name = 'petcare_db';
    private $username = 'root';
    private $password = 'zainab@0558';
    private $conn;

    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO(
                "mysql:host=" . $this->host . ";dbname=" . $this->db_name,
                $this->username,
                $this->password
            );
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            error_log("Database connection error: " . $e->getMessage());
            return null;
        }
        
        return $this->conn;
    }
}

// Global function to get database connection
function getConnection() {
    $db = new Database();
    return $db->getConnection();
}
?>