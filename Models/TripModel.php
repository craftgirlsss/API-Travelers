<?php
// Models/TripModel.php

class TripModel {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    private string $tableName = 'trips';

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
    
    // [FIX]: Mengupdate jumlah booked_participants
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

    public function getAllTrips(): array {
        $stmt = $this->db->prepare("
            SELECT 
                t.id,
                t.title,
                t.duration,
                t.location,
                t.price,
                t.discount_price,
                t.max_participants,
                t.booked_participants,
                (t.max_participants - t.booked_participants) AS remaining_seats,
                t.start_date,
                p.id AS provider_id,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path
            FROM trips t
            INNER JOIN providers p ON p.id = t.provider_id
            INNER JOIN users u ON u.id = p.user_id
            WHERE t.is_deleted = 0
            AND t.approval_status = 'approved'
        ");


        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function searchTripsByLocation(string $keyword): array {
        $sql = "
            SELECT 
                t.id,
                t.title,
                t.duration,
                t.location,
                t.price,
                t.discount_price,
                t.max_participants,
                t.booked_participants,
                (t.max_participants - t.booked_participants) AS remaining_seats,
                t.start_date,
                p.id AS provider_id,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path
            FROM trips t
            INNER JOIN providers p ON p.id = t.provider_id
            WHERE t.approval_status = 'approved'
            AND t.location LIKE :keyword
        ";

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['keyword' => '%' . $keyword . '%']);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Cari trips berdasarkan gathering_point_name (LIKE), hanya yg approved & provider active.
     * @param string $keyword
     * @return array
     */
    public function searchTripsByGatheringPoint(string $keyword): array {
        $sql = "
            SELECT
                t.id,
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
                p.id AS provider_id,
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


    public function getTripById(int $id): ?array {
        $sql = "
            SELECT
                t.id,
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
                p.id AS provider_id,
                p.company_name AS provider_company_name,
                p.company_logo_path AS provider_company_logo_path,
                u.email AS provider_email
            FROM trips t
            JOIN providers p ON p.id = t.provider_id
            JOIN users u ON u.id = p.user_id
            WHERE t.id = :id
            AND t.is_deleted = 0
            AND t.approval_status = 'approved'
            LIMIT 1
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $id]);
        $trip = $stmt->fetch(PDO::FETCH_ASSOC);
        return $trip ?: null;
    }
}
