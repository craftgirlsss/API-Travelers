<?php
// Config/Database.php sudah mendefinisikan $db sebagai PDO instance

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

    // Metode CRUD lainnya (get, update, delete)...
}
?><?php
// Config/Database.php sudah mendefinisikan $db sebagai PDO instance

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

    // Metode CRUD lainnya (get, update, delete)...
}
?>