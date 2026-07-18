<?php
/**
 * Password reset request handler - Generates a token and sends PHPMailer email
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);

    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid email address is required']);
        exit;
    }

    // Check if the user exists
    $stmt = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $userResult = $stmt->get_result();

    // To prevent user enumeration attacks, don't reveal if an email doesn't exist
    if ($userResult->num_rows === 0) {
        http_response_code(200);
        echo json_encode(['success' => true, 'message' => 'If that account exists, a reset link has been sent.']);
        $stmt->close();
        exit;
    }

    $user = $userResult->fetch_assoc();
    $stmt->close();

    // Invalidate any older unused reset tokens for this user
    $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0");
    $stmt->bind_param("i", $user['id']);
    $stmt->execute();
    $stmt->close();

    // Generate standard password reset credentials
    $reset_token = bin2hex(random_bytes(32));
    $token_expires = date('Y-m-d H:i:s', strtotime('+1 hour')); // Password resets usually expire faster (e.g., 1 hour)

    // Insert reset token record into password_resets
    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, reset_token, token_expires, used) VALUES (?, ?, ?, 0)");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("iss", $user['id'], $reset_token, $token_expires);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }
    $stmt->close();

    // ==========================================
    // PHPMailer: SEND PASSWORD RESET EMAIL
    // ==========================================
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();                                            
        $mail->Host       = SMTP_HOST;               
        $mail->SMTPAuth   = SMTP_AUTH;                                   
        $mail->Username   = SMTP_USER;      
        $mail->Password   = SMTP_PASS;                      
        $mail->Port       = SMTP_PORT;                                    
        $mail->SMTPSecure = SMTP_ENCRYPTION;    

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $user['first_name'] . ' ' . $user['last_name']);     

        // Path pointing back to the verification endpoint file you shared
        $reset_link = APP_URL . "/reset-password.html?token=" . $reset_token;

        $mail->isHTML(true);                                  
        $mail->Subject = 'Reset Your Password - ' . SMTP_FROM_NAME;
        $mail->Body    = "<h1>Hello, " . htmlspecialchars($user['first_name']) . "</h1>"
                       . "<p>We received a request to reset your password for your account at " . SMTP_FROM_NAME . ".</p>"
                       . "<p>Click the button below to choose a new password:</p>"
                       . "<p><a href='" . $reset_link . "' style='padding: 10px 20px; background-color: #dc3545; color: white; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;'>Reset Password</a></p>"
                       . "<p>This link is only valid for 1 hour. If you did not request this, please ignore this email.</p>";
        $mail->AltBody = "Hello, " . $user['first_name'] . "\n\nWe received a request to reset your password. Use the link below to choose a new password:\n" . $reset_link . "\n\nThis link expires in 1 hour.";

        $mail->send();
    } catch (Exception $e) {
        logError("Password reset email failed to send", ['user_id' => $user['id'], 'mailer_error' => $mail->ErrorInfo]);
        // Throw exception to trigger standard server failure state
        throw new Exception("Email delivery subsystem failed.");
    }
    // ==========================================

    logActivity($user['id'], 'password_reset_request', 'Password reset email dispatched');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'If that account exists, a reset link has been sent.'
    ]);

} catch (Exception $e) {
    logError('Password reset generation failure', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'An error occurred processing your request.']);
}
