<?php
// Controllers/ClientController.php
require_once __DIR__ . '/../Models/ClientDetailModel.php';
require_once __DIR__ . '/../Models/UserModel.php';

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ClientController {
    private ClientDetailModel $clientDetailModel;
    private UserModel $userModel;

    public function __construct(PDO $db) {
        $this->clientDetailModel = new ClientDetailModel($db);
        $this->userModel = new UserModel($db);
    }

    private function parseJsonInput(Request $request): array {
        $data = $request->getParsedBody();
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            $data = json_decode($content, true); 
            $request->getBody()->rewind(); 
            if (is_null($data)) { $data = []; }
        }
        return $data;
    }

    /**
     * Endpoint: PUT /client/profile/{uuid}
     */
    public function updateClientProfile(Request $request, Response $response, array $args): Response {
        $data = $this->parseJsonInput($request);
        $requestedUserUuid = $args['uuid'] ?? null;
        
        // Cek data otentikasi
        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserUuid = $jwtData['uuid'] ?? null;
        $authenticatedUserId = $jwtData['id'] ?? null;
        $authenticatedUserRole = $jwtData['role'] ?? 'guest';

        // --- 1. OTORISASI ---
        $isAccessAllowed = (
            $authenticatedUserRole === 'admin' || 
            $authenticatedUserUuid === $requestedUserUuid
        );

        if (!$isAccessAllowed) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized access. You can only update your own profile.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        // Pastikan kita punya ID internal user yang akan di-update
        $userToUpdate = $this->userModel->findByUuid($requestedUserUuid);
        if (!$userToUpdate || $userToUpdate['role'] !== 'customer') {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Invalid user or cannot edit non-client profile.']));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $targetUserId = (int)$userToUpdate['id'];
        
        // --- 2. VALIDASI INPUT DASAR ---
        if (empty($data['name']) || empty($data['gender']) || empty($data['address'])) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Name, gender, and address are required fields.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        // Data yang akan di-update di tabel `users`
        $updateBasicSuccess = $this->userModel->updateBasicData(
            $targetUserId,
            $data['name'], 
            $data['phone'] ?? $userToUpdate['phone'] // Ambil phone lama jika tidak dikirim
        );
        
        // Data yang akan di-update/buat di tabel `client_details`
        $clientDetailsData = [
            'gender' => $data['gender'],
            'birth_date' => $data['birth_date'] ?? null, 
            'address' => $data['address'],
            'province' => $data['province'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'profile_picture_url' => $data['profile_picture_url'] ?? null,
        ];

        // Lakukan update detail
        $updateDetailsSuccess = $this->clientDetailModel->updateOrCreate($targetUserId, $clientDetailsData);

        if ($updateBasicSuccess || $updateDetailsSuccess) {
            // Ambil data terbaru untuk respons
            $updatedData = $this->clientDetailModel->getClientDetailByUuid($requestedUserUuid);

            $response->getBody()->write(json_encode([
                'status' => 'success', 
                'message' => 'Client profile updated successfully.',
                'data' => $updatedData
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }
        
        $response->getBody()->write(json_encode([
            'status' => 'error', 
            'message' => 'Update failed. Data might be the same or a server error occurred.'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }

    /**
     * Endpoint: GET /client/profile/{uuid}
     * Membutuhkan AuthMiddleware sebelumnya.
     */
    public function getClientProfile(Request $request, Response $response, array $args): Response {
        
        // UUID user yang diminta dari URL (contoh: a1b2c3d4-e5f6...)
        $requestedUserUuid = $args['uuid'] ?? null; 

        if (!$requestedUserUuid) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'User identifier (UUID) is required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // --- KONTROL AKSES / OTORISASI (Data dari AuthMiddleware) ---
        // AuthMiddleware sudah melampirkan payload JWT: user_id, uuid, role
        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserUuid = $jwtData['uuid'] ?? null;
        $authenticatedUserRole = $jwtData['role'] ?? 'guest';

        // Logika Keamanan: Admin (boleh melihat semua) ATAU User harus melihat profilnya sendiri.
        $isAccessAllowed = (
            $authenticatedUserRole === 'admin' || 
            $authenticatedUserUuid === $requestedUserUuid
        );

        if (!$isAccessAllowed) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized access. You can only view your own profile.']));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }
        // --- AKHIR KONTROL AKSES ---

        // 1. Ambil Detail Klien (JOIN users dan client_details)
        $detail = $this->clientDetailModel->getClientDetailByUuid($requestedUserUuid); 

        if ($detail) {
            // Skenario A: Detail lengkap ditemukan
            $response->getBody()->write(json_encode(['status' => 'success', 'data' => $detail]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } 
        
        // 2. Jika client_details TIDAK ditemukan, ambil data dasar dari tabel users
        $user = $this->userModel->findByUuid($requestedUserUuid); 

        if (!$user) {
             // UUID tidak valid atau tidak ada di database
             $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'User not found.']));
             return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Skenario B: Detail klien belum diisi, kembalikan data dasar user
        $output = [
            'status' => 'success', 
            'data' => [
                'uuid' => $user['uuid'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $user['role'],
                'message' => 'Client details not set yet. Basic user data returned.'
            ]
        ];
        $response->getBody()->write(json_encode($output));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
}