<?php
// Invoice Status Update API
// Updates invoice status (draft, sent, paid, overdue, pending, cancelled)

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
$invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : null;
$status = isset($_POST['status']) ? trim($_POST['status']) : null;

if (!$invoice_id || !$status) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID and status are required']);
    exit();
}

// Valid statuses
$valid_statuses = ['draft', 'sent', 'paid', 'overdue', 'pending', 'cancelled'];

if (!in_array($status, $valid_statuses)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status. Must be: ' . implode(', ', $valid_statuses)]);
    exit();
}

try {
    // Verify invoice belongs to user
    $checkQuery = "SELECT id, status as old_status FROM invoices WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($checkQuery);
    $stmt->bind_param('ii', $invoice_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit();
    }

    // Update status
    $updateQuery = "UPDATE invoices SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($updateQuery);
    
    if (!$stmt) {
        throw new Exception($db->error);
    }

    $stmt->bind_param('si', $status, $invoice_id);
    
    if ($stmt->execute()) {
        // Log activity
        logActivity($db, $user_id, 'update_invoice_status', 'invoices', $invoice_id, 
                   'Changed invoice status from ' . $invoice['old_status'] . ' to ' . $status);

        // Log to invoice history
        logInvoiceHistory($db, $invoice_id, $user_id, 'update_status', 
                         ['status' => $invoice['old_status']], 
                         ['status' => $status]);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Invoice status updated to ' . ucfirst($status),
            'old_status' => $invoice['old_status'],
            'new_status' => $status
        ]);
    } else {
        throw new Exception($stmt->error);
    }
    
    $stmt->close();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
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

/**
 * Log invoice history for audit trail
 */
function logInvoiceHistory($db, $invoice_id, $user_id, $action, $old_values, $new_values) {
    $old_values_json = json_encode($old_values);
    $new_values_json = json_encode($new_values);
    
    $historyQuery = "INSERT INTO invoice_history (invoice_id, user_id, action, old_values, new_values) VALUES (?, ?, ?, ?, ?)";
    $stmt = $db->prepare($historyQuery);
    
    if ($stmt) {
        $stmt->bind_param('iisss', $invoice_id, $user_id, $action, $old_values_json, $new_values_json);
        $stmt->execute();
        $stmt->close();
    }
}

?>
