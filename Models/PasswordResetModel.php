<?php
// Models/PasswordResetModel.php

class PasswordResetModel {
    private $db;
    private $tableName = 'password_resets';

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Membuat record OTP baru di database.
     */
    public function createOTP(int $userId, string $otpCode, string $expirationTime): bool {
        // Hapus semua OTP lama yang mungkin masih ada untuk user ini (opsional)
        $this->deleteOTPByUserId($userId);

        $stmt = $this->db->prepare("
            INSERT INTO {$this->tableName} (user_id, otp_code, expires_at, created_at)
            VALUES (:user_id, :otp_code, :expires_at, NOW())
        ");

        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':otp_code', $otpCode);
        $stmt->bindParam(':expires_at', $expirationTime);
        // Kolom created_at diisi langsung dengan NOW() di query, tidak perlu bindParam.

        return $stmt->execute();
    }

    /**
     * Mencari dan memverifikasi kode OTP yang masih valid.
     */
    public function findValidOTP(int $userId, string $otpCode): array|false {
        $stmt = $this->db->prepare("
            SELECT * FROM {$this->tableName} 
            WHERE user_id = :user_id 
              AND otp_code = :otp_code 
              AND expires_at > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");

        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':otp_code', $otpCode);
        $stmt->execute();

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Memverifikasi OTP dan langsung menghapus record jika sukses.
     */
    public function verifyAndDeleteOTP(int $userId, string $otpCode): bool {
        $otp = $this->findValidOTP($userId, $otpCode);
        
        if ($otp) {
            // OTP valid, hapus untuk mencegah penggunaan ulang
            $this->deleteOTPByUserId($userId);
            return true;
        }
        return false;
    }

    /**
     * Menghapus semua OTP untuk user tertentu.
     */
    public function deleteOTPByUserId(int $userId): bool {
        $stmt = $this->db->prepare("DELETE FROM {$this->tableName} WHERE user_id = :user_id");
        $stmt->bindParam(':user_id', $userId);
        return $stmt->execute();
    }

    public function createResetToken(int $userId, string $resetToken, string $expirationTime): bool {
        // Hapus entri lama
        $this->deleteOTPByUserId($userId); 

        $stmt = $this->db->prepare("
            INSERT INTO {$this->tableName} (user_id, reset_token, expires_at, created_at)
            VALUES (:user_id, :reset_token, :expires_at, NOW())
        ");

        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':reset_token', $resetToken);
        $stmt->bindParam(':expires_at', $expirationTime);

        return $stmt->execute();
    }

    /**
     * Memverifikasi Reset Token dan menghapusnya setelah digunakan.
     */
    public function verifyAndDeleteResetToken(int $userId, string $resetToken): bool {
        $stmt = $this->db->prepare("
            SELECT id FROM {$this->tableName} 
            WHERE user_id = :user_id 
              AND reset_token = :reset_token 
              AND expires_at > NOW()
            LIMIT 1
        ");

        $stmt->bindParam(':user_id', $userId);
        $stmt->bindParam(':reset_token', $resetToken);
        $stmt->execute();
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($record) {
            // Token valid, hapus record
            $this->deleteOTPByUserId($userId);
            return true;
        }
        return false;
    }
}