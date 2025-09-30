<?php
// Models/ClientDetailModel.php

class ClientDetailModel {
    private PDO $db;
    private string $tableName = 'client_details'; 

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Mengambil detail klien dan data dasar user (JOIN) berdasarkan UUID.
     * * @param string $userUuid UUID user yang diminta.
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
                u.uuid -- Mengambil UUID lagi untuk konfirmasi
            FROM {$this->tableName} cd
            JOIN users u ON cd.user_id = u.id
            WHERE u.uuid = :user_uuid  -- Mencari berdasarkan UUID dari tabel users
        ");

        $stmt->bindParam(':user_uuid', $userUuid, PDO::PARAM_STR);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Anda bisa tambahkan metode untuk CREATE/UPDATE detail klien di sini.
    /**
     * Update atau buat baru detail klien.
     * @param int $userId ID internal pengguna.
     * @param array $data Data detail klien.
     * @return bool
     */
    public function updateOrCreate(int $userId, array $data): bool {
        // Cek apakah detail sudah ada
        $existing = $this->db->prepare("SELECT id FROM {$this->tableName} WHERE user_id = :user_id");
        $existing->bindParam(':user_id', $userId);
        $existing->execute();

        if ($existing->fetch()) {
            // Lakukan UPDATE
            $stmt = $this->db->prepare("
                UPDATE {$this->tableName} 
                SET gender = :gender, 
                    birth_date = :birth_date, 
                    address = :address,
                    province = :province,
                    city = :city,
                    postal_code = :postal_code,
                    profile_picture_url = :profile_picture_url,
                    updated_at = NOW() 
                WHERE user_id = :user_id
            ");
        } else {
            // Lakukan INSERT
            $stmt = $this->db->prepare("
                INSERT INTO {$this->tableName} 
                (user_id, gender, birth_date, address, province, city, postal_code, profile_picture_url, created_at, updated_at)
                VALUES 
                (:user_id, :gender, :birth_date, :address, :province, :city, :postal_code, :profile_picture_url, NOW(), NOW())
            ");
        }
        
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
    }
}