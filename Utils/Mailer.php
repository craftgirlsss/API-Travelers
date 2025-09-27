<?php
// Utils/Mailer.php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

class MailerUtility {

    // --- KONFIGURASI SMTP ANDA ---
    private const HOST = 'mail.karyadeveloperindonesia.com'; // Ganti dengan Host SMTP Webmail Anda
    private const USERNAME = 'no-reply@karyadeveloperindonesia.com'; // Ganti dengan Email Webmail Anda
    private const PASSWORD = 'Justformeokay23'; // Ganti dengan Password Webmail Anda
    private const PORT = 587; // Umumnya 587 (TLS) atau 465 (SSL)
    private const SENDER_NAME = 'Travelers';
    // ----------------------------

    /**
     * Mengirim email dengan PHPMailer.
     * * @param string $toEmail Email penerima.
     * @param string $subject Subjek email.
     * @param string $body Konten HTML email.
     * @return bool True jika sukses, False jika gagal.
     */
    public static function sendEmail(string $toEmail, string $subject, string $body): bool {
        $mail = new PHPMailer(true);

        try {
            // Konfigurasi Server
            $mail->isSMTP();
            $mail->SMTPDebug  = SMTP::DEBUG_OFF; // Ubah ke DEBUG_SERVER saat testing
            $mail->Host       = self::HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = self::USERNAME;
            $mail->Password   = self::PASSWORD;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Atau ENCRYPTION_SMTPS untuk port 465
            $mail->Port       = self::PORT;

            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];

            // Penerima
            $mail->setFrom(self::USERNAME, self::SENDER_NAME);
            $mail->addAddress($toEmail);
            $mail->addReplyTo(self::USERNAME, 'Reply');
            
            // Konten
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = strip_tags($body); // Versi teks biasa untuk klien non-HTML

            $mail->send();
            return true;
        } catch (Exception $e) {
            // Penting: Catat error ini ke log server, JANGAN tampilkan ke user.
            error_log("Email failed to send to {$toEmail}. Mailer Error: {$mail->ErrorInfo}");
            return false;
        }
    }
    
    /**
     * Helper khusus untuk mengirim kode OTP.
     */
    public static function sendOTPEmail(string $toEmail, string $otpCode): bool {
        $subject = "Kode OTP Reset Password Anda";
        $body = "
            <html>
            <body>
                <h2>Permintaan Reset Password</h2>
                <p>Halo,</p>
                <p>Anda telah meminta reset password. Gunakan kode verifikasi (OTP) di bawah ini:</p>
                <div style='background-color:#f0f0f0; padding: 20px; border-radius: 5px; text-align: center;'>
                    <h1 style='color: #333;'>{$otpCode}</h1>
                </div>
                <p>Kode ini akan kadaluarsa dalam 5 menit. Jangan bagikan kode ini kepada siapa pun.</p>
                <p>Hormat kami,<br>Tim OpenTripku</p>
            </body>
            </html>
        ";
        return self::sendEmail($toEmail, $subject, $body);
    }
}