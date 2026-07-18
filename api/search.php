<?php
// Start session and check authentication
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once '../config/db.php';

$user_id = $_SESSION['user_id'];
$query = isset($_GET['q']) ? trim($_GET['q']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : 'all'; // all, invoices, customers

// Validate input
if (strlen($query) < 2) {
    echo json_encode([
        'success' => false,
        'message' => 'Search query must be at least 2 characters',
        'results' => []
    ]);
    exit();
}

$results = [
    'invoices' => [],
    'customers' => [],
    'query' => $query
];

try {
    // Search invoices by invoice number or customer name
    if ($type === 'all' || $type === 'invoices') {
        $invoiceQuery = "
            SELECT 
                i.id,
                i.invoice_number,
                i.total_amount,
                i.status,
                i.created_date,
                c.name as customer_name
            FROM invoices i
            LEFT JOIN customers c ON i.customer_id = c.id
            WHERE i.user_id = ? 
            AND (
                i.invoice_number LIKE ? 
                OR c.name LIKE ?
                OR i.status LIKE ?
            )
            ORDER BY i.created_date DESC
            LIMIT 10
        ";
        
        $searchTerm = '%' . $query . '%';
        $stmt = $db->prepare($invoiceQuery);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        
        $stmt->bind_param('isss', $user_id, $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $invoiceResult = $stmt->get_result();
        
        while ($row = $invoiceResult->fetch_assoc()) {
            $results['invoices'][] = [
                'id' => $row['id'],
                'type' => 'invoice',
                'invoice_number' => $row['invoice_number'],
                'customer' => $row['customer_name'],
                'amount' => $row['total_amount'],
                'status' => $row['status'],
                'date' => $row['created_date'],
                'displayText' => $row['invoice_number'] . ' - ' . $row['customer_name'],
                'url' => 'invoice-view.php?id=' . $row['id']
            ];
        }
        $stmt->close();
    }

    // Search customers
    if ($type === 'all' || $type === 'customers') {
        $customerQuery = "
            SELECT 
                id,
                name,
                email,
                phone,
                city
            FROM customers
            WHERE user_id = ? 
            AND (
                name LIKE ? 
                OR email LIKE ?
                OR phone LIKE ?
                OR city LIKE ?
            )
            ORDER BY name ASC
            LIMIT 10
        ";
        
        $searchTerm = '%' . $query . '%';
        $stmt = $db->prepare($customerQuery);
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $db->error);
        }
        
        $stmt->bind_param('issss', $user_id, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
        $stmt->execute();
        $customerResult = $stmt->get_result();
        
        while ($row = $customerResult->fetch_assoc()) {
            $results['customers'][] = [
                'id' => $row['id'],
                'type' => 'customer',
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'city' => $row['city'],
                'displayText' => $row['name'] . ' (' . $row['email'] . ')',
                'url' => 'customers.php?id=' . $row['id']
            ];
        }
        $stmt->close();
    }

    // Count total results
    $totalResults = count($results['invoices']) + count($results['customers']);

    echo json_encode([
        'success' => true,
        'query' => $query,
        'totalResults' => $totalResults,
        'results' => $results
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'results' => []
    ]);
}
?>
