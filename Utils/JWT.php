<?php
// Utils/JWT.php

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JWTUtility {
    // Kunci Rahasia untuk menandatangani token. HARUS dirahasiakan!
    private static string $secret_key = '2ec5b8458f468b58b6bf849bdcad6200';
    
    // Algoritma yang digunakan (HS256 adalah standar)
    private static string $algorithm = 'HS256';

    /**
     * Menghasilkan token JWT baru.
     *
     * @param int $userId ID pengguna yang login (internal).
     * @param string $role Peran pengguna ('admin', 'provider', 'customer').
     * @param string $uuid UUID pengguna (publik). <--- ARGUMEN KETIGA DITAMBAHKAN
     * @return string Token JWT yang sudah di-encode.
     */
    public static function generateToken(int $userId, string $role, string $uuid): string { // <--- EDIT DI SINI
        $issuedAt = time();
        $expirationTime = $issuedAt + (3600 * 24); // Token berlaku 24 jam
        
        $payload = [
            'iss' => "OpenTripku-API", // Issuer (penerbit token)
            'aud' => "Customer/Provider/Admin", // Audience (penerima token)
            'iat' => $issuedAt, // Issued At (waktu pembuatan)
            'exp' => $expirationTime, // Expiration Time (waktu kadaluarsa)
            'user_id' => $userId, // Data spesifik user (internal ID)
            'uuid' => $uuid, // <--- UUID DITAMBAHKAN KE PAYLOAD
            'role' => $role // Data peran user
        ];

        error_log("SECRET KEY LENGTH: " . strlen(self::$secret_key));
        error_log("SECRET HEX: " . bin2hex(self::$secret_key));

        try {
            // Encode payload menjadi token JWT
            return JWT::encode($payload, self::$secret_key, self::$algorithm);
        } catch (\Exception $e) {
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
        error_log("SECRET KEY LENGTH: " . strlen(self::$secret_key));
        error_log("SECRET HEX: " . bin2hex(self::$secret_key));
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