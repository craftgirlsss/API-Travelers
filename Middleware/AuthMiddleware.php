<?php
// Middleware/AuthMiddleware.php (VERSI YANG BENAR)

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;

class AuthMiddleware {
    public function __invoke(Request $request, RequestHandler $handler): Response {
        
        require_once __DIR__ . '/../Utils/JWT.php'; 
        
        $authHeader = $request->getHeaderLine('Authorization');
        
        // Cek 1: Pastikan Header Ada
        if (!$authHeader) {
            $response = new Response(); 
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Token not provided.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // Cek 2: Ambil Token (Hapus 'Bearer ') dan Definisi $token
        // INI KRITIS: $token harus didefinisikan sebelum digunakan
        $token = str_replace('Bearer ', '', $authHeader);
        
        try {
            // Decode token
            $decoded = JWTUtility::decodeToken($token); 
            error_log("Token diterima: " . $token);
            error_log("Decoded UUID: " . $decoded->uuid);
            $jwtData = (array) $decoded;
            error_log("Args UUID: " . $jwtData['uuid'] ?? 'NULL');
            
            // Simpan data user (id, uuid, dan role) ke request attribute 'jwt_data'
            $request = $request->withAttribute('jwt_data', [
                'id' => $jwtData['user_id'],
                'uuid' => $jwtData['uuid'],
                'role' => $jwtData['role']
            ]);
            
            error_log("AuthMiddleware: Authorization header = " . $request->getHeaderLine('Authorization'));
            error_log("AuthMiddleware: Body = " . (string)$request->getBody());
            // Lanjutkan ke handler
            return $handler->handle($request);

        } catch (\Exception $e) {
            // Token invalid (signature, expired, atau format salah)
            $response = new Response();
            // $e->getMessage() akan berisi alasan error yang lebih spesifik jika Anda ingin melihatnya
            error_log("AuthMiddleware: Decode failed: " . $e->getMessage());
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or expired token.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }
}