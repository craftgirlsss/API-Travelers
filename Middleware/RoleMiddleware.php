<?php
// Middleware/RoleMiddleware.php

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Response;

class RoleMiddleware {
    private $allowedRoles;

    /**
     * @param array $allowedRoles Daftar peran yang diizinkan (misal: ['admin', 'provider'])
     */
    public function __construct(array $allowedRoles) {
        $this->allowedRoles = $allowedRoles;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response {
        $response = new Response();
        
        // Ambil data user dari request attribute yang disuntikkan oleh AuthMiddleware
        $userData = $request->getAttribute('user_data');

        // Pastikan user data ada (jika tidak ada, berarti AuthMiddleware gagal, tapi kita cek lagi)
        if (!$userData || !isset($userData['role'])) {
             $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Role data missing. Internal error.']));
             return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
        }

        $userRole = $userData['role'];

        // Cek apakah role user ada dalam daftar peran yang diizinkan
        if (!in_array($userRole, $this->allowedRoles)) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Forbidden: Insufficient access rights. Role: ' . $userRole]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json'); // 403 Forbidden
        }

        // Role diizinkan, lanjutkan ke Controller
        return $handler->handle($request);
    }
}