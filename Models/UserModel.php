<?php
// Models/UserModel.php

class UserModel {
    private PDO $db;
    private string $tableName = 'users'; 
    
    public function __construct(PDO $db) {
        $this->db = $db;
    }

    // =================================================================
    // HELPER: IMPLEMENTASI UUID V4 (TANPA RAMSEY/UUID)
    // =================================================================
    /**
     * Menggunakan fungsi bawaan PHP untuk membuat UUID V4 secara pseudo-random.
     * Digunakan sebagai pengganti ramsey/uuid.
     */
    private function generateUuid(): string {
        try {
            $data = random_bytes(16);
        } catch (\Exception $e) {
            // Fallback jika random_bytes gagal (jarang terjadi)
            $data = openssl_random_pseudo_bytes(16);
        }
        
        // Atur versi ke 0100 (UUID versi 4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        // Atur clock_seq_hi_and_reserved ke 10 (RFC 4122)
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 

        // Format sebagai string UUID standar: 8-4-4-4-12 karakter
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    // =================================================================
    // FINDERS (Login & Security)
    // =================================================================

    public function findByEmail(string $email): array|false {
        // MENAMBAH login_attempts dan is_suspended untuk logika login
        $stmt = $this->db->prepare("SELECT id, uuid, name, email, password, role, status, is_suspended, login_attempts FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mengambil data user berdasarkan ID internal (dibutuhkan AuthController setelah create).
     */
    public function findByUserId(int $userId): array|false {
        $stmt = $this->db->prepare("SELECT id, uuid, name, email, role FROM users WHERE id = :id");
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getUserProfileById(int $userId): array|false {
        $sql = "
            SELECT 
                uuid, 
                name, 
                email, 
                phone, 
                profile_picture_path, 
                created_at,
                updated_at
            FROM users 
            WHERE id = :id
        ";
        $stmt = $this->db->prepare($sql);
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    
    // =================================================================
    // CRUD
    // =================================================================

    /**
     * Membuat record user baru di database, termasuk UUID.
     * Nama diganti menjadi registerNewUser untuk menghindari bentrok dengan framework.
     */
    public function registerNewUser(string $email, string $hashedPassword, string $name, ?string $phone = null, string $role = 'customer'): int|false {
        
        $uuid = $this->generateUuid();

        $stmt = $this->db->prepare("
            INSERT INTO {$this->tableName} 
                (name, email, password, phone, role, uuid, status, login_attempts, is_suspended)
            VALUES 
                (:name, :email, :pass, :phone, :role, :uuid, 'active', 0, 0)
        ");
        
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':pass', $hashedPassword);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':uuid', $uuid);

        try {
            if ($stmt->execute()) {
                return (int)$this->db->lastInsertId();
            }
            return false;
        } catch (\PDOException $e) {
            // Lempar Exception untuk ditangkap di Controller (misalnya Duplicate Entry)
            throw $e; 
        }
    }

    public function updatePassword(int $userId, string $hashedPassword): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET password = :password, updated_at = NOW() WHERE id = :user_id
        ");
        
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':user_id', $userId);

        return $stmt->execute();
    }
    
    // ... (Method CRUD lainnya seperti updateBasicData, deactivateAccount, reactivateAccount)
    
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

    public function deactivateAccount(int $userId): bool {
        $sql = "UPDATE {$this->tableName} SET status = 'deactivated', updated_at = NOW() WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $userId]);
    }

    public function reactivateAccount(int $userId): bool {
        $sql = "UPDATE {$this->tableName} SET status = 'active', updated_at = NOW() WHERE id = :id";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':id' => $userId]);
    }

    // =================================================================
    // SECURITY/LOGIN
    // =================================================================

    public function resetLoginAttempts(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET login_attempts = 0, is_suspended = 0, updated_at = NOW() WHERE id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function incrementLoginAttempts(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET login_attempts = login_attempts + 1, updated_at = NOW() WHERE id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function suspendAccount(int $userId): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET 
                is_suspended = 1, 
                suspended_until = NULL, 
                updated_at = NOW() 
            WHERE id = :user_id
        ");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }
}