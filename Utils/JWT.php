<?php
// Utils/JWT.php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTUtility {
    // Kunci Rahasia untuk menandatangani token. HARUS dirahasiakan!
    // Ganti dengan string acak yang panjang dan kompleks di lingkungan produksi.
    private static string $secret_key = 'K3yR4h4s!aOp3nT!pKu_2025_#P0K0KNYAAMAN';
    
    // Algoritma yang digunakan (HS256 adalah standar)
    private static string $algorithm = 'HS256';

    /**
     * Menghasilkan token JWT baru.
     *
     * @param int $userId ID pengguna yang login.
     * @param string $role Peran pengguna ('admin', 'provider', 'customer').
     * @return string Token JWT yang sudah di-encode.
     */
    public static function generateToken(int $userId, string $role): string {
        $issuedAt = time();
        $expirationTime = $issuedAt + (3600 * 24); // Token berlaku 24 jam (3600 detik * 24 jam)
        
        $payload = [
            'iss' => "OpenTripku-API", // Issuer (penerbit token)
            'aud' => "Customer/Provider/Admin", // Audience (penerima token)
            'iat' => $issuedAt, // Issued At (waktu pembuatan)
            'exp' => $expirationTime, // Expiration Time (waktu kadaluarsa)
            'user_id' => $userId, // Data spesifik user
            'role' => $role // Data peran user
        ];

        try {
            // Encode payload menjadi token JWT
            return JWT::encode($payload, self::$secret_key, self::$algorithm);
        } catch (\Exception $e) {
            // Dalam kasus nyata, log error ini, jangan tampilkan ke user.
            throw new \RuntimeException("Failed to generate JWT token: " . $e->getMessage());
        }
    }

    /**
     * Memverifikasi dan mendekode token JWT.
     *
     * @param string $token Token JWT dari header Authorization.
     * @return object Data payload yang sudah didekode.
     * @throws \Exception Jika token tidak valid atau kadaluarsa.
     */
    public static function decodeToken(string $token): object {
        try {
            // Dekode token dan verifikasi signature dan klaim (iat, exp)
            $decoded = JWT::decode($token, new Key(self::$secret_key, self::$algorithm));
            return $decoded;
        } catch (\Firebase\JWT\ExpiredException $e) {
            // Token kadaluarsa
            throw new \Exception("Token has expired.", 401); 
        } catch (\Exception $e) {
            // Signature tidak cocok, format token salah, atau error lainnya
            throw new \Exception("Invalid token: " . $e->getMessage(), 401);
        }
    }
}