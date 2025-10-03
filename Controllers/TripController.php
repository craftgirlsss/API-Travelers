<?php
// Controllers/TripController.php
require_once __DIR__ . '/../Models/TripModel.php';

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class TripController {
    private TripModel $tripModel;

    public function __construct(PDO $db) {
        $this->tripModel = new TripModel($db);
    }

    /**
     * Endpoint: GET /trips
     * Menggunakan UUID di output.
     */
    /**
     * Endpoint: GET /trips
     * Menggunakan UUID di output.
     */
    public function getTrips(Request $request, Response $response): Response {
        try {
            $trips = $this->tripModel->getAllTrips();

            $data = array_map(function($trip) {
                return [
                    'uuid' => $trip['uuid'],
                    'title' => $trip['title'],
                    'duration' => $trip['duration'],
                    'location' => $trip['location'],
                    'price' => (float)$trip['price'],
                    'discount_price' => $trip['discount_price'] !== null ? (float)$trip['discount_price'] : null,
                    'max_participants' => (int)$trip['max_participants'],
                    'booked_participants' => (int)$trip['booked_participants'],
                    'remaining_seats' => (int)$trip['remaining_seats'],
                    'start_date' => $trip['start_date'],
                    // --- BARIS BARU DITAMBAHKAN ---
                    'main_image_url' => $trip['main_image_url'], 
                    // -----------------------------
                    'provider' => [
                        'company_name' => $trip['provider_company_name'],
                        'company_logo_path' => $trip['provider_company_logo_path']
                    ]
                ];
            }, $trips);

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $data
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch trips: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Endpoint: GET /trips/search?location=...
     * Menggunakan UUID di output.
     */
    public function searchTripsByLocation(Request $request, Response $response): Response {
        try {
            $params = $request->getQueryParams();
            $keyword = $params['location'] ?? '';

            if (empty($keyword)) {
                $response->getBody()->write(json_encode([
                    'status' => 'error',
                    'message' => 'Location keyword is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $trips = $this->tripModel->searchTripsByLocation($keyword);

            $data = array_map(function($trip) {
                return [
                    'uuid' => $trip['uuid'], // <--- Mengganti 'id' menjadi 'uuid' (string)
                    'title' => $trip['title'],
                    'duration' => $trip['duration'],
                    'location' => $trip['location'],
                    'price' => (float)$trip['price'],
                    'discount_price' => $trip['discount_price'] !== null ? (float)$trip['discount_price'] : null,
                    'max_participants' => (int)$trip['max_participants'],
                    'booked_participants' => (int)$trip['booked_participants'],
                    'remaining_seats' => (int)$trip['remaining_seats'],
                    'start_date' => $trip['start_date'],
                    'provider' => [
                        'company_name' => $trip['provider_company_name'],
                        'company_logo_path' => $trip['provider_company_logo_path']
                    ]
                ];
            }, $trips);

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'data' => $data
            ]));

            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Failed to search trips: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Endpoint: GET /trips/search?q=...
     * Menggunakan UUID di output.
     */
    public function searchTripsByGatheringPoint(Request $request, Response $response): Response {
        try {
            $params = $request->getQueryParams();
            $keyword = trim($params['q'] ?? '');

            if ($keyword === '') {
                $response->getBody()->write(json_encode([
                    'status' => 'error',
                    'message' => 'Query parameter q is required'
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }

            $trips = $this->tripModel->searchTripsByGatheringPoint($keyword);

            $data = array_map(function($trip) {
                return [
                    'uuid' => $trip['uuid'], // <--- Mengganti 'id' menjadi 'uuid' (string)
                    'title' => $trip['title'],
                    'duration' => $trip['duration'],
                    'location' => $trip['location'],
                    'gathering_point_name' => $trip['gathering_point_name'],
                    'price' => isset($trip['price']) ? (float)$trip['price'] : null,
                    'discount_price' => $trip['discount_price'] !== null ? (float)$trip['discount_price'] : null,
                    'max_participants' => (int)$trip['max_participants'],
                    'booked_participants' => (int)$trip['booked_participants'],
                    'remaining_seats' => (int)$trip['remaining_seats'],
                    'start_date' => $trip['start_date'],
                    'provider' => [
                        'company_name' => $trip['provider_company_name'] ?? null,
                        'company_logo_path' => $trip['provider_company_logo_path'] ?? null
                    ]
                ];
            }, $trips);

            $response->getBody()->write(json_encode(['status' => 'success', 'data' => $data]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'message' => 'Failed to fetch trips: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }

    /**
     * Endpoint: GET /trips/{uuid}
     * Mengambil UUID dari URL dan memanggil Model yang sesuai.
     */
    public function getTripDetail(Request $request, Response $response, array $args): Response {
        try {
            // Ambil argumen UUID dari URL. Key harus 'uuid' sesuai route {uuid}
            $uuid = $args['uuid'] ?? null; 
            
            // Log untuk debug (Opsional, hapus di production)
            // error_log("Trying to find trip with UUID: " . ($uuid ?? 'NULL/EMPTY')); 

            if (empty($uuid)) {
                $response->getBody()->write(json_encode([
                    'status' => 'failed',
                    'success' => false,
                    'message' => 'UUID Trip tidak valid. (UUID Kosong)'
                ]));
                return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
            }

            $trip = $this->tripModel->getTripDetailByUuid($uuid);

            if (!$trip) {
                $response->getBody()->write(json_encode([
                    'status' => 'failed',
                    'success' => false,
                    'message' => 'UUID Trip tidak valid.' 
                ]));
                return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
            }

            // --- PERUBAHAN UTAMA DI SINI: MENAMBAHKAN ARRAY 'images' ---
            $data = [
                'uuid' => $trip['uuid'],
                'title' => $trip['title'],
                'description' => $trip['description'],
                'duration' => $trip['duration'],
                'location' => $trip['location'],
                'gathering_point_name' => $trip['gathering_point_name'],
                'gathering_point_url' => $trip['gathering_point_url'],
                'price' => (float)$trip['price'],
                'discount_price' => $trip['discount_price'] !== null ? (float)$trip['discount_price'] : null,
                'max_participants' => (int)$trip['max_participants'],
                'booked_participants' => (int)$trip['booked_participants'],
                'remaining_seats' => (int)$trip['remaining_seats'],
                'start_date' => $trip['start_date'],
                'end_date' => $trip['end_date'],
                'departure_time' => $trip['departure_time'],
                'return_time' => $trip['return_time'],
                'created_at' => $trip['created_at'],
                'updated_at' => $trip['updated_at'],
                'provider' => [
                    'email' => $trip['provider_email'],
                    'company_name' => $trip['provider_company_name'],
                    'company_logo_path' => $trip['provider_company_logo_path']
                ],
                // DATA BARU: Daftar Gambar dari TripModel
                'images' => $trip['images'] ?? [] 
            ];

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Berhasil mendapatkan data',
                'data' => $data
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $response->getBody()->write(json_encode([
                'status' => 'failed',
                'success' => false,
                'message' => 'Failed to fetch trip detail: ' . $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
        }
    }
}
