<?php
// Invoice Duplicate API
// Clone/duplicate an existing invoice

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

if (!$invoice_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID is required']);
    exit();
}

try {
    $db->begin_transaction();

    // Get original invoice
    $query = "
        SELECT 
            customer_id,
            total_amount,
            tax_amount,
            subtotal,
            currency,
            payment_terms,
            nature_of_business,
            notes
        FROM invoices
        WHERE id = ? AND user_id = ?
    ";

    $stmt = $db->prepare($query);
    $stmt->bind_param('ii', $invoice_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $original = $result->fetch_assoc();
    $stmt->close();

    if (!$original) {
        throw new Exception('Original invoice not found');
    }

    // Generate new invoice number
    $new_invoice_number = generateInvoiceNumber($db, $user_id);

    // Create new invoice
    $today = date('Y-m-d');
    $insert_query = "
        INSERT INTO invoices (
            user_id,
            customer_id,
            invoice_number,
            invoice_date,
            created_date,
            due_date,
            subtotal,
            tax_amount,
            total_amount,
            currency,
            status,
            payment_terms,
            nature_of_business,
            notes
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db->prepare($insert_query);
    $status = 'draft';
    $due_date = date('Y-m-d', strtotime($today . ' + 30 days'));

    $stmt->bind_param(
        'iisssssdsssss',
        $user_id,
        $original['customer_id'],
        $new_invoice_number,
        $today,
        $today,
        $due_date,
        $original['subtotal'],
        $original['tax_amount'],
        $original['total_amount'],
        $original['currency'],
        $status,
        $original['payment_terms'],
        $original['nature_of_business'],
        $original['notes']
    );

    if (!$stmt->execute()) {
        throw new Exception('Failed to create new invoice: ' . $stmt->error);
    }

    $new_invoice_id = $stmt->insert_id;
    $stmt->close();

    // Get original invoice items
    $items_query = "
        SELECT item_name, item_description, quantity, rate, amount
        FROM invoice_items
        WHERE invoice_id = ?
    ";

    $stmt = $db->prepare($items_query);
    $stmt->bind_param('i', $invoice_id);
    $stmt->execute();
    $items_result = $stmt->get_result();
    $items = [];
    while ($row = $items_result->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    // Insert new invoice items
    $item_query = "
        INSERT INTO invoice_items (invoice_id, item_name, item_description, quantity, rate, amount)
        VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = $db->prepare($item_query);

    foreach ($items as $item) {
        $stmt->bind_param(
            'issdd',
            $new_invoice_id,
            $item['item_name'],
            $item['item_description'],
            $item['quantity'],
            $item['rate'],
            $item['amount']
        );

        if (!$stmt->execute()) {
            throw new Exception('Failed to copy invoice items: ' . $stmt->error);
        }
    }

    $stmt->close();

    // Log activity
    logActivity($db, $user_id, 'duplicate_invoice', 'invoices', $new_invoice_id, 
               'Duplicated invoice from ' . $invoice_id . ' to ' . $new_invoice_id);

    // Commit transaction
    $db->commit();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Invoice duplicated successfully',
        'new_invoice_id' => $new_invoice_id,
        'new_invoice_number' => $new_invoice_number,
        'redirect_url' => 'invoice-create.php?id=' . $new_invoice_id
    ]);

} catch (Exception $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Generate unique invoice number
 */
function generateInvoiceNumber($db, $user_id) {
    $query = "SELECT invoice_number FROM invoices WHERE user_id = ? ORDER BY id DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $last = $result->fetch_assoc();
    $stmt->close();

    if ($last) {
        $last_number = intval(str_replace('INV-', '', $last['invoice_number']));
        $new_number = 'INV-' . str_pad($last_number + 1, 3, '0', STR_PAD_LEFT);
    } else {
        $new_number = 'INV-001';
    }

    return $new_number;
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
