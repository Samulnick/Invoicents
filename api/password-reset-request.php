<?php
/**
 * Password reset: request a reset token (sends email)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    // CSRF optional for password reset request (can be enforced if frontend obtains token)
    if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        // For privacy, still accept but return generic message - but here we enforce
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid email is required']);
        exit;
    }

    // Find user
    $stmt = $conn->prepare("SELECT id, first_name, account_status FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // Response is generic to avoid account enumeration
    $genericResponse = ['success' => true, 'message' => 'If an account with that email exists, you will receive a password reset link shortly.'];

    if ($result->num_rows === 0) {
        // Still return generic response
        echo json_encode($genericResponse);
        $stmt->close();
        exit;
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // If account is suspended/inactive, do not reveal - still send generic response
    if (in_array($user['account_status'], ['inactive', 'suspended'])) {
        echo json_encode($genericResponse);
        exit;
    }

    // Create reset token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + (60 * 60)); // 1 hour
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $stmt = $conn->prepare("INSERT INTO password_resets (user_id, reset_token, token_expires, ip_address, user_agent, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
    $stmt->bind_param('issss', $user['id'], $token, $expires, $ip_address, $user_agent);
    $stmt->execute();
    $stmt->close();

    // Build reset link
    $resetLink = rtrim(APP_URL, '/') . '/auth/reset-password.html?token=' . $token;

    // Build email
    $subject = APP_NAME . ' Password Reset Request';
    $body = "Hello " . ($user['first_name'] ?? '') . ",\n\n";
    $body .= "We received a request to reset the password for your account. If you made this request, click the link below to reset your password (valid for 1 hour):\n\n";
    $body .= $resetLink . "\n\n";
    $body .= "If you did not request a password reset, you can safely ignore this message.\n\n";
    $body .= "Regards,\n" . APP_NAME . " Team";

    // Send email (best-effort)
    $sent = sendMail($email, $subject, $body, false);

    if (!$sent) {
        // Log failure but still respond generic
        logError('Failed to send password reset email', ['user_id' => $user['id'], 'email' => $email]);
    }

    echo json_encode($genericResponse);
    exit;

} catch (Exception $e) {
    logError('Password reset request error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to process request']);
}
