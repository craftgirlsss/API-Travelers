<?php
// Models/BookingModel.php

// PENTING: Jika Anda memutuskan kembali menggunakan ramsey/uuid di PHP 8.1 CLI,
// Hapus implementasi generateUuid() di bawah dan tambahkan baris ini:
// use Ramsey\Uuid\Uuid;

class BookingModel {
    private PDO $db;
    private string $tableName = 'bookings';

    public function __construct(PDO $db) {
        $this->db = $db;
    }
    
    // =======================================================
    // HELPER: IMPLEMENTASI UUID V4 (TANPA RAMSEY/UUID)
    // =======================================================
    /**
     * Menggunakan fungsi bawaan PHP untuk membuat UUID V4 secara pseudo-random.
     * Digunakan sebagai pengganti ramsey/uuid.
     */
    private function generateUuid(): string {
        $data = random_bytes(16);
        
        // Atur versi ke 0100 (UUID versi 4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        // Atur clock_seq_hi_and_reserved ke 10 (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 

        // Format sebagai string UUID standar: 8-4-4-4-12 karakter
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }


    // =======================================================
    // INTERNAL (Menggunakan ID Internal)
    // =======================================================

    /**
     * Membuat entri booking baru.
     * @return int ID booking yang baru dibuat.
     */
    public function createBooking(int $userId, int $tripId, int $noOfPeople, float $totalPrice): int {
        
        // 1. GENERATE UUID
        $uuid = $this->generateUuid();

        $sql = "INSERT INTO bookings (uuid, user_id, trip_id, num_of_people, total_price, status, created_at) 
                VALUES (:uuid, :user_id, :trip_id, :no_of_people, :total_price, 'pending', NOW())";

        $params = [
            ':uuid' => $uuid, 
            ':user_id' => $userId,
            ':trip_id' => $tripId,
            ':no_of_people' => $noOfPeople,
            ':total_price' => $totalPrice
        ];

        try {
            error_log("BOOKING SQL: $sql");
            error_log("BOOKING PARAMS: " . json_encode($params));
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params); 

            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::createBooking => " . $e->getMessage());
            error_log("FAILED PARAMS: " . json_encode($params));
            throw $e;
        }
    }

    /**
     * Mengambil UUID booking berdasarkan ID internal. (FIX untuk Controller)
     * @param int $bookingId ID internal booking.
     * @return string|null UUID booking atau null jika tidak ditemukan.
     */
    public function getBookingUuidById(int $bookingId): string|null {
        $stmt = $this->db->prepare("
            SELECT uuid FROM bookings WHERE id = :id
        ");
        
        try {
            $stmt->bindParam(':id', $bookingId, PDO::PARAM_INT);
            $stmt->execute();
            
            $uuid = $stmt->fetchColumn(); 
            return $uuid ?: null;
            
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::getBookingUuidById => " . $e->getMessage());
            return null; 
        }
    }
    
    /**
     * Mengupdate status booking berdasarkan ID internal. (INTERNAL)
     */
    public function updateBookingStatus(int $bookingId, string $status): bool {
        $sql = "UPDATE bookings SET status = :status, updated_at = NOW() WHERE id = :id";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':status' => $status,
                ':id' => $bookingId
            ]);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::updateBookingStatus => " . $e->getMessage());
            throw $e;
        }
    }
    
    // =======================================================
    // PUBLIC ACCESS (Menggunakan UUID)
    // =======================================================

    /**
     * Mengambil detail booking yang diperlukan untuk pembatalan. (Pencarian menggunakan UUID)
     */
    public function getBookingDetailsForCancellation(string $bookingUuid, int $userId): array|false {
        $sql = "SELECT id, user_id, trip_id, num_of_people, status FROM bookings WHERE uuid = :uuid AND user_id = :user_id";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':uuid' => $bookingUuid,
                ':user_id' => $userId
            ]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::getBookingDetailsForCancellation => " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Mengambil detail lengkap booking dan trip yang diperlukan untuk menampilkan tagihan pembayaran.
     * (Pencarian menggunakan UUID)
     */
    public function getPaymentDetails(string $bookingUuid, int $userId): array|false {
        $sql = "
            SELECT
                b.uuid AS booking_uuid,
                b.user_id,
                b.total_price,
                b.num_of_people,
                b.status AS booking_status,
                b.created_at AS booking_date,
                
                t.title AS trip_title,
                t.price AS original_price,
                t.discount_price,
                
                pr.company_name AS provider_name,
                pr.bank_name,
                pr.bank_account_number,
                pr.bank_account_name
            FROM bookings b
            JOIN trips t ON t.id = b.trip_id
            JOIN providers pr ON pr.id = t.provider_id
            WHERE b.uuid = :booking_uuid AND b.user_id = :user_id
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':booking_uuid' => $bookingUuid,
                ':user_id' => $userId
            ]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::getPaymentDetails => " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mengambil daftar riwayat pemesanan untuk user tertentu.
     * (Output menggunakan UUID)
     */
    public function getUserBookings(int $userId): array {
        $sql = "
            SELECT
                b.uuid AS booking_uuid,
                b.total_price,
                b.num_of_people,
                b.status AS booking_status,
                b.created_at AS booking_date,
                
                t.uuid AS trip_uuid,
                t.title AS trip_title,
                t.location AS trip_location,
                t.start_date AS trip_start_date,
                t.departure_time AS trip_departure_time,
                
                pr.company_name AS provider_name
            FROM bookings b
            JOIN trips t ON t.id = b.trip_id
            JOIN providers pr ON pr.id = t.provider_id
            WHERE b.user_id = :user_id
            ORDER BY b.created_at DESC
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':user_id' => $userId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::getUserBookings => " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Mengambil detail lengkap satu booking (termasuk detail trip dan provider)
     * untuk menampilkan detail history pemesanan.
     */
    public function getBookingDetailByUuid(string $bookingUuid, int $userId): array|false {
        $sql = "
            SELECT
                b.uuid AS booking_uuid,
                b.total_price,
                b.num_of_people,
                b.status AS booking_status,
                b.created_at AS booking_date,
                
                t.uuid AS trip_uuid,
                t.title AS trip_title,
                t.description AS trip_description,
                t.duration,
                t.location,
                t.gathering_point_name,
                t.gathering_point_url,
                t.price AS trip_price,
                t.discount_price,
                t.start_date,
                t.end_date,
                t.departure_time,
                t.return_time,
                
                pr.company_name AS provider_name,
                pr.phone_number AS provider_phone,
                pr.company_logo_path AS provider_logo,
                pr.bank_name,
                pr.bank_account_number,
                pr.bank_account_name
                
            FROM bookings b
            JOIN trips t ON t.id = b.trip_id
            JOIN providers pr ON pr.id = t.provider_id
            WHERE b.uuid = :booking_uuid AND b.user_id = :user_id
        ";

        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':booking_uuid' => $bookingUuid,
                ':user_id' => $userId
            ]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in BookingModel::getBookingDetailByUuid => " . $e->getMessage());
            throw $e;
        }
    }
}