<?php
// Customer Get API
// Fetch single customer details

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
$customer_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$customer_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Customer ID is required']);
    exit();
}

try {
    // Get customer details
    $customerQuery = "
        SELECT 
            id,
            name,
            email,
            phone,
            address,
            city,
            state,
            country,
            postal_code,
            tax_id,
            notes,
            is_active,
            created_at,
            updated_at
        FROM customers
        WHERE id = ? AND user_id = ?
    ";

    $stmt = $db->prepare($customerQuery);
    $stmt->bind_param('ii', $customer_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $customer = $result->fetch_assoc();
    $stmt->close();

    if (!$customer) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit();
    }

    // Get invoice count for this customer
    $invoiceQuery = "SELECT COUNT(*) as invoice_count FROM invoices WHERE customer_id = ? AND user_id = ?";
    $stmt = $db->prepare($invoiceQuery);
    $stmt->bind_param('ii', $customer_id, $user_id);
    $stmt->execute();
    $invoiceResult = $stmt->get_result();
    $invoiceData = $invoiceResult->fetch_assoc();
    $stmt->close();

    $customer['invoice_count'] = $invoiceData['invoice_count'];

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $customer
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

?>
