<?php
// Start session
session_start();

// Include database connection
require_once '../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if mPDF is installed via Composer
require_once '../vendor/autoload.php';

use Mpdf\Mpdf;

try {
    // Get JSON data from request
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception('No invoice data provided');
    }

    // Prepare invoice data
    $invoiceNumber = htmlspecialchars($data['invoiceNumber'] ?? 'INV-001');
    $invoiceDate = htmlspecialchars($data['invoiceDate'] ?? date('Y-m-d'));
    $dueDate = htmlspecialchars($data['dueDate'] ?? date('Y-m-d', strtotime('+30 days')));
    
    // Customer info
    $customerName = htmlspecialchars($data['customerName'] ?? '');
    $customerEmail = htmlspecialchars($data['customerEmail'] ?? '');
    $customerPhone = htmlspecialchars($data['customerPhone'] ?? '');
    $customerAddress = htmlspecialchars($data['customerAddress'] ?? '');
    
    // Business info (from session)
    $user_id = $_SESSION['user_id'];
    $userQuery = "SELECT first_name, last_name, email, phone, business_name, business_address FROM users WHERE id = ?";
    $stmt = $db->prepare($userQuery);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $userResult = $stmt->get_result();
    $user = $userResult->fetch_assoc();
    $stmt->close();

    $businessName = htmlspecialchars($user['business_name'] ?? 'Your Business');
    $businessEmail = htmlspecialchars($user['email'] ?? '');
    $businessPhone = htmlspecialchars($user['phone'] ?? '');
    
    // Totals
    $subtotal = floatval($data['subtotal'] ?? 0);
    $taxRate = floatval($data['taxRate'] ?? 0);
    $taxAmount = floatval($data['taxAmount'] ?? 0);
    $discountPercent = floatval($data['discountPercent'] ?? 0);
    $grandTotal = floatval($data['grandTotal'] ?? 0);
    $paymentTerms = htmlspecialchars($data['paymentTerms'] ?? '');
    $notes = htmlspecialchars($data['notes'] ?? '');
    
    // Line items
    $lineItems = $data['lineItems'] ?? [];

    // Build HTML for PDF
    $html = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            color: #333;
            background-color: #fff;
        }
        
        .container {
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #667eea;
            padding-bottom: 15px;
        }
        
        .header h1 {
            font-size: 28px;
            color: #667eea;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 12px;
            color: #999;
        }
        
        .invoice-info {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        .invoice-info-item {
            display: table-cell;
            width: 33%;
            padding: 10px;
            border: 1px solid #eee;
            background-color: #f9f9f9;
        }
        
        .invoice-info-label {
            font-weight: bold;
            font-size: 11px;
            color: #667eea;
            text-transform: uppercase;
            margin-bottom: 3px;
        }
        
        .invoice-info-value {
            font-size: 12px;
            color: #333;
        }
        
        .parties {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        .party {
            display: table-cell;
            width: 50%;
            padding: 15px;
            border: 1px solid #eee;
        }
        
        .party-label {
            font-weight: bold;
            font-size: 11px;
            color: #667eea;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        
        .party-content {
            font-size: 12px;
            line-height: 1.6;
        }
        
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        table.items thead {
            background-color: #667eea;
            color: white;
        }
        
        table.items th {
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        
        table.items td {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        
        table.items tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        table.items .amount-col {
            text-align: right;
        }
        
        .totals {
            width: 50%;
            margin-left: auto;
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        .total-row {
            display: table;
            width: 100%;
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        
        .total-label {
            display: table-cell;
            width: 60%;
            padding-right: 10px;
        }
        
        .total-amount {
            display: table-cell;
            width: 40%;
            text-align: right;
            padding-right: 10px;
        }
        
        .grand-total {
            background-color: #667eea;
            color: white;
            font-weight: bold;
            font-size: 14px;
            padding: 12px !important;
        }
        
        .grand-total .total-label,
        .grand-total .total-amount {
            padding: 0;
        }
        
        .terms {
            margin-top: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border: 1px solid #eee;
            font-size: 11px;
            line-height: 1.6;
        }
        
        .terms-label {
            font-weight: bold;
            color: #667eea;
            margin-bottom: 8px;
            font-size: 11px;
            text-transform: uppercase;
        }
        
        .footer {
            text-align: center;
            font-size: 10px;
            color: #999;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>INVOICE</h1>
            <div class="invoice-number">{{ INVOICE_NUMBER }}</div>
            <div class="subtitle">Invoice</div>
        </div>
        
        <div class="invoice-info">
            <div class="invoice-info-item">
                <div class="invoice-info-label">Invoice Date</div>
                <div class="invoice-info-value">{{ INVOICE_DATE }}</div>
            </div>
            <div class="invoice-info-item">
                <div class="invoice-info-label">Due Date</div>
                <div class="invoice-info-value">{{ DUE_DATE }}</div>
            </div>
            <div class="invoice-info-item">
                <div class="invoice-info-label">Invoice Number</div>
                <div class="invoice-info-value">{{ INVOICE_NUMBER }}</div>
            </div>
        </div>
        
        <div class="parties">
            <div class="party">
                <div class="party-label">From</div>
                <div class="party-content">
                    <strong>{{ BUSINESS_NAME }}</strong><br>
                    {{ BUSINESS_EMAIL }}<br>
                    {{ BUSINESS_PHONE }}
                </div>
            </div>
            <div class="party">
                <div class="party-label">Bill To</div>
                <div class="party-content">
                    <strong>{{ CUSTOMER_NAME }}</strong><br>
                    {{ CUSTOMER_EMAIL }}<br>
                    {{ CUSTOMER_PHONE }}
                </div>
            </div>
        </div>
        
        <table class="items">
            <thead>
                <tr>
                    <th>Description</th>
                    <th style="width: 15%; text-align: center;">Qty</th>
                    <th style="width: 15%; text-align: right;">Rate</th>
                    <th style="width: 20%; text-align: right;">Amount</th>
                </tr>
            </thead>
            <tbody>
                {{ ITEMS_TABLE }}
            </tbody>
        </table>
        
        <div class="totals">
            <div class="total-row">
                <div class="total-label">Subtotal</div>
                <div class="total-amount">₦{{ SUBTOTAL }}</div>
            </div>
            <div class="total-row">
                <div class="total-label">Tax ({{ TAX_RATE }}%)</div>
                <div class="total-amount">₦{{ TAX_AMOUNT }}</div>
            </div>
            <div class="total-row">
                <div class="total-label">Discount ({{ DISCOUNT_PERCENT }}%)</div>
                <div class="total-amount">-₦{{ DISCOUNT_AMOUNT }}</div>
            </div>
            <div class="total-row grand-total">
                <div class="total-label">Grand Total</div>
                <div class="total-amount">₦{{ GRAND_TOTAL }}</div>
            </div>
        </div>
        
        {{ TERMS_SECTION }}
        
        <div class="footer">
            <p>Generated on {{ GENERATED_DATE }}</p>
        </div>
    </div>
</body>
</html>
HTML;

    // Build items table
    $itemsHtml = '';
    foreach ($lineItems as $item) {
        $desc = htmlspecialchars($item['description'] ?? '');
        $qty = floatval($item['quantity'] ?? 0);
        $rate = floatval($item['rate'] ?? 0);
        $amount = $qty * $rate;
        
        $itemsHtml .= '<tr>';
        $itemsHtml .= '<td>' . $desc . '</td>';
        $itemsHtml .= '<td style="text-align: center;">' . number_format($qty, 2) . '</td>';
        $itemsHtml .= '<td style="text-align: right;">₦' . number_format($rate, 2) . '</td>';
        $itemsHtml .= '<td style="text-align: right;">₦' . number_format($amount, 2) . '</td>';
        $itemsHtml .= '</tr>';
    }

    // Build terms section
    $termsHtml = '';
    if ($paymentTerms || $notes) {
        $termsHtml = '<div class="terms">';
        if ($paymentTerms) {
            $termsHtml .= '<div class="terms-label">Payment Terms</div>';
            $termsHtml .= '<div>' . nl2br($paymentTerms) . '</div>';
        }
        if ($notes) {
            $termsHtml .= '<div class="terms-label" style="margin-top: 10px;">Notes</div>';
            $termsHtml .= '<div>' . nl2br($notes) . '</div>';
        }
        $termsHtml .= '</div>';
    }

    // Calculate discount amount
    $discountAmount = ($subtotal * $discountPercent) / 100;

    // Replace placeholders
    $html = str_replace('{{ INVOICE_NUMBER }}', $invoiceNumber, $html);
    $html = str_replace('{{ INVOICE_DATE }}', $invoiceDate, $html);
    $html = str_replace('{{ DUE_DATE }}', $dueDate, $html);
    $html = str_replace('{{ BUSINESS_NAME }}', $businessName, $html);
    $html = str_replace('{{ BUSINESS_EMAIL }}', $businessEmail, $html);
    $html = str_replace('{{ BUSINESS_PHONE }}', $businessPhone, $html);
    $html = str_replace('{{ CUSTOMER_NAME }}', $customerName, $html);
    $html = str_replace('{{ CUSTOMER_EMAIL }}', $customerEmail, $html);
    $html = str_replace('{{ CUSTOMER_PHONE }}', $customerPhone, $html);
    $html = str_replace('{{ ITEMS_TABLE }}', $itemsHtml, $html);
    $html = str_replace('{{ SUBTOTAL }}', number_format($subtotal, 2), $html);
    $html = str_replace('{{ TAX_RATE }}', number_format($taxRate, 2), $html);
    $html = str_replace('{{ TAX_AMOUNT }}', number_format($taxAmount, 2), $html);
    $html = str_replace('{{ DISCOUNT_PERCENT }}', number_format($discountPercent, 2), $html);
    $html = str_replace('{{ DISCOUNT_AMOUNT }}', number_format($discountAmount, 2), $html);
    $html = str_replace('{{ GRAND_TOTAL }}', number_format($grandTotal, 2), $html);
    $html = str_replace('{{ TERMS_SECTION }}', $termsHtml, $html);
    $html = str_replace('{{ GENERATED_DATE }}', date('Y-m-d H:i:s'), $html);

    // Initialize mPDF with A5 format
    $mpdf = new Mpdf([
        'format' => 'A5',
        'orientation' => 'P',
        'margin_left' => 5,
        'margin_right' => 5,
        'margin_top' => 5,
        'margin_bottom' => 5,
    ]);

    // Write HTML to PDF
    $mpdf->WriteHTML($html);

    // Output PDF
    $filename = str_replace(['/', '\\', ' '], '_', $invoiceNumber) . '.pdf';
    $mpdf->Output($filename, 'D'); // 'D' for download

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'message' => 'Error generating PDF: ' . $e->getMessage()
    ]);
}
?>
