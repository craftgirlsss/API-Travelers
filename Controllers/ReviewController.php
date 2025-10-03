<?php
// Controllers/ReviewController.php

require_once __DIR__ . '/../Models/ReviewModel.php';
require_once __DIR__ . '/../Models/TripModel.php'; // <-- Wajib diimpor untuk UUID lookup

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ReviewController {
    private ReviewModel $reviewModel;
    private TripModel $tripModel; // <-- Tambahkan TripModel

    public function __construct(PDO $db) {
        $this->reviewModel = new ReviewModel($db);
        $this->tripModel = new TripModel($db); // <-- Inisialisasi TripModel
    }
    
    private function parseJsonInput(Request $request): array {
        $contentType = $request->getHeaderLine('Content-Type');
        if (strstr($contentType, 'application/json')) {
            $contents = $request->getBody()->getContents();
            $request->getBody()->rewind();
            if ($contents) {
                return json_decode($contents, true) ?? [];
            }
        }
        return $request->getParsedBody() ?? [];
    }


    /**
     * Endpoint: POST /reviews
     * Klien memberikan review untuk trip yang sudah selesai.
     * Menerima trip_uuid, bukan trip_id.
     */
    public function submitReview(Request $request, Response $response): Response {
        try {
            $jwtData = $request->getAttribute('jwt_data');
            $userId = (int)($jwtData['id'] ?? 0);

            $parsedBody = $this->parseJsonInput($request);
            
            // --- INPUT: MENGAMBIL UUID ---
            $tripUuid = trim($parsedBody['trip_uuid'] ?? '');
            $rating = (float)($parsedBody['rating'] ?? 0.0);
            $comment = trim($parsedBody['comment'] ?? '');

            // --- 1. VALIDASI INPUT DASAR & AUTORISASI ---
            if ($userId <= 0) {
                throw new \Exception('Unauthorized user.', 401);
            }
            if (empty($tripUuid) || strlen($tripUuid) < 36 || $rating < 1 || $rating > 5 || empty($comment)) {
                throw new \Exception('Valid Trip UUID, rating (1-5), and comment are required.', 400);
            }

            // --- 2. KONVERSI UUID KE ID INTERNAL ---
            // Asumsikan TripModel memiliki getTripIdByUuid(string $uuid): int|false
            $tripId = $this->tripModel->getTripIdByUuid($tripUuid); 

            if (!$tripId) {
                throw new \Exception("Trip with UUID {$tripUuid} not found.", 404);
            }
            
            // --- 3. VALIDASI HAK REVIEW (Menggunakan ID internal) ---
            $validBooking = $this->reviewModel->validateBookingForReview($userId, $tripId, ['completed']);
            
            if (!$validBooking) {
                throw new \Exception('Review failed. You must have a completed booking for this trip, and you must not have reviewed it previously.', 403);
            }
            
            $bookingId = (int)$validBooking['booking_id'];

            // --- 4. SIMPAN REVIEW ---
            $success = $this->reviewModel->createReview($userId, $tripId, $bookingId, $rating, $comment);

            if (!$success) {
                throw new \Exception('Failed to submit review.', 500);
            }

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Thank you for your review! It has been submitted successfully.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            if (str_contains($e->getMessage(), 'must have a completed booking')) { $statusCode = 403; }
            if (str_contains($e->getMessage(), 'not found')) { $statusCode = 404; }
            if ($statusCode < 100 || $statusCode >= 600) { $statusCode = 500; }

            error_log("APP ERROR in ReviewController::submitReview => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }
    
    // =======================================================
    // PENAMBAHAN ENDPOINT: GET /trips/{uuid}/reviews
    // =======================================================
    
    /**
     * Endpoint: GET /trips/{uuid}/reviews
     * Mengambil daftar review untuk trip tertentu berdasarkan UUID-nya.
     */
    public function getReviewsByTrip(Request $request, Response $response, array $args): Response {
        try {
            // Ambil UUID dari URL
            $tripUuid = $args['id'] ?? ''; 
            
            if (empty($tripUuid) || strlen($tripUuid) < 36) {
                throw new \Exception('Valid Trip UUID is required.', 400);
            }
            
            // Panggil Model yang mencari berdasarkan UUID
            $reviews = $this->reviewModel->getReviewsByTripUuid($tripUuid);
            
            $data = array_map(function($review) {
                return [
                    'uuid' => $review['uuid'], // <--- Menggunakan UUID Review
                    'rating' => (float)$review['rating'],
                    'comment' => $review['comment'],
                    'created_at' => $review['created_at'],
                    'user' => [
                        'name' => $review['user_name'],
                        'photo' => $review['user_photo']
                    ]
                ];
            }, $reviews);

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Reviews successfully retrieved.',
                'data' => $data
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            if ($statusCode < 100 || $statusCode >= 600) { $statusCode = 500; }

            error_log("APP ERROR in ReviewController::getReviewsByTrip => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }
}