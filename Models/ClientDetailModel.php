<?php
// Models/ClientDetailModel.php

class ClientDetailModel {
    private PDO $db;
    private string $tableName = 'client_details'; 

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // -----------------------------------------------------------------
    // FINDERS
    // -----------------------------------------------------------------

    /**
     * MENGAMBIL DETAIL CLIENT BERDASARKAN ID INTERNAL USER. 
     * Ini yang dibutuhkan oleh GET /client/profile.
     * @param int $userId ID internal user yang diminta (dari JWT).
     * @return array|false Data gabungan dari client_details dan users, atau false.
     */
    public function getClientDetailByUserId(int $userId): array|false {
        $sql = "
            SELECT 
                u.uuid, u.name, u.email, u.phone, u.role, 
                -- HAPUS baris u.profile_picture_path, yang menyebabkan error 1054
                cd.gender, cd.birth_date, cd.address, cd.province, cd.city, cd.postal_code,
                cd.profile_picture_url AS detail_picture_url, -- Mengambil dari tabel client_details
                u.created_at
            FROM users u
            LEFT JOIN {$this->tableName} cd ON u.id = cd.user_id
            WHERE u.id = :user_id
        ";
        // Menggunakan LEFT JOIN agar tetap mengembalikan data dasar user meskipun detail_client belum ada.
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            error_log("SQL ERROR in ClientDetailModel::getClientDetailByUserId => " . $e->getMessage());
            throw $e;
        }
    }


    /**
     * Mengambil detail klien dan data dasar user (JOIN) berdasarkan UUID.
     * @param string $userUuid UUID user yang diminta.
     * @return array|false Data gabungan dari client_details dan users, atau false.
     */
    public function getClientDetailByUuid(string $userUuid): array|false {
        $stmt = $this->db->prepare("
            SELECT 
                cd.*,
                u.name,
                u.email,
                u.phone,
                u.role,
                u.uuid
            FROM {$this->tableName} cd
            JOIN users u ON cd.user_id = u.id
            WHERE u.uuid = :user_uuid
        ");

        $stmt->bindParam(':user_uuid', $userUuid, PDO::PARAM_STR);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // -----------------------------------------------------------------
    // CRUD
    // -----------------------------------------------------------------
    
    /**
     * Update atau buat baru detail klien.
     * Menggunakan sintaks INSERT...ON DUPLICATE KEY UPDATE untuk efisiensi.
     * CATATAN: Pastikan kolom `user_id` di tabel `client_details` memiliki UNIQUE INDEX.
     * * @param int $userId ID internal pengguna.
     * @param array $data Data detail klien.
     * @return bool
     */
    public function updateOrCreate(int $userId, array $data): bool {
        
        $sql = "
            INSERT INTO {$this->tableName} 
            (user_id, gender, birth_date, address, province, city, postal_code, profile_picture_url, created_at, updated_at)
            VALUES 
            (:user_id, :gender, :birth_date, :address, :province, :city, :postal_code, :profile_picture_url, NOW(), NOW())
            ON DUPLICATE KEY UPDATE 
                gender = VALUES(gender),
                birth_date = VALUES(birth_date),
                address = VALUES(address),
                province = VALUES(province),
                city = VALUES(city),
                postal_code = VALUES(postal_code),
                profile_picture_url = VALUES(profile_picture_url),
                updated_at = NOW()
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            
            // Bind parameter
            $stmt->bindParam(':user_id', $userId);
            $stmt->bindParam(':gender', $data['gender']);
            $stmt->bindParam(':birth_date', $data['birth_date']);
            $stmt->bindParam(':address', $data['address']);
            $stmt->bindParam(':province', $data['province']);
            $stmt->bindParam(':city', $data['city']);
            $stmt->bindParam(':postal_code', $data['postal_code']);
            $stmt->bindParam(':profile_picture_url', $data['profile_picture_url']);
    
            return $stmt->execute();
        } catch (\PDOException $e) {
            error_log("SQL ERROR in ClientDetailModel::updateOrCreate => " . $e->getMessage());
            throw $e;
        }
    }
}