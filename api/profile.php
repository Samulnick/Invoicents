<?php
/**
 * Invoicent - Update User Profile & Security Password Endpoint
 * Path: api/profile.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php'; // Included for secure centralized validations

// Enforces login verification, active timeouts, and CSRF compliance checks automatically
validateAPIRequest();

// Extract payload arrays safely out of global memory context initialized by the middleware
$input = $GLOBALS['API_DATA'] ?? [];
$userId = intval($_SESSION['user_id']);
$action = $input['action'] ?? '';

try {
    // Branch 1: Update Information Form
    if ($action === 'update_info') {
        $firstName = trim($input['first_name'] ?? '');
        $lastName = trim($input['last_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');

        if (empty($email)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email address field cannot remain blank.']);
            exit;
        }

        // Enforce uniqueness validation check on target email updates
        $emailCheck = executeQuery($conn, "SELECT id FROM users WHERE email = ? AND id != ?", [$email, $userId], 'si');
        if ($emailCheck->get_result()->num_rows > 0) {
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'This email address is already bound to another profile.']);
            exit;
        }
        $emailCheck->close();

        // Save modifications to database
        $updateQuery = "UPDATE users SET first_name = ?, last_name = ?, email = ?, phone = ? WHERE id = ?";
        $stmt = executeQuery($conn, $updateQuery, [$firstName, $lastName, $email, $phone, $userId], 'ssssi');
        $stmt->close();

        // Update working session variables to avoid state misalignment
        $_SESSION['user_email'] = $email;

        echo json_encode(['success' => true, 'message' => 'Profile information data updated cleanly.']);
        exit;
    }

    // Branch 2: Handle Secure Password Changing Modifications
    if ($action === 'change_password') {
        $currentPass = $input['current_password'] ?? '';
        $newPass = $input['new_password'] ?? '';

        if (empty($currentPass) || empty($newPass)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Password configuration constraints cannot be empty.']);
            exit;
        }

        // Pull stored cryptographic credential records
        $authCheck = executeQuery($conn, "SELECT password_hash FROM users WHERE id = ?", [$userId], 'i');
        $userRow = $authCheck->get_result()->fetch_assoc();
        $authCheck->close();

        if (!$userRow || !password_verify($currentPass, $userRow['password_hash'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Verification failed: Current password mismatch.']);
            exit;
        }

        // Generate clean modern crypt hash strings before storage write operations
        $newHash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $passUpdate = executeQuery($conn, "UPDATE users SET password_hash = ? WHERE id = ?", [$newHash, $userId], 'si');
        $passUpdate->close();

        echo json_encode(['success' => true, 'message' => 'Password settings reassigned successfully.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Undefined transactional router request action.']);
} catch (Exception $e) {
    logError("Profile Management Fault: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal database engine exception triggered.']);
}
