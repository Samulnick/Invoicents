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

// Get next invoice number
$invoiceQuery = "SELECT MAX(CAST(SUBSTRING(invoice_number, 5) AS UNSIGNED)) as max_number FROM invoices WHERE user_id = ?";
$stmt = $db->prepare($invoiceQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$invoiceResult = $stmt->get_result();
$invoiceRow = $invoiceResult->fetch_assoc();
$stmt->close();
$nextNumber = ($invoiceRow['max_number'] ?? 0) + 1;
$invoiceNumber = 'INV-' . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);

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
                                        <input type="number" id="taxRate" min="0" max="100" step="0.01" value="0" onchange="calculateTotals()">
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
                                    <textarea id="paymentTerms" placeholder="e.g., Payment due within 30 days"></textarea>
                                </div>
                                <div class="form-group">
                                    <label>Additional Notes</label>
                                    <textarea id="notes" placeholder="Any additional notes for the customer"></textarea>
                                </div>
                            </div>

                            <!-- Actions -->
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" name="action" value="draft" class="btn btn-secondary">
                                    <i class="fas fa-save"></i> Save Draft
                                </button>
                                <button type="button" class="btn btn-success" onclick="generatePDF()">
                                    <i class="fas fa-download"></i> Download PDF
                                </button>
                                <button type="submit" name="action" value="send" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Send Invoice
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Invoice Preview -->
                    <div class="invoice-preview" id="invoicePreview">
                        <div class="invoice-header">
                            <div style="text-align: center; margin-bottom: 10px;">
                                <i class="fas fa-file-invoice-dollar" style="font-size: 32px; color: #667eea;"></i>
                            </div>
                            <div class="invoice-number" id="previewInvoiceNumber"><?php echo htmlspecialchars($invoiceNumber); ?></div>
                            <div style="font-size: 12px; color: #999;">Invoice</div>
                        </div>

                        <div class="invoice-meta">
                            <div>
                                <div class="invoice-section-title">Invoice Date</div>
                                <div id="previewInvoiceDate"><?php echo $today; ?></div>
                            </div>
                            <div>
                                <div class="invoice-section-title">Due Date</div>
                                <div id="previewDueDate"><?php echo $dueDate; ?></div>
                            </div>
                        </div>

                        <div class="invoice-section">
                            <div class="invoice-section-title">From</div>
                            <div style="font-size: 13px;">
                                <strong><?php echo htmlspecialchars($businessName); ?></strong><br>
                                <?php echo htmlspecialchars($businessEmail); ?><br>
                                <?php echo htmlspecialchars($businessPhone); ?>
                            </div>
                        </div>

                        <div class="invoice-section">
                            <div class="invoice-section-title">Bill To</div>
                            <div style="font-size: 13px;">
                                <strong id="previewCustomerName">-</strong><br>
                                <span id="previewCustomerEmail">-</span><br>
                                <span id="previewCustomerPhone">-</span>
                            </div>
                        </div>

                        <table class="invoice-table">
                            <thead>
                                <tr>
                                    <th>Description</th>
                                    <th>Qty</th>
                                    <th>Rate</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody id="previewLineItems">
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #999;">No items yet</td>
                                </tr>
                            </tbody>
                        </table>

                        <div class="invoice-totals">
                            <div class="totals-box">
                                <div class="total-row">
                                    <span>Subtotal</span>
                                    <span id="previewSubtotal">₦0.00</span>
                                </div>
                                <div class="total-row">
                                    <span>Tax (<span id="previewTaxRate">0</span>%)</span>
                                    <span id="previewTaxAmount">₦0.00</span>
                                </div>
                                <div class="total-row">
                                    <span>Discount (<span id="previewDiscountPercent">0</span>%)</span>
                                    <span id="previewDiscountAmount">-₦0.00</span>
                                </div>
                                <div class="total-row grand-total">
                                    <span>Grand Total</span>
                                    <span id="previewGrandTotal">₦0.00</span>
                                </div>
                            </div>
                        </div>

                        <div class="invoice-section" id="previewPaymentTerms" style="display: none;">
                            <div class="invoice-section-title">Payment Terms</div>
                            <div style="font-size: 12px;" id="previewPaymentTermsText"></div>
                        </div>

                        <div class="invoice-section" id="previewNotes" style="display: none;">
                            <div class="invoice-section-title">Notes</div>
                            <div style="font-size: 12px;" id="previewNotesText"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script>
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

        // Update preview
        function updatePreview() {
            document.getElementById('previewInvoiceNumber').textContent = document.getElementById('invoiceNumber').value || 'INV-001';
            document.getElementById('previewInvoiceDate').textContent = document.getElementById('invoiceDate').value || '-';
            document.getElementById('previewDueDate').textContent = document.getElementById('dueDate').value || '-';
            document.getElementById('previewCustomerName').textContent = document.getElementById('customerName').value || '-';
            document.getElementById('previewCustomerEmail').textContent = document.getElementById('customerEmail').value || '-';
            document.getElementById('previewCustomerPhone').textContent = document.getElementById('customerPhone').value || '-';

            // Update line items
            let lineItemsHtml = '';
            let items = document.querySelectorAll('.item-row');
            if (items.length > 0 && items[0].querySelector('.item-description').value) {
                items.forEach(row => {
                    const desc = row.querySelector('.item-description').value;
                    const qty = row.querySelector('.item-quantity').value;
                    const rate = row.querySelector('.item-rate').value;
                    const amount = row.querySelector('.item-amount').value;
                    
                    if (desc && qty && rate) {
                        lineItemsHtml += `
                            <tr>
                                <td>${desc}</td>
                                <td>${qty}</td>
                                <td>₦${parseFloat(rate).toFixed(2)}</td>
                                <td>₦${parseFloat(amount).toFixed(2)}</td>
                            </tr>
                        `;
                    }
                });
            }

            if (lineItemsHtml) {
                document.getElementById('previewLineItems').innerHTML = lineItemsHtml;
            } else {
                document.getElementById('previewLineItems').innerHTML = '<tr><td colspan="4" style="text-align: center; color: #999;">No items yet</td></tr>';
            }

            // Update totals
            document.getElementById('previewSubtotal').textContent = '₦' + (document.getElementById('subtotal').value || '0');
            document.getElementById('previewTaxRate').textContent = document.getElementById('taxRate').value || '0';
            document.getElementById('previewTaxAmount').textContent = '₦' + (document.getElementById('taxAmount').value || '0');
            document.getElementById('previewDiscountPercent').textContent = document.getElementById('discountPercent').value || '0';
            document.getElementById('previewDiscountAmount').textContent = '-₦' + (((parseFloat(document.getElementById('subtotal').value || 0) * parseFloat(document.getElementById('discountPercent').value || 0)) / 100).toFixed(2));
            document.getElementById('previewGrandTotal').textContent = '₦' + (document.getElementById('grandTotal').value || '0');

            // Update notes and terms
            const paymentTerms = document.getElementById('paymentTerms').value;
            if (paymentTerms) {
                document.getElementById('previewPaymentTerms').style.display = 'block';
                document.getElementById('previewPaymentTermsText').textContent = paymentTerms;
            } else {
                document.getElementById('previewPaymentTerms').style.display = 'none';
            }

            const notes = document.getElementById('notes').value;
            if (notes) {
                document.getElementById('previewNotes').style.display = 'block';
                document.getElementById('previewNotesText').textContent = notes;
            } else {
                document.getElementById('previewNotes').style.display = 'none';
            }
        }
		
		
