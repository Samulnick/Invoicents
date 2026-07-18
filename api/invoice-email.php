<?php
// Invoice Email API
// Sends invoices via email with attachment

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
$recipient_email = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) : null;
$custom_message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$invoice_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID is required']);
    exit();
}

if (!$recipient_email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid email address is required']);
    exit();
}

try {
    // Get invoice details
    $invoiceQuery = "
        SELECT 
            i.id,
            i.invoice_number,
            i.total_amount,
            i.created_date,
            i.due_date,
            c.name as customer_name,
            c.email as customer_email,
            u.business_name,
            u.business_email,
            u.business_phone
        FROM invoices i
        LEFT JOIN customers c ON i.customer_id = c.id
        LEFT JOIN users u ON i.user_id = u.id
        WHERE i.id = ? AND i.user_id = ?
    ";

    $stmt = $db->prepare($invoiceQuery);
    $stmt->bind_param('ii', $invoice_id, $user_id);
    $stmt->execute();
    $invoiceResult = $stmt->get_result();
    $invoice = $invoiceResult->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit();
    }

    // Get user info for from address
    $userQuery = "SELECT email FROM users WHERE id = ?";
    $stmt = $db->prepare($userQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $user = $userResult->fetch_assoc();
    $stmt->close();

    // Prepare email
    $from_email = $user['email'];
    $from_name = $invoice['business_name'] ?: 'Invoicent';
    $to_email = $recipient_email;
    $subject = 'Invoice ' . $invoice['invoice_number'] . ' from ' . $from_name;

    // Email headers
    $headers = "From: " . $from_name . " <" . $from_email . ">\r\n";
    $headers .= "Reply-To: " . $from_email . "\r\n";
    $headers .= "X-Mailer: Invoicent\r\n";

    // Build email body
    $email_body = buildEmailBody($invoice, $custom_message);

    // Send email
    $mail_sent = mail($to_email, $subject, $email_body, $headers);

    if ($mail_sent) {
        // Log activity
        logActivity($db, $user_id, 'send_invoice_email', 'invoices', $invoice_id, 'Sent invoice via email to ' . $to_email);

        // Update sent count (optional - add to invoices table)
        $updateQuery = "UPDATE invoices SET updated_at = CURRENT_TIMESTAMP WHERE id = ?";
        $stmt = $db->prepare($updateQuery);
        $stmt->bind_param('i', $invoice_id);
        $stmt->execute();
        $stmt->close();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Invoice sent successfully to ' . $to_email
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send email. Please try again later.'
        ]);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Build professional email body
 */
function buildEmailBody($invoice, $custom_message) {
    $invoiceNumber = htmlspecialchars($invoice['invoice_number']);
    $businessName = htmlspecialchars($invoice['business_name'] ?: 'Your Business');
    $businessPhone = htmlspecialchars($invoice['business_phone'] ?: '');
    $totalAmount = number_format($invoice['total_amount'], 2);
    $dueDate = htmlspecialchars($invoice['due_date']);
    $customerName = htmlspecialchars($invoice['customer_name'] ?: 'Valued Customer');

    $body = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
            .container { max-width: 600px; margin: 0 auto; background: #f9f9f9; padding: 20px; }
            .header { background: #667eea; color: white; padding: 20px; border-radius: 10px 10px 0 0; }
            .header h1 { margin: 0; font-size: 24px; }
            .content { background: white; padding: 20px; }
            .invoice-info { background: #f5f5f5; padding: 15px; border-radius: 5px; margin: 15px 0; }
            .invoice-info p { margin: 8px 0; }
            .footer { background: #f5f5f5; padding: 15px; text-align: center; font-size: 12px; color: #666; }
            .btn { display: inline-block; background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin: 10px 0; }
            .highlight { color: #667eea; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>Invoice {$invoiceNumber}</h1>
                <p>From {$businessName}</p>
            </div>
            <div class='content'>
                <p>Hello {$customerName},</p>
                
                <p>We've attached an invoice for your recent purchase. Here are the details:</p>
                
                <div class='invoice-info'>
                    <p><strong>Invoice Number:</strong> {$invoiceNumber}</p>
                    <p><strong>Total Amount:</strong> <span class='highlight'>₦{$totalAmount}</span></p>
                    <p><strong>Due Date:</strong> {$dueDate}</p>
                </div>

                " . (!empty($custom_message) ? "<div class='invoice-info'><p><strong>Message from sender:</strong></p><p>{$custom_message}</p></div>" : "") . "

                <p>Please review the attached invoice and let us know if you have any questions.</p>
                
                <p>If you have any inquiries, please contact us at {$businessPhone}.</p>
                
                <p>Thank you for your business!</p>
                
                <p>Best regards,<br><strong>{$businessName}</strong></p>
            </div>
            <div class='footer'>
                <p>This email was sent from Invoicent Invoice Management System</p>
                <p>© " . date('Y') . " All rights reserved</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return $body;
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
