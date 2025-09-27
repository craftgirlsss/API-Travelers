<?php
// Models/UserModel.php

class UserModel {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function findByEmail($email) {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = :email");
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function createUser($name, $email, $hashedPassword, $phone, $role) {
        $stmt = $this->db->prepare("INSERT INTO users (name, email, password, phone, role) VALUES (:name, :email, :pass, :phone, :role)");
        
        // Menggunakan Prepared Statements untuk mencegah SQL Injection
        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':pass', $hashedPassword);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':role', $role);

        if ($stmt->execute()) {
            return $this->db->lastInsertId();
        }
        return false;
    }

    public function updatePassword(int $userId, string $hashedPassword): bool {
        $stmt = $this->db->prepare("
            UPDATE users SET password = :password WHERE id = :user_id
        ");
        
        $stmt->bindParam(':password', $hashedPassword);
        $stmt->bindParam(':user_id', $userId);

        return $stmt->execute();
    }

    // Metode CRUD lainnya (get, update, delete)...
}
?>