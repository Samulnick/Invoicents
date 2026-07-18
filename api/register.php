<?php
/**
 * Registration handler - small cleanup and safer binds
 */

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate CSRF token
    if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid security token']);
        exit;
    }

    // Validate input
    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $input['password'] ?? '';
    $confirm_password = $input['confirm_password'] ?? '';
    $first_name = sanitizeInput($input['first_name'] ?? '');
    $last_name = sanitizeInput($input['last_name'] ?? '');

    $errors = [];

    // Validation
    if (!$email) {
        $errors[] = 'Valid email is required';
    }

    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters';
    }

    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match';
    }

    if (strlen($first_name) < 2) {
        $errors[] = 'First name must be at least 2 characters';
    }

    if (strlen($last_name) < 2) {
        $errors[] = 'Last name must be at least 2 characters';
    }

    if (!empty($errors)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'errors' => $errors]);
        exit;
    }

    // Check if email already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email already registered']);
        $stmt->close();
        exit;
    }
    $stmt->close();

    // Hash password
    $password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);


    // Insert new user
    $stmt = $conn->prepare("INSERT INTO users (email, password_hash, first_name, last_name, email_verification_token, email_verification_expires) VALUES (?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }

    $user_id = $conn->insert_id;
    $stmt->close();

    // Create user settings record
    $default_business_name = $first_name . ' ' . $last_name;
    $currency_code = 'NGN';
    $currency_symbol = '₦';

    $stmt = $conn->prepare("INSERT INTO user_settings (user_id, business_name, currency_code, currency_symbol) VALUES (?, ?, ?, ?)");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("isss", $user_id, $default_business_name, $currency_code, $currency_symbol);
    $stmt->execute();
    $stmt->close();

    

    http_response_code(201);
    echo json_encode([
        'success' => true,
        'message' => 'Registration successful! Please check your email to verify your account.',
        'user_id' => $user_id
    ]);

} catch (Exception $e) {
    logError("Registration error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Registration failed. Please try again.']);
}

/**
 * Sanitize input string
 */
function sanitizeInput($input) {
    return trim(htmlspecialchars(strip_tags($input), ENT_QUOTES, 'UTF-8'));
}
?>
