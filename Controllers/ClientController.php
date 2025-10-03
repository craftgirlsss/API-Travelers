<?php
// Controllers/ClientController.php
require_once __DIR__ . '/../Models/ClientDetailModel.php';
require_once __DIR__ . '/../Models/UserModel.php';
require_once __DIR__ . '/../Helpers/FileHelper.php';

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ClientController {
    private ClientDetailModel $clientDetailModel;
    private UserModel $userModel;

    public function __construct(PDO $db) {
        $this->clientDetailModel = new ClientDetailModel($db);
        $this->userModel = new UserModel($db);
    }

    /**
     * Parse JSON input dengan error handling.
     */
    private function parseJsonInput(Request $request): array {
        $data = $request->getParsedBody();

        if (is_null($data) || empty($data)) {
            $content = (string)$request->getBody();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException("Invalid JSON: " . json_last_error_msg());
            }

            $request->getBody()->rewind();
        }

        return $data ?? [];
    }
    
    // =================================================================
    // GET /client/profile
    // =================================================================

    /**
     * Endpoint: GET /client/profile (Mengambil data profile pengguna yang sedang terautentikasi)
     */
    public function getAuthenticatedClientProfile(Request $request, Response $response): Response {
        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserId = (int)($jwtData['id'] ?? 0);
        $authenticatedUserRole = $jwtData['role'] ?? 'guest';

        if ($authenticatedUserId <= 0) {
             $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Unauthorized access. Token is missing or invalid.'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // --- 1. AMBIL DETAIL DARI CLIENT_DETAILS ---
        // Asumsi: getClientDetailByUserId ada di ClientDetailModel (TOLONG PASTIKAN METHOD INI ADA)
        $detail = $this->clientDetailModel->getClientDetailByUserId($authenticatedUserId); 

        if ($detail) {
            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $detail
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } 
        
        // --- 2. FALLBACK KE DATA DASAR USER (JIKA CLIENT_DETAILS KOSONG) ---
        // Menggunakan method getUserProfileById yang aman (TOLONG PASTIKAN METHOD INI ADA DI USERMODEL)
        $user = $this->userModel->getUserProfileById($authenticatedUserId); 
        
        if (!$user) {
             $response->getBody()->write(json_encode([
                 'status' => 'error', 
                 'message' => 'User not found.'
             ]));
             return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        $output = [
            'status' => 'success', 
            'data' => [
                'uuid' => $user['uuid'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $authenticatedUserRole,
                'profile_picture_url' => $user['profile_picture_path'] ?? null,
                'message' => 'Client details not set yet. Basic user data returned.'
            ]
        ];
        $response->getBody()->write(json_encode($output));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }
    
    // =================================================================
    // PUT /client/profile
    // =================================================================

    /**
     * Endpoint: PUT /client/profile (Memperbarui data profile pengguna yang sedang terautentikasi)
     */
    public function updateAuthenticatedClientProfile(Request $request, Response $response): Response {
        try {
            // --- 1. OTORISASI ---
            $jwtData = $request->getAttribute('jwt_data'); 
            $authenticatedUserId = (int)($jwtData['id'] ?? 0);
            $authenticatedUserRole = $jwtData['role'] ?? 'guest';

            if ($authenticatedUserId <= 0) {
                 throw new \Exception('Unauthorized access.', 401);
            }
            
            $data = $this->parseJsonInput($request);

        } catch (\RuntimeException $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json')->getBody()->write(json_encode(['status' => 'error', 'message' => 'Unauthorized access.']));
        }

        // --- 2. CEK USER TARGET ---
        // Menggunakan findByUserId untuk mendapatkan semua data (termasuk role, status, dll.)
        $userToUpdate = $this->userModel->findByUserId($authenticatedUserId); 
        
        if (!$userToUpdate || $userToUpdate['role'] !== 'customer') {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Invalid user or cannot edit non-client profile.'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // --- 3. VALIDASI INPUT DASAR ---
        if (empty($data['name']) || empty($data['gender']) || empty($data['address'])) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Name, gender, and address are required fields.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        // --- 4. UPDATE DATA ---
        $updateBasicSuccess = $this->userModel->updateBasicData(
            $authenticatedUserId,
            $data['name'], 
            $data['phone'] ?? $userToUpdate['phone']
        );

        $clientDetailsData = [
            'gender' => $data['gender'],
            'birth_date' => $data['birth_date'] ?? null, 
            'address' => $data['address'],
            'province' => $data['province'] ?? null,
            'city' => $data['city'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'profile_picture_url' => $data['profile_picture_url'] ?? null,
        ];

        // Asumsi: ClientDetailModel->updateOrCreate menggunakan ID internal
        $updateDetailsSuccess = $this->clientDetailModel->updateOrCreate($authenticatedUserId, $clientDetailsData);

        if ($updateBasicSuccess || $updateDetailsSuccess) {
            $updatedData = $this->userModel->getUserProfileById($authenticatedUserId);

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
    
    // =================================================================
    // DELETE /client/account
    // =================================================================

    /**
     * Endpoint: DELETE /client/account (Menonaktifkan akun pengguna yang sedang terautentikasi)
     */
    public function deactivateAuthenticatedClientAccount(Request $request, Response $response): Response {
        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserId = (int)($jwtData['id'] ?? 0);

        if ($authenticatedUserId <= 0) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Unauthorized access. Token is missing or invalid.'
            ]));
            return $response->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        // --- 1. CEK USER TARGET ---
        $userToDeactivate = $this->userModel->findByUserId($authenticatedUserId); 
        
        if (!$userToDeactivate || $userToDeactivate['role'] !== 'customer') {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Invalid client user not found or not a client profile.'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        
        // Cek apakah akun sudah nonaktif
        if ($userToDeactivate['status'] === 'deactivated') {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Account is already deactivated.'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }

        // --- 2. NONAKTIFKAN AKUN ---
        $deactivateSuccess = $this->userModel->deactivateAccount($authenticatedUserId);

        if ($deactivateSuccess) {
            $response->getBody()->write(json_encode([
                'status' => 'success', 
                'message' => 'Your account has been successfully deactivated. You will not be able to log in until it is reactivated.'
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode([
            'status' => 'error', 
            'message' => 'Failed to deactivate account due to a server error.'
        ]));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}
