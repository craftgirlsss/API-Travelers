<?php
// Middleware/AuthMiddleware.php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthMiddleware {
    private $secretKey = 'YOUR_SUPER_SECRET_KEY'; // Ganti dengan key rahasia Anda

    public function __invoke(Request $request, RequestHandler $handler): Response {
        $response = new Response();
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Token not provided.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Ambil token (hapus 'Bearer ')
        $token = str_replace('Bearer ', '', $authHeader);

        try {
            // Decode token
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));

            // Simpan data user (user_id dan role) ke request attribute
            // Controller dapat mengakses data ini melalui $request->getAttribute('user_data')
            $request = $request->withAttribute('user_data', [
                'id' => $decoded->user_id,
                'role' => $decoded->role
            ]);
            
            // Lanjutkan ke handler berikutnya (atau RoleMiddleware)
            $response = $handler->handle($request);
            return $response;

        } catch (\Exception $e) {
            // Token tidak valid (expired, signature salah, dll.)
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or expired token.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }
}