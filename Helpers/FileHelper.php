<?php
// Helpers/FileHelper.php

use Psr\Http\Message\UploadedFileInterface;

class FileHelper {
    
    /**
     * Menangani upload gambar dan mengembalikan path relatif yang akan disimpan di DB.
     * @param UploadedFileInterface $file Objek file yang diupload.
     * @param string $uploadDir Direktori fisik untuk menyimpan file.
     * @param int $userId ID user (untuk membuat nama file unik).
     * @return string Path relatif file yang disimpan (contoh: 'assets/user/123_profile.jpg')
     */
    public static function uploadImage(UploadedFileInterface $file, string $uploadDir, int $userId): string {
        
        // Pastikan direktori ada
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $basename = 'user_' . $userId . '_' . time(); // Nama file unik berdasarkan ID dan waktu
        $filename = $basename . '.' . $extension;

        // Path lengkap di server
        $filePath = $uploadDir . $filename;
        
        // Pindahkan file
        $file->moveTo($filePath);

        // Path relatif yang akan disimpan di database
        // ASUMSI: Proyek API root adalah satu level di atas direktori 'assets'
        $relativePath = 'assets/user/' . $filename;
        
        return $relativePath;
    }
}