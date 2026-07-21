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

// Get user information
$userQuery = "SELECT first_name, last_name, email, phone, business_name, business_address FROM users WHERE id = ?";
$stmt = $db->prepare($userQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

// Get user settings
$settingsQuery = "SELECT 
    currency, invoice_prefix, default_tax_rate, payment_terms, invoice_notes
FROM users WHERE id = ?";
$stmt = $db->prepare($settingsQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$settingsResult = $stmt->get_result();
$settings = $settingsResult->fetch_assoc();
$stmt->close();

// Set defaults for missing settings
$currency = $settings['currency'] ?? 'NGN';
$invoicePrefix = $settings['invoice_prefix'] ?? 'INV';
$defaultTaxRate = $settings['default_tax_rate'] ?? '0';
$defaultPaymentTerms = $settings['payment_terms'] ?? 'Payment due within 30 days';
$defaultInvoiceNotes = $settings['invoice_notes'] ?? '';

// Get next invoice number
$invoiceQuery = "SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_number FROM invoices WHERE user_id = ?";
$stmt = $db->prepare($invoiceQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$invoiceResult = $stmt->get_result();
$invoiceRow = $invoiceResult->fetch_assoc();
$stmt->close();
$nextNumber = ($invoiceRow['max_number'] ?? 0) + 1;
$invoiceNumber = $invoicePrefix . '-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

// Get customer list for autocomplete
$customersQuery = "SELECT id, name, email, phone, address FROM customers WHERE user_id = ? ORDER BY name";
$stmt = $db->prepare($customersQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$customersResult = $stmt->get_result();
$customers = [];
while ($row = $customersResult->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

$businessName = $user['business_name'] ?? 'Your Business';
$businessEmail = $user['email'] ?? '';
$businessPhone = $user['phone'] ?? '';
$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];

// Set default dates
$today = date('Y-m-d');
$dueDate = date('Y-m-d', strtotime('+30 days'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Invoice - Invoicent</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
	<link rel="stylesheet" href="../css/invoice-create.css">
    <style>
        /* A5 Page Styling for PDF */
        @media print {
            .invoice-page {
                page-break-after: always;
                page-break-inside: avoid;
            }
        }
        
        .invoice-page {
            width: 148mm;
            height: 210mm;
            padding: 10mm;
            box-sizing: border-box;
            background: white;
            margin: 0 auto 20px;
            display: flex;
            flex-direction: column;
        }
    </style>
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
                <li><a href="invoice-create.php" class="active"><i class="fas fa-plus-circle"></i><span>New Invoice</span></a></li>
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
                    <h3 class="navbar-title">Create New Invoice</h3>
                </div>
                <div class="navbar-right">
                    <div class="user-profile">
                        <div class="user-avatar"><?php echo strtoupper(substr($userFirstName, 0, 1)); ?></div>
                        <div>
                            <strong><?php echo htmlspecialchars($userName); ?></strong>
                            <div style="font-size: 12px; color: #999;"><?php echo htmlspecialchars($businessEmail); ?></div>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Content Area -->
            <div class="content">
                <div class="invoice-builder">
                    <!-- Invoice Form -->
                    <div class="invoice-form">
                        <form id="invoiceForm" onsubmit="handleFormSubmit(event)">
                            <!-- Invoice Details -->
                            <div class="form-section">
                                <h4><i class="fas fa-file-alt"></i> Invoice Details</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Invoice Number</label>
                                        <input type="text" id="invoiceNumber" placeholder="INV-001" value="<?php echo htmlspecialchars($invoiceNumber); ?>" readonly>
                                    </div>
                                    <div class="form-group">
                                        <label>Invoice Date</label>
                                        <input type="date" id="invoiceDate" value="<?php echo $today; ?>" required>
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Due Date</label>
                                        <input type="date" id="dueDate" value="<?php echo $dueDate; ?>" required>
                                    </div>
                                    <div class="form-group">
                                        <label>Status</label>
                                        <select id="invoiceStatus">
                                            <option value="draft">Draft</option>
                                            <option value="sent">Sent</option>
                                            <option value="paid">Paid</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Customer Information -->
                            <div class="form-section">
                                <h4><i class="fas fa-user"></i> Bill To (Customer)</h4>
                                <div class="form-group">
                                    <label>Customer Name</label>
                                    <input type="text" id="customerName" placeholder="Customer or Business Name" list="customerList" required>
                                    <datalist id="customerList">
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo htmlspecialchars($customer['name']); ?>" data-id="<?php echo $customer['id']; ?>">
                                        <?php endforeach; ?>
                                    </datalist>
                                </div>
                                <div class="form-group">
                                    <label>Email</label>
                                    <input type="email" id="customerEmail" placeholder="customer@example.com" required>
                                </div>
                                <div class="form-group">
                                    <label>Address</label>
                                    <textarea id="customerAddress" placeholder="Full address"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Phone</label>
                                    <input type="tel" id="customerPhone" placeholder="+234...">
                                </div>
                            </div>

                            <!-- Line Items -->
                            <div class="form-section">
                                <h4><i class="fas fa-list"></i> Line Items</h4>
                                <div id="lineItemsContainer">
                                    <div class="item-row">
                                        <input type="text" placeholder="Description" class="item-description">
                                        <input type="number" placeholder="Qty" class="item-quantity" value="1" min="1">
                                        <input type="number" placeholder="Rate" class="item-rate" min="0" step="0.01">
                                        <input type="number" placeholder="Amount" class="item-amount" readonly>
                                        <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(this)">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-secondary" onclick="addLineItem()">
                                    <i class="fas fa-plus"></i> Add Item
                                </button>
                            </div>

                            <!-- Totals -->
                            <div class="form-section">
                                <h4><i class="fas fa-calculator"></i> Totals</h4>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Subtotal</label>
                                        <input type="number" id="subtotal" readonly value="0">
                                    </div>
                                    <div class="form-group">
                                        <label>Tax Rate (%)</label>
                                        <input type="number" id="taxRate" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($defaultTaxRate); ?>" onchange="calculateTotals()">
                                    </div>
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label>Tax Amount</label>
                                        <input type="number" id="taxAmount" readonly value="0">
                                    </div>
                                    <div class="form-group">
                                        <label>Discount (%)</label>
                                        <input type="number" id="discountPercent" min="0" max="100" step="0.01" value="0" onchange="calculateTotals()">
                                    </div>
                                </div>
                                <div class="form-row full">
                                    <div class="form-group">
                                        <label><strong>Grand Total</strong></label>
                                        <input type="number" id="grandTotal" readonly value="0" style="font-size: 18px; font-weight: 700;">
                                    </div>
                                </div>
                            </div>

                            <!-- Notes -->
                            <div class="form-section">
                                <h4><i class="fas fa-sticky-note"></i> Notes & Terms</h4>
                                <div class="form-group">
                                    <label>Payment Terms</label>
                                    <textarea id="paymentTerms" placeholder="e.g., Payment due within 30 days"><?php echo htmlspecialchars($defaultPaymentTerms); ?></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Additional Notes</label>
                                    <textarea id="notes" placeholder="Any additional notes for the customer"><?php echo htmlspecialchars($defaultInvoiceNotes); ?></textarea>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                                <button type="submit" name="action" value="draft" class="btn btn-secondary">
                                    <i class="fas fa-save"></i> Save Draft
                                </button>
                                <button type="button" class="btn btn-info" onclick="previewPDF()">
                                    <i class="fas fa-eye"></i> Preview
                                </button>
                                <button type="button" class="btn btn-success" onclick="downloadPDF()">
                                    <i class="fas fa-download"></i> Download PDF
                                </button>
                                <button type="submit" name="action" value="send" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Send Invoice
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Invoice Preview -->
                    <div id="invoicePreviewContainer" style="display: none;">
                        <div id="invoicePages"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal for PDF Preview -->
    <div id="pdfPreviewModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 1000; overflow: auto;">
        <div style="position: relative; background: white; margin: 20px auto; padding: 20px; border-radius: 8px; width: 95%; max-width: 900px; max-height: 90vh; overflow: auto;">
            <button onclick="closePdfPreview()" style="position: absolute; top: 10px; right: 10px; background: #667eea; color: white; border: none; padding: 10px 15px; border-radius: 4px; cursor: pointer; font-size: 16px;">Close</button>
            <div id="pdfContainer" style="margin-top: 40px;"></div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
        const { jsPDF } = window.jspdf;
        const A5_WIDTH = 148;
        const A5_HEIGHT = 210;
        const PAGE_MARGIN = 10;
        const USABLE_HEIGHT = A5_HEIGHT - (PAGE_MARGIN * 2);

        // Add line item
        function addLineItem() {
            const container = document.getElementById('lineItemsContainer');
            const row = document.createElement('div');
            row.className = 'item-row';
            row.innerHTML = `
                <input type="text" placeholder="Description" class="item-description">
                <input type="number" placeholder="Qty" class="item-quantity" value="1" min="1">
                <input type="number" placeholder="Rate" class="item-rate" min="0" step="0.01">
                <input type="number" placeholder="Amount" class="item-amount" readonly>
                <button type="button" class="btn btn-danger btn-sm" onclick="removeItem(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(row);
            attachItemListeners(row);
        }

        // Remove line item
        function removeItem(btn) {
            btn.closest('.item-row').remove();
            calculateTotals();
        }

        // Attach event listeners to line items
        function attachItemListeners(row) {
            const quantityInput = row.querySelector('.item-quantity');
            const rateInput = row.querySelector('.item-rate');
            const amountInput = row.querySelector('.item-amount');

            quantityInput.addEventListener('change', function() {
                const amount = parseFloat(quantityInput.value || 0) * parseFloat(rateInput.value || 0);
                amountInput.value = amount.toFixed(2);
                calculateTotals();
                updatePreview();
            });

            rateInput.addEventListener('change', function() {
                const amount = parseFloat(quantityInput.value || 0) * parseFloat(rateInput.value || 0);
                amountInput.value = amount.toFixed(2);
                calculateTotals();
                updatePreview();
            });

            row.querySelector('.item-description').addEventListener('input', updatePreview);
        }

        // Attach listeners to initial line item
        document.querySelectorAll('.item-row').forEach(attachItemListeners);

        // Calculate totals
        function calculateTotals() {
            let subtotal = 0;
            document.querySelectorAll('.item-amount').forEach(input => {
                subtotal += parseFloat(input.value || 0);
            });

            const taxRate = parseFloat(document.getElementById('taxRate').value || 0);
            const discountPercent = parseFloat(document.getElementById('discountPercent').value || 0);

            const taxAmount = (subtotal * taxRate) / 100;
            const discountAmount = (subtotal * discountPercent) / 100;
            const grandTotal = subtotal + taxAmount - discountAmount;

            document.getElementById('subtotal').value = subtotal.toFixed(2);
            document.getElementById('taxAmount').value = taxAmount.toFixed(2);
            document.getElementById('grandTotal').value = grandTotal.toFixed(2);

            updatePreview();
        }

        // Generate HTML invoice content
        function generateInvoiceHTML(includeTerms = false) {
            const html = `
                <div style="font-family: Arial, sans-serif; font-size: 11px; color: #333; padding: 5mm;">
                    <div style="text-align: center; margin-bottom: 10px;">
                        <div style="font-size: 18px; font-weight: bold; color: #667eea;">INVOICE</div>
                        <div style="font-size: 14px; font-weight: bold;">${document.getElementById('invoiceNumber').value || 'INV-001'}</div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; font-size: 10px;">
                        <div>
                            <div style="font-weight: bold; font-size: 11px;">Invoice Date</div>
                            <div>${document.getElementById('invoiceDate').value || '-'}</div>
                        </div>
                        <div>
                            <div style="font-weight: bold; font-size: 11px;">Due Date</div>
                            <div>${document.getElementById('dueDate').value || '-'}</div>
                        </div>
                    </div>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 10px; font-size: 10px;">
                        <div>
                            <div style="font-weight: bold; margin-bottom: 3px;">From:</div>
                            <div style="font-weight: bold;"><?php echo htmlspecialchars($businessName); ?></div>
                            <div><?php echo htmlspecialchars($businessEmail); ?></div>
                            <div><?php echo htmlspecialchars($businessPhone); ?></div>
                        </div>
                        <div>
                            <div style="font-weight: bold; margin-bottom: 3px;">Bill To:</div>
                            <div style="font-weight: bold;">${document.getElementById('customerName').value || '-'}</div>
                            <div>${document.getElementById('customerEmail').value || '-'}</div>
                            <div>${document.getElementById('customerPhone').value || '-'}</div>
                            <div style="font-size: 9px; margin-top: 2px;">${document.getElementById('customerAddress').value || ''}</div>
                        </div>
                    </div>

                    <table style="width: 100%; border-collapse: collapse; margin-bottom: 8px; font-size: 10px;">
                        <thead>
                            <tr style="background-color: #f5f5f5; border-top: 1px solid #ddd; border-bottom: 1px solid #ddd;">
                                <th style="text-align: left; padding: 3px; font-weight: bold;">Description</th>
                                <th style="text-align: center; padding: 3px; font-weight: bold; width: 40px;">Qty</th>
                                <th style="text-align: right; padding: 3px; font-weight: bold; width: 50px;">Rate</th>
                                <th style="text-align: right; padding: 3px; font-weight: bold; width: 50px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody id="invoiceLineItemsHTML">
                            ${(() => {
                                let html = '';
                                document.querySelectorAll('.item-row').forEach(row => {
                                    const desc = row.querySelector('.item-description').value;
                                    const qty = row.querySelector('.item-quantity').value;
                                    const rate = row.querySelector('.item-rate').value;
                                    const amount = row.querySelector('.item-amount').value;
                                    
                                    if (desc && qty && rate) {
                                        html += `
                                            <tr style="border-bottom: 1px solid #eee;">
                                                <td style="padding: 3px;">${desc}</td>
                                                <td style="text-align: center; padding: 3px;">${qty}</td>
                                                <td style="text-align: right; padding: 3px;">₦${parseFloat(rate).toFixed(2)}</td>
                                                <td style="text-align: right; padding: 3px;">₦${parseFloat(amount).toFixed(2)}</td>
                                            </tr>
                                        `;
                                    }
                                });
                                return html || '<tr><td colspan="4" style="text-align: center; padding: 5px; color: #999;">No items</td></tr>';
                            })()}
                        </tbody>
                    </table>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 8px;">
                        <div></div>
                        <div style="font-size: 10px;">
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 5px; border-top: 1px solid #ddd; padding-top: 3px;">
                                <div>Subtotal:</div>
                                <div style="text-align: right;">₦${parseFloat(document.getElementById('subtotal').value || 0).toFixed(2)}</div>
                                <div>Tax (${document.getElementById('taxRate').value || '0'}%):</div>
                                <div style="text-align: right;">₦${parseFloat(document.getElementById('taxAmount').value || 0).toFixed(2)}</div>
                                <div>Discount (${document.getElementById('discountPercent').value || '0'}%):</div>
                                <div style="text-align: right;">-₦${(((parseFloat(document.getElementById('subtotal').value || 0) * parseFloat(document.getElementById('discountPercent').value || 0)) / 100).toFixed(2))}</div>
                                <div style="font-weight: bold; border-top: 1px solid #ddd; padding-top: 3px;">Grand Total:</div>
                                <div style="text-align: right; font-weight: bold; border-top: 1px solid #ddd; padding-top: 3px;">₦${parseFloat(document.getElementById('grandTotal').value || 0).toFixed(2)}</div>
                            </div>
                        </div>
                    </div>

                    ${includeTerms ? `
                        <div style="border-top: 1px solid #ddd; padding-top: 5px; font-size: 9px;">
                            ${document.getElementById('paymentTerms').value ? `
                                <div style="margin-bottom: 5px;">
                                    <div style="font-weight: bold;">Payment Terms:</div>
                                    <div>${document.getElementById('paymentTerms').value}</div>
                                </div>
                            ` : ''}
                            ${document.getElementById('notes').value ? `
                                <div>
                                    <div style="font-weight: bold;">Notes:</div>
                                    <div>${document.getElementById('notes').value}</div>
                                </div>
                            ` : ''}
                        </div>
                    ` : ''}
                </div>
            `;
            return html;
        }

        // Update preview
        function updatePreview() {
            // Real-time preview is optional, you can implement this if needed
        }

        // Generate PDF with multi-page support
        async function generatePDF() {
            try {
                // Validate customer
                const customerName = document.getElementById('customerName').value.trim();
                if (!customerName) {
                    alert('Please enter a customer name.');
                    return null;
                }

                // Collect line items
                const lineItems = [];
                let hasValidItems = false;

                document.querySelectorAll('.item-row').forEach(row => {
                    const description = row.querySelector('.item-description').value.trim();
                    const quantity = parseFloat(row.querySelector('.item-quantity').value) || 0;
                    const rate = parseFloat(row.querySelector('.item-rate').value) || 0;

                    if (description && quantity > 0) {
                        hasValidItems = true;
                    }

                    lineItems.push({
                        description: description,
                        quantity: quantity,
                        rate: rate
                    });
                });

                if (!hasValidItems) {
                    alert('Please add at least one valid line item.');
                    return null;
                }

                return {
                    invoiceNumber: document.getElementById('invoiceNumber').value,
                    invoiceDate: document.getElementById('invoiceDate').value,
                    dueDate: document.getElementById('dueDate').value,
                    customerName: document.getElementById('customerName').value,
                    customerEmail: document.getElementById('customerEmail').value,
                    customerPhone: document.getElementById('customerPhone').value,
                    customerAddress: document.getElementById('customerAddress').value,
                    subtotal: parseFloat(document.getElementById('subtotal').value || 0),
                    taxRate: parseFloat(document.getElementById('taxRate').value || 0),
                    taxAmount: parseFloat(document.getElementById('taxAmount').value || 0),
                    discountPercent: parseFloat(document.getElementById('discountPercent').value || 0),
                    grandTotal: parseFloat(document.getElementById('grandTotal').value || 0),
                    paymentTerms: document.getElementById('paymentTerms').value,
                    notes: document.getElementById('notes').value,
                    lineItems: lineItems
                };
            } catch (error) {
                console.error(error);
                alert('Error collecting invoice data: ' + error.message);
                return null;
            }
        }

        // Create multi-page PDF
        async function createMultiPagePDF() {
            const invoiceData = await generatePDF();
            if (!invoiceData) return null;

            try {
                const pdf = new jsPDF({
                    orientation: 'portrait',
                    unit: 'mm',
                    format: 'a5'
                });

                let currentPage = 1;
                let yPosition = PAGE_MARGIN;
                const maxY = A5_HEIGHT - PAGE_MARGIN;

                // Create temporary div for first page content
                const tempDiv = document.createElement('div');
                tempDiv.innerHTML = generateInvoiceHTML(false);
                tempDiv.style.width = (A5_WIDTH - PAGE_MARGIN * 2) + 'mm';
                tempDiv.style.display = 'none';
                document.body.appendChild(tempDiv);

                // Generate first page
                const canvas1 = await html2canvas(tempDiv, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff'
                });

                const imgHeight1 = (canvas1.height * A5_WIDTH) / canvas1.width;
                
                if (imgHeight1 > USABLE_HEIGHT) {
                    // Content exceeds page, needs continuation
                    const imgData1 = canvas1.toDataURL('image/png');
                    const scaledHeight = (A5_WIDTH - PAGE_MARGIN * 2);
                    pdf.addImage(imgData1, 'PNG', PAGE_MARGIN, PAGE_MARGIN, scaledHeight, imgHeight1);
                    
                    // Add second page for payment terms and notes
                    pdf.addPage('a5', 'portrait');
                    
                    const tempDiv2 = document.createElement('div');
                    tempDiv2.innerHTML = `
                        <div style="font-family: Arial, sans-serif; font-size: 11px; color: #333; padding: 5mm;">
                            <div style="font-size: 14px; font-weight: bold; margin-bottom: 10px; color: #667eea;">Invoice Settings & Terms</div>
                            
                            <div style="margin-bottom: 10px;">
                                <div style="font-weight: bold; margin-bottom: 5px;">Invoice Number:</div>
                                <div>${invoiceData.invoiceNumber}</div>
                            </div>

                            ${invoiceData.paymentTerms ? `
                                <div style="margin-bottom: 10px;">
                                    <div style="font-weight: bold; margin-bottom: 5px;">Payment Terms:</div>
                                    <div style="font-size: 10px; white-space: pre-wrap;">${invoiceData.paymentTerms}</div>
                                </div>
                            ` : ''}

                            ${invoiceData.notes ? `
                                <div style="margin-bottom: 10px;">
                                    <div style="font-weight: bold; margin-bottom: 5px;">Additional Notes:</div>
                                    <div style="font-size: 10px; white-space: pre-wrap;">${invoiceData.notes}</div>
                                </div>
                            ` : ''}

                            <div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 9px; color: #666;">
                                <div>Generated on: ${new Date().toLocaleDateString()}</div>
                                <div>Business: <?php echo htmlspecialchars($businessName); ?></div>
                            </div>
                        </div>
                    `;
                    tempDiv2.style.width = (A5_WIDTH - PAGE_MARGIN * 2) + 'mm';
                    tempDiv2.style.display = 'none';
                    document.body.appendChild(tempDiv2);

                    const canvas2 = await html2canvas(tempDiv2, {
                        scale: 2,
                        useCORS: true,
                        backgroundColor: '#ffffff'
                    });

                    const imgData2 = canvas2.toDataURL('image/png');
                    const scaledHeight2 = (A5_WIDTH - PAGE_MARGIN * 2);
                    pdf.addImage(imgData2, 'PNG', PAGE_MARGIN, PAGE_MARGIN, scaledHeight2, scaledHeight2);

                    document.body.removeChild(tempDiv2);
                } else {
                    // Single page, add to PDF
                    const imgData = canvas1.toDataURL('image/png');
                    const scaledHeight = (A5_WIDTH - PAGE_MARGIN * 2);
                    pdf.addImage(imgData, 'PNG', PAGE_MARGIN, PAGE_MARGIN, scaledHeight, imgHeight1);
                }

                document.body.removeChild(tempDiv);
                return pdf;
            } catch (error) {
                console.error(error);
                alert('Error creating PDF: ' + error.message);
                return null;
            }
        }

        // Preview PDF in modal
        async function previewPDF() {
            try {
                const pdf = await createMultiPagePDF();
                if (!pdf) return;

                // Display in modal
                const pdfBlob = pdf.output('blob');
                const pdfUrl = window.URL.createObjectURL(pdfBlob);
                const pdfContainer = document.getElementById('pdfContainer');
                pdfContainer.innerHTML = `<embed src="${pdfUrl}" type="application/pdf" style="width: 100%; height: 600px;" />`;
                
                document.getElementById('pdfPreviewModal').style.display = 'block';
            } catch (error) {
                console.error(error);
                alert('Error generating preview: ' + error.message);
            }
        }

        // Download PDF
        async function downloadPDF() {
            try {
                const invoiceData = await generatePDF();
                if (!invoiceData) return;

                const pdf = await createMultiPagePDF();
                if (!pdf) return;

                pdf.save(invoiceData.invoiceNumber + '.pdf');
            } catch (error) {
                console.error(error);
                alert('Error downloading PDF: ' + error.message);
            }
        }

        // Close PDF preview modal
        function closePdfPreview() {
            document.getElementById('pdfPreviewModal').style.display = 'none';
        }

        // Handle form submission
        function handleFormSubmit(event) {
            event.preventDefault();
            
            const action = event.submitter.value;
            const formData = new FormData(document.getElementById('invoiceForm'));
            formData.append('action', action);

            // Gather line items
            const lineItems = [];
            document.querySelectorAll('.item-row').forEach(row => {
                const description = row.querySelector('.item-description').value;
                const quantity = row.querySelector('.item-quantity').value;
                const rate = row.querySelector('.item-rate').value;
                
                if (description && quantity && rate) {
                    lineItems.push({
                        description: description,
                        quantity: quantity,
                        rate: rate
                    });
                }
            });

            const data = {
                action: action,
                invoiceNumber: document.getElementById('invoiceNumber').value,
                invoiceDate: document.getElementById('invoiceDate').value,
                dueDate: document.getElementById('dueDate').value,
                status: document.getElementById('invoiceStatus').value,
                customerName: document.getElementById('customerName').value,
                customerEmail: document.getElementById('customerEmail').value,
                customerAddress: document.getElementById('customerAddress').value,
                customerPhone: document.getElementById('customerPhone').value,
                lineItems: lineItems,
                subtotal: parseFloat(document.getElementById('subtotal').value),
                taxRate: parseFloat(document.getElementById('taxRate').value),
                taxAmount: parseFloat(document.getElementById('taxAmount').value),
                discountPercent: parseFloat(document.getElementById('discountPercent').value),
                grandTotal: parseFloat(document.getElementById('grandTotal').value),
                paymentTerms: document.getElementById('paymentTerms').value,
                notes: document.getElementById('notes').value
            };

            fetch('../api/invoice-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Invoice ' + action + ' successfully!');
                    if (action === 'send') {
                        window.location.href = 'invoices.php';
                    }
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the invoice.');
            });
        }

        // Add event listeners to all inputs
        document.getElementById('invoiceForm').addEventListener('input', updatePreview);
        document.getElementById('invoiceForm').addEventListener('change', updatePreview);
    </script>
</body>
</html>
