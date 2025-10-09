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
     */
    private function generateUuid(): string {
        try {
            $data = random_bytes(16);
        } catch (\Exception $e) {
            $data = openssl_random_pseudo_bytes(16);
        }
        
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); 
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); 

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
        $stmt = $this->db->prepare("SELECT id, uuid, name, email, role, phone, status FROM users WHERE id = :id");
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
                profile_picture_path, /* Kolom ini sudah benar */
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
    
    /**
     * REVISI: Mengupdate data dasar user, termasuk menambahkan profile_picture_path.
     */
    public function updateBasicData(int $userId, string $name, ?string $phone, ?string $profilePicturePath = null): bool {
        
        $fields = ['name = :name', 'updated_at = NOW()'];
        $params = [
            ':name' => $name,
            ':id' => $userId
        ];

        // Tambahkan phone jika disediakan
        if ($phone !== null) {
            $fields[] = 'phone = :phone';
            $params[':phone'] = $phone;
        }

        // Tambahkan profile_picture_path jika file baru diupload (tidak null)
        if ($profilePicturePath !== null) {
            $fields[] = 'profile_picture_path = :path';
            $params[':path'] = $profilePicturePath;
        }

        $sql = "
            UPDATE {$this->tableName} 
            SET " . implode(', ', $fields) . " 
            WHERE id = :id
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            error_log("SQL Error in updateBasicData: " . $e->getMessage());
            throw $e;
        }
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