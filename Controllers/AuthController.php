<?php
// Controllers/AuthController.php
require_once __DIR__ . '/../Models/PasswordResetModel.php'; 
require_once __DIR__ . '/../Utils/Mailer.php';
require_once __DIR__ . '/../Models/UserModel.php'; 
require_once __DIR__ . '/../Utils/JWT.php'; // Asumsikan JWT.php ada di folder Utils

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class AuthController {
    private $userModel;
    private $resetModel;
    
    public function __construct($db) {
        // Inisialisasi UserModel dengan koneksi database PDO
        $this->userModel = new UserModel($db);
        $this->resetModel = new PasswordResetModel($db);
    }

    /**
     * Endpoint: POST /register
     */
    public function register(Request $request, Response $response): Response {
        // // Ambil data dari body request
        $data = $request->getParsedBody();
        
        // --- Solusi: Cek apakah $data kosong, jika ya, baca input mentah (raw input)
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            
            // Coba decode JSON lagi
            $data = json_decode($content, true); 
            
            // Penting: Kembalikan pointer body ke awal untuk middleware/handler lain
            $request->getBody()->rewind(); 

            // Jika $data masih kosong setelah di-decode, set ke array kosong agar validasi berjalan
            if (is_null($data)) {
                $data = [];
            }
        }
        // --- Akhir Solusi Manual Input

        // --- 1. Validasi Input
        // ... sisa kode Anda di sini
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
        
        // Ambil data yang sudah diurai oleh Slim
        $data = $request->getParsedBody();
        
        // --- Solusi Parsing Body JSON (Ambil raw input jika $data kosong)
        // Terapkan ini untuk memastikan login berjalan di konfigurasi server apa pun.
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            $data = json_decode($content, true); 
            $request->getBody()->rewind();
            if (is_null($data)) {
                $data = [];
            }
        }
        // --- Akhir Solusi Parsing

        // --- 1. Validasi Input
        // Periksa apakah $data adalah array dan mengandung key yang dibutuhkan
        if (!is_array($data) || empty($data['email']) || empty($data['password'])) {
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
            // Jangan berikan detail apakah email atau password yang salah, cukup 'Invalid credentials'
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Invalid credentials'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // --- 4. Generate JWT Token
        // Asumsi JWTUtility sudah di-require di bagian atas AuthController.php
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

    public function forgotPassword(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        
        // --- Solusi Pembacaan Raw Input JSON
        if (is_null($data) || empty($data)) {
            // Baca raw input dari stream PHP
            $content = $request->getBody()->getContents(); 
            
            // Coba decode JSON lagi
            $data = json_decode($content, true); 
            
            // Kembalikan pointer body ke awal (penting)
            $request->getBody()->rewind(); 

            if (is_null($data)) {
                $data = [];
            }
        }
        // --- Akhir Solusi Manual Input

        $email = $data['email'] ?? null;

        // --- 1. Validasi Input
        if (empty($email)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email is required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $user = $this->userModel->findByEmail($email);
        
        // Safety check: Jangan beritahu attacker user tidak ada. Selalu kirim 200 OK.
        if ($user) {
            // 1. Generate OTP (misal 6 digit angka)
            $otpCode = rand(100000, 999999); 
            $expiration = date('Y-m-d H:i:s', time() + (5 * 60)); // 5 menit kadaluarsa

            // 2. Simpan OTP ke database
            $this->resetModel->createOTP($user['id'], $otpCode, $expiration); 

            // 3. Panggil fungsi pengiriman email
            $isSent = MailerUtility::sendOTPEmail($email, $otpCode);
            if (!$isSent) {
                // Catat kegagalan pengiriman email (tapi tetap kirim 200 OK ke user)
                error_log("Failed to send OTP email to: " . $email);
            }
        }
        
        // Response selalu sukses (untuk keamanan)
        $response->getBody()->write(json_encode([
            'status' => 'success', 
            'message' => 'If the email exists, an OTP code has been sent.'
        ]));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    public function verifyOTP(Request $request, Response $response): Response {
        $data = $request->getParsedBody();
        
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            
            $data = json_decode($content, true); 
            
            $request->getBody()->rewind(); 

            if (is_null($data)) {
                $data = [];
            }
        }

        $email = $data['email'] ?? null;
        $otpCode = $data['otp_code'] ?? null;
        
        // Konversi OTP ke string, jaga-jaga Dart mengirim integer
        $otpCode = (string)$otpCode; 
        
        // ... sisa validasi dan logika
        if (empty($email) || empty($otpCode)) {
            // ... jika Anda masih masuk ke sini, berarti $data masih null/empty.
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email and OTP code are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid OTP or email.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $otpCode = (string)$otpCode; 

        $isVerified = $this->resetModel->verifyAndDeleteOTP($user['id'], $otpCode); 

        if (!$isVerified) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid or expired OTP code.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // 2. JIKA SUKSES, GENERATE RESET TOKEN SEMENTARA
        // Reset token ini TIDAK disebar ke email. Ini hanya untuk langkah terakhir.
        $resetToken = bin2hex(random_bytes(16)); // Token acak 32 karakter
        $tokenExpiration = date('Y-m-d H:i:s', time() + (10 * 60)); // Berlaku 10 menit
        
        // 3. Simpan Reset Token di tabel password_resets (atau field baru)
        // Karena OTP sudah dihapus, kita bisa menggunakan tabel yang sama untuk menyimpan token ini.
        // Kita akan membuat kolom 'reset_token' di tabel password_resets.
        $this->resetModel->createResetToken($user['id'], $resetToken, $tokenExpiration); 
        
        // 4. Kirim response sukses
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
    
        // Ambil data yang sudah diurai oleh Slim
        $data = $request->getParsedBody();
        
        // --- BLOK KRITIS: Solusi Pembacaan Raw Input JSON ---
        if (is_null($data) || empty($data)) {
            // Baca raw input dari stream PHP
            $content = $request->getBody()->getContents(); 
            
            // Coba decode JSON lagi
            $data = json_decode($content, true); 
            
            // Kembalikan pointer body ke awal (penting)
            $request->getBody()->rewind(); 

            // Jika data masih null setelah decode, set ke array kosong
            if (is_null($data)) {
                $data = [];
            }
        }
        // --- AKHIR BLOK RAW INPUT ---

        // Dapatkan input yang diharapkan
        $email = $data['email'] ?? null;
        $resetToken = $data['reset_token'] ?? null; // Kita menggunakan reset_token
        $newPassword = $data['new_password'] ?? null;

        // --- 1. Validasi Input
        if (empty($email) || empty($resetToken) || empty($newPassword)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Email, reset token, and new password are required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // --- 2. Ambil User
        $user = $this->userModel->findByEmail($email);
        if (!$user) {
            // Selalu respons aman untuk menghindari user enumeration
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid or expired verification.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
        
        // --- 3. Verifikasi Reset Token dan Hapus
        // Kita memanggil verifyAndDeleteResetToken, BUKAN verifyAndDeleteOTP
        $isTokenValid = $this->resetModel->verifyAndDeleteResetToken($user['id'], $resetToken);

        if (!$isTokenValid) {
            // Jika token tidak valid, berikan pesan error umum
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid or expired verification.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // --- 4. Hash dan Update Password
        $hashedPassword = password_hash($newPassword, PASSWORD_ARGON2ID);
        
        // Pastikan metode ini ada di UserModel
        $updateSuccess = $this->userModel->updatePassword($user['id'], $hashedPassword); 
        
        if (!$updateSuccess) {
            // Handle kegagalan update database
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