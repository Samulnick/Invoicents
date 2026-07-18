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

if (!$data || empty($data['invoiceId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request - invoice ID required']);
    exit();
}

$invoice_id = $data['invoiceId'];

try {
    // Start transaction
    $db->begin_transaction();

    // Verify invoice belongs to user
    $verifyQuery = "SELECT id, invoice_number, status FROM invoices WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($verifyQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param('ii', $invoice_id, $user_id);
    $stmt->execute();
    $verifyResult = $stmt->get_result();
    $stmt->close();

    if ($verifyResult->num_rows === 0) {
        throw new Exception("Invoice not found or unauthorized");
    }

    $invoiceRow = $verifyResult->fetch_assoc();
    $invoiceNumber = $invoiceRow['invoice_number'];
    $invoiceStatus = $invoiceRow['status'];

    // Check if invoice can be deleted (only draft invoices or specific statuses)
    $deletableStatuses = ['draft', 'pending'];
    if (!in_array($invoiceStatus, $deletableStatuses)) {
        throw new Exception("Cannot delete invoice with status: " . $invoiceStatus . ". Only draft invoices can be deleted.");
    }

    // Delete invoice line items first
    $deleteItemsQuery = "DELETE FROM invoice_items WHERE invoice_id = ?";
    $stmt = $db->prepare($deleteItemsQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param('i', $invoice_id);
    if (!$stmt->execute()) {
        throw new Exception("Delete items failed: " . $stmt->error);
    }
    $stmt->close();

    // Delete invoice
    $deleteQuery = "DELETE FROM invoices WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($deleteQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param('ii', $invoice_id, $user_id);
    if (!$stmt->execute()) {
        throw new Exception("Delete invoice failed: " . $stmt->error);
    }
    $stmt->close();

    // Log activity
    $activityQuery = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, 'invoice_deleted', ?, NOW())";
    $activityStmt = $db->prepare($activityQuery);
    if ($activityStmt) {
        $description = "Invoice deleted: " . $invoiceNumber;
        $activityStmt->bind_param('is', $user_id, $description);
        $activityStmt->execute();
        $activityStmt->close();
    }

    // Commit transaction
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Invoice deleted successfully',
        'invoice_id' => $invoice_id,
        'invoice_number' => $invoiceNumber
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
