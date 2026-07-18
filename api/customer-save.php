<?php
// Start session and check authentication
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

// Get JSON data from request
$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

// Validate required fields
if (empty($data['name']) || empty($data['email'])) {
    echo json_encode(['success' => false, 'message' => 'Customer name and email are required']);
    exit();
}

// Validate email format
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit();
}

try {
    // Check if updating or creating new customer
    $customerId = $data['customerId'] ?? null;

    if ($customerId) {
        // Update existing customer
        $updateQuery = "
            UPDATE customers 
            SET name = ?, email = ?, phone = ?, address = ?, city = ?, notes = ?, updated_at = NOW()
            WHERE id = ? AND user_id = ?
        ";
        
        $stmt = $db->prepare($updateQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param(
            'sssssii',
            $data['name'],
            $data['email'],
            $data['phone'],
            $data['address'],
            $data['city'],
            $data['notes'],
            $customerId,
            $user_id
        );

        if (!$stmt->execute()) {
            throw new Exception("Update failed: " . $stmt->error);
        }

        if ($stmt->affected_rows === 0) {
            throw new Exception("Customer not found or unauthorized");
        }

        $stmt->close();

        echo json_encode([
            'success' => true,
            'message' => 'Customer updated successfully!',
            'customer_id' => $customerId
        ]);

    } else {
        // Create new customer
        // Check if customer with same email already exists
        $checkQuery = "SELECT id FROM customers WHERE user_id = ? AND email = ?";
        $stmt = $db->prepare($checkQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param('is', $user_id, $data['email']);
        $stmt->execute();
        $checkResult = $stmt->get_result();
        $stmt->close();

        if ($checkResult->num_rows > 0) {
            echo json_encode(['success' => false, 'message' => 'A customer with this email already exists']);
            exit();
        }

        // Insert new customer
        $insertQuery = "
            INSERT INTO customers (user_id, name, email, phone, address, city, notes, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ";

        $stmt = $db->prepare($insertQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param(
            'issssss',
            $user_id,
            $data['name'],
            $data['email'],
            $data['phone'],
            $data['address'],
            $data['city'],
            $data['notes']
        );

        if (!$stmt->execute()) {
            throw new Exception("Insert failed: " . $stmt->error);
        }

        $customer_id = $stmt->insert_id;
        $stmt->close();

        // Log activity
        $activityQuery = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, 'customer_created', ?, NOW())";
        $activityStmt = $db->prepare($activityQuery);
        if ($activityStmt) {
            $description = "New customer added: " . $data['name'];
            $activityStmt->bind_param('is', $user_id, $description);
            $activityStmt->execute();
            $activityStmt->close();
        }

        echo json_encode([
            'success' => true,
            'message' => 'Customer added successfully!',
            'customer_id' => $customer_id
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
