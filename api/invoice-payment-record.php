<?php
// Invoice Payment Record API
// Record payment for an invoice

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
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : null;
$payment_date = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d');
$payment_method = isset($_POST['payment_method']) ? trim($_POST['payment_method']) : 'transfer';
$reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

if (!$invoice_id || !$amount) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID and amount are required']);
    exit();
}

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Amount must be greater than 0']);
    exit();
}

// Valid payment methods
$valid_methods = ['cash', 'transfer', 'check', 'card', 'cryptocurrency', 'other'];
if (!in_array($payment_method, $valid_methods)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid payment method']);
    exit();
}

try {
    $db->begin_transaction();

    // Verify invoice belongs to user
    $check_query = "SELECT id, total_amount, status FROM invoices WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($check_query);
    $stmt->bind_param('ii', $invoice_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    // Insert payment record
    $insert_query = "
        INSERT INTO payments (invoice_id, user_id, amount, payment_date, payment_method, reference_number, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db->prepare($insert_query);
    $stmt->bind_param('iidssss', $invoice_id, $user_id, $amount, $payment_date, $payment_method, $reference, $notes);

    if (!$stmt->execute()) {
        throw new Exception('Failed to record payment: ' . $stmt->error);
    }

    $stmt->close();

    // Get total paid for this invoice
    $total_query = "SELECT SUM(amount) as total_paid FROM payments WHERE invoice_id = ?";
    $stmt = $db->prepare($total_query);
    $stmt->bind_param('i', $invoice_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $paid = $result->fetch_assoc()['total_paid'] ?? 0;
    $stmt->close();

    // Update invoice status if fully paid
    $new_status = $invoice['status'];
    if ($paid >= $invoice['total_amount']) {
        $new_status = 'paid';
        $update_query = "UPDATE invoices SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $db->prepare($update_query);
        $stmt->bind_param('si', $new_status, $invoice_id);
        $stmt->execute();
        $stmt->close();
    }

    // Log activity
    logActivity($db, $user_id, 'record_payment', 'invoices', $invoice_id, 
               'Recorded payment of ₦' . number_format($amount, 2) . ' via ' . $payment_method);

    // Log to invoice history
    logInvoiceHistory($db, $invoice_id, $user_id, 'record_payment',
                     ['status' => $invoice['status']],
                     ['status' => $new_status, 'amount' => $amount]);

    $db->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Payment recorded successfully',
        'total_paid' => $paid,
        'invoice_total' => $invoice['total_amount'],
        'remaining' => max(0, $invoice['total_amount'] - $paid),
        'invoice_status' => $new_status
    ]);

} catch (Exception $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
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
 * Log invoice history
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
