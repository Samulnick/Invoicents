<?php
// Profile Update API
// Handles profile information and password changes

session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once '../config/db.php';

$user_id = $_SESSION['user_id'];
$action = isset($_POST['action']) ? $_POST['action'] : null;

try {
    if ($action === 'update_profile') {
        updateProfile($db, $user_id);
    } elseif ($action === 'update_password') {
        updatePassword($db, $user_id);
    } elseif ($action === 'update_business') {
        updateBusinessInfo($db, $user_id);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Update user profile information
 */
function updateProfile($db, $user_id) {
    // Validate inputs
    $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : null;
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : null;
    $email = isset($_POST['email']) ? trim($_POST['email']) : null;
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : null;

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'First name, last name, and email are required']);
        exit();
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid email format']);
        exit();
    }

    // Check if email already exists (excluding current user)
    $checkEmailQuery = "SELECT id FROM users WHERE email = ? AND id != ?";
    $stmt = $db->prepare($checkEmailQuery);
    $stmt->bind_param('si', $email, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Email already in use']);
        exit();
    }
    $stmt->close();

    // Update profile
    $updateQuery = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($updateQuery);
    
    if (!$stmt) {
        throw new Exception($db->error);
    }

    $stmt->bind_param('ssssi', $first_name, $last_name, $email, $phone, $user_id);
    
    if ($stmt->execute()) {
        logActivity($db, $user_id, 'update_profile', 'users', $user_id, 'Updated profile information');
        echo json_encode(['success' => true, 'message' => 'Profile updated successfully']);
    } else {
        throw new Exception($stmt->error);
    }
    
    $stmt->close();
}

/**
 * Update user password
 */
function updatePassword($db, $user_id) {
    $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : null;
    $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : null;
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : null;

    // Validation
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'All password fields are required']);
        exit();
    }

    if ($new_password !== $confirm_password) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'New passwords do not match']);
        exit();
    }

    if (strlen($new_password) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long']);
        exit();
    }

    // Get current password hash
    $getUserQuery = "SELECT password_hash FROM users WHERE id = ?";
    $stmt = $db->prepare($getUserQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }

    // Verify current password
    if (!password_verify($current_password, $user['password_hash'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect']);
        exit();
    }

    // Hash new password
    $password_hash = password_hash($new_password, PASSWORD_BCRYPT);

    // Update password
    $updateQuery = "UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($updateQuery);
    
    if (!$stmt) {
        throw new Exception($db->error);
    }

    $stmt->bind_param('si', $password_hash, $user_id);
    
    if ($stmt->execute()) {
        logActivity($db, $user_id, 'update_password', 'users', $user_id, 'Changed password');
        echo json_encode(['success' => true, 'message' => 'Password updated successfully']);
    } else {
        throw new Exception($stmt->error);
    }
    
    $stmt->close();
}

/**
 * Update business information
 */
function updateBusinessInfo($db, $user_id) {
    $business_name = isset($_POST['business_name']) ? trim($_POST['business_name']) : null;
    $business_address = isset($_POST['business_address']) ? trim($_POST['business_address']) : null;
    $business_phone = isset($_POST['business_phone']) ? trim($_POST['business_phone']) : null;
    $business_email = isset($_POST['business_email']) ? trim($_POST['business_email']) : null;
    $currency = isset($_POST['currency']) ? trim($_POST['currency']) : '₦ NGN';
    $tax_rate = isset($_POST['tax_rate']) ? floatval($_POST['tax_rate']) : 0;
    $payment_terms = isset($_POST['payment_terms']) ? trim($_POST['payment_terms']) : 'Net 30';

    // Validation
    if (empty($business_name)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Business name is required']);
        exit();
    }

    if ($tax_rate < 0 || $tax_rate > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Tax rate must be between 0 and 100']);
        exit();
    }

    // Update business info
    $updateQuery = "UPDATE users SET business_name = ?, business_address = ?, business_phone = ?, business_email = ?, currency = ?, tax_rate = ?, payment_terms = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($updateQuery);
    
    if (!$stmt) {
        throw new Exception($db->error);
    }

    $stmt->bind_param('sssssdsi', $business_name, $business_address, $business_phone, $business_email, $currency, $tax_rate, $payment_terms, $user_id);
    
    if ($stmt->execute()) {
        logActivity($db, $user_id, 'update_business_info', 'users', $user_id, 'Updated business information');
        echo json_encode(['success' => true, 'message' => 'Business information updated successfully']);
    } else {
        throw new Exception($stmt->error);
    }
    
    $stmt->close();
}

/**
 * Log user activity
 */
function logActivity($db, $user_id, $action, $entity_type, $entity_id, $description) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $logQuery = "INSERT INTO activity_logs (user_id, action, entity_type, entity_id, description, ip_address) VALUES (?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($logQuery);
    
    if ($stmt) {
        $stmt->bind_param('isiiis', $user_id, $action, $entity_type, $entity_id, $description, $ip_address);
        $stmt->execute();
        $stmt->close();
    }
}

?>
