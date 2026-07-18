<?php
/**
 * Invoicent SaaS Application
 * Authentication Middleware/Helper
 */

require_once __DIR__ . '/../config/config.php';

/**
 * Check if user is authenticated
 * Redirect to login if not
 */
function requireLogin() {
    // Start session if not already initialized across entry point files
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}

/**
 * Get current authenticated user
 */
function getCurrentUser() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['user_id'])) {
        return null;
    }
    
    global $conn;
    
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("SELECT id, email, first_name, last_name FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    return $user;
}

/**
 * Get user settings
 */
function getUserSettings($user_id) {
    global $conn;
    
    $stmt = $conn->prepare("SELECT * FROM user_settings WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $settings = $result->fetch_assoc();
    $stmt->close();
    
    return $settings;
}

/**
 * Check session timeout
 */
function checkSessionTimeout() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (empty($_SESSION['login_time'])) {
        return false;
    }
    
    // Fallback definition check for safety if constant isn't defined yet
    $session_timeout = defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT : 1800;
    $elapsed_time = time() - $_SESSION['login_time'];
    
    if ($elapsed_time > $session_timeout) {
        session_unset();
        session_destroy();
        return false;
    }
    
    // Update login time to extend session
    $_SESSION['login_time'] = time();
    return true;
}

/**
 * Validate API request (Optimized for multiple reads)
 */
function validateAPIRequest() {
    requireLogin();
    
    // Validate session expiry first
    if (!checkSessionTimeout()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Session expired']);
        exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
        // Read stream once and store inside global application memory context
        $rawData = file_get_contents('php://input');
        $input = json_decode($rawData, true) ?: [];
        $GLOBALS['API_DATA'] = $input; 
        
        if (empty($input['csrf_token']) || !verifyCSRFToken($input['csrf_token'])) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Invalid security token']);
            exit;
        }
    }
}
?>
