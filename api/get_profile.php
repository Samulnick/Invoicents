<?php
/**
 * Invoicent - Fetch User Profile Endpoint
 * Path: api/get_profile.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/auth.php'; // Include your authentication helpers

// Secure endpoint using your centralized authentication middleware
requireLogin();

try {
    // Session context is validated and safe to extract
    $userId = intval($_SESSION['user_id']);
    
    // Fetch only safe presentation data metrics matching your exact schema columns
    $query = "SELECT email, first_name, last_name, phone, account_status, DATE_FORMAT(created_at, '%M %d, %Y') as joined_date FROM users WHERE id = ?";
    $stmt = executeQuery($conn, $query, [$userId], 'i');
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        
        // Generate an optional custom placeholder fallback if name records are missing
        $initials = 'U';
        if (!empty($user['first_name']) && !empty($user['last_name'])) {
            $initials = strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1));
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'email' => $user['email'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'phone' => $user['phone'] ?? '',
                'status' => ucfirst($user['account_status']),
                'joined' => $user['joined_date'],
                'initials' => $initials
            ]
        ]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User profile data matrix missing.']);
    }
    $stmt->close();
} catch (Exception $e) {
    logError("Profile Retrieval failure: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'System routing server error.']);
}
