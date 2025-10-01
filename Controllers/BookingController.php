<?php
// Controllers/BookingController.php
require_once __DIR__ . '/../Models/BookingModel.php';
require_once __DIR__ . '/../Models/TripModel.php'; 

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class BookingController {
    // [PERBAIKAN 1]: Definisikan dan simpan instance PDO untuk transaksi
    private PDO $db; 
    
    private BookingModel $bookingModel;
    private TripModel $tripModel; 

    public function __construct(PDO $db) {
        $this->db = $db; // <-- Inisialisasi PDO
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

            // [PERBAIKAN 2]: Gunakan helper untuk membaca JSON/Form Data
            $parsedBody = $this->parseJsonInput($request); 
            
            // Casting ke int harus menggunakan nilai dari $parsedBody
            $tripId = (int)($parsedBody['trip_id'] ?? 0);
            $noOfPeople = (int)($parsedBody['num_of_people'] ?? 0); // Default ke 0, bukan 1, agar validasi di bawah menangkapnya

            // --- VALIDASI INPUT DASAR ---
            if ($tripId <= 0 || $noOfPeople <= 0) {
                throw new \Exception('Trip ID and number of people must be positive integers.', 400);
            }

            // --- 1. AMBIL DETAIL TRIP (Untuk Harga dan Kapasitas) ---
            $trip = $this->tripModel->findTripById($tripId);
            if (!$trip || $trip['status'] !== 'available') {
                throw new \Exception('Trip not found or not available for booking.', 404);
            }

            // --- 2. VALIDASI KAPASITAS & KONFLIK ---
            $currentBooked = $trip['booked_participants'];
            $maxCapacity = $trip['max_participants'];
            $newBooked = $currentBooked + $noOfPeople;
            
            if ($newBooked > $maxCapacity) {
                throw new \Exception("Booking failed: Only " . ($maxCapacity - $currentBooked) . " slots remaining.", 409);
            }

            // --- 3. PERHITUNGAN HARGA ---
            $pricePerPerson = $trip['price'] - $trip['discount_price'];
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
}
