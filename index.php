<?php
// index.php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpMethodNotAllowedException;

require __DIR__ . '/vendor/autoload.php';
// Pastikan path ke konfigurasi Database Anda benar
require __DIR__ . '/Config/Database.php'; 

$app = AppFactory::create();

// URUTAN MIDDLEWARE SANGAT PENTING!
$app->addRoutingMiddleware();
$app->addBodyParsingMiddleware();

// Aktifkan Error Middleware: (displayErrorDetails, logErrors, logErrorDetails)
// Set ketiganya ke 'true' selama pengembangan (development)
$errorMiddleware = $app->addErrorMiddleware(true, true, true);

// ----------------------------------------------------
// ## CUSTOM ERROR HANDLERS (JSON Output)
// ----------------------------------------------------

/**
 * Handler Khusus untuk Not Found (404)
 * Menangkap HttpNotFoundException.
 */
$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    // 1. Buat respons 404
    $response = $app->getResponseFactory()->createResponse(404);
    
    // 2. Definisikan payload JSON
    $payload = [
        "status" => "failed",
        "success" => false,
        "message" => "Endpoint tidak ditemukan. Periksa kembali URL Anda."
    ];
    
    // 3. Tulis JSON ke body respons
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
    
    // 4. KUNCI: Set Content-Type menjadi application/json
    return $response->withHeader("Content-Type", "application/json"); 
});

/**
 * Handler Khusus untuk Method Not Allowed (405)
 * Menangkap HttpMethodNotAllowedException.
 */
$errorMiddleware->setErrorHandler(HttpMethodNotAllowedException::class, function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $response = $app->getResponseFactory()->createResponse(405);
    $payload = [
        "status" => "failed",
        "success" => false,
        "message" => "Metode HTTP tidak diperbolehkan untuk endpoint ini."
    ];
    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
    return $response->withHeader("Content-Type", "application/json");
});

/**
 * Default Handler (500) untuk semua Exception lain.
 * Penting agar semua error yang tidak tertangkap di atas juga keluar JSON.
 */
$errorMiddleware->setDefaultErrorHandler(function (
    Request $request,
    Throwable $exception,
    bool $displayErrorDetails,
    bool $logErrors,
    bool $logErrorDetails
) use ($app) {
    $response = $app->getResponseFactory()->createResponse(500);

    // Tentukan pesan error (detail hanya ditampilkan jika $displayErrorDetails true)
    $errorMessage = $displayErrorDetails ? $exception->getMessage() : "Terjadi kesalahan pada server.";
    
    $payload = [
        "status" => "error", // Diubah menjadi 'error' agar berbeda dari 4xx
        "success" => false,
        "message" => $errorMessage
    ];

    $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));

    // KUNCI: Set Content-Type menjadi application/json
    return $response->withHeader("Content-Type", "application/json");
});

// ----------------------------------------------------

// Pastikan file ini berisi definisi route Anda
require __DIR__ . '/Routes/api.php';

$app->run();