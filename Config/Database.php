<?php
// Config/Database.php

class Database {
    private $host = 'localhost';
    private $db_name = 'sql_api_traveler';
    private $username = 'sql_api_traveler';
    private $password = 'a7f136533cbbf8'; // Ganti dengan password Anda
    private $conn;

    /**
     * Mendapatkan koneksi database
     * @return PDO
     */
    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC); // Set default fetch mode ke associative array
            // Set charset ke utf8mb4
            $this->conn->exec("set names utf8mb4");
        } catch(PDOException $exception) {
            // Dalam aplikasi production, jangan tampilkan error detail di sini.
            echo "Connection error: " . $exception->getMessage();
            exit(); 
        }
        return $this->conn;
    }
}

// Helper untuk mendapatkan koneksi
$db = (new Database())->getConnection();