<?php
// Customer List API
// Get all customers for dropdown/select options

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
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$active_only = isset($_GET['active_only']) ? boolval($_GET['active_only']) : true;

try {
    // Build query
    $query = "
        SELECT 
            id,
            name,
            email,
            phone,
            address,
            is_active,
            created_at
        FROM customers
        WHERE user_id = ?
    ";

    $params = ['i', $user_id];

    // Add active filter
    if ($active_only) {
        $query .= " AND is_active = TRUE";
    }

    // Add search filter
    if (!empty($search)) {
        $query .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
        $search_term = '%' . $search . '%';
        $params = array_merge($params, ['s', 's', 's']);
        array_push($params, $search_term, $search_term, $search_term);
    }

    // Order and limit
    $query .= " ORDER BY name ASC LIMIT ?";
    $params = array_merge($params, ['i']);
    array_push($params, $limit);

    // Prepare statement
    $stmt = $db->prepare(substr($query, 0, strpos($query, 'LIMIT') + 5) . '?');
    
    // Build param string dynamically
    $param_string = '';
    $param_values = [];
    
    if (!empty($search)) {
        $param_string = 'isssi';
        $param_values = [$user_id, $search_term, $search_term, $search_term, $limit];
    } else {
        $param_string = 'ii';
        $param_values = [$user_id, $limit];
    }

    // Simpler approach using direct query construction
    $where_clause = "WHERE user_id = " . intval($user_id);
    
    if ($active_only) {
        $where_clause .= " AND is_active = TRUE";
    }

    if (!empty($search)) {
        $search_escaped = $db->real_escape_string($search);
        $where_clause .= " AND (name LIKE '%{$search_escaped}%' OR email LIKE '%{$search_escaped}%' OR phone LIKE '%{$search_escaped}%')";
    }

    $query = "
        SELECT 
            id,
            name,
            email,
            phone,
            address,
            is_active,
            created_at
        FROM customers
        {$where_clause}
        ORDER BY name ASC
        LIMIT {$limit}
    ";

    $result = $db->query($query);

    if (!$result) {
        throw new Exception($db->error);
    }

    $customers = [];
    while ($row = $result->fetch_assoc()) {
        $customers[] = $row;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $customers,
        'count' => count($customers)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

?>
