<?php
// Helpers/FileHelper.php

use Psr\Http\Message\UploadedFileInterface;

class FileHelper {
    
    /**
     * Menangani upload gambar dan mengembalikan path RELATIF (contoh: 'assets/user/123_profile.jpg')
     */
    public static function uploadImage(UploadedFileInterface $file, string $uploadDir, int $userId): string {
        
        // Validasi tipe file dasar
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp'];
        $mimeType = $file->getClientMediaType();
        
        if (!in_array($mimeType, $allowedMimeTypes)) {
            throw new \Exception("Tipe file tidak didukung: $mimeType. Hanya JPG, PNG, WEBP.");
        }

        // Pastikan direktori ada
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $basename = 'user_' . $userId . '_' . time(); 
        $filename = $basename . '.' . $extension;

        // Path lengkap di server
        $filePath = $uploadDir . $filename;
        
        // Pindahkan file
        $file->moveTo($filePath);

        // Path relatif yang akan disimpan di database
        // (Asumsi /assets/user/ bisa diakses publik oleh server)
        $relativePath = 'assets/user/' . $filename;
        
        return $relativePath;
    }
}