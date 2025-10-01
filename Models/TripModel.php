<?php
// Models/TripModel.php

class TripModel {
    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    public function getAllTrips(): array {
        $stmt = $this->db->prepare("
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
                t.approval_status,
                t.created_at,
                t.updated_at,
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

    // Mengupdate jumlah booked_participants (menggunakan transaksi dan LOCK)
    private string $tableName = 'trips';
    public function updateBookedParticipants(int $tripId, int $newBookedParticipants): bool {
        $stmt = $this->db->prepare("
            UPDATE {$this->tableName} 
            SET booked_participants = :booked, updated_at = NOW() 
            WHERE id = :id
        ");
        $stmt->bindParam(':booked', $newBookedParticipants);
        $stmt->bindParam(':id', $tripId);
        return $stmt->execute();
    }
}
