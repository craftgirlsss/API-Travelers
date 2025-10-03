<?php
// Models/ReviewModel.php

class ReviewModel {
    private PDO $db;
    private string $tableName = 'reviews';

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // Asumsi: Ini adalah method helper untuk membuat UUID. 
    // Anda harus mengimplementasikannya (misalnya menggunakan Ramsey\Uuid).
    private function generateUuid(): string {
        // Placeholder: Ganti ini dengan implementasi UUID generator Anda yang sebenarnya
        return Ramsey\Uuid\Uuid::uuid4()->toString(); 
    }
    
    /**
     * Memeriksa apakah user memiliki booking yang COMPLETED dan belum memberikan review untuk trip ini.
     * Catatan: Method ini wajib menggunakan trip_id (integer) karena itu adalah Foreign Key.
     */
    public function validateBookingForReview(int $userId, int $tripId, array $validStatuses = ['completed']): array|false {
        // Cek status booking dan apakah user sudah pernah mereview trip ini
        $statusList = "'" . implode("','", $validStatuses) . "'";
        
        $sql = "
            SELECT 
                b.id AS booking_id -- Mengambil ID internal booking
            FROM bookings b
            LEFT JOIN reviews r ON r.booking_id = b.id AND r.user_id = b.user_id
            WHERE b.user_id = :user_id 
              AND b.trip_id = :trip_id 
              AND b.status IN ({$statusList})
              AND r.id IS NULL -- Belum ada review untuk booking ini
            LIMIT 1
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId, ':trip_id' => $tripId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in ReviewModel::validateBookingForReview => " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Menyimpan review baru ke database. (Wajib menambahkan UUID)
     */
    public function createReview(int $userId, int $tripId, int $bookingId, float $rating, string $comment): bool {
        
        // 1. GENERATE UUID
        $uuid = $this->generateUuid();

        $sql = "INSERT INTO {$this->tableName} 
                (uuid, user_id, trip_id, booking_id, rating, comment) 
                VALUES (:uuid, :user_id, :trip_id, :booking_id, :rating, :comment)";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':uuid' => $uuid, // <--- TAMBAHAN UUID
                ':user_id' => $userId,
                ':trip_id' => $tripId,
                ':booking_id' => $bookingId,
                ':rating' => $rating,
                ':comment' => $comment
            ]);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in ReviewModel::createReview => " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Mengambil semua review untuk trip tertentu berdasarkan UUID-nya. (PUBLIC)
     */
    public function getReviewsByTripUuid(string $tripUuid): array {
        $sql = "
            SELECT
                r.uuid,
                r.rating,
                r.comment,
                r.created_at,
                u.name AS user_name,
                u.profile_picture_path AS user_photo
            FROM reviews r
            JOIN trips t ON t.id = r.trip_id -- Join ke trips masih melalui ID
            JOIN users u ON u.id = r.user_id
            WHERE t.uuid = :trip_uuid -- Filter menggunakan UUID trip
            ORDER BY r.created_at DESC
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':trip_uuid' => $tripUuid]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in ReviewModel::getReviewsByTripUuid => " . $e->getMessage());
            throw $e;
        }
    }
}