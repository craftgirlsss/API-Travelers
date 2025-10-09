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

    // Tentukan Base URL untuk akses gambar di response
    private const BASE_URL = 'https://api-travelers.karyadeveloperindonesia.com/';

    public function __construct(PDO $db) {
        $this->clientDetailModel = new ClientDetailModel($db);
        $this->userModel = new UserModel($db);
    }

    /**
     * Parse JSON input dengan error handling. (Dapat dipertahankan, tapi akan diabaikan
     * jika request adalah multipart)
     */
    private function parseJsonInput(Request $request): array {
        // Logika ini hanya relevan untuk application/json
        $data = $request->getParsedBody();

        if (is_null($data) || empty($data)) {
            $content = (string)$request->getBody();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                // Jangan throw, kembalikan array kosong, karena mungkin ini adalah multipart
                return []; 
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
             return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Unauthorized access. Token is missing or invalid.'], 401);
        }

        // --- 1. AMBIL DETAIL DARI CLIENT_DETAILS ---
        $detail = $this->clientDetailModel->getClientDetailByUserId($authenticatedUserId); 

        if ($detail) {
            // REVISI BARIS 66 MENGGUNAKAN NULL COALESCING ATAU isset()
            $profilePicturePath = $detail['profile_picture_url'] ?? null;
            
            if ($profilePicturePath) {
                $detail['profile_picture_url'] = self::BASE_URL . $profilePicturePath;
            } else {
                // Pastikan kunci ini ada, meskipun nilainya null, agar response konsisten
                $detail['profile_picture_url'] = null;
            }
            return $this->jsonResponse($response, ['status' => 'success', 'data' => $detail], 200);
        } 
        
        // --- 2. FALLBACK KE DATA DASAR USER (JIKA CLIENT_DETAILS KOSONG) ---
        $user = $this->userModel->getUserProfileById($authenticatedUserId); 
        
        if (!$user) {
             return $this->jsonResponse($response, ['status' => 'error', 'message' => 'User not found.'], 404);
        }
        
        // Pastikan URL gambar diubah menjadi Absolute URL
        $profilePictureUrl = $user['profile_picture_path'] ?? null;
        if ($profilePictureUrl) {
            $profilePictureUrl = self::BASE_URL . $profilePictureUrl;
        }

        $output = [
            'status' => 'success', 
            'data' => [
                'uuid' => $user['uuid'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'],
                'role' => $authenticatedUserRole,
                'profile_picture_url' => $profilePictureUrl,
                'message' => 'Client details not set yet. Basic user data returned.'
            ]
        ];
        return $this->jsonResponse($response, $output, 200);
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

            // Mengambil input. getParsedBody() akan menangani JSON dan form-data
            $body = $request->getParsedBody() ?? []; 
            $uploadedFiles = $request->getUploadedFiles() ?? [];

            // ===============================================
            // DEBUGGING: Cek apakah data form-data terbaca
            // ===============================================
            if (empty($body)) {
                 error_log("DEBUG: getParsedBody() is empty. Checking raw input.");
            } else {
                 error_log("DEBUG: Parsed Body (Data Teks): " . print_r($body, true));
            }
            error_log("DEBUG: Uploaded Files: " . print_r(array_keys($uploadedFiles), true));
            // ===============================================

            if ($authenticatedUserId <= 0) {
                 throw new \Exception('Unauthorized access.', 401);
            }
            
            // Mengambil input. getParsedBody() akan menangani JSON dan form-data
            $body = $request->getParsedBody() ?? []; 
            $uploadedFiles = $request->getUploadedFiles() ?? [];

        } catch (\Exception $e) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Unauthorized access.'], 401);
        }

        // --- 2. CEK USER TARGET ---
        $userToUpdate = $this->userModel->findByUserId($authenticatedUserId); 
        
        if (!$userToUpdate || $userToUpdate['role'] !== 'customer') {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Invalid user or cannot edit non-client profile.'], 404);
        }

        // --- 3. HANDLE FILE UPLOAD (Gambar Profil) ---
        $profilePicturePath = null;
        $profilePictureFile = $uploadedFiles['profile_picture'] ?? null;
        $uploadDir = __DIR__ . '/../assets/user/'; // Lokasi penyimpanan

        if ($profilePictureFile && $profilePictureFile->getError() === UPLOAD_ERR_OK) {
            try {
                // Panggil Helper untuk proses penyimpanan
                $profilePicturePath = FileHelper::uploadImage($profilePictureFile, $uploadDir, $authenticatedUserId);
            } catch (\Exception $e) {
                 return $this->jsonResponse($response, ['status' => 'failed', 'message' => 'Gagal mengupload gambar: ' . $e->getMessage()], 500);
            }
        }
        
        // --- 4. VALIDASI INPUT DASAR (Ambil dari $body/form-data) ---
        $name = trim($body['name'] ?? '');
        $gender = $body['gender'] ?? '';
        $address = $body['address'] ?? '';

        if (empty($name) || empty($gender) || empty($address)) {
            return $this->jsonResponse($response, ['status' => 'error', 'message' => 'Name, gender, and address are required fields.'], 400);
        }

        // --- 5. UPDATE DATA DI USER MODEL ---
        // Asumsi: updateBasicData di UserModel sudah diperbaiki untuk menerima phone
        $updateBasicSuccess = $this->userModel->updateBasicData(
            $authenticatedUserId,
            $name, 
            $body['phone'] ?? $userToUpdate['phone']
        );

        // --- 6. UPDATE DATA DI CLIENT DETAIL MODEL ---
        $clientDetailsData = [
            'gender' => $gender,
            'birth_date' => $body['birth_date'] ?? null, 
            'address' => $address,
            'province' => $body['province'] ?? null,
            'city' => $body['city'] ?? null,
            'postal_code' => $body['postal_code'] ?? null,
            // Jika ada gambar baru diupload, gunakan path baru, jika tidak, gunakan path dari body/path lama
            'profile_picture_url' => $profilePicturePath // Path RELATIF hasil upload
        ];

        // ClientDetailModel->updateOrCreate akan menangani jika profile_picture_url adalah null
        $updateDetailsSuccess = $this->clientDetailModel->updateOrCreate($authenticatedUserId, $clientDetailsData);

        if ($updateBasicSuccess || $updateDetailsSuccess || $profilePicturePath !== null) {
            // Ambil data terbaru
            $updatedData = $this->clientDetailModel->getClientDetailByUserId($authenticatedUserId);

            // Periksa dan ubah URL gambar menjadi Absolute URL
            // >>> REVISI PENTING MENGGUNAKAN isset() <<<
            if ($updatedData && isset($updatedData['profile_picture_url']) && $updatedData['profile_picture_url']) {
                $updatedData['profile_picture_url'] = self::BASE_URL . $updatedData['profile_picture_url'];
            } else if ($updatedData) {
                // Tambahkan kunci jika tidak ada, agar response API konsisten
                $updatedData['profile_picture_url'] = null;
            }
            
            return $this->jsonResponse($response, [
                'status' => 'success', 
                'success' => true,
                'message' => 'Client profile updated successfully.',
                'data' => $updatedData
            ], 200);
        }

        return $this->jsonResponse($response, [
            'status' => 'error', 
            'success' => false,
            'message' => 'Update failed. Data might be the same or a server error occurred.'
        ], 500);
    }
    
    // ... (Method deactivateAuthenticatedClientAccount tetap sama)

    // Helper untuk JSON Response
    private function jsonResponse(Response $response, array $data, int $status): Response {
        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}