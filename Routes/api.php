<?php
// Routes/api.php
// Asumsi: $app adalah instance Slim\App

use Slim\Routing\RouteCollectorProxy;

// Import Middleware dan Controller
// use App\Middleware\AuthMiddleware; 
// use App\Middleware\RoleMiddleware;
// use App\Controllers\AuthController;
// use App\Controllers\UserController;
// ... dll.

// Contoh inisialisasi koneksi DB (dari Config/Database.php)
global $db; 

// Fungsi untuk membuat Controller dengan injeksi DB (untuk kesederhanaan)
$container = $app->getContainer();
$container['db'] = fn() => $db; // Asumsikan $db sudah di-setup dari Database.php

// -----------------------------------------------------------
// 1. AUTH (Public Endpoints)
// -----------------------------------------------------------
$app->post('/register', function ($request, $response) use ($db) {
    // return (new AuthController($db))->register($request, $response);
});
$app->post('/login', function ($request, $response) use ($db) {
    // return (new AuthController($db))->login($request, $response);
});


// -----------------------------------------------------------
// 2. PROTECTED ENDPOINTS (Hanya untuk User Terautentikasi)
// -----------------------------------------------------------
$app->group('', function (RouteCollectorProxy $group) use ($db) {
    
    // Group ini akan melalui AuthMiddleware untuk memverifikasi JWT
    $auth = new AuthMiddleware();

    // 2.1. USERS (CRUD)
    $group->get('/users', function ($request, $response) use ($db) {
        // return (new UserController($db))->getAll($request, $response);
    })->add(new RoleMiddleware(['admin'])); // Hanya ADMIN yang boleh melihat list
    
    $group->get('/users/{id}', function ($request, $response) use ($db) {
        // return (new UserController($db))->getById($request, $response);
    });
    // ... PUT dan DELETE /users/{id} juga butuh Auth/Role Middleware

    // 2.2. TRIPS (Provider/Admin: Create, Update, Delete)
    $group->post('/trips', function ($request, $response) use ($db) {
        // return (new TripController($db))->create($request, $response);
    })->add(new RoleMiddleware(['admin', 'provider']));
    
    $group->put('/trips/{id}', function ($request, $response) use ($db) {
        // return (new TripController($db))->update($request, $response);
    })->add(new RoleMiddleware(['admin', 'provider']));
    
    // 2.3. BOOKINGS (Customer: Create)
    $group->post('/bookings', function ($request, $response) use ($db) {
        // return (new BookingController($db))->create($request, $response);
    })->add(new RoleMiddleware(['admin', 'customer']));

    $group->get('/bookings/user/{id}', function ($request, $response) use ($db) {
        // return (new BookingController($db))->getByUser($request, $response);
        // Controller perlu memverifikasi apakah ID user yang diminta sama dengan user yang login (kecuali admin)
    })->add(new RoleMiddleware(['admin', 'customer']));

    // 2.4. REVIEWS (Customer: Create)
    $group->post('/reviews', function ($request, $response) use ($db) {
        // return (new ReviewController($db))->create($request, $response);
    })->add(new RoleMiddleware(['admin', 'customer']));

})->add(AuthMiddleware::class); // Terapkan AuthMiddleware untuk seluruh grup


// -----------------------------------------------------------
// 3. PUBLIC ENDPOINTS (Tanpa Autentikasi)
// -----------------------------------------------------------
$app->get('/trips', function ($request, $response) use ($db) {
    // return (new TripController($db))->getAll($request, $response);
});

$app->get('/trips/{id}', function ($request, $response) use ($db) {
    // return (new TripController($db))->getById($request, $response);
});

$app->get('/reviews/trip/{id}', function ($request, $response) use ($db) {
    // return (new ReviewController($db))->getByTrip($request, $response);
});