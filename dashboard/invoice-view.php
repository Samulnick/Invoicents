<?php
// Start session and check authentication
session_start();

// Include database connection
require_once '../config/config.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.html");
    exit();
}

$user_id = $_SESSION['user_id'];

// Get invoice ID from URL parameter
$invoice_id = isset($_GET['id']) ? intval($_GET['id']) : null;

if (!$invoice_id) {
    header("Location: invoices.php");
    exit();
}

// Get user information
$userQuery = "SELECT first_name, last_name, email FROM users WHERE id = ?";
$stmt = $db->prepare($userQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

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
        u.business_email
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

// If invoice not found, redirect
if (!$invoice) {
    header("Location: invoices.php");
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

$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];
$userEmail = $user['email'];

// Set default values
$businessName = $invoice['business_name'] ?: 'Your Business';
$businessAddress = $invoice['business_address'] ?: '';
$businessPhone = $invoice['business_phone'] ?: '';
$businessEmail = $invoice['business_email'] ?: '';

$customerName = $invoice['customer_name'] ?: 'N/A';
$customerAddress = $invoice['customer_address'] ?: '';
$customerPhone = $invoice['customer_phone'] ?: '';
$customerEmail = $invoice['customer_email'] ?: '';

$invoiceNumber = $invoice['invoice_number'];
$invoiceDate = $invoice['created_date'];
$dueDate = $invoice['due_date'];
$status = ucfirst($invoice['status']);
$currency = $invoice['currency'] ?: '₦ NGN';
$paymentTerms = $invoice['payment_terms'] ?: 'Net 30';
$subtotal = $invoice['subtotal'];
$taxAmount = $invoice['tax_amount'];
$totalAmount = $invoice['total_amount'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice Details - Invoicent</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
	<link rel="stylesheet" href="../css/invoice-view.css">
    
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar Navigation -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-file-invoice-dollar sidebar-logo"></i>
                <h2>Invoicent</h2>
            </div>

            <nav class="sidebar-nav">
                <li><a href="index.php"><i class="fas fa-home"></i><span>Dashboard</span></a></li>
                <li><a href="invoices.php"><i class="fas fa-file-invoice"></i><span>Invoices</span></a></li>
                <li><a href="invoice-create.php"><i class="fas fa-plus-circle"></i><span>New Invoice</span></a></li>
                <li><a href="customers.php"><i class="fas fa-users"></i><span>Customers</span></a></li>
                <li><a href="settings.php"><i class="fas fa-cog"></i><span>Settings</span></a></li>
                <li><a href="profile.php"><i class="fas fa-user-circle"></i><span>Profile</span></a></li>
                <li><hr style="border: none; border-top: 1px solid rgba(255, 255, 255, 0.1); margin: 20px 0;"></li>
                <li><a href="../api/logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation Bar -->
            <nav class="navbar">
                <div class="navbar-left">
                    <h3 class="navbar-title">Invoice Details</h3>
                </div>
                <div class="navbar-right">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($userFirstName, 0, 1)); ?></div>
                        <div>
                            <strong><?php echo htmlspecialchars($userName); ?></strong>
                            <div style="font-size: 12px; color: #999;"><?php echo htmlspecialchars($userEmail); ?></div>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Content Area -->
            <div class="content">
                <!-- Invoice Header -->
                <div class="invoice-header">
                    <div>
                        <h1><?php echo htmlspecialchars($invoiceNumber); ?></h1>
                        <span class="status-badge status-<?php echo strtolower($invoice['status']); ?>"><?php echo $status; ?></span>
                    </div>
                    <div class="invoice-actions">
                        <button class="btn btn-primary" onclick="printInvoice()">
                            <i class="fas fa-print"></i> Print
                        </button>
                        <button class="btn btn-primary" onclick="downloadPDF()">
                            <i class="fas fa-download"></i> Download PDF
                        </button>
                        <button class="btn btn-secondary" onclick="sendEmail()">
                            <i class="fas fa-envelope"></i> Send Email
                        </button>
                        <button class="btn btn-secondary" onclick="sendWhatsApp()">
                            <i class="fab fa-whatsapp"></i> WhatsApp
                        </button>
                        <button class="btn btn-outline" onclick="window.location.href='invoice-create.php?id=<?php echo $invoice_id; ?>'">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                    </div>
                </div>

                <!-- Invoice Details Grid -->
                <div class="invoice-details">
                    <!-- Business Information -->
                    <div class="detail-section">
                        <h3><i class="fas fa-building"></i> Business Information</h3>
                        <div class="detail-item">
                            <span class="detail-label">Business Name:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($businessName); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Address:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($businessAddress ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Phone:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($businessPhone ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($businessEmail ?: 'N/A'); ?></span>
                        </div>
                    </div>

                    <!-- Customer Information -->
                    <div class="detail-section">
                        <h3><i class="fas fa-user"></i> Customer Information</h3>
                        <div class="detail-item">
                            <span class="detail-label">Customer Name:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customerName); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Address:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customerAddress ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Phone:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customerPhone ?: 'N/A'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Email:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($customerEmail ?: 'N/A'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Invoice Dates and Numbers -->
                <div class="invoice-details">
                    <div class="detail-section">
                        <h3><i class="fas fa-calendar"></i> Dates & References</h3>
                        <div class="detail-item">
                            <span class="detail-label">Invoice Date:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($invoiceDate); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Due Date:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($dueDate); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Invoice Number:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($invoiceNumber); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Currency:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($currency); ?></span>
                        </div>
                    </div>

                    <div class="detail-section">
                        <h3><i class="fas fa-info-circle"></i> Additional Information</h3>
                        <div class="detail-item">
                            <span class="detail-label">Status:</span>
                            <span class="detail-value"><?php echo $status; ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Payment Terms:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($paymentTerms); ?></span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Notes:</span>
                            <span class="detail-value"><?php echo htmlspecialchars($invoice['notes'] ?: 'N/A'); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Invoice Items Table -->
                <h3 style="margin-top: 30px; margin-bottom: 20px;"><i class="fas fa-list"></i> Invoice Items</h3>
                <table class="invoice-items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Description</th>
                            <th style="text-align: right;">Quantity</th>
                            <th style="text-align: right;">Rate</th>
                            <th style="text-align: right;">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo htmlspecialchars($item['item_description']); ?></td>
                            <td style="text-align: right;"><?php echo htmlspecialchars($item['quantity']); ?></td>
                            <td style="text-align: right;">₦<?php echo number_format($item['rate'], 2); ?></td>
                            <td style="text-align: right;">₦<?php echo number_format($item['amount'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Totals Section -->
                <div class="totals-section">
                    <div class="total-item subtotal">
                        <span>Subtotal:</span>
                        <span>₦<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    <div class="total-item tax">
                        <span>Tax:</span>
                        <span>₦<?php echo number_format($taxAmount, 2); ?></span>
                    </div>
                    <div class="total-item total">
                        <span>Total Amount:</span>
                        <span>₦<?php echo number_format($totalAmount, 2); ?></span>
                    </div>
                </div>

                <!-- Signatures Section -->
                <div class="signature-section">
                    <div class="signature-box">
                        <div style="height: 50px;"></div>
                        <div class="signature-label">For: <?php echo htmlspecialchars($businessName); ?></div>
                    </div>
                    <div class="signature-box">
                        <div style="height: 50px;"></div>
                        <div class="signature-label">Customer Signature</div>
                    </div>
                </div>

                <!-- Print Button -->
                <div class="print-button">
                    <button class="btn btn-primary" onclick="printInvoice()" style="width: 100%;">
                        <i class="fas fa-print"></i> Print Invoice
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Print invoice
        function printInvoice() {
            window.print();
        }

        // Download as PDF (Production-Ready with mPDF)
        function downloadPDF() {
            const invoiceId = <?php echo $invoice_id; ?>;
            const invoiceNumber = '<?php echo htmlspecialchars($invoiceNumber); ?>';
            
            try {
                // Redirect to PDF generation endpoint
                window.location.href = `../api/invoice-pdf.php?id=${invoiceId}`;
            } catch (error) {
                alert('Error generating PDF. Please try again.');
                console.error('PDF Error:', error);
            }
        }

        // Send via email
        function sendEmail() {
            const customerEmail = '<?php echo htmlspecialchars($customerEmail); ?>';
            if (customerEmail && customerEmail !== 'N/A') {
                alert(`Sending invoice to ${customerEmail}...`);
                // In production: call API endpoint to send email
                 window.location.href = `../api/invoice-email.php?id=<?php echo $invoice_id; ?>`;
            } else {
                alert('Customer email not available');
            }
        }

        // Send via WhatsApp
        function sendWhatsApp() {
            const customerPhone = '<?php echo htmlspecialchars($customerPhone); ?>';
            if (customerPhone && customerPhone !== 'N/A') {
                alert(`Sending invoice via WhatsApp to ${customerPhone}...`);
                // In production: integrate with WhatsApp API
            } else {
                alert('Customer phone not available');
            }
        }
    </script>
</body>
</html>