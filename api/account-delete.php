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

if (!$data || empty($data['confirm'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

try {
    // Start transaction
    $db->begin_transaction();

    // Get user info for logging
    $userQuery = "SELECT email FROM users WHERE id = ?";
    $stmt = $db->prepare($userQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $userRow = $userResult->fetch_assoc();
    $stmt->close();
    $userEmail = $userRow['email'];

    // Delete all invoices and related items
    $getInvoicesQuery = "SELECT id FROM invoices WHERE user_id = ?";
    $stmt = $db->prepare($getInvoicesQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $invoicesResult = $stmt->get_result();
    $invoiceIds = [];
    while ($row = $invoicesResult->fetch_assoc()) {
        $invoiceIds[] = $row['id'];
    }
    $stmt->close();

    // Delete invoice items
    if (!empty($invoiceIds)) {
        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $deleteItemsQuery = "DELETE FROM invoice_items WHERE invoice_id IN ($placeholders)";
        $stmt = $db->prepare($deleteItemsQuery);
        $types = str_repeat('i', count($invoiceIds));
        $stmt->bind_param($types, ...$invoiceIds);
        $stmt->execute();
        $stmt->close();

        // Delete invoices
        $deleteInvoicesQuery = "DELETE FROM invoices WHERE user_id = ?";
        $stmt = $db->prepare($deleteInvoicesQuery);
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->close();
    }

    // Delete all customers
    $deleteCustomersQuery = "DELETE FROM customers WHERE user_id = ?";
    $stmt = $db->prepare($deleteCustomersQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();

    // Delete activity logs
    $deleteLogsQuery = "DELETE FROM activity_logs WHERE user_id = ?";
    $stmt = $db->prepare($deleteLogsQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();

    // Delete user
    $deleteUserQuery = "DELETE FROM users WHERE id = ?";
    $stmt = $db->prepare($deleteUserQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();

    // Commit transaction
    $db->commit();

    // Destroy session
    session_destroy();

    echo json_encode([
        'success' => true,
        'message' => 'Account deleted successfully',
        'email' => $userEmail
    ]);

} catch (Exception $e) {
    // Rollback transaction on error
    if ($db->connect_errno === 0) {
        $db->rollback();
    }

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}
?>
