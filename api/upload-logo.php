<?php
// Business Logo Upload API
// Handles business logo uploads for invoices

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

// Configuration
$upload_dir = '../uploads/logos/';
$max_file_size = 5 * 1024 * 1024; // 5MB
$allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
$allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];

try {
    // Create upload directory if it doesn't exist
    if (!is_dir($upload_dir)) {
        if (!mkdir($upload_dir, 0755, true)) {
            throw new Exception('Failed to create upload directory');
        }
    }

    // Check if file was uploaded
    if (!isset($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
        exit();
    }

    $file = $_FILES['logo'];
    $file_name = $file['name'];
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_type = $file['type'];

    // Validate file size
    if ($file_size > $max_file_size) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
        exit();
    }

    // Validate file extension
    $file_extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
    if (!in_array($file_extension, $allowed_extensions)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid file type. Allowed: jpg, jpeg, png, gif, svg, webp']);
        exit();
    }

    // Validate MIME type
    if (!in_array($file_type, $allowed_mimes)) {
        // For SVG, allow it even if MIME is text/plain (some servers report SVG as text/plain)
        if (!($file_extension === 'svg' && in_array($file_type, ['text/plain', 'image/svg+xml']))) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid file MIME type']);
            exit();
        }
    }

    // Verify it's actually an image (except for SVG)
    if ($file_extension !== 'svg') {
        $getimagesize_result = getimagesize($file_tmp);
        if ($getimagesize_result === false) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'File is not a valid image']);
            exit();
        }
    }

    // Validate SVG content for security (prevent XXE attacks)
    if ($file_extension === 'svg') {
        $svg_content = file_get_contents($file_tmp);
        
        // Check for potentially dangerous content
        $dangerous_patterns = ['<!DOCTYPE', '<!ENTITY', '<script', 'javascript:', 'on'];
        foreach ($dangerous_patterns as $pattern) {
            if (stripos($svg_content, $pattern) !== false) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'SVG contains potentially dangerous content']);
                exit();
            }
        }
    }

    // Generate unique filename
    $timestamp = time();
    $random_string = bin2hex(random_bytes(8));
    $new_filename = 'logo_' . $user_id . '_' . $timestamp . '_' . $random_string . '.' . $file_extension;
    $upload_path = $upload_dir . $new_filename;

    // Move uploaded file
    if (!move_uploaded_file($file_tmp, $upload_path)) {
        throw new Exception('Failed to save file');
    }

    // Set file permissions
    chmod($upload_path, 0644);

    // Get old logo path for deletion
    $getUserQuery = "SELECT business_logo FROM users WHERE id = ?";
    $stmt = $db->prepare($getUserQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    $old_logo = $user['business_logo'] ?? null;

    // Update database with new logo path
    $relative_path = 'uploads/logos/' . $new_filename;
    $updateQuery = "UPDATE users SET business_logo = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?";
    $stmt = $db->prepare($updateQuery);
    
    if (!$stmt) {
        @unlink($upload_path);
        throw new Exception($db->error);
    }

    $stmt->bind_param('si', $relative_path, $user_id);
    
    if (!$stmt->execute()) {
        @unlink($upload_path);
        throw new Exception($stmt->error);
    }
    
    $stmt->close();

    // Delete old logo if it exists and it's different
    if ($old_logo && $old_logo !== $relative_path && file_exists('../' . $old_logo)) {
        @unlink('../' . $old_logo);
    }

    // Log activity
    logActivity($db, $user_id, 'upload_logo', 'users', $user_id, 'Uploaded business logo');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'Logo uploaded successfully',
        'logo_path' => $relative_path,
        'filename' => $new_filename
    ]);

} catch (Exception $e) {
    // Clean up any partially uploaded file
    if (isset($upload_path) && file_exists($upload_path)) {
        @unlink($upload_path);
    }
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
