<?php
// Middleware/RoleMiddleware.php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;

class RoleMiddleware {
    private array $allowedRoles;

    public function __construct(array $allowedRoles) {
        $this->allowedRoles = $allowedRoles;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response {
        
        // --- KRITIS: Ambil data JWT dari atribut yang benar ---
        // AuthMiddleware melampirkan data ke 'jwt_data'
        $jwtData = $request->getAttribute('jwt_data'); 

        // 1. Cek apakah data otentikasi ada
        if (empty($jwtData) || !isset($jwtData['role'])) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Role data missing. Internal error.' // Error yang Anda terima!
            ]));
            return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $userRole = $jwtData['role'];

        // 2. Cek apakah role pengguna termasuk dalam allowedRoles
        if (!in_array($userRole, $this->allowedRoles)) {
            $response = new Response();
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Forbidden. Your role ('.$userRole.') is not authorized to access this resource.'
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // 3. Jika role sesuai, lanjutkan request
        return $handler->handle($request);
    }
}