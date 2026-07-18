<?php
// File Delete API
// Delete uploaded files (photos and logos)

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
$file_id = isset($_POST['file_id']) ? intval($_POST['file_id']) : null;

if (!$file_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File ID is required']);
    exit();
}

try {
    // Get file details
    $query = "SELECT id, file_path, upload_type FROM file_uploads WHERE id = ? AND user_id = ?";
    $stmt = $db->prepare($query);
    $stmt->bind_param('ii', $file_id, $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $file = $result->fetch_assoc();
    $stmt->close();

    if (!$file) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'File not found']);
        exit();
    }

    // Delete physical file
    $file_path = '../' . $file['file_path'];
    if (file_exists($file_path)) {
        if (!unlink($file_path)) {
            throw new Exception('Failed to delete file from storage');
        }
    }

    // Mark as deleted in database
    $delete_query = "UPDATE file_uploads SET is_deleted = TRUE, deleted_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($delete_query);
    $stmt->bind_param('i', $file_id);

    if (!$stmt->execute()) {
        throw new Exception('Failed to update database record');
    }

    $stmt->close();

    // Log activity
    logActivity($db, $user_id, 'delete_file', 'file_uploads', $file_id, 
               'Deleted ' . $file['upload_type'] . ' file');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'File deleted successfully'
    ]);

} catch (Exception $e) {
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

?>
