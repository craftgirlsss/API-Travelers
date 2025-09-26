// Contoh fungsi untuk Generate Token
public static function generateToken($userId, $role) {
    $payload = [
        'iss' => "OpenTripku",
        'aud' => "Customer/Provider/Admin",
        'iat' => time(),
        'exp' => time() + (3600 * 24), // Token berlaku 24 jam
        'user_id' => $userId,
        'role' => $role
    ];
    // return JWT::encode($payload, $secret_key, 'HS256');
}