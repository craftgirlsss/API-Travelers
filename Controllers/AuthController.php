<?php
// Controllers/AuthController.php

// PENTING: Anda harus memastikan file-file ini dimuat (require) di file yang memanggil AuthController
// atau pastikan file ini menggunakan autoloading/namespace yang benar.
require_once __DIR__ . '/../Models/UserModel.php'; 
require_once __DIR__ . '/../Utils/JWT.php'; // Asumsikan JWT.php ada di folder Utils

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class AuthController {
    private $userModel;
    
    public function __construct($db) {
        // Inisialisasi UserModel dengan koneksi database PDO
        $this->userModel = new UserModel($db);
    }

    /**
     * Endpoint: POST /register
     */
    public function register(Request $request, Response $response): Response {
        // Ambil data dari body request
        $data = $request->getParsedBody();
        
        // --- 1. Validasi Input
        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Name, email, and password are required.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // Set default role
        $role = $data['role'] ?? 'customer'; 
        
        // --- 2. Hash Password
        // Gunakan PASSWORD_ARGON2ID (lebih modern) atau PASSWORD_BCRYPT
        $hashedPassword = password_hash($data['password'], PASSWORD_ARGON2ID); 
        
        // --- 3. Panggil Model untuk menyimpan data
        try {
            $newUserId = $this->userModel->createUser(
                $data['name'], 
                $data['email'], 
                $hashedPassword, 
                $data['phone'] ?? null, // Default null jika phone tidak ada
                $role
            );
        } catch (\PDOException $e) {
            // Error jika email duplikat atau error DB lainnya
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Registration failed. Email might already exist or invalid data.'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json'); // 409 Conflict
        }

        // --- 4. Kirim Response Sukses
        $output = [
            'status' => 'success', 
            'message' => 'User registered successfully', 
            'data' => [
                'id' => $newUserId,
                'name' => $data['name'],
                'email' => $data['email'],
                'role' => $role
            ]
        ];
        $response->getBody()->write(json_encode($output));
        return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Endpoint: POST /login
     */
    public function login(Request $request, Response $response): Response {
        // Ambil data dari body request
        require_once __DIR__ . '/../Utils/JWT.php';
        $data = $request->getParsedBody();

        // --- 1. Validasi Input
        if (empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Email and password are required'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json'); 
        }

        // --- 2. Ambil user
        $user = $this->userModel->findByEmail($data['email']);
        

        // --- 3. Verifikasi Password
        if (!$user || !password_verify($data['password'], $user['password'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Invalid credentials'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json'); // 401 Unauthorized
        }

        // --- 4. Generate JWT Token
        // Asumsi class JWT sudah diimplementasikan (seperti di Utils/JWT.php)
        $token = JWTUtility::generateToken($user['id'], $user['role']);

        // --- 5. Kirim Response Sukses
        $output = [
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'user_id' => $user['id'],
                'role' => $user['role'],
                'token' => $token
            ]
        ];
        $response->getBody()->write(json_encode($output));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}