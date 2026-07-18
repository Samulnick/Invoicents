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

// Get user and settings information
$userQuery = "SELECT 
    first_name, last_name, email, phone,
    business_name, business_email, business_phone, business_address,
    currency, invoice_prefix, default_tax_rate, payment_terms, invoice_notes,
    auto_email_invoice, email_subject, email_message,
    auto_whatsapp_invoice, whatsapp_phone, whatsapp_message,
    notify_invoice_sent, notify_invoice_paid, notify_overdue
FROM users WHERE id = ?";

$stmt = $db->prepare($userQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];
$userEmail = $user['email'];

// Set defaults for missing settings
$settings = [
    'business_name' => $user['business_name'] ?? '',
    'business_email' => $user['business_email'] ?? $userEmail,
    'business_phone' => $user['business_phone'] ?? $user['phone'] ?? '',
    'business_address' => $user['business_address'] ?? '',
    'currency' => $user['currency'] ?? 'NGN',
    'invoice_prefix' => $user['invoice_prefix'] ?? 'INV',
    'default_tax_rate' => $user['default_tax_rate'] ?? '0',
    'payment_terms' => $user['payment_terms'] ?? 'Payment due within 30 days',
    'invoice_notes' => $user['invoice_notes'] ?? '',
    'auto_email_invoice' => $user['auto_email_invoice'] ?? 1,
    'email_subject' => $user['email_subject'] ?? 'Invoice #[INV_NUMBER]',
    'email_message' => $user['email_message'] ?? 'Dear [CUSTOMER_NAME]...',
    'auto_whatsapp_invoice' => $user['auto_whatsapp_invoice'] ?? 0,
    'whatsapp_phone' => $user['whatsapp_phone'] ?? '',
    'whatsapp_message' => $user['whatsapp_message'] ?? '',
    'notify_invoice_sent' => $user['notify_invoice_sent'] ?? 1,
    'notify_invoice_paid' => $user['notify_invoice_paid'] ?? 1,
    'notify_overdue' => $user['notify_overdue'] ?? 1,
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Invoicent</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
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
                <li><a href="settings.php" class="active"><i class="fas fa-cog"></i><span>Settings</span></a></li>
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
                    <h3 class="navbar-title">Settings</h3>
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
                <div style="display: grid; grid-template-columns: 250px 1fr; gap: 20px;">
                    <!-- Settings Sidebar -->
                    <div class="card" style="height: fit-content;">
                        <div class="card-body" style="padding: 0;">
                            <ul style="list-style: none;">
                                <li><a href="#business" class="settings-link active" onclick="switchTab(event, 'business')" style="display: block; padding: 15px; border-left: 4px solid #667eea; color: #667eea; text-decoration: none; transition: all 0.3s;"><i class="fas fa-building"></i> Business</a></li>
                                <li><a href="#invoice" class="settings-link" onclick="switchTab(event, 'invoice')" style="display: block; padding: 15px; border-left: 4px solid transparent; color: #333; text-decoration: none; transition: all 0.3s;"><i class="fas fa-file-alt"></i> Invoice</a></li>
                                <li><a href="#email" class="settings-link" onclick="switchTab(event, 'email')" style="display: block; padding: 15px; border-left: 4px solid transparent; color: #333; text-decoration: none; transition: all 0.3s;"><i class="fas fa-envelope"></i> Email</a></li>
                                <li><a href="#whatsapp" class="settings-link" onclick="switchTab(event, 'whatsapp')" style="display: block; padding: 15px; border-left: 4px solid transparent; color: #333; text-decoration: none; transition: all 0.3s;"><i class="fab fa-whatsapp"></i> WhatsApp</a></li>
                                <li><a href="#notifications" class="settings-link" onclick="switchTab(event, 'notifications')" style="display: block; padding: 15px; border-left: 4px solid transparent; color: #333; text-decoration: none; transition: all 0.3s;"><i class="fas fa-bell"></i> Notifications</a></li>
                            </ul>
                        </div>
                    </div>

                    <!-- Settings Content -->
                    <div>
                        <!-- Business Info Tab -->
                        <div id="business" class="settings-tab card" style="display: block;">
                            <div class="card-header">
                                <h3>Business Information</h3>
                            </div>
                            <form class="card-body" id="businessForm" onsubmit="saveBusinessSettings(event)">
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Business Name</label>
                                    <input type="text" id="businessName" placeholder="Your Business Name" value="<?php echo htmlspecialchars($settings['business_name']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Business Email</label>
                                    <input type="email" id="businessEmail" placeholder="business@example.com" value="<?php echo htmlspecialchars($settings['business_email']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Business Phone</label>
                                    <input type="tel" id="businessPhone" placeholder="+234..." value="<?php echo htmlspecialchars($settings['business_phone']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Business Address</label>
                                    <textarea id="businessAddress" placeholder="Full business address" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; min-height: 100px;"><?php echo htmlspecialchars($settings['business_address']); ?></textarea>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Currency</label>
                                    <select id="currency" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                        <option value="NGN" <?php echo $settings['currency'] === 'NGN' ? 'selected' : ''; ?>>Nigerian Naira (₦)</option>
                                        <option value="USD" <?php echo $settings['currency'] === 'USD' ? 'selected' : ''; ?>>US Dollar ($)</option>
                                        <option value="GBP" <?php echo $settings['currency'] === 'GBP' ? 'selected' : ''; ?>>British Pound (£)</option>
                                        <option value="EUR" <?php echo $settings['currency'] === 'EUR' ? 'selected' : ''; ?>>Euro (€)</option>
                                    </select>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Business Logo</label>
                                    <div style="padding: 20px; border: 2px dashed #ddd; border-radius: 6px; text-align: center; cursor: pointer;" onclick="document.getElementById('businessLogo').click()">
                                        <input type="file" id="businessLogo" accept="image/*" style="display: none;" onchange="handleLogoUpload(event)">
                                        <i class="fas fa-cloud-upload-alt" style="font-size: 28px; color: #667eea; margin-bottom: 10px; display: block;"></i>
                                        <p>Click to upload logo</p>
                                        <small style="color: #999;">Max 5MB</small>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Business Info
                                </button>
                            </form>
                        </div>

                        <!-- Invoice Settings Tab -->
                        <div id="invoice" class="settings-tab card" style="display: none;">
                            <div class="card-header">
                                <h3>Invoice Settings</h3>
                            </div>
                            <form class="card-body" id="invoiceForm" onsubmit="saveInvoiceSettings(event)">
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Invoice Prefix</label>
                                    <input type="text" id="invoicePrefix" placeholder="INV" value="<?php echo htmlspecialchars($settings['invoice_prefix']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Default Tax Rate (%)</label>
                                    <input type="number" id="defaultTaxRate" placeholder="0" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($settings['default_tax_rate']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Payment Terms</label>
                                    <textarea id="paymentTerms" placeholder="e.g., Payment due within 30 days" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; min-height: 100px;"><?php echo htmlspecialchars($settings['payment_terms']); ?></textarea>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Invoice Notes</label>
                                    <textarea id="invoiceNotes" placeholder="Default notes to appear on invoices" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; min-height: 100px;"><?php echo htmlspecialchars($settings['invoice_notes']); ?></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Invoice Settings
                                </button>
                            </form>
                        </div>

                        <!-- Email Settings Tab -->
                        <div id="email" class="settings-tab card" style="display: none;">
                            <div class="card-header">
                                <h3>Email Settings</h3>
                            </div>
                            <form class="card-body" id="emailForm" onsubmit="saveEmailSettings(event)">
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                        <input type="checkbox" id="autoEmailInvoice" <?php echo $settings['auto_email_invoice'] ? 'checked' : ''; ?>>
                                        <span>Automatically send invoice via email</span>
                                    </label>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Email Subject</label>
                                    <input type="text" id="emailSubject" placeholder="Invoice #[INV_NUMBER]" value="<?php echo htmlspecialchars($settings['email_subject']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Email Message</label>
                                    <textarea id="emailMessage" placeholder="Dear [CUSTOMER_NAME]..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; min-height: 150px;"><?php echo htmlspecialchars($settings['email_message']); ?></textarea>
                                    <small style="color: #999; display: block; margin-top: 8px;">Use [CUSTOMER_NAME], [INV_NUMBER], [AMOUNT], [DUE_DATE] as placeholders</small>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Email Settings
                                </button>
                            </form>
                        </div>

                        <!-- WhatsApp Settings Tab -->
                        <div id="whatsapp" class="settings-tab card" style="display: none;">
                            <div class="card-header">
                                <h3>WhatsApp Settings</h3>
                            </div>
                            <form class="card-body" id="whatsappForm" onsubmit="saveWhatsappSettings(event)">
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                                        <input type="checkbox" id="autoWhatsappInvoice" <?php echo $settings['auto_whatsapp_invoice'] ? 'checked' : ''; ?>>
                                        <span>Automatically send invoice via WhatsApp</span>
                                    </label>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">WhatsApp Business Phone</label>
                                    <input type="tel" id="whatsappPhone" placeholder="+234..." value="<?php echo htmlspecialchars($settings['whatsapp_phone']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">WhatsApp Message</label>
                                    <textarea id="whatsappMessage" placeholder="Hi [CUSTOMER_NAME], your invoice..." style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; min-height: 100px;"><?php echo htmlspecialchars($settings['whatsapp_message']); ?></textarea>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save WhatsApp Settings
                                </button>
                            </form>
                        </div>

                        <!-- Notifications Tab -->
                        <div id="notifications" class="settings-tab card" style="display: none;">
                            <div class="card-header">
                                <h3>Notification Preferences</h3>
                            </div>
                            <div class="card-body">
                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 15px;">
                                        <input type="checkbox" id="notifyInvoiceSent" <?php echo $settings['notify_invoice_sent'] ? 'checked' : ''; ?>>
                                        <span>Notify when invoice is sent</span>
                                    </label>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 15px;">
                                        <input type="checkbox" id="notifyInvoicePaid" <?php echo $settings['notify_invoice_paid'] ? 'checked' : ''; ?>>
                                        <span>Notify when invoice is paid</span>
                                    </label>
                                </div>

                                <div class="form-group" style="margin-bottom: 20px;">
                                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; margin-bottom: 15px;">
                                        <input type="checkbox" id="notifyOverdue" <?php echo $settings['notify_overdue'] ? 'checked' : ''; ?>>
                                        <span>Notify about overdue invoices</span>
                                    </label>
                                </div>

                                <button type="button" class="btn btn-primary" onclick="saveNotifications()">
                                    <i class="fas fa-save"></i> Save Notifications
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTab(event, tabName) {
            event.preventDefault();
            
            // Hide all tabs
            const tabs = document.querySelectorAll('.settings-tab');
            tabs.forEach(tab => tab.style.display = 'none');
            
            // Remove active class from all links
            const links = document.querySelectorAll('.settings-link');
            links.forEach(link => {
                link.style.borderLeftColor = 'transparent';
                link.style.color = '#333';
            });
            
            // Show selected tab
            document.getElementById(tabName).style.display = 'block';
            
            // Add active class to clicked link
            event.target.closest('a').style.borderLeftColor = '#667eea';
            event.target.closest('a').style.color = '#667eea';
        }

        function saveBusinessSettings(event) {
            event.preventDefault();
            
            const data = {
                business_name: document.getElementById('businessName').value,
                business_email: document.getElementById('businessEmail').value,
                business_phone: document.getElementById('businessPhone').value,
                business_address: document.getElementById('businessAddress').value,
                currency: document.getElementById('currency').value
            };

            fetch('../api/settings-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'business' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Business settings saved successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving settings.');
            });
        }

        function saveInvoiceSettings(event) {
            event.preventDefault();
            
            const data = {
                invoice_prefix: document.getElementById('invoicePrefix').value,
                default_tax_rate: document.getElementById('defaultTaxRate').value,
                payment_terms: document.getElementById('paymentTerms').value,
                invoice_notes: document.getElementById('invoiceNotes').value
            };

            fetch('../api/settings-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'invoice' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Invoice settings saved successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving settings.');
            });
        }

        function saveEmailSettings(event) {
            event.preventDefault();
            
            const data = {
                auto_email_invoice: document.getElementById('autoEmailInvoice').checked ? 1 : 0,
                email_subject: document.getElementById('emailSubject').value,
                email_message: document.getElementById('emailMessage').value
            };

            fetch('../api/settings-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'email' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Email settings saved successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving settings.');
            });
        }

        function saveWhatsappSettings(event) {
            event.preventDefault();
            
            const data = {
                auto_whatsapp_invoice: document.getElementById('autoWhatsappInvoice').checked ? 1 : 0,
                whatsapp_phone: document.getElementById('whatsappPhone').value,
                whatsapp_message: document.getElementById('whatsappMessage').value
            };

            fetch('../api/settings-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'whatsapp' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('WhatsApp settings saved successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving settings.');
            });
        }

        function saveNotifications() {
            const data = {
                notify_invoice_sent: document.getElementById('notifyInvoiceSent').checked ? 1 : 0,
                notify_invoice_paid: document.getElementById('notifyInvoicePaid').checked ? 1 : 0,
                notify_overdue: document.getElementById('notifyOverdue').checked ? 1 : 0
            };

            fetch('../api/settings-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'notifications' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Notification preferences saved successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving preferences.');
            });
        }

        function handleLogoUpload(event) {
            if (event.target.files[0]) {
                const file = event.target.files[0];
                const formData = new FormData();
                formData.append('logo', file);

                fetch('../api/upload-logo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Logo uploaded successfully!');
                    } else {
                        alert('Error: ' + result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while uploading the logo.');
                });
            }
        }
    </script>
</body>
</html>