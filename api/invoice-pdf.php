<?php
// Invoice PDF Generation API
// Production-ready with mPDF library

session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Include database connection
require_once '../config/config.php';

$user_id = $_SESSION['user_id'];
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$invoice_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invoice ID is required']);
    exit();
}

try {
    // Get invoice details
    $invoiceQuery = "
        SELECT 
            i.id,
            i.invoice_number,
            i.customer_id,
            i.total_amount,
            i.tax_amount,
            i.subtotal,
            i.created_date,
            i.due_date,
            i.status,
            i.currency,
            i.notes,
            i.payment_terms,
            c.name as customer_name,
            c.email as customer_email,
            c.phone as customer_phone,
            c.address as customer_address,
            u.business_name,
            u.business_address,
            u.business_phone,
            u.business_email,
            u.business_logo
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

    // If invoice not found, return error
    if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Invoice not found']);
        exit();
    }

    // Get invoice items
    $itemsQuery = "
        SELECT 
            item_name,
            item_description,
            quantity,
            rate,
            amount
        FROM invoice_items
        WHERE invoice_id = ?
        ORDER BY id ASC
    ";

    $stmt = $db->prepare($itemsQuery);
    $stmt->bind_param('i', $invoice_id);
    $stmt->execute();
    $itemsResult = $stmt->get_result();
    $items = [];
    while ($row = $itemsResult->fetch_assoc()) {
        $items[] = $row;
    }
    $stmt->close();

    // Include mPDF library
    require_once '../vendor/autoload.php';

    // Initialize mPDF
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',  
        'format' => 'A5',
        'margin_top' => 15,
        'margin_bottom' => 15,
        'margin_left' => 15,
        'margin_right' => 15,
        'default_font' => 'Arial',
    ]);

    // Set document metadata
    $mpdf->SetTitle($invoice['invoice_number']);
    $mpdf->SetAuthor($invoice['business_name'] ?: 'Invoicent');
    $mpdf->SetCreator('Invoicent');

    // Generate HTML content for PDF
    $html = generateInvoicePDF($invoice, $items);

    // Write HTML to PDF
    $mpdf->WriteHTML($html);

    // Log activity
    logActivity($db, $user_id, 'download_pdf', 'invoices', $invoice_id, 'Downloaded invoice as PDF');

    // Output PDF as download
    $filename = 'Invoice_' . $invoice['invoice_number'] . '_' . date('Y-m-d') . '.pdf';
    $mpdf->Output($filename, 'D'); // 'D' = download

} catch (\Mpdf\MpdfException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error generating PDF: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

/**
 * Generate professional HTML for invoice PDF
 */
function generateInvoicePDF($invoice, $items) {
    $invoiceNumber = htmlspecialchars($invoice['invoice_number']);
    $businessName = htmlspecialchars($invoice['business_name'] ?: 'Your Business');
    $businessAddress = htmlspecialchars($invoice['business_address'] ?: '');
    $businessPhone = htmlspecialchars($invoice['business_phone'] ?: '');
    $businessEmail = htmlspecialchars($invoice['business_email'] ?: '');
    
    $customerName = htmlspecialchars($invoice['customer_name'] ?: 'N/A');
    $customerAddress = htmlspecialchars($invoice['customer_address'] ?: '');
    $customerPhone = htmlspecialchars($invoice['customer_phone'] ?: '');
    $customerEmail = htmlspecialchars($invoice['customer_email'] ?: '');
    
    $invoiceDate = htmlspecialchars($invoice['created_date']);
    $dueDate = htmlspecialchars($invoice['due_date']);
    $status = ucfirst(htmlspecialchars($invoice['status']));
    $currency = htmlspecialchars($invoice['currency'] ?: '₦ NGN');
    $paymentTerms = htmlspecialchars($invoice['payment_terms'] ?: 'Net 30');
    $notes = htmlspecialchars($invoice['notes'] ?: '');
    
    $subtotal = number_format($invoice['subtotal'], 2);
    $taxAmount = number_format($invoice['tax_amount'], 2);
    $totalAmount = number_format($invoice['total_amount'], 2);

    // Generate items table rows
    $itemsHTML = '';
    foreach ($items as $item) {
        $itemName = htmlspecialchars($item['item_name']);
        $itemDesc = htmlspecialchars($item['item_description']);
        $quantity = htmlspecialchars($item['quantity']);
        $rate = number_format($item['rate'], 2);
        $amount = number_format($item['amount'], 2);

        $itemsHTML .= "
        <tr>
            <td style='padding: 12px; border-bottom: 1px solid #ddd; font-size: 11px;'>{$itemName}</td>
            <td style='padding: 12px; border-bottom: 1px solid #ddd; font-size: 11px;'>{$itemDesc}</td>
            <td style='padding: 12px; border-bottom: 1px solid #ddd; text-align: right; font-size: 11px;'>{$quantity}</td>
            <td style='padding: 12px; border-bottom: 1px solid #ddd; text-align: right; font-size: 11px;'>₦{$rate}</td>
            <td style='padding: 12px; border-bottom: 1px solid #ddd; text-align: right; font-size: 11px;'>₦{$amount}</td>
        </tr>
        ";
    }

    $currentDate = date('Y-m-d H:i:s');

    $html = "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
            
            .invoice-container { max-width: 800px; margin: 0 auto; }
            
            .invoice-header {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 3px solid #667eea;
            }
            
            .company-info h1 { 
                color: #667eea; 
                margin-bottom: 10px; 
                font-size: 24px;
            }
            
            .company-info p { 
                font-size: 11px; 
                color: #666; 
                margin: 4px 0; 
            }
            
            .invoice-title {
                text-align: right;
            }
            
            .invoice-title h2 {
                font-size: 36px;
                color: #667eea;
                margin-bottom: 5px;
                font-weight: bold;
            }
            
            .invoice-meta {
                font-size: 12px;
                color: #666;
                margin: 5px 0;
            }
            
            .status-badge {
                display: inline-block;
                padding: 8px 15px;
                border-radius: 20px;
                font-size: 11px;
                font-weight: 600;
                background: #d1ecf1;
                color: #0c5460;
            }
            
            .invoice-details {
                display: table;
                width: 100%;
                margin-bottom: 30px;
            }
            
            .detail-column {
                display: table-cell;
                width: 50%;
                padding-right: 20px;
                vertical-align: top;
            }
            
            .detail-column h3 {
                font-size: 12px;
                font-weight: 700;
                color: #667eea;
                margin-bottom: 8px;
                text-transform: uppercase;
            }
            
            .detail-column p {
                font-size: 11px;
                color: #666;
                margin: 4px 0;
            }
            
            .dates-section {
                display: table;
                width: 100%;
                margin-bottom: 30px;
            }
            
            .date-item {
                display: table-cell;
                width: 50%;
                padding-right: 20px;
            }
            
            .date-item h4 {
                font-size: 11px;
                font-weight: 700;
                color: #667eea;
                margin-bottom: 5px;
            }
            
            .date-item p {
                font-size: 11px;
                color: #333;
            }
            
            .items-table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
            }
            
            .items-table thead tr {
                background: #667eea;
                color: white;
            }
            
            .items-table th {
                padding: 12px;
                text-align: left;
                font-size: 11px;
                font-weight: 700;
            }
            
            .totals {
                float: right;
                width: 45%;
                margin-bottom: 30px;
            }
            
            .total-row {
                display: flex;
                justify-content: space-between;
                padding: 10px 0;
                font-size: 11px;
                border-bottom: 1px solid #ddd;
            }
            
            .total-row.total {
                font-size: 13px;
                font-weight: 700;
                color: #667eea;
                border-bottom: 2px solid #667eea;
                border-top: 2px solid #667eea;
                padding: 12px 0;
            }
            
            .clear { clear: both; }
            
            .notes-section {
                margin-top: 30px;
                padding-top: 15px;
                border-top: 1px solid #ddd;
                font-size: 11px;
                color: #666;
            }
            
            .notes-section h4 {
                font-weight: 700;
                color: #333;
                margin-bottom: 5px;
                font-size: 11px;
            }
            
            .signatures {
                display: table;
                width: 100%;
                margin-top: 50px;
            }
            
            .signature-box {
                display: table-cell;
                width: 50%;
                text-align: center;
                padding: 20px;
            }
            
            .signature-line {
                border-top: 2px solid #333;
                margin-top: 50px;
                padding-top: 10px;
                font-size: 11px;
                font-weight: 600;
            }
            
            .footer {
                margin-top: 40px;
                padding-top: 15px;
                border-top: 1px solid #ddd;
                text-align: center;
                font-size: 10px;
                color: #999;
            }
        </style>
    </head>
    <body>
        <div class='invoice-container'>
            <!-- Header -->
            <div class='invoice-header'>
                <div class='company-info'>
                    <h1>{$businessName}</h1>
                    <p>{$businessAddress}</p>
                    <p>Phone: {$businessPhone}</p>
                    <p>Email: {$businessEmail}</p>
                </div>
                <div class='invoice-title'>
                    <h2>INVOICE</h2>
                    <div class='invoice-meta'><strong>{$invoiceNumber}</strong></div>
                    <div class='invoice-meta'><span class='status-badge'>{$status}</span></div>
                </div>
            </div>

            <!-- From/To Section -->
            <div class='invoice-details'>
                <div class='detail-column'>
                    <h3>From</h3>
                    <p><strong>{$businessName}</strong></p>
                    <p>{$businessAddress}</p>
                    <p>Phone: {$businessPhone}</p>
                    <p>Email: {$businessEmail}</p>
                </div>
                <div class='detail-column'>
                    <h3>Bill To</h3>
                    <p><strong>{$customerName}</strong></p>
                    <p>{$customerAddress}</p>
                    <p>Phone: {$customerPhone}</p>
                    <p>Email: {$customerEmail}</p>
                </div>
            </div>

            <!-- Dates Section -->
            <div class='dates-section'>
                <div class='date-item'>
                    <h4>Invoice Date</h4>
                    <p>{$invoiceDate}</p>
                </div>
                <div class='date-item'>
                    <h4>Due Date</h4>
                    <p>{$dueDate}</p>
                </div>
            </div>

            <!-- Items Table -->
            <table class='items-table'>
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Description</th>
                        <th style='text-align: right;'>Quantity</th>
                        <th style='text-align: right;'>Rate</th>
                        <th style='text-align: right;'>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    {$itemsHTML}
                </tbody>
            </table>

            <!-- Totals -->
            <div class='totals'>
                <div class='total-row'>
                    <span>Subtotal:</span>
                    <span>₦{$subtotal}</span>
                </div>
                <div class='total-row'>
                    <span>Tax:</span>
                    <span>₦{$taxAmount}</span>
                </div>
                <div class='total-row total'>
                    <span>TOTAL:</span>
                    <span>₦{$totalAmount}</span>
                </div>
            </div>
            <div class='clear'></div>

            <!-- Notes and Terms -->
            <div class='notes-section'>
                <h4>Payment Terms:</h4>
                <p>{$paymentTerms}</p>
                " . ($notes ? "<h4 style='margin-top: 10px;'>Notes:</h4><p>{$notes}</p>" : "") . "
            </div>

            <!-- Signatures -->
            <div class='signatures'>
                <div class='signature-box'>
                    <div class='signature-line'>For: {$businessName}</div>
                </div>
                <div class='signature-box'>
                    <div class='signature-line'>Customer Signature</div>
                </div>
            </div>

            <!-- Footer -->
            <div class='footer'>
                <p>Generated on {$currentDate} | Invoicent - Invoice Management System</p>
            </div>
        </div>
    </body>
    </html>
    ";

    return $html;
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
