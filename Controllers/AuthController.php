<?php
// Asumsikan UserModel dan JWT Utility sudah di-include

class AuthController {
    private $userModel;

    public function __construct($db) {
        $this->userModel = new UserModel($db);
    }

    public function login($requestData) {
        // Validasi input
        if (empty($requestData['email']) || empty($requestData['password'])) {
            http_response_code(400); // Bad Request
            return ['status' => 'error', 'message' => 'Email and password are required'];
        }

        $user = $this->userModel->findByEmail($requestData['email']);

        if (!$user || !password_verify($requestData['password'], $user['password'])) {
            http_response_code(401); // Unauthorized
            return ['status' => 'error', 'message' => 'Invalid credentials'];
        }

        // Generate JWT Token
        $token = JWT::generateToken($user['id'], $user['role']);

        http_response_code(200);
        return [
            'status' => 'success',
            'message' => 'Login successful',
            'data' => [
                'user_id' => $user['id'],
                'role' => $user['role'],
                'token' => $token
            ]
        ];
    }
}
?>