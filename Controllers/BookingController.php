<?php
// Controllers/BookingController.php
require_once __DIR__ . '/../Models/BookingModel.php';
require_once __DIR__ . '/../Models/TripModel.php'; 

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class BookingController {
    private PDO $db; 
    private BookingModel $bookingModel;
    private TripModel $tripModel; 

    public function __construct(PDO $db) {
        $this->db = $db; 
        $this->bookingModel = new BookingModel($db);
        $this->tripModel = new TripModel($db);
    }

    /**
     * Helper untuk menangani pembacaan Raw Input JSON
     */
    private function parseJsonInput(Request $request): array {
        $data = $request->getParsedBody();
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            $request->getBody()->rewind();
            if (!empty($content)) {
                $data = json_decode($content, true); 
                if (is_null($data)) { $data = []; }
            } else {
                 $data = [];
            }
        }
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    $data[$key] = trim($value, '"'); 
                }
            }
        }
        return $data;
    }

    // =======================================================
    // 1. POST /booking (Tidak Berubah Signatur, Booking ID dibuat INTERNAL)
    // =======================================================
    public function createBooking(Request $request, Response $response): Response {
        
        $this->db->beginTransaction();

        try {
            $jwtData = $request->getAttribute('jwt_data');
            $userId = (int)($jwtData['id'] ?? 0);
            if (!$userId) {
                throw new \Exception('Unauthorized: user not found in token payload', 401);
            }

            $parsedBody = $this->parseJsonInput($request); 
            
            // --- PERUBAHAN INI: Menerima trip_uuid (string) ---
            $tripUuid = $parsedBody['trip_id'] ?? null; // Tetap gunakan trip_id sebagai key input
            $noOfPeople = (int)($parsedBody['num_of_people'] ?? 0); 

            // --- VALIDASI INPUT DASAR ---
            if (empty($tripUuid) || $noOfPeople <= 0 || !is_string($tripUuid) || strlen($tripUuid) < 36) {
                throw new \Exception('Valid Trip UUID and positive number of people are required.', 400);
            }
            
            // --- 1. AMBIL DETAIL TRIP BERDASARKAN UUID ---
            // GANTI findTripById menjadi findTripByUuid
            $trip = $this->tripModel->findTripByUuid($tripUuid); // <--- METHOD BARU YANG HARUS ADA DI TRIPMODEL
            
            if (!$trip) {
                throw new \Exception('Trip not found or UUID is invalid.', 404);
            }

            // Dapatkan ID internal dari hasil pencarian:
            $tripId = (int)$trip['id']; 

            // Validasi ketersediaan:
            if ($trip['status'] !== 'published' || $trip['is_approved'] !== 1) { // Menambahkan is_approved
                throw new \Exception('Trip not available for booking.', 409);
            }

            // --- 2. VALIDASI KAPASITAS & KONFLIK (Sama seperti sebelumnya) ---
            $currentBooked = (int)$trip['booked_participants'];
            $maxCapacity = (int)$trip['max_participants'];
            $newBooked = $currentBooked + $noOfPeople;
            
            if ($newBooked > $maxCapacity) {
                throw new \Exception("Booking failed: Only " . ($maxCapacity - $currentBooked) . " slots remaining.", 409);
            }

            // --- 3. PERHITUNGAN HARGA ---
            $pricePerPerson = (float)$trip['price'] - (float)$trip['discount_price'];
            $totalPrice = $pricePerPerson * $noOfPeople;
            if ($totalPrice < 0) { $totalPrice = 0; }

            // --- 4. BUAT BOOKING (Model menggunakan ID internal) ---
            $bookingId = $this->bookingModel->createBooking($userId, $tripId, $noOfPeople, $totalPrice);
            
            // Ambil UUID booking yang baru dibuat (PENTING untuk output yang bagus)
            $newBookingUuid = $this->bookingModel->getBookingUuidById($bookingId);
            
            // --- 5. UPDATE booked_participants DI TABEL TRIPS ---
            $updateSuccess = $this->tripModel->updateBookedParticipants($tripId, $newBooked);
            if (!$updateSuccess) {
                 throw new \Exception('Failed to update trip capacity.', 500);
            }

            $this->db->commit();

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Booking created successfully',
                'booking_uuid' => $newBookingUuid, // <--- KEMBALIKAN UUID
                'total_price' => $totalPrice
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (\Exception $e) {
            // Error handling dan Rollback...
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            $statusCode = 500;
            if ($e->getCode() > 0 && $e->getCode() < 600) { $statusCode = $e->getCode(); }
            if (str_contains($e->getMessage(), 'remaining') || str_contains($e->getMessage(), 'not available') || str_contains($e->getMessage(), 'UUID is invalid')) { $statusCode = 409; }
            if (str_contains($e->getMessage(), 'positive integers') || str_contains($e->getMessage(), 'Valid Trip UUID')) { $statusCode = 400; } // <-- Tambahkan pengecekan UUID
            if (str_contains($e->getMessage(), 'Unauthorized')) { $statusCode = 401; }
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'UUID is invalid')) { $statusCode = 404; } // <-- Tambahkan pengecekan not found

            
            error_log("APP ERROR in BookingController::create => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            
            // GARANSI bahwa Response dikembalikan:
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }

    // =======================================================
    // 2. PUT /booking/cancel/{uuid} (REVISI MENGGUNAKAN UUID)
    // =======================================================
    /**
     * Endpoint: PUT /booking/cancel/{uuid}
     * Membatalkan pesanan berdasarkan UUID dan mengembalikan slot ke trip.
     */
    public function cancelBooking(Request $request, Response $response, array $args): Response {
        // AMBIL UUID STRING
        $bookingUuid = $args['id'] ?? '';
        
        if (!is_string($bookingUuid) || strlen($bookingUuid) < 36) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Valid Booking UUID is required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $this->db->beginTransaction();

        try {
            $jwtData = $request->getAttribute('jwt_data');
            $authenticatedUserId = (int)($jwtData['id'] ?? 0);

            // --- 1. AMBIL DETAIL BOOKING BERDASARKAN UUID ---
            // Model mengembalikan ID internal booking, ID trip, dan num_of_people
            $booking = $this->bookingModel->getBookingDetailsForCancellation($bookingUuid, $authenticatedUserId);

            if (!$booking) {
                throw new \Exception('Booking not found or unauthorized access.', 404);
            }
            
            // --- 2. OTORISASI (Sudah dilakukan di Model, tapi cek redudansi) ---
            if ($booking['user_id'] !== $authenticatedUserId) {
                // Walaupun getBookingDetailsForCancellation sudah memfilter user_id, 
                // ini adalah lapisan pengecekan tambahan untuk keamanan
                throw new \Exception('Unauthorized access.', 403);
            }

            // --- 3. VALIDASI STATUS ---
            if ($booking['status'] === 'cancelled') {
                throw new \Exception('Booking is already cancelled.', 409);
            }
            if ($booking['status'] === 'completed') {
                throw new \Exception('Cannot cancel a completed trip.', 409);
            }
            
            $bookingIdInternal = (int)$booking['id']; // ID internal untuk update status
            $tripId = (int)$booking['trip_id'];
            $numToDecrease = (int)$booking['num_of_people'];

            // --- 4. UPDATE STATUS BOOKING menjadi 'cancelled' (Menggunakan ID INTERNAL) ---
            $updateBookingSuccess = $this->bookingModel->updateBookingStatus($bookingIdInternal, 'cancelled');
            if (!$updateBookingSuccess) {
                 throw new \Exception('Failed to update booking status.', 500);
            }

            // --- 5. KURANGI booked_participants DI TABEL TRIPS ---
            $decreaseTripSuccess = $this->tripModel->decreaseBookedParticipants($tripId, $numToDecrease);
            
            if (!$decreaseTripSuccess) {
                 throw new \Exception('Failed to release trip capacity. The booked count might be inconsistent.', 500);
            }

            $this->db->commit();

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => "Booking {$bookingUuid} successfully cancelled. {$numToDecrease} slots have been released."
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            
            $statusCode = 500;
            if ($e->getCode() > 0 && $e->getCode() < 600) { $statusCode = $e->getCode(); }
            if (str_contains($e->getMessage(), 'Unauthorized')) { $statusCode = 403; }
            if (str_contains($e->getMessage(), 'not found')) { $statusCode = 404; }
            if (str_contains($e->getMessage(), 'already cancelled') || str_contains($e->getMessage(), 'completed trip')) { $statusCode = 409; }
            
            error_log("APP ERROR in BookingController::cancelBooking => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }


    // =======================================================
    // 3. GET /booking/{uuid}/payment-details (REVISI MENGGUNAKAN UUID)
    // =======================================================
    /**
     * Endpoint: GET /booking/{uuid}/payment-details
     * Menampilkan detail tagihan dan informasi bank tujuan transfer.
     */
    public function getPaymentDetails(Request $request, Response $response, array $args): Response {
        // AMBIL UUID STRING
        $bookingUuid = $args['id'] ?? '';
        
        if (!is_string($bookingUuid) || strlen($bookingUuid) < 36) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Valid Booking UUID is required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $jwtData = $request->getAttribute('jwt_data');
            $authenticatedUserId = (int)($jwtData['id'] ?? 0);

            // --- 1. AMBIL DETAIL TAGIHAN & BANK BERDASARKAN UUID ---
            $details = $this->bookingModel->getPaymentDetails($bookingUuid, $authenticatedUserId);

            if (!$details) {
                throw new \Exception('Booking not found or unauthorized access.', 404);
            }
            
            // --- 2. HITUNG DAN FORMAT DATA ---
            $pricePerPerson = (float)$details['original_price'] - (float)$details['discount_price'];
            $paid = ($details['booking_status'] === 'paid' || $details['booking_status'] === 'confirmed');

            $output = [
                'status' => 'success',
                'success' => true,
                'message' => $paid ? 'Pembayaran telah diterima dan booking berhasil.' : 'Menunggu pembayaran.',
                'data' => [
                    'booking_uuid' => $details['booking_uuid'], // Menggunakan UUID
                    'trip_title' => $details['trip_title'],
                    'booking_status' => $details['booking_status'],
                    'is_paid' => $paid,
                    'summary' => [
                        'num_of_people' => (int)$details['num_of_people'],
                        'price_per_person' => $pricePerPerson,
                        'total_bill' => (float)$details['total_price'],
                        'discount' => (float)$details['discount_price']
                    ],
                    'payment_info' => [
                        'provider_name' => $details['provider_name'],
                        'bank_name' => $details['bank_name'],
                        'account_name' => $details['bank_account_name'],
                        'account_number' => $details['bank_account_number'],
                    ]
                ]
            ];

            $response->getBody()->write(json_encode($output));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $statusCode = 500;
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'unauthorized')) { $statusCode = 404; }
            
            error_log("APP ERROR in BookingController::getPaymentDetails => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }


    // =======================================================
    // 4. GET /booking (REVISI OUTPUT MENGGUNAKAN UUID)
    // =======================================================
    /**
     * Endpoint: GET /booking
     * Menampilkan riwayat pemesanan (History). Output menggunakan UUID.
     */
    public function getUserBookings(Request $request, Response $response): Response {
        
        try {
            $jwtData = $request->getAttribute('jwt_data');
            $userId = (int)($jwtData['id'] ?? 0);
            
            if (!$userId) {
                throw new \Exception('Unauthorized: user not found in token payload', 401);
            }

            // --- 1. AMBIL DATA DARI MODEL ---
            $bookings = $this->bookingModel->getUserBookings($userId);

            // --- 2. FORMAT DATA ---
            $data = array_map(function($booking) {
                return [
                    'booking_uuid' => $booking['booking_uuid'], // <--- UUID Booking
                    'trip_uuid' => $booking['trip_uuid'],       // <--- UUID Trip
                    'trip_title' => $booking['trip_title'],
                    'provider_name' => $booking['provider_name'],
                    'location' => $booking['trip_location'],
                    'start_date' => $booking['trip_start_date'],
                    'departure_time' => $booking['trip_departure_time'],
                    'num_of_people' => (int)$booking['num_of_people'],
                    'total_price' => (float)$booking['total_price'],
                    'status' => $booking['booking_status'],
                    'booking_date' => $booking['booking_date'],
                ];
            }, $bookings);
            
            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Successfully fetched user booking history.',
                'data' => $data
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $statusCode = 500;
            if (str_contains($e->getMessage(), 'Unauthorized')) { $statusCode = 401; }
            
            error_log("APP ERROR in BookingController::getUserBookings => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }

    // =======================================================
    // 5. GET /booking/{uuid} (REVISI MENGGUNAKAN UUID)
    // =======================================================
    /**
     * Endpoint: GET /booking/{uuid}
     * Menampilkan detail lengkap satu riwayat pemesanan berdasarkan UUID.
     */
    public function getBookingDetail(Request $request, Response $response, array $args): Response {
        // AMBIL UUID STRING
        $bookingUuid = $args['id'] ?? '';
        
        if (!is_string($bookingUuid) || strlen($bookingUuid) < 36) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Valid Booking UUID is required.', 'success' => false]));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        try {
            $jwtData = $request->getAttribute('jwt_data');
            $authenticatedUserId = (int)($jwtData['id'] ?? 0);

            // 1. Ambil Detail Booking dari Model (sekaligus otorisasi)
            $detail = $this->bookingModel->getBookingDetailByUuid($bookingUuid, $authenticatedUserId); // <-- Method baru

            if (!$detail) {
                throw new \Exception('Booking not found or unauthorized access.', 404);
            }
            
            // 2. Format Data Output
            $pricePerPerson = (float)$detail['trip_price'] - (float)$detail['discount_price'];
            $totalPrice = (float)$detail['total_price'];
            
            $output = [
                'status' => 'success',
                'success' => true,
                'message' => 'Successfully fetched booking details.',
                'data' => [
                    'booking_info' => [
                        'booking_uuid' => $detail['booking_uuid'], // <--- UUID
                        'status' => $detail['booking_status'],
                        'booking_date' => $detail['booking_date'],
                        'total_paid' => $totalPrice,
                        'num_of_people' => (int)$detail['num_of_people'],
                    ],
                    'trip_info' => [
                        'trip_uuid' => $detail['trip_uuid'], // <--- UUID Trip
                        'title' => $detail['trip_title'],
                        'description' => $detail['trip_description'],
                        'duration' => $detail['duration'],
                        'location' => $detail['location'],
                        'start_date' => $detail['start_date'],
                        'end_date' => $detail['end_date'],
                        'departure_time' => $detail['departure_time'],
                        'return_time' => $detail['return_time'],
                        'gathering_point' => [
                            'name' => $detail['gathering_point_name'],
                            'url' => $detail['gathering_point_url'],
                        ]
                    ],
                    'price_breakdown' => [
                        'price_per_person' => $pricePerPerson,
                        'total_price_trip' => (float)$detail['trip_price'],
                        'discount_applied' => (float)$detail['discount_price'],
                        'final_bill' => $totalPrice,
                    ],
                    'provider_info' => [
                        'company_name' => $detail['provider_name'],
                        'phone_number' => $detail['provider_phone'],
                        'logo' => $detail['provider_logo'],
                        'payment_account' => [
                             'bank_name' => $detail['bank_name'],
                             'account_name' => $detail['bank_account_name'],
                             'account_number' => $detail['bank_account_number'],
                        ]
                    ]
                ]
            ];

            $response->getBody()->write(json_encode($output));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $statusCode = 500;
            if (str_contains($e->getMessage(), 'not found') || str_contains($e->getMessage(), 'unauthorized')) { $statusCode = 404; }
            if (str_contains($e->getMessage(), 'Forbidden')) { $statusCode = 403; }
            
            error_log("APP ERROR in BookingController::getBookingDetail => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }
}
