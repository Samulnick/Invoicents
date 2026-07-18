<?php
// Activity Log API
// Fetch user activity logs for audit trail

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
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
$offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
$action_filter = isset($_GET['action']) ? trim($_GET['action']) : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '';

try {
    // Build query
    $where = "WHERE user_id = ?";
    $params = ['i', $user_id];

    // Add action filter
    if (!empty($action_filter)) {
        $where .= " AND action = ?";
        $params = array_merge($params, ['s']);
        array_push($params, $action_filter);
    }

    // Add date range filter
    if (!empty($start_date)) {
        $where .= " AND created_at >= ?";
        $params = array_merge($params, ['s']);
        array_push($params, $start_date . ' 00:00:00');
    }

    if (!empty($end_date)) {
        $where .= " AND created_at <= ?";
        $params = array_merge($params, ['s']);
        array_push($params, $end_date . ' 23:59:59');
    }

    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM activity_logs {$where}";
    
    // Use prepared statement for count
    $escaped_where = $where;
    $count_result = $db->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = {$user_id}");
    
    if (!empty($action_filter)) {
        $action_filter_escaped = $db->real_escape_string($action_filter);
        $count_result = $db->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = {$user_id} AND action = '{$action_filter_escaped}'");
    }
    
    if (!empty($start_date) && !empty($end_date)) {
        $start_escaped = $db->real_escape_string($start_date);
        $end_escaped = $db->real_escape_string($end_date);
        $count_result = $db->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = {$user_id} AND created_at >= '{$start_escaped} 00:00:00' AND created_at <= '{$end_escaped} 23:59:59'");
    } elseif (!empty($start_date)) {
        $start_escaped = $db->real_escape_string($start_date);
        $count_result = $db->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = {$user_id} AND created_at >= '{$start_escaped} 00:00:00'");
    } elseif (!empty($end_date)) {
        $end_escaped = $db->real_escape_string($end_date);
        $count_result = $db->query("SELECT COUNT(*) as total FROM activity_logs WHERE user_id = {$user_id} AND created_at <= '{$end_escaped} 23:59:59'");
    }

    $total = $count_result->fetch_assoc()['total'];

    // Build where clause for data query
    $data_where = "WHERE user_id = {$user_id}";
    
    if (!empty($action_filter)) {
        $action_filter_escaped = $db->real_escape_string($action_filter);
        $data_where .= " AND action = '{$action_filter_escaped}'";
    }

    if (!empty($start_date)) {
        $start_escaped = $db->real_escape_string($start_date);
        $data_where .= " AND created_at >= '{$start_escaped} 00:00:00'";
    }

    if (!empty($end_date)) {
        $end_escaped = $db->real_escape_string($end_date);
        $data_where .= " AND created_at <= '{$end_escaped} 23:59:59'";
    }

    // Get logs
    $query = "
        SELECT 
            id,
            user_id,
            action,
            entity_type,
            entity_id,
            description,
            ip_address,
            created_at
        FROM activity_logs
        {$data_where}
        ORDER BY created_at DESC
        LIMIT {$limit} OFFSET {$offset}
    ";

    $result = $db->query($query);

    if (!$result) {
        throw new Exception($db->error);
    }

    $logs = [];
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'data' => $logs,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset,
        'has_more' => ($offset + $limit) < $total
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

?>
