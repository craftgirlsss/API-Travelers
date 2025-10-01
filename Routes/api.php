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
require_once __DIR__ . '/../Models/UserModel.php';

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
$app->post('/reset-password', function ($request, $response) use ($db) {
    return (new AuthController($db))->resetPassword($request, $response);
});


// -----------------------------------------------------------
// 2. PROTECTED ENDPOINTS (Hanya untuk User Terautentikasi)
// -----------------------------------------------------------

// Buat instance AuthMiddleware
$authMiddlewareInstance = new AuthMiddleware();

$app->group('', function (RouteCollectorProxy $group) use ($db) {
    
    // PENTING: Gunakan ClientController langsung
    $clientController = new ClientController($db);
    
    // 2.1. USERS (CRUD)
    $group->get('/users', function ($request, $response) use ($db) {
        // return (new UserController($db))->getAll($request, $response);
    })->add(new RoleMiddleware(['admin'])); 
    
    $group->get('/users/{uuid}', function ($request, $response) use ($db) { // Ganti {id} jadi {uuid}
        // return (new UserController($db))->getByUuid($request, $response);
    });

    // 2.5. CLIENT PROFILE
    // PENTING: Ganti {id} menjadi {uuid} untuk keamanan
    $group->get('/client/profile/{uuid}', function ($request, $response, $args) use ($clientController) { 
        return $clientController->getClientProfile($request, $response, $args);
    })->add(new RoleMiddleware(['admin', 'customer'])); // Role check untuk membatasi akses

    // --- BARU: ENDPOINT EDIT DATA DIRI ---
    $group->put('/client/profile/{uuid}', function ($request, $response, $args) use ($clientController) {
        return $clientController->updateClientProfile($request, $response, $args);
    })->add(new RoleMiddleware(['admin', 'customer'])); // Role yang sama dengan GET

    // Rute Protected lainnya (Trips, Bookings, Reviews)
    // ... (rute 2.2, 2.3, 2.4 Anda sebelumnya)
    $group->post('/booking', function ($request, $response) use ($db) { // Hapus $args
        return (new BookingController($db))->createBooking($request, $response);
    })->add(new RoleMiddleware(['customer', 'admin'])); // Tambahkan admin (opsional)

    $group->get('/booking', function ($request, $response, $args) use ($db) {
        return (new BookingController($db))->getUserBookings($request, $response, $args);
    })->add(new RoleMiddleware(['customer']));
    
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

$app->get('/trips/{id}', function ($request, $response, $args) use ($db) {
    $controller = new TripController($db);
    return $controller->getTripDetail($request, $response, $args);
});

$app->get('/reviews/trip/{id}', function ($request, $response) use ($db) {
    // return (new ReviewController($db))->getByTrip($request, $response);
});