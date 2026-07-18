<?php
/**
 * Login handler - improved session handling and remember-me cookie
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

    $email = filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $input['password'] ?? '';
    $remember_me = !empty($input['remember_me']);

    if (!$email || empty($password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email and password are required']);
        exit;
    }

    // Check login attempts
    $ip_address = $_SERVER['REMOTE_ADDR'];
    $attempt_key = "login_attempts_" . hash('sha256', $email . $ip_address);
    $attempts = $_SESSION[$attempt_key] ?? 0;
    $attempt_time = $_SESSION[$attempt_key . "_time"] ?? 0;

    if ($attempts >= MAX_LOGIN_ATTEMPTS && (time() - $attempt_time) < LOGIN_ATTEMPT_TIMEOUT) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many login attempts. Please try again later.']);
        logActivity(null, 'login_failed', 'Too many attempts from ' . $ip_address);
        exit;
    }

    if ((time() - $attempt_time) > LOGIN_ATTEMPT_TIMEOUT) {
        $_SESSION[$attempt_key] = 0;
    }

    // Fetch user
    $stmt = $conn->prepare("SELECT id, email, password_hash, first_name, last_name, account_status, email_verified FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $_SESSION[$attempt_key] = ($attempts + 1);
        $_SESSION[$attempt_key . "_time"] = time();

        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        logActivity(null, 'login_failed', 'User not found: ' . $email);
        $stmt->close();
        exit;
    }

    $user = $result->fetch_assoc();
    $stmt->close();

    // Check account status
    if ($user['account_status'] === 'inactive') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your account is inactive']);
        exit;
    }

    if ($user['account_status'] === 'suspended') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your account has been suspended']);
        exit;
    }

    if (!$user['email_verified'] && $user['account_status'] === 'pending_verification') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please verify your email first']);
        exit;
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        $_SESSION[$attempt_key] = ($attempts + 1);
        $_SESSION[$attempt_key . "_time"] = time();

        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Invalid email or password']);
        logActivity($user['id'] ?? null, 'login_failed', 'Wrong password');
        exit;
    }

    // Regenerate session id to prevent fixation
    session_regenerate_id(true);

    // Reset login attempts
    $_SESSION[$attempt_key] = 0;

    // Create session
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['first_name'] = $user['first_name'];
    $_SESSION['last_name'] = $user['last_name'];
    $_SESSION['login_time'] = time();

    // Update last login
    $last_login = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("UPDATE users SET last_login = ? WHERE id = ?");
    $stmt->bind_param("si", $last_login, $user['id']);
    $stmt->execute();
    $stmt->close();

    // Store session in database and extend cookie if remember_me
    if ($remember_me) {
        $session_id = session_id();
        $expires = date('Y-m-d H:i:s', time() + REMEMBER_ME_DURATION);

        $stmt = $conn->prepare("INSERT INTO sessions (id, user_id, ip_address, user_agent, last_activity, expires_at) VALUES (?, ?, ?, ?, NOW(), ?)");
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $stmt->bind_param("sisss", $session_id, $user['id'], $ip_address, $user_agent, $expires);
        $stmt->execute();
        $stmt->close();

        // Extend session cookie lifetime
        $params = session_get_cookie_params();
        setcookie(session_name(), session_id(), time() + REMEMBER_ME_DURATION, $params['path'] ?? '/', $params['domain'] ?? '', (APP_ENV === 'production'), true);
    }

    logActivity($user['id'], 'login_success', 'User logged in successfully');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Login successful',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name']
        ]
    ]);

} catch (Exception $e) {
    logError("Login error", ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Login failed. Please try again.']);
}
?>