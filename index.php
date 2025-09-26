<?php
// index.php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;

// 1. Memuat Composer Autoloader
// Ini memungkinkan kita menggunakan kelas dari vendor (seperti Slim dan JWT)
// serta kelas dari folder Models, Controllers, dan Middleware.
require __DIR__ . '/vendor/autoload.php';

// 2. Memuat Konfigurasi Database
// Ini membuat koneksi PDO instance ($db) yang akan digunakan oleh Controllers.
require __DIR__ . '/Config/Database.php';

// 3. Import Kelas Middleware dan Controller (jika tidak menggunakan namespace)
// Jika Anda menggunakan namespace, baris ini mungkin tidak diperlukan.
// require __DIR__ . '/Middleware/AuthMiddleware.php';
// require __DIR__ . '/Middleware/RoleMiddleware.php';
// require __DIR__ . '/Controllers/AuthController.php';
// require __DIR__ . '/Controllers/TripController.php';
// ... dll.

// 4. Inisialisasi Slim App
$app = AppFactory::create();

// 5. Tambahkan Middleware untuk Routing
// Harus ada sebelum Route Middleware
$app->addRoutingMiddleware();

// 6. Tambahkan Error Middleware
// Atur agar error ditampilkan (hanya untuk development!)
$errorMiddleware = $app->addErrorMiddleware(true, true, true); 

// 7. Load Routes
// Ini akan memuat semua definisi endpoint yang ada di Routes/api.php
require __DIR__ . '/Routes/api.php';

// 8. Jalankan Aplikasi
$app->run();