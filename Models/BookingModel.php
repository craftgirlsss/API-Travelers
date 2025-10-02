<?php
// Models/BookingModel.php

class BookingModel {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Membuat entri booking baru.
     * @param float $totalPrice Total harga yang sudah memperhitungkan diskon.
     * @return int ID booking yang baru dibuat.
     */
    public function createBooking(int $userId, int $tripId, int $noOfPeople, float $totalPrice): int {
        
        $sql = "INSERT INTO bookings (user_id, trip_id, num_of_people, total_price, status, created_at) 
                VALUES (:user_id, :trip_id, :no_of_people, :total_price, 'pending', NOW())";

        $params = [
            ':user_id' => $userId,
            ':trip_id' => $tripId,
            ':no_of_people' => $noOfPeople,
            ':total_price' => $totalPrice
        ];

        try {
            // Log Query dan Parameter
            error_log("BOOKING SQL: $sql");
            error_log("BOOKING PARAMS: " . json_encode($params));
            
            // [PERBAIKAN]: Hapus definisi $sql yang duplikat dan gunakan $params di execute
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params); // Menggunakan array params yang sudah dibuat

            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::createBooking => " . $e->getMessage());
            error_log("FAILED PARAMS: " . json_encode($params));
            throw $e;
        }
    }

    /**
     * Mengupdate status booking berdasarkan ID.
     */
    public function updateBookingStatus(int $bookingId, string $status): bool {
        $sql = "UPDATE bookings SET status = :status, updated_at = NOW() WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':status' => $status,
            ':id' => $bookingId
        ]);
    }

    /**
     * Mengambil detail booking yang diperlukan untuk pembatalan (user_id, trip_id, num_of_people, status).
     */
    public function getBookingDetailsForCancellation(int $bookingId): array|false {
        $sql = "SELECT user_id, trip_id, num_of_people, status FROM bookings WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $bookingId]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}