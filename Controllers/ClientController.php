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

    /**
     * Endpoint: PUT /client/profile/{uuid}
     */
    public function updateClientProfile(Request $request, Response $response, array $args): Response {
        try {
            $data = $this->parseJsonInput($request);
        } catch (\RuntimeException $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => $e->getMessage()
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $requestedUserUuid = $args['uuid'] ?? null;

        // --- 1. OTORISASI ---
        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserUuid = $jwtData['uuid'] ?? null;
        $authenticatedUserId = $jwtData['id'] ?? null;
        $authenticatedUserRole = $jwtData['role'] ?? 'guest';

        $isAccessAllowed = (
            $authenticatedUserRole === 'admin' || 
            $authenticatedUserUuid === $requestedUserUuid
        );

        if (!$isAccessAllowed) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Unauthorized access. You can only update your own profile.'
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // --- 2. CEK USER TARGET ---
        $userToUpdate = $this->userModel->findByUuid($requestedUserUuid);
        if (!$userToUpdate || $userToUpdate['role'] !== 'customer') {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Invalid user or cannot edit non-client profile.'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $targetUserId = (int)$userToUpdate['id'];

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
            $targetUserId,
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

        $updateDetailsSuccess = $this->clientDetailModel->updateOrCreate($targetUserId, $clientDetailsData);

        if ($updateBasicSuccess || $updateDetailsSuccess) {
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
     */
    public function getClientProfile(Request $request, Response $response, array $args): Response {
        $requestedUserUuid = $args['uuid'] ?? null; 

        if (!$requestedUserUuid) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'User identifier (UUID) is required.'
            ]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserUuid = $jwtData['uuid'] ?? null;
        $authenticatedUserRole = $jwtData['role'] ?? 'guest';

        $isAccessAllowed = (
            $authenticatedUserRole === 'admin' || 
            $authenticatedUserUuid === $requestedUserUuid
        );

        if (!$isAccessAllowed) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Unauthorized access. You can only view your own profile.'
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        $detail = $this->clientDetailModel->getClientDetailByUuid($requestedUserUuid); 

        if ($detail) {
            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $detail
            ]));
            return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
        } 
        
        $user = $this->userModel->findByUuid($requestedUserUuid); 
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
                'role' => $user['role'],
                'message' => 'Client details not set yet. Basic user data returned.'
            ]
        ];
        $response->getBody()->write(json_encode($output));
        return $response->withStatus(200)->withHeader('Content-Type', 'application/json');
    }

    public function deactivateClientAccount(Request $request, Response $response, array $args): Response {
        $requestedUserUuid = $args['uuid'] ?? null;
        
        // --- 1. OTORISASI ---
        $jwtData = $request->getAttribute('jwt_data'); 
        $authenticatedUserUuid = $jwtData['uuid'] ?? null;
        $authenticatedUserRole = $jwtData['role'] ?? 'guest';

        $isAccessAllowed = (
            $authenticatedUserRole === 'admin' || 
            $authenticatedUserUuid === $requestedUserUuid
        );

        if (!$isAccessAllowed) {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Unauthorized access. You can only deactivate your own account.'
            ]));
            return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
        }

        // --- 2. CEK USER TARGET ---
        $userToDeactivate = $this->userModel->findByUuid($requestedUserUuid);
        if (!$userToDeactivate || $userToDeactivate['role'] !== 'customer') {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Invalid client user not found or not a client profile.'
            ]));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }
        $targetUserId = (int)$userToDeactivate['id'];
        
        // Cek apakah akun sudah nonaktif
        if ($userToDeactivate['status'] === 'deactivated') {
            $response->getBody()->write(json_encode([
                'status' => 'error', 
                'message' => 'Account is already deactivated.'
            ]));
            return $response->withStatus(409)->withHeader('Content-Type', 'application/json');
        }


        // --- 3. NONAKTIFKAN AKUN ---
        $deactivateSuccess = $this->userModel->deactivateAccount($targetUserId);

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