// Generate PDF using server-side mPDF
async function generatePDF() {
    try {
        // Validate customer
        const customerName = document.getElementById('customerName').value.trim();
        if (!customerName) {
            alert('Please enter a customer name.');
            return;
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
            return;
        }

        // Build payload expected by generate-invoice-pdf.php
        const invoiceData = {
            invoiceNumber: document.getElementById('invoiceNumber').value,
            invoiceDate: document.getElementById('invoiceDate').value,
            dueDate: document.getElementById('dueDate').value,

            customerName: document.getElementById('customerName').value,
            customerEmail: document.getElementById('customerEmail').value,
            customerPhone: document.getElementById('customerPhone').value,
            customerAddress: document.getElementById('customerAddress').value,

            subtotal: parseFloat(document.getElementById('subtotal').textContent.replace(/[₦,]/g, '')) || 0,
            taxRate: parseFloat(document.getElementById('taxRate').value) || 0,
            taxAmount: parseFloat(document.getElementById('taxAmount').textContent.replace(/[₦,]/g, '')) || 0,
            discountPercent: parseFloat(document.getElementById('discountPercent').value) || 0,
            grandTotal: parseFloat(document.getElementById('grandTotal').textContent.replace(/[₦,]/g, '')) || 0,

            paymentTerms: document.getElementById('paymentTerms').value,
            notes: document.getElementById('notes').value,

            lineItems: lineItems
        };

        // Send to PHP
        const response = await fetch('generate-invoice-pdf.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(invoiceData)
        });

        if (!response.ok) {
            const text = await response.text();
            throw new Error(text);
        }

        // Download returned PDF
        const blob = await response.blob();

        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');

        a.href = url;
        a.download = invoiceData.invoiceNumber + '.pdf';

        document.body.appendChild(a);
        a.click();

        a.remove();
        window.URL.revokeObjectURL(url);

    } catch (error) {
        console.error(error);
        alert('Error generating PDF: ' + error.message);
    }
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
