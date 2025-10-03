<?php
// Controllers/ComplaintController.php

require_once __DIR__ . '/../Models/ComplaintModel.php';
// Anda juga mungkin butuh TripModel untuk validasi trip ID
// require_once __DIR__ . '/../Models/TripModel.php'; 

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

class ComplaintController {
    private ComplaintModel $complaintModel;
    // private TripModel $tripModel; // Opsional

    public function __construct(PDO $db) {
        $this->complaintModel = new ComplaintModel($db);
        // $this->tripModel = new TripModel($db); // Opsional
    }
    
    // Anda bisa memindahkan parseJsonInput dari Controller lain ke sini
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
     * Endpoint: POST /complaints
     * Klien mengajukan pengaduan tentang trip tertentu.
     */
    public function submitComplaint(Request $request, Response $response): Response {
        try {
            $jwtData = $request->getAttribute('jwt_data');
            $userId = (int)($jwtData['id'] ?? 0);

            $parsedBody = $this->parseJsonInput($request);
            
            $tripId = (int)($parsedBody['trip_id'] ?? 0);
            $subject = trim($parsedBody['subject'] ?? '');
            $description = trim($parsedBody['description'] ?? '');

            // --- VALIDASI INPUT ---
            if ($userId <= 0) {
                throw new \Exception('Unauthorized: Invalid user.', 401);
            }
            if ($tripId <= 0) {
                throw new \Exception('Trip ID is required for complaint.', 400);
            }
            if (empty($subject) || empty($description)) {
                throw new \Exception('Subject and Description are required.', 400);
            }
            // *Opsional: Cek apakah trip ID valid di TripModel*
            
            // --- SIMPAN PENGADUAN ---
            $success = $this->complaintModel->createComplaint($userId, $tripId, $subject, $description);

            if (!$success) {
                throw new \Exception('Failed to submit complaint to database.', 500);
            }

            $response->getBody()->write(json_encode([
                'status' => 'success',
                'success' => true,
                'message' => 'Complaint submitted successfully. Admin will review your report shortly.'
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);

        } catch (\Exception $e) {
            $statusCode = $e->getCode() ?: 500;
            if ($statusCode < 100 || $statusCode >= 600) { $statusCode = 500; } // Amankan status code

            error_log("APP ERROR in ComplaintController::submitComplaint => " . $e->getMessage());
            
            $response->getBody()->write(json_encode([
                'status' => 'error',
                'success' => false,
                'message' => $e->getMessage()
            ]));
            return $response->withHeader('Content-Type', 'application/json')->withStatus($statusCode);
        }
    }
}