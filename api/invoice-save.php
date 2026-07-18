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
$required = ['action', 'invoiceNumber', 'invoiceDate', 'dueDate', 'status', 'customerName', 'customerEmail'];
foreach ($required as $field) {
    if (empty($data[$field])) {
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit();
    }
}

// Validate line items
if (empty($data['lineItems']) || !is_array($data['lineItems'])) {
    echo json_encode(['success' => false, 'message' => 'At least one line item is required']);
    exit();
}

// Validate email
if (!filter_var($data['customerEmail'], FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer email']);
    exit();
}

try {
    // Start transaction
    $db->begin_transaction();

    // Check if customer exists or create new one
    $customerQuery = "SELECT id FROM customers WHERE user_id = ? AND name = ?";
    $stmt = $db->prepare($customerQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }
    
    $stmt->bind_param('is', $user_id, $data['customerName']);
    $stmt->execute();
    $customerResult = $stmt->get_result();
    $stmt->close();

    if ($customerResult->num_rows > 0) {
        $customerRow = $customerResult->fetch_assoc();
        $customer_id = $customerRow['id'];
        
        // Update existing customer
        $updateCustomerQuery = "UPDATE customers SET email = ?, phone = ?, address = ?, updated_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($updateCustomerQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        
        $stmt->bind_param('sssii', $data['customerEmail'], $data['customerPhone'], $data['customerAddress'], $customer_id, $user_id);
        $stmt->execute();
        $stmt->close();
    } else {
        // Create new customer
        $createCustomerQuery = "INSERT INTO customers (user_id, name, email, phone, address, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($createCustomerQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        
        $stmt->bind_param('issss', $user_id, $data['customerName'], $data['customerEmail'], $data['customerPhone'], $data['customerAddress']);
        $stmt->execute();
        $customer_id = $stmt->insert_id;
        $stmt->close();
    }

    // Insert invoice
    $invoiceQuery = "INSERT INTO invoices (user_id, customer_id, invoice_number, created_date, due_date, status, subtotal, tax_rate, tax_amount, discount_percent, total_amount, payment_terms, notes, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    $stmt = $db->prepare($invoiceQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param(
        'iissssdddddss',
        $user_id,
        $customer_id,
        $data['invoiceNumber'],
        $data['invoiceDate'],
        $data['dueDate'],
        $data['status'],
        $data['subtotal'],
        $data['taxRate'],
        $data['taxAmount'],
        $data['discountPercent'],
        $data['grandTotal'],
        $data['paymentTerms'],
        $data['notes']
    );
    $stmt->execute();
    $invoice_id = $stmt->insert_id;
    $stmt->close();

    // Insert line items
        // Insert line items (UPDATED COLUMN NAMES TO MATCH DATABASE)
    $lineItemQuery = "INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, total_price) VALUES (?, ?, ?, ?, ?)";
    $stmt = $db->prepare($lineItemQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    foreach ($data['lineItems'] as $item) {
        $amount = floatval($item['quantity']) * floatval($item['rate']);
        // FIXED: Added 'd' type definition and passed the missing $amount variable
        $stmt->bind_param('isddd', $invoice_id, $item['description'], $item['quantity'], $item['rate'], $amount);
        if (!$stmt->execute()) {
            throw new Exception("Line item insert failed: " . $stmt->error);
        }
    }
    $stmt->close();


    // If action is "send", create activity log and optionally send email
    if ($data['action'] === 'send') {
        // Update invoice status to 'sent'
        $updateStatusQuery = "UPDATE invoices SET status = 'sent', sent_date = NOW() WHERE id = ?";
        $stmt = $db->prepare($updateStatusQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $stmt->close();

        // Log activity
        $activityQuery = "INSERT INTO activity_logs (user_id, action, description, created_at) VALUES (?, 'invoice_sent', ?, NOW())";
        $stmt = $db->prepare($activityQuery);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        $description = "Invoice {$data['invoiceNumber']} sent to {$data['customerName']}";
        $stmt->bind_param('is', $user_id, $description);
        $stmt->execute();
        $stmt->close();

        // TODO: Send email to customer
        // sendInvoiceEmail($data['customerEmail'], $data['customerName'], $invoice_id);
    }

    // Commit transaction
    $db->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Invoice ' . $data['action'] . ' successfully!',
        'invoice_id' => $invoice_id,
        'invoice_number' => $data['invoiceNumber'],
        'action' => $data['action']
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
