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
        $token = str_replace('Bearer ', '', $authHeader);
        
        try {
            // Decode token menggunakan token yang sudah didefinisikan
            $decoded = JWTUtility::decodeToken($token); 
            $jwtData = (array) $decoded;
            
            // Simpan data user (id, uuid, dan role) ke request attribute 'jwt_data'
            $request = $request->withAttribute('jwt_data', [
                'id' => $jwtData['user_id'],
                'uuid' => $jwtData['uuid'],
                'role' => $jwtData['role']
            ]);
            
            // Lanjutkan ke handler
            return $handler->handle($request);

        } catch (\Exception $e) {
            // Token tidak valid (expired, signature salah, dll.)
            $response = new Response();
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or expired token.']));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }
    }
}