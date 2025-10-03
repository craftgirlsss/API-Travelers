<?php
// Models/TripModel.php

class TripModel {
    private PDO $db;
    private string $tableName = 'trips';
    private string $imagesTableName = 'trip_images';

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // =======================================================
    // INTERNAL FINDERS (Dipanggil oleh BookingController, dll.)
    // =======================================================

    public function findTripById(int $tripId): array|false {
        $sql = "
            SELECT 
                id, price, discount_price, max_participants, booked_participants, status 
            FROM {$this->tableName} 
            WHERE id = :id AND approval_status = 'approved' AND is_deleted = 0
            LIMIT 1
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $tripId]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in TripModel::findTripById => " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Digunakan oleh BookingController untuk memvalidasi trip dan mengambil kuota.
     * Membersihkan kolom dan syntax yang bermasalah.
     */
    public function findTripByUuid(string $uuid): array|false {
        $stmt = $this->db->prepare("
            SELECT 
                id, 
                uuid, 
                price, 
                discount_price, 
                status, 
                approval_status,
                booked_participants, 
                max_participants
            FROM trips 
            WHERE uuid = :uuid 
            AND approval_status = 'approved' 
            AND is_deleted = 0
            LIMIT 1
        ");
        $stmt->bindParam(':uuid', $uuid);
        $stmt->execute();
        // Hanya perlu mengembalikan ID internal dan data kuota
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // =======================================================
    // CRUD DAN OPERASI INTERNAL LAINNYA
    // =======================================================
    
    /**
     * [FIX]: Mengupdate jumlah booked_participants (Internal)
     */
    public function updateBookedParticipants(int $tripId, int $newBookedParticipants): bool {
        $sql = "
            UPDATE {$this->tableName} 
            SET booked_participants = :booked, updated_at = NOW() 
            WHERE id = :id
        ";
        
        try {
            error_log("TRIP UPDATE PARAMS: " . json_encode([':booked' => $newBookedParticipants, ':id' => $tripId]));
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':booked' => $newBookedParticipants,
                ':id' => $tripId
            ]);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in TripModel::updateBookedParticipants => " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Mengurangi jumlah booked_participants (Internal)
     */
    public function decreaseBookedParticipants(int $tripId, int $numToDecrease): bool {
        $sql = "
            UPDATE {$this->tableName} 
            SET booked_participants = booked_participants - :decrease_amount, 
                updated_at = NOW() 
            WHERE id = :id AND booked_participants >= :decrease_amount
        ";
        
        try {
            error_log("TRIP DECREASE PARAMS: " . json_encode([':decrease_amount' => $numToDecrease, ':id' => $tripId]));
            $stmt = $this->db->prepare($sql);
            return $stmt->execute([
                ':decrease_amount' => $numToDecrease,
                ':id' => $tripId
            ]);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in TripModel::decreaseBookedParticipants => " . $e->getMessage());
            throw $e;
        }
    }

    // =======================================================
    // PUBLIC FINDERS (Untuk API)
    // =======================================================

    public function getAllTrips(): array {
        $sql = "
            SELECT 
                t.uuid, 
                t.title,
                t.duration,
                t.location,
                t.price,
                t.discount_price,
                t.max_participants,
                t.booked_participants,
                (t.max_participants - t.booked_participants) AS remaining_seats,
                t.start_date,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path,
                -- SUB-QUERY BARU: Mengambil URL gambar utama saja (is_main = 1)
                (
                    SELECT ti.image_url 
                    FROM {$this->imagesTableName} ti 
                    WHERE ti.trip_id = t.id AND ti.is_main = 1
                    LIMIT 1
                ) AS main_image_url 
            FROM trips t
            INNER JOIN providers p ON p.id = t.provider_id
            INNER JOIN users u ON u.id = p.user_id
            WHERE t.is_deleted = 0
            AND t.approval_status = 'approved'
            ORDER BY t.created_at DESC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchTripsByLocation(string $keyword): array {
        $sql = "
            SELECT 
                t.uuid, 
                t.title,
                t.duration,
                t.location,
                t.price,
                t.discount_price,
                t.max_participants,
                t.booked_participants,
                (t.max_participants - t.booked_participants) AS remaining_seats,
                t.start_date,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path
            FROM trips t
            INNER JOIN providers p ON p.id = t.provider_id
            WHERE t.approval_status = 'approved'
            AND t.location LIKE :keyword
            ORDER BY t.created_at DESC
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['keyword' => '%' . $keyword . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cari trips berdasarkan gathering_point_name (LIKE).
     */
    public function searchTripsByGatheringPoint(string $keyword): array {
        $sql = "
            SELECT
                t.uuid, 
                t.title,
                t.duration,
                t.location,
                t.gathering_point_name,
                t.price,
                t.discount_price,
                t.max_participants,
                t.booked_participants,
                (t.max_participants - t.booked_participants) AS remaining_seats,
                t.start_date,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path,
                u.email AS provider_email
            FROM trips t
            JOIN providers p ON p.id = t.provider_id
            JOIN users u ON u.id = p.user_id
            WHERE t.is_deleted = 0
              AND t.approval_status = 'approved'
              AND t.gathering_point_name LIKE :keyword
            ORDER BY t.created_at DESC
            LIMIT 100
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':keyword' => '%' . $keyword . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getTripImagesByTripId(int $tripId): array {
        $sql = "
            SELECT 
                image_url, 
                is_main 
            FROM {$this->imagesTableName} 
            WHERE trip_id = :trip_id 
            ORDER BY is_main DESC, created_at ASC
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([':trip_id' => $tripId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in TripModel::getTripImagesByTripId => " . $e->getMessage());
            return []; // Mengembalikan array kosong jika gagal
        }
    }


    /**
     * Mendapatkan detail trip berdasarkan UUID (Public API)
     */
    public function getTripDetailByUuid(string $uuid): ?array {
        $sql = "
            SELECT
                t.id, 
                t.uuid, 
                t.title,
                t.description,
                t.duration,
                t.location,
                t.gathering_point_name,
                t.gathering_point_url AS gathering_point_url,
                t.price,
                t.discount_price,
                t.max_participants,
                t.booked_participants,
                (t.max_participants - t.booked_participants) AS remaining_seats,
                t.start_date,
                t.end_date,
                t.departure_time,
                t.return_time,
                t.status,
                t.created_at,
                t.updated_at,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path,
                u.email AS provider_email
            FROM trips t
            JOIN providers p ON p.id = t.provider_id
            JOIN users u ON u.id = p.user_id
            WHERE t.uuid = :uuid
            AND t.is_deleted = 0
            AND t.approval_status = 'approved'
            LIMIT 1
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$trip) {
            return null; 
        }

        // --- LANGKAH BARU: AMBIL GAMBAR ---
        $tripId = (int)$trip['id']; // Ambil ID internal trip
        
        $images = $this->getTripImagesByTripId($tripId);
        
        // Bersihkan ID internal dari array response akhir
        unset($trip['id']); 
        
        // Tambahkan list gambar ke array trip
        $trip['images'] = $images; 

        return $trip; 
    }
}