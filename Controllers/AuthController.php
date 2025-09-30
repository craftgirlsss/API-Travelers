<?php
// Controllers/AuthController.php
// Pastikan semua file ini ada di folder Models/Utils:
require_once __DIR__ . '/../Models/PasswordResetModel.php'; 
require_once __DIR__ . '/../Utils/Mailer.php';
require_once __DIR__ . '/../Models/UserModel.php'; 
require_once __DIR__ . '/../Utils/JWT.php'; // Mengasumsikan nama filenya adalah JWT.php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class AuthController {
    private $userModel;
    private $resetModel;
    
    public function __construct(PDO $db) {
        $this->userModel = new UserModel($db);
        $this->resetModel = new PasswordResetModel($db);
    }

    /**
     * Helper untuk menangani pembacaan Raw Input JSON.
     */
    private function parseJsonInput(Request $request): array {
        $data = $request->getParsedBody();
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            $data = json_decode($content, true); 
            
            // Penting: Kembalikan pointer body ke awal untuk middleware/handler lain
            $request->getBody()->rewind(); 

            if (is_null($data)) { $data = []; }
        }
        return $data;
    }

    /**
     * Endpoint: POST /register
     */
    public function register(Request $request, Response $response): Response {
        $data = $this->parseJsonInput($request);

        // --- 1. Validasi Input
        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Name, email, and password are required.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $role = $data['role'] ?? 'customer'; 
        
        // --- 2. Hash Password
        $hashedPassword = password_hash($data['password'], PASSWORD_ARGON2ID); 
        
        // --- 3. Panggil Model untuk menyimpan data
        $newUserId = null;
        try {
            $newUserId = $this->userModel->create(
                $data['email'], 
                $hashedPassword, 
                $data['name'], 
                $data['phone'] ?? null, 
                $role
            );
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'Integrity constraint violation: 1062 Duplicate entry') !== false) {
                $response->getBody()->write(json_encode([
                    'status' => 'error', 
                    'message' => 'Registration failed. Email address is already registered.'
                ]));
                return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
            }
            
            error_log("Registration DB Error: " . $e->getMessage());
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Registration failed due to server error.'
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        if ($newUserId) {
            try {
                MailerUtility::sendRegistrationNotification($data['email'], $data['name']);
            } catch (\Exception $e) {
                error_log("Failed to send registration notification to {$data['email']}: " . $e->getMessage());
            }
            
            // Perlu panggil findByUserId untuk mendapatkan UUID user yang baru dibuat
            $newUser = $this->userModel->findByUserId($newUserId);

            // --- 4. Kirim Response Sukses
            $output = [
                'status' => 'success', 
                'message' => 'User registered successfully. A notification email has been sent.', 
                'data' => [
                    'id' => $newUserId,
                    'uuid' => $newUser['uuid'] ?? null, // Tambahkan UUID di register
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'role' => $role
                ]
            ];
            $response->getBody()->write(json_encode($output));
            return $response->withStatus(201)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Registration failed unexpectedly.']));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Endpoint: POST /login
     */
    public function login(Request $request, Response $response): Response {
        $data = $this->parseJsonInput($request);

        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        if (empty($email) || empty($password)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email and password are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // findByEmail harus mengambil kolom 'uuid'
        $user = $this->userModel->findByEmail($email);

        // Cek 1: User tidak ditemukan
        if (!$user) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid email or password.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        // Cek 2: Akun disuspend
        if ((int)$user['is_suspended'] === 1) {
            $contactInfo = 'info@karyadeveloperindonesia.com';
            $message = "Your account has been suspended due to 5 failed login attempts. Please contact {$contactInfo} to reactivate your account.";
            
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => $message]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // Cek 3: Verifikasi Password
        if (password_verify($password, $user['password'])) {
            // LOGIN SUKSES
            
            // Atur ulang attempts
            $this->userModel->resetLoginAttempts((int)$user['id']); 

            $userUuid = $user['uuid'] ?? '';

            // --- KRITIS: PEMBUATAN TOKEN JWT DENGAN UUID ---
            // ASUMSI: JWT.php memiliki kelas/fungsi statis JWTUtility::generateToken($id, $role, $uuid)
            $token = JWTUtility::generateToken((int)$user['id'], $user['role'], $userUuid);  
            // ------------------------------------------
            
            $responseData = [
                // 'id' => (int)$user['id'], 
                'uuid' => $userUuid, // <-- PERBAIKAN: Gunakan variabel yang sudah diamankan
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'token' => $token, 
                'token_type' => 'Bearer'
            ];

            $response->getBody()->write(json_encode([
                'status' => 'success', 
                'message' => 'Login successful.', 
                'data' => $responseData
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');

        } else {
            // LOGIN GAGAL (Password Salah)
            
            // 1. Tambah jumlah attempts
            $this->userModel->incrementLoginAttempts((int)$user['id']);
            
            // 2. Ambil data user terbaru untuk cek attempts
            $updatedUser = $this->userModel->findByEmail($email);
            $attempts = (int)($updatedUser['login_attempts'] ?? 0);
            
            // 3. Cek batas (5 kali)
            $MAX_ATTEMPTS = 5;
            if ($attempts >= $MAX_ATTEMPTS) {
                // Suspensi akun
                $this->userModel->suspendAccount((int)$user['id']);
                
                $contactInfo = 'info@karyadeveloperindonesia.com';
                $message = "Login failed. You have reached the maximum failed attempts ({$MAX_ATTEMPTS}). Your account has been suspended. Please contact {$contactInfo} to reactivate.";
                
                $response->getBody()->write(json_encode(['status' => 'error', 'message' => $message]));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }

            // Pesan Gagal Normal
            $remaining = $MAX_ATTEMPTS - $attempts;
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => "Invalid email or password. {$remaining} attempts remaining before suspension."]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }

    public function forgotPassword(Request $request, Response $response): Response {
        $data = $this->parseJsonInput($request);

        $email = $data['email'] ?? null;

        // --- 1. Validasi Input
        if (empty($email)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email is required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $user = $this->userModel->findByEmail($email);
        
        // Safety check: Selalu kirim 200 OK meskipun user tidak ditemukan
        if ($user) {
            // 1. Generate OTP (6 digit angka)
            $otpCode = rand(100000, 999999); 
            $expiration = date('Y-m-d H:i:s', time() + (5 * 60)); // 5 menit kadaluarsa

            // 2. Simpan OTP ke database
            $this->resetModel->createOTP($user['id'], $otpCode, $expiration); 

            // 3. Panggil fungsi pengiriman email
            $isSent = MailerUtility::sendOTPEmail($email, $otpCode);
            if (!$isSent) {
                error_log("Failed to send OTP email to: " . $email);
            }
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'success', 
            'message' => 'If the email exists, an OTP code has been sent.'
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    public function verifyOTP(Request $request, Response $response): Response {
        $data = $this->parseJsonInput($request);

        $email = $data['email'] ?? null;
        $otpCode = $data['otp_code'] ?? null;
        
        // Konversi OTP ke string
        $otpCode = (string)$otpCode; 
        
        if (empty($email) || empty($otpCode)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email and OTP code are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid OTP or email.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $isVerified = $this->resetModel->verifyAndDeleteOTP($user['id'], $otpCode); 

        if (!$isVerified) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP code.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // JIKA SUKSES, GENERATE RESET TOKEN SEMENTARA
        $resetToken = bin2hex(random_bytes(16)); // Token acak 32 karakter
        $tokenExpiration = date('Y-m-d H:i:s', time() + (10 * 60)); // Berlaku 10 menit
        
        // Simpan Reset Token (menggantikan OTP)
        $this->resetModel->createResetToken($user['id'], $resetToken, $tokenExpiration); 
        
        // Kirim response sukses
        $response->getBody()->write(json_encode([
            'status' => 'success', 
            'message' => 'OTP verified successfully. Use the reset_token to set a new password.',
            'data' => [
                'reset_token' => $resetToken // KIRIM TOKEN KE KLIEN
            ]
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    public function resetPassword(Request $request, Response $response): Response {
        $data = $this->parseJsonInput($request);

        $email = $data['email'] ?? null;
        $resetToken = $data['reset_token'] ?? null;
        $newPassword = $data['new_password'] ?? null;

        // --- 1. Validasi Input
        if (empty($email) || empty($resetToken) || empty($newPassword)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email, reset token, and new password are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // --- 2. Ambil User
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid or expired verification.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        // --- 3. Verifikasi Reset Token dan Hapus
        $isTokenValid = $this->resetModel->verifyAndDeleteResetToken($user['id'], $resetToken);

        if (!$isTokenValid) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid or expired verification.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // --- 4. Hash dan Update Password
        $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
        
        $updateSuccess = $this->userModel->updatePassword($user['id'], $hashedPassword); 
        
        if (!$updateSuccess) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Failed to update password in the database.']));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        // --- 5. Kirim Response Sukses
        $response->getBody()->write(json_encode([
            'status' => 'success', 
            'message' => 'Your password has been reset successfully.'
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}
