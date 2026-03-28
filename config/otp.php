<?php
/**
 * 1. Generate a secure 6-digit OTP and store in session
 */
function generateOtp(string $email): string {
    // Using random_int for cryptographic security
    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    $_SESSION['otp'] = [
        'code'       => $otp,
        'email'      => $email,
        'expires_at' => time() + 600 // 10 minutes
    ];
    
    return $otp;
}

/**
 * 2. Verify OTP with timing-attack protection
 */
function verifyOtp(string $email, string $otp): bool {
    if (empty($_SESSION['otp'])) {
        return false;
    }
    
    $stored = $_SESSION['otp'];
    
    // Check if expired
    if (time() > $stored['expires_at']) {
        clearOtp();
        return false;
    }

    // Check email and code (using hash_equals to prevent timing attacks)
    if ($stored['email'] === $email && hash_equals($stored['code'], $otp)) {
        return true;
    }

    return false;
}

/**
 * 3. Clear OTP data from session
 */
function clearOtp(): void {
    unset($_SESSION['otp']);
}

/**
 * 4. Improved Email Sender with Local Logging
 */
function sendOtpEmail(string $toEmail, string $otp): bool {
    // Check if running on localhost (XAMPP)
    $isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);

    if ($isLocal) {
        // Log the OTP to a text file in your project root
        $logMessage = "[" . date('Y-m-d H:i:s') . "] To: $toEmail | OTP: $otp" . PHP_EOL;
        // Adjust the path as needed for your specific folder structure
        @file_put_contents(__DIR__ . '/otp_debug.txt', $logMessage, FILE_APPEND);
        
        return true; 
    }

    // Production Email Logic
    $subject  = 'JSTACK — Your Verification Code';
    $message  = "
    <html>
    <body style='font-family: sans-serif; line-height: 1.6;'>
        <div style='max-width: 500px; margin: auto; border: 1px solid #eee; padding: 20px;'>
            <h2 style='color:#0a66c2; text-align: center;'>JSTACK Verification</h2>
            <p>Hello,</p>
            <p>Use the code below to verify your email address and complete your registration:</p>
            <div style='background: #f4f4f4; padding: 15px; text-align: center; font-size: 32px; font-weight: bold; letter-spacing: 10px;'>
                {$otp}
            </div>
            <p style='font-size: 12px; color: #888; text-align: center; margin-top: 20px;'>
                This code expires in 10 minutes. If you didn't request this, please ignore this email.
            </p>
        </div>
    </body>
    </html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: JSTACK <noreply@jstack.com>\r\n";

    // Use @ to suppress mail server warnings if mail() is not configured
    return @mail($toEmail, $subject, $message, $headers);
}