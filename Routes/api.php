<?php
// Routes/api.php
// Asumsi: $app adalah instance Slim\App

use Slim\Routing\RouteCollectorProxy;

// Pastikan path ini benar berdasarkan lokasi file Anda
require_once __DIR__ . '/../Controllers/ClientController.php';
require_once __DIR__ . '/../Controllers/BookingController.php';
require_once __DIR__ . '/../Controllers/TripController.php';
require_once __DIR__ . '/../Controllers/AuthController.php';
require_once __DIR__ . '/../Middleware/AuthMiddleware.php';
require_once __DIR__ . '/../Middleware/RoleMiddleware.php';
require_once __DIR__ . '/../Controllers/ComplaintController.php';
require_once __DIR__ . '/../Models/UserModel.php';
require_once __DIR__ . '/../Controllers/ReviewController.php'; 

global $db; 

// -----------------------------------------------------------
// 1. AUTH (Public Endpoints)
// -----------------------------------------------------------
$app->post('/register', function ($request, $response) use ($db) {
    return (new AuthController($db))->register($request, $response); 
});
$app->post('/login', function ($request, $response) use ($db) {
    return (new AuthController($db))->login($request, $response);
});

// Password Reset Flow
$app->post('/forgot-password', function ($request, $response) use ($db) {
    return (new AuthController($db))->forgotPassword($request, $response);
});
$app->post('/verify-otp', function ($request, $response) use ($db) {
    return (new AuthController($db))->verifyOTP($request, $response);
});

// HAPUS RUTE DELETE YANG DI LUAR GROUP DI SINI, pindahkan ke Bawah (Bagian 2)

// --- RUTE BARU: RESEND OTP ---
$app->post('/resend-otp', function ($request, $response) use ($db) {
    return (new AuthController($db))->resendOTP($request, $response);
});

$app->post('/reset-password', function ($request, $response) use ($db) {
    return (new AuthController($db))->resetPassword($request, $response);
});


// -----------------------------------------------------------
// 2. PROTECTED ENDPOINTS (Hanya untuk User Terautentikasi)
// -----------------------------------------------------------

// Buat instance AuthMiddleware
$authMiddlewareInstance = new AuthMiddleware();

$app->group('', function (RouteCollectorProxy $group) use ($db) {
    
    // Inisialisasi Controllers
    $clientController = new ClientController($db);
    $bookingController = new BookingController($db);
    $complaintController = new ComplaintController($db);
    $reviewController = new ReviewController($db);

    // 2.1. USERS (CRUD)
    $group->get('/users', function ($request, $response) use ($db) {
        // return (new UserController($db))->getAll($request, $response);
    })->add(new RoleMiddleware(['admin'])); 
    
    $group->get('/users/{uuid}', function ($request, $response) use ($db) { // Ganti {id} jadi {uuid}
        // return (new UserController($db))->getByUuid($request, $response);
    });

    // 2.5. CLIENT PROFILE (RUTE PROFIL DIRI SENDIRI)
    
    // GET /client/profile (Melihat profile sendiri - TIDAK PAKAI UUID DI URL)
    $group->get('/client/profile', function ($request, $response) use ($clientController) { 
        return $clientController->getAuthenticatedClientProfile($request, $response);
    })->add(new RoleMiddleware(['admin', 'customer']));

    // PUT /client/profile (Edit profile sendiri - TIDAK PAKAI UUID DI URL)
    $group->put('/client/profile', function ($request, $response) use ($clientController) {
        return $clientController->updateAuthenticatedClientProfile($request, $response);
    })->add(new RoleMiddleware(['admin', 'customer']));

    // DELETE /client/account (Nonaktifkan akun sendiri - TIDAK PAKAI UUID DI URL)
    $group->delete('/client/account', function ($request, $response) use ($clientController) { 
        // Menggunakan method yang sudah direvisi
        return $clientController->deactivateAuthenticatedClientAccount($request, $response);
    })->add(new RoleMiddleware(['admin', 'customer'])); 

    // 2.6. COMPLAINTS & REVIEWS
    $group->post('/complaints', function ($request, $response) use ($complaintController) {
        return $complaintController->submitComplaint($request, $response);
    })->add(new RoleMiddleware(['customer']));

    $group->post('/reviews', function ($request, $response) use ($reviewController) {
        return $reviewController->submitReview($request, $response);
    })->add(new RoleMiddleware(['customer']));

    // 2.7. BOOKINGS
    $group->post('/booking', function ($request, $response) use ($bookingController) {
        return $bookingController->createBooking($request, $response);
    })->add(new RoleMiddleware(['customer', 'admin']));

    $group->get('/booking', function ($request, $response) use ($bookingController) {
        return $bookingController->getUserBookings($request, $response);
    })->add(new RoleMiddleware(['customer']));

    $group->get('/booking/{id}', function ($request, $response, $args) use ($bookingController) {
        return $bookingController->getBookingDetail($request, $response, $args);
    })->add(new RoleMiddleware(['customer', 'admin']));
    
    $group->get('/booking/{id}/payment-details', function ($request, $response, $args) use ($bookingController) {
        return $bookingController->getPaymentDetails($request, $response, $args);
    })->add(new RoleMiddleware(['customer', 'admin']));

    $group->put('/booking/cancel/{id}', function ($request, $response, $args) use ($bookingController) {
        return $bookingController->cancelBooking($request, $response, $args);
    })->add(new RoleMiddleware(['customer', 'admin']));
    
})->add($authMiddlewareInstance); // <-- Terapkan AuthMiddleware di sini

// -----------------------------------------------------------
// 3. PUBLIC ENDPOINTS (Tanpa Autentikasi)
// -----------------------------------------------------------
// Routes untuk Trips
$app->get('/trips', function($request, $response) use ($db) {
    $controller = new TripController($db);
    return $controller->getTrips($request, $response);
});

// 🔎 Harus sebelum /trips/{id}
$app->get('/trips/search', function ($request, $response) use ($db) {
    $controller = new TripController($db);
    return $controller->searchTripsByLocation($request, $response);
});

$app->get('/trips/search-gathering-point', function ($request, $response) use ($db) {
    $controller = new TripController($db);
    return $controller->searchTripsByGatheringPoint($request, $response);
});

$app->get('/trips/{uuid}', function ($request, $response, $args) use ($db) {
    $controller = new TripController($db);
    return $controller->getTripDetail($request, $response, $args);
});

$app->get('/trips/{uuid}/reviews', function ($request, $response, $args) use ($db) {
    $reviewController = new ReviewController($db);
    return $reviewController->getReviewsByTrip($request, $response, $args);
});
