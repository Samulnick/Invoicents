<?php
// Invoice Reminder Send API
// Sends payment reminder notifications

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
$reminder_type = isset($_POST['reminder_type']) ? trim($_POST['reminder_type']) : 'due_date';
$recipient_email = isset($_POST['email']) ? filter_var($_POST['email'], FILTER_VALIDATE_EMAIL) : null;
$custom_message = isset($_POST['message']) ? trim($_POST['message']) : '';

if (!$invoice_id || !$recipient_email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID and email are required']);
    exit();
}

// Valid reminder types
$valid_types = ['due_date', 'overdue', 'custom'];
if (!in_array($reminder_type, $valid_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid reminder type']);
    exit();
}

try {
    $db->begin_transaction();

    // Get invoice details
    $invoiceQuery = "
        SELECT 
            i.id,
            i.invoice_number,
            i.total_amount,
            i.due_date,
            i.status,
            c.name as customer_name,
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
    $result = $stmt->get_result();
    $invoice = $result->fetch_assoc();
    $stmt->close();

    if (!$invoice) {
        throw new Exception('Invoice not found');
    }

    // Get user email
    $userQuery = "SELECT email FROM users WHERE id = ?";
    $stmt = $db->prepare($userQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $user = $userResult->fetch_assoc();
    $stmt->close();

    // Prepare reminder email
    $from_email = $user['email'];
    $from_name = $invoice['business_name'] ?: 'Invoicent';
    $to_email = $recipient_email;

    // Set subject based on reminder type
    $subject_map = [
        'due_date' => 'Payment Due Reminder - Invoice ' . $invoice['invoice_number'],
        'overdue' => 'Payment Overdue Notice - Invoice ' . $invoice['invoice_number'],
        'custom' => 'Reminder - Invoice ' . $invoice['invoice_number']
    ];

    $subject = $subject_map[$reminder_type] ?? 'Invoice Reminder';

    // Email headers
    $headers = "From: " . $from_name . " <" . $from_email . ">\r\n";
    $headers .= "Reply-To: " . $from_email . "\r\n";
    $headers .= "X-Mailer: Invoicent\r\n";

    // Build email body
    $email_body = buildReminderEmail($invoice, $reminder_type, $custom_message);

    // Send email
    $mail_sent = mail($to_email, $subject, $email_body, $headers);

    if ($mail_sent) {
        // Insert reminder record
        $reminder_date = date('Y-m-d');
        $reminderQuery = "
            INSERT INTO invoice_reminders (invoice_id, user_id, reminder_date, reminder_type, is_sent, sent_at)
            VALUES (?, ?, ?, ?, TRUE, CURRENT_TIMESTAMP)
        ";

        $stmt = $db->prepare($reminderQuery);
        $stmt->bind_param('iiss', $invoice_id, $user_id, $reminder_date, $reminder_type);

        if (!$stmt->execute()) {
            throw new Exception('Failed to log reminder: ' . $stmt->error);
        }

        $stmt->close();

        // Log activity
        logActivity($db, $user_id, 'send_reminder', 'invoices', $invoice_id, 
                   'Sent ' . $reminder_type . ' reminder to ' . $to_email);

        $db->commit();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Reminder sent successfully to ' . $to_email
        ]);
    } else {
        $db->rollback();
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to send reminder. Please try again later.'
        ]);
    }

} catch (Exception $e) {
    $db->rollback();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

/**
 * Build reminder email body
 */
function buildReminderEmail($invoice, $reminder_type, $custom_message) {
    $invoiceNumber = htmlspecialchars($invoice['invoice_number']);
    $businessName = htmlspecialchars($invoice['business_name'] ?: 'Your Business');
    $businessPhone = htmlspecialchars($invoice['business_phone'] ?: '');
    $totalAmount = number_format($invoice['total_amount'], 2);
    $dueDate = htmlspecialchars($invoice['due_date']);
    $customerName = htmlspecialchars($invoice['customer_name'] ?: 'Valued Customer');

    $reminder_messages = [
        'due_date' => "Your invoice <strong>{$invoiceNumber}</strong> is due on <strong>{$dueDate}</strong>.",
        'overdue' => "Your invoice <strong>{$invoiceNumber}</strong> is now <strong>OVERDUE</strong>. Payment was due on {$dueDate}.",
        'custom' => "This is a reminder regarding invoice <strong>{$invoiceNumber}</strong>."
    ];

    $message = $reminder_messages[$reminder_type] ?? $reminder_messages['custom'];

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
            .alert { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 5px; margin: 15px 0; color: #856404; }
            .alert.danger { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; }
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
                <h1>Invoice Reminder</h1>
                <p>From {$businessName}</p>
            </div>
            <div class='content'>
                <p>Hello {$customerName},</p>
                
                <div class='alert " . ($reminder_type === 'overdue' ? 'danger' : '') . "'>
                    <strong>" . ($reminder_type === 'overdue' ? '⚠️ URGENT: ' : '') . "Invoice Reminder</strong><br>
                    {$message}
                </div>

                <div class='invoice-info'>
                    <p><strong>Invoice Number:</strong> {$invoiceNumber}</p>
                    <p><strong>Amount Due:</strong> <span class='highlight'>₦{$totalAmount}</span></p>
                    <p><strong>Due Date:</strong> {$dueDate}</p>
                </div>

                " . (!empty($custom_message) ? "<div class='alert'><p><strong>Additional Information:</strong></p><p>{$custom_message}</p></div>" : "") . "

                <p>Please arrange payment at your earliest convenience. If you have already paid, please disregard this reminder.</p>
                
                <p>If you have any questions or need assistance, please contact us at {$businessPhone}.</p>
                
                <p>Thank you,<br><strong>{$businessName}</strong></p>
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
