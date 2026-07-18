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

if (!$data || empty($data['type'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request - type required']);
    exit();
}

$type = $data['type'];

try {
    switch ($type) {
        case 'business':
            savBusinessSettings($db, $user_id, $data);
            break;
        case 'invoice':
            saveInvoiceSettings($db, $user_id, $data);
            break;
        case 'email':
            saveEmailSettings($db, $user_id, $data);
            break;
        case 'whatsapp':
            saveWhatsappSettings($db, $user_id, $data);
            break;
        case 'notifications':
            saveNotificationSettings($db, $user_id, $data);
            break;
        default:
            throw new Exception("Unknown settings type: " . $type);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
}

function savBusinessSettings($db, $user_id, $data) {
    $updateQuery = "
        UPDATE users 
        SET business_name = ?, business_email = ?, business_phone = ?, business_address = ?, currency = ?
        WHERE id = ?
    ";
    
    $stmt = $db->prepare($updateQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $stmt->bind_param(
        'sssss',
        $data['business_name'],
        $data['business_email'],
        $data['business_phone'],
        $data['business_address'],
        $data['currency'],
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Business settings saved successfully'
    ]);
}

function saveInvoiceSettings($db, $user_id, $data) {
    $updateQuery = "
        UPDATE users 
        SET invoice_prefix = ?, default_tax_rate = ?, payment_terms = ?, invoice_notes = ?
        WHERE id = ?
    ";
    
    $stmt = $db->prepare($updateQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $taxRate = floatval($data['default_tax_rate']);
    
    $stmt->bind_param(
        'sdssi',
        $data['invoice_prefix'],
        $taxRate,
        $data['payment_terms'],
        $data['invoice_notes'],
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Invoice settings saved successfully'
    ]);
}

function saveEmailSettings($db, $user_id, $data) {
    $updateQuery = "
        UPDATE users 
        SET auto_email_invoice = ?, email_subject = ?, email_message = ?
        WHERE id = ?
    ";
    
    $stmt = $db->prepare($updateQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $autoEmail = intval($data['auto_email_invoice']);
    
    $stmt->bind_param(
        'issi',
        $autoEmail,
        $data['email_subject'],
        $data['email_message'],
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Email settings saved successfully'
    ]);
}

function saveWhatsappSettings($db, $user_id, $data) {
    $updateQuery = "
        UPDATE users 
        SET auto_whatsapp_invoice = ?, whatsapp_phone = ?, whatsapp_message = ?
        WHERE id = ?
    ";
    
    $stmt = $db->prepare($updateQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $autoWhatsapp = intval($data['auto_whatsapp_invoice']);
    
    $stmt->bind_param(
        'issi',
        $autoWhatsapp,
        $data['whatsapp_phone'],
        $data['whatsapp_message'],
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'WhatsApp settings saved successfully'
    ]);
}

function saveNotificationSettings($db, $user_id, $data) {
    $updateQuery = "
        UPDATE users 
        SET notify_invoice_sent = ?, notify_invoice_paid = ?, notify_overdue = ?
        WHERE id = ?
    ";
    
    $stmt = $db->prepare($updateQuery);
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $db->error);
    }

    $notifySent = intval($data['notify_invoice_sent']);
    $notifyPaid = intval($data['notify_invoice_paid']);
    $notifyOverdue = intval($data['notify_overdue']);
    
    $stmt->bind_param(
        'iiii',
        $notifySent,
        $notifyPaid,
        $notifyOverdue,
        $user_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Update failed: " . $stmt->error);
    }

    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'Notification preferences saved successfully'
    ]);
}
?>
