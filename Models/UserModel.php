<?php
// Models/UserModel.php

// Asumsi: Anda telah menginstal dan menggunakan library seperti ramsey/uuid
// use Ramsey\Uuid\Uuid; 

class UserModel {
    private $db;
    private $tableName = 'users'; 
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Helper untuk membuat UUID V4. 
     * Ganti dengan implementasi library yang sebenarnya jika menggunakan Composer.
     */
    private function generateUuid(): string {
        // Jika menggunakan Ramsey\Uuid: return Uuid::uuid4()->toString();
        
        // Stub/Contoh sederhana (HINDARI INI DI PRODUKSI, gunakan library profesional)
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
    
    // -----------------------------------------------------------------
    // FINDERS
    // -----------------------------------------------------------------

    public function findByEmail(string $email): array|false {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function findByUserId(int $userId): array|false {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = :id");
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mencari user berdasarkan UUID (ID publik). Digunakan untuk /client/profile/{uuid}
     */
    public function findByUuid(string $uuid): array|false {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE uuid = :uuid");
        $stmt->bindParam(':uuid', $uuid, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    
    // -----------------------------------------------------------------
    // CRUD
    // -----------------------------------------------------------------

    /**
     * Membuat record user baru di database, termasuk UUID.
     */
    public function create(string $email, string $hashedPassword, string $name, ?string $phone = null, string $role = 'customer'): int|false {
        
        $uuid = $this->generateUuid(); // <--- GENERATE UUID BARU

        $stmt = $this->db->prepare("
            INSERT INTO {$this->tableName} 
                (name, email, password, phone, role, uuid, status, login_attempts, is_suspended, suspended_until)
            VALUES 
                (:name, :email, :pass, :phone, :role, :uuid, 'active', 0, 0, NULL)
        ");
        
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':pass', $hashedPassword);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':uuid', $uuid); // <--- BIND UUID

        try {
            if ($stmt->execute()) {
                return $this->db->lastInsertId();
            }
            return false;
        } catch (\PDOException $e) {
            throw $e; 
        }
    }

    public function updatePassword(int $userId, string $hashedPassword): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET password = :password WHERE id = :user_id
        ");
        
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':user_id', $userId);

        return $stmt->execute();
    }

    // -----------------------------------------------------------------
    // SECURITY/LOGIN
    // -----------------------------------------------------------------

    /**
     * Mengatur ulang (reset) jumlah login attempts menjadi 0.
     */
    public function resetLoginAttempts(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET login_attempts = 0 WHERE id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    /**
     * Menambahkan 1 ke jumlah login attempts.
     */
    public function incrementLoginAttempts(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET login_attempts = login_attempts + 1 WHERE id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    /**
     * Mensuspend akun user.
     */
    public function suspendAccount(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET 
                is_suspended = 1, 
                suspended_until = NULL 
            WHERE id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }


    /**
     * Update data dasar user (nama, phone) berdasarkan user ID.
     */
    public function updateBasicData(int $userId, string $name, ?string $phone): bool {
        $stmt = $this->db->prepare("
            UPDATE users 
            SET name = :name, phone = :phone, updated_at = NOW() 
            WHERE id = :id
        ");
        
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':id', $userId);
        
        return $stmt->execute();
    }
}