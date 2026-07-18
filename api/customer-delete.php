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

if (!$data || empty($data['customerId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request - customer ID required']);
    exit();
}

$customer_id = $data['customerId'];

try {
    // Start transaction
    $db->begin_transaction();

    // Verify customer belongs to user
    $verifyQuery = "SELECT id, name FROM customers WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($verifyQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param('ii', $customer_id, $user_id);
    $stmt->execute();
    $verifyResult = $stmt->get_result();
    $stmt->close();

    if ($verifyResult->num_rows === 0) {
        throw new Exception("Customer not found or unauthorized");
    }

    $customerRow = $verifyResult->fetch_assoc();
    $customerName = $customerRow['name'];

    // Check if customer has invoices
    $invoiceCheckQuery = "SELECT COUNT(*) as count FROM invoices WHERE customer_id = ?";
    $stmt = $db->prepare($invoiceCheckQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param('i', $customer_id);
    $stmt->execute();
    $invoiceCheckResult = $stmt->get_result();
    $invoiceRow = $invoiceCheckResult->fetch_assoc();
    $stmt->close();

    $invoiceCount = $invoiceRow['count'];

    // If customer has invoices, do soft delete (mark as deleted)
    if ($invoiceCount > 0) {
        $softDeleteQuery = "UPDATE customers SET is_deleted = 1, deleted_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($softDeleteQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param('ii', $customer_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Soft delete failed: " . $stmt->error);
        }
        $stmt->close();

        $deleteType = 'soft_deleted';
        $message = 'Customer marked as deleted (has associated invoices)';
    } else {
        // No invoices, perform hard delete
        $deleteQuery = "DELETE FROM customers WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($deleteQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }

        $stmt->bind_param('ii', $customer_id, $user_id);
        if (!$stmt->execute()) {
            throw new Exception("Delete failed: " . $stmt->error);
        }
        $stmt->close();

        $deleteType = 'hard_deleted';
        $message = 'Customer deleted successfully';
    }

    // Log activity
    $activityQuery = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, 'customer_deleted', ?, NOW())";
    $activityStmt = $db->prepare($activityQuery);
    if ($activityStmt) {
        $description = "Customer deleted: " . $customerName . " (Type: " . $deleteType . ")";
        $activityStmt->bind_param('is', $user_id, $description);
        $activityStmt->execute();
        $activityStmt->close();
    }

    // Commit transaction
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => $message,
        'customer_id' => $customer_id,
        'customer_name' => $customerName,
        'delete_type' => $deleteType,
        'had_invoices' => ($invoiceCount > 0)
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
