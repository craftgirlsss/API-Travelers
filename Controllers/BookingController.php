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
     * Helper untuk menangani pembacaan Raw Input JSON (Disalin dari ClientController)
     */
    private function parseJsonInput(Request $request): array {
        $data = $request->getParsedBody();
        if (is_null($data) || empty($data)) {
            $content = $request->getBody()->getContents(); 
            $request->getBody()->rewind(); // Penting: Menggulirkan pointer kembali
            if (!empty($content)) {
                $data = json_decode($content, true); 
                if (is_null($data)) { $data = []; } // Jika gagal decode, set array kosong
            } else {
                 $data = [];
            }
        }
        // Jika data masih berupa array (dari form-data), pastikan nilai string dikonversi
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value)) {
                    // Menghapus tanda kutip jika dikirim melalui form-data/curl
                    $data[$key] = trim($value, '"'); 
                }
            }
        }
        return $data;
    }

    public function createBooking(Request $request, Response $response): Response {
        
        $this->db->beginTransaction(); // Mulai Transaksi untuk Integritas Data

        try {
            $jwtData = $request->getAttribute('jwt_data');
            $userId = (int)($jwtData['id'] ?? 0);
            if (!$userId) {
                throw new \Exception('Unauthorized: user not found in token payload', 401);
            }

            $parsedBody = $this->parseJsonInput($request); 
            
            $tripId = (int)($parsedBody['trip_id'] ?? 0);
            $noOfPeople = (int)($parsedBody['num_of_people'] ?? 0); 

            // --- VALIDASI INPUT DASAR ---
            if ($tripId <= 0 || $noOfPeople <= 0) {
                // ... (ini adalah error yang muncul jika parsing body gagal)
                throw new \Exception('Trip ID and number of people must be positive integers.', 400);
            }
            // --- 1. AMBIL DETAIL TRIP (Untuk Harga dan Kapasitas) ---
            $trip = $this->tripModel->findTripById($tripId);
            if (!$trip || $trip['status'] !== 'published') {
                throw new \Exception('Trip not found or not available for booking.', 404);
            }

            // --- 2. VALIDASI KAPASITAS & KONFLIK ---
            $currentBooked = (int)$trip['booked_participants']; // Pastikan casting ke integer
            $maxCapacity = (int)$trip['max_participants'];
            $newBooked = $currentBooked + $noOfPeople;
            
            if ($newBooked > $maxCapacity) {
                throw new \Exception("Booking failed: Only " . ($maxCapacity - $currentBooked) . " slots remaining.", 409);
            }

            // --- 3. PERHITUNGAN HARGA ---
            $pricePerPerson = (float)$trip['price'] - (float)$trip['discount_price'];
            $totalPrice = $pricePerPerson * $noOfPeople;
            
            if ($totalPrice < 0) {
                $totalPrice = 0; 
            }

            // --- 4. BUAT BOOKING ---
            $bookingId = $this->bookingModel->createBooking($userId, $tripId, $noOfPeople, $totalPrice);

            // --- 5. UPDATE booked_participants DI TABEL TRIPS ---
            $updateSuccess = $this->tripModel->updateBookedParticipants($tripId, $newBooked);
            if (!$updateSuccess) {
                 throw new \Exception('Failed to update trip capacity.', 500);
            }

            $this->db->commit(); // Commit Transaksi

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Booking created successfully',
                'booking_id' => $bookingId,
                'total_price' => $totalPrice
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (\Exception $e) {
            // Pastikan rollback hanya dipanggil jika transaksi aktif
            if ($this->db->inTransaction()) {
                $this->db->rollBack(); 
            }
            
            $statusCode = 500;
            if ($e->getCode() > 0 && $e->getCode() < 600) { $statusCode = $e->getCode(); }
            if (str_contains($e->getMessage(), 'remaining') || str_contains($e->getMessage(), 'not available')) { $statusCode = 409; }
            if (str_contains($e->getMessage(), 'positive integers')) { $statusCode = 400; }
            if (str_contains($e->getMessage(), 'Unauthorized')) { $statusCode = 401; }
            
            error_log("APP ERROR in BookingController::create => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }

    /**
     * Endpoint: PUT /booking/cancel/{id}
     * Membatalkan pesanan dan mengembalikan slot ke trip.
     */
    public function cancelBooking(Request $request, Response $response, array $args): Response {
        $bookingId = (int)($args['id'] ?? 0);
        
        if ($bookingId <= 0) {
            $response->getBody()->write(json_encode(['status' => 'error', 'message' => 'Valid Booking ID is required.']));
            return $response->withStatus(400)->withHeader('Content-Type', 'application/json');
        }
        
        $this->db->beginTransaction(); // Mulai Transaksi

        try {
            $jwtData = $request->getAttribute('jwt_data');
            $authenticatedUserId = (int)($jwtData['id'] ?? 0);

            // --- 1. AMBIL DETAIL BOOKING ---
            $booking = $this->bookingModel->getBookingDetailsForCancellation($bookingId);

            if (!$booking) {
                throw new \Exception('Booking not found.', 404);
            }
            
            // --- 2. OTORISASI ---
            // Hanya user yang membuat booking yang boleh membatalkan
            if ($booking['user_id'] !== $authenticatedUserId) {
                throw new \Exception('Unauthorized access. You can only cancel your own bookings.', 403);
            }

            // --- 3. VALIDASI STATUS ---
            // Hanya pesanan 'pending' atau 'confirmed' yang boleh dibatalkan
            if ($booking['status'] === 'cancelled') {
                throw new \Exception('Booking is already cancelled.', 409);
            }
            if ($booking['status'] === 'completed') {
                throw new \Exception('Cannot cancel a completed trip.', 409);
            }
            
            $tripId = (int)$booking['trip_id'];
            $numToDecrease = (int)$booking['num_of_people'];

            // --- 4. UPDATE STATUS BOOKING menjadi 'cancelled' ---
            $updateBookingSuccess = $this->bookingModel->updateBookingStatus($bookingId, 'cancelled');
            if (!$updateBookingSuccess) {
                 throw new \Exception('Failed to update booking status.', 500);
            }

            // --- 5. KURANGI booked_participants DI TABEL TRIPS ---
            $decreaseTripSuccess = $this->tripModel->decreaseBookedParticipants($tripId, $numToDecrease);
            
            // Walaupun ada pengecekan >= di SQL, cek ini penting untuk logika aplikasi
            if (!$decreaseTripSuccess) {
                 throw new \Exception('Failed to release trip capacity. The booked count might be inconsistent.', 500);
            }

            $this->db->commit(); // Commit Transaksi

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => "Booking ID {$bookingId} successfully cancelled. {$numToDecrease} slots have been released."
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack(); 
            }
            
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
}