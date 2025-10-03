<?php
// Models/ComplaintModel.php

class ComplaintModel {
    private PDO $db;
    private string $tableName = 'complaints';

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Menyimpan data pengaduan baru dari client.
     */
    public function createComplaint(int $userId, int $tripId, string $subject, string $description): bool {
        $sql = "INSERT INTO {$this->tableName} 
                (user_id, trip_id, subject, description) 
                VALUES (:user_id, :trip_id, :subject, :description)";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':user_id' => $userId,
                ':trip_id' => $tripId,
                ':subject' => $subject,
                ':description' => $description
            ]);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in ComplaintModel::createComplaint => " . $e->getMessage());
            throw $e;
        }
    }
    
    // Anda mungkin ingin menambahkan method untuk mengambil detail pengaduan (Admin/Provider side) di sini.
}