<?php
// Dashboard Stats API
// Get dashboard statistics and overview

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

try {
    $stats = [];

    // 1. Total Invoices Count
    $query = "SELECT COUNT(*) as total FROM invoices WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['total_invoices'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // 2. Invoice Status Breakdown
    $query = "
        SELECT 
            status,
            COUNT(*) as count
        FROM invoices
        WHERE user_id = ?
        GROUP BY status
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['invoices_by_status'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['invoices_by_status'][$row['status']] = intval($row['count']);
    }
    $stmt->close();

    // 3. Total Revenue (all time)
    $query = "SELECT SUM(total_amount) as total FROM invoices WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['total_revenue'] = floatval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    // 4. Total Paid Revenue
    $query = "SELECT SUM(total_amount) as total FROM invoices WHERE user_id = ? AND status = 'paid'";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['paid_revenue'] = floatval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    // 5. Outstanding Revenue (pending + sent + overdue)
    $query = "SELECT SUM(total_amount) as total FROM invoices WHERE user_id = ? AND status IN ('pending', 'sent', 'overdue')";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['outstanding_revenue'] = floatval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    // 6. Overdue Invoices Count and Amount
    $query = "
        SELECT COUNT(*) as count, SUM(total_amount) as amount FROM invoices 
        WHERE user_id = ? AND status IN ('overdue', 'pending') AND due_date < CURDATE()
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stats['overdue_invoices'] = intval($result['count'] ?? 0);
    $stats['overdue_amount'] = floatval($result['amount'] ?? 0);
    $stmt->close();

    // 7. Total Customers
    $query = "SELECT COUNT(*) as total FROM customers WHERE user_id = ? AND is_active = TRUE";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['total_customers'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // 8. This Month's Revenue
    $query = "
        SELECT SUM(total_amount) as total FROM invoices 
        WHERE user_id = ? AND YEAR(created_date) = YEAR(CURDATE()) AND MONTH(created_date) = MONTH(CURDATE())
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['this_month_revenue'] = floatval($stmt->get_result()->fetch_assoc()['total'] ?? 0);
    $stmt->close();

    // 9. This Month's Invoice Count
    $query = "
        SELECT COUNT(*) as total FROM invoices 
        WHERE user_id = ? AND YEAR(created_date) = YEAR(CURDATE()) AND MONTH(created_date) = MONTH(CURDATE())
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['this_month_invoices'] = $stmt->get_result()->fetch_assoc()['total'];
    $stmt->close();

    // 10. Recent Invoices (last 5)
    $query = "
        SELECT 
            id,
            invoice_number,
            total_amount,
            created_date,
            status,
            (SELECT name FROM customers WHERE id = invoices.customer_id) as customer_name
        FROM invoices
        WHERE user_id = ?
        ORDER BY created_date DESC
        LIMIT 5
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['recent_invoices'] = [];
    while ($row = $result->fetch_assoc()) {
        $stats['recent_invoices'][] = $row;
    }
    $stmt->close();

    // 11. Average Invoice Value
    $query = "SELECT AVG(total_amount) as avg FROM invoices WHERE user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stats['average_invoice_value'] = floatval($stmt->get_result()->fetch_assoc()['avg'] ?? 0);
    $stmt->close();

    // 12. Monthly Revenue Trend (last 6 months)
    $query = "
        SELECT 
            YEAR(created_date) as year,
            MONTH(created_date) as month,
            SUM(total_amount) as total
        FROM invoices
        WHERE user_id = ? AND created_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY YEAR(created_date), MONTH(created_date)
        ORDER BY year, month
    ";
    $stmt = $db->prepare($query);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['monthly_trend'] = [];
    while ($row = $result->fetch_assoc()) {
        $month_name = date('M', mktime(0, 0, 0, $row['month'], 1));
        $stats['monthly_trend'][] = [
            'month' => $month_name . ' ' . $row['year'],
            'amount' => floatval($row['total'])
        ];
    }
    $stmt->close();

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $stats
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

?>
