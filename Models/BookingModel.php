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
            error_log("BOOKING SQL: $sql");
            error_log("BOOKING PARAMS: " . json_encode($params));
            // DITAMBAHKAN total_price ke INSERT
            $sql = "INSERT INTO bookings (user_id, trip_id, num_of_people, total_price, status, created_at) 
                    VALUES (:user_id, :trip_id, :no_of_people, :total_price, 'pending', NOW())";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':trip_id' => $tripId,
                ':no_of_people' => $noOfPeople,
                ':total_price' => $totalPrice // NILAI BARU
            ]);

            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::createBooking => " . $e->getMessage());
            throw $e;
        }
    }
}