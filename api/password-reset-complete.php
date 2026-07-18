<?php
/**
 * Complete password reset: accept token and new password
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../lib/mailer.php'; // Included to handle security alert email dispatch

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true);

    // Require CSRF
    if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    $token = $input['token'] ?? '';
    $password = $input['password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';

    if (empty($token) || empty($password) || empty($confirm_password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Token and password are required']);
        exit;
    }

    if ($password !== $confirm_password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
        exit;
    }

    // Password strength
    if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[@$!%*?&])[A-Za-z\\d@$!%*?&]{8,}$/', $password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password must contain uppercase, lowercase, number, and special character']);
        exit;
    }

    // Find token
    $stmt = $conn->prepare("SELECT id, user_id, token_expires, used FROM password_resets WHERE reset_token = ? LIMIT 1");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invalid token']);
        $stmt->close();
        exit;
    }

    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row['used']) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'Token already used']);
        exit;
    }

    if (strtotime($row['token_expires']) < time()) {
        http_response_code(410);
        echo json_encode(['success' => false, 'message' => 'Token expired']);
        exit;
    }

    // Update user's password
    $new_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $user_id = $row['user_id'];

    $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->bind_param('si', $new_hash, $user_id);
    $stmt->execute();
    $stmt->close();

    // Mark token used
    $stmt = $conn->prepare("UPDATE password_resets SET used = 1, used_at = NOW() WHERE reset_token = ?");
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $stmt->close();

    // Invalidate other tokens for this user
    $stmt = $conn->prepare("UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0 AND reset_token != ?");
    $stmt->bind_param('is', $user_id, $token);
    $stmt->execute();
    $stmt->close();

    // Fetch user details to send security alert
    $stmt = $conn->prepare("SELECT email, first_name FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $userResult = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    // ==========================================
    // DISPATCH SECURITY NOTIFICATION EMAIL
    // ==========================================
    if (!empty($userResult['email'])) {
        $subject = '[' . APP_NAME . '] Security Alert: Password Changed';
        
        $body = "<h1>Hello " . htmlspecialchars($userResult['first_name'] ?? '') . ",</h1>"
              . "<p>This is a security confirmation that your account password for <strong>" . APP_NAME . "</strong> has been successfully changed.</p>"
              . "<p>If you authorized this change, no further action is necessary.</p>"
              . "<p style='color: #dc3545; font-weight: bold;'>If you did not request this modification, please contact our support team immediately to secure your account.</p>"
              . "<p>Regards,<br>" . APP_NAME . " Security Team</p>";

        // Dispatched safely as an HTML message via our mail library abstraction layer
        sendMail($userResult['email'], $subject, $body, true);
    }
    // ==========================================

    logActivity($user_id, 'password_reset', 'User reset password via token');

    echo json_encode(['success' => true, 'message' => 'Password has been reset successfully']);
    exit;

} catch (Exception $e) {
    logError('Password reset complete error', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to reset password']);
}
