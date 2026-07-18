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
$userQuery = "SELECT first_name, last_name, email FROM users WHERE id = ?";
$stmt = $db->prepare($userQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

// Get all invoices for the user with customer names
$invoicesQuery = "
    SELECT 
        i.id,
        i.invoice_number,
        c.name as customer,
        i.total_amount,
        i.created_date,
        i.due_date,
        i.status
    FROM invoices i
    LEFT JOIN customers c ON i.customer_id = c.id
    WHERE i.user_id = ?
    ORDER BY i.created_date DESC
";

$stmt = $db->prepare($invoicesQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$invoicesResult = $stmt->get_result();
$invoices = [];
while ($row = $invoicesResult->fetch_assoc()) {
    $invoices[] = $row;
}
$stmt->close();

// Convert to JSON for JavaScript
$invoicesJson = json_encode($invoices);

$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];
$userEmail = $user['email'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoices - Invoicent</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
	<link rel="stylesheet" href="../css/invoices.css">
    
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
                <li><a href="invoices.php" class="active"><i class="fas fa-file-invoice"></i><span>Invoices</span></a></li>
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
                    <h3 class="navbar-title">Invoices</h3>
                </div>
                <div class="navbar-right">
                    <button class="btn btn-primary" onclick="window.location.href='invoice-create.php'">
                        <i class="fas fa-plus"></i> Create Invoice
                    </button>
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
                <!-- Filter Bar -->
                <div class="filter-bar">
                    <select id="statusFilter" onchange="filterInvoices()">
                        <option value="">All Statuses</option>
                        <option value="draft">Draft</option>
                        <option value="sent">Sent</option>
                        <option value="paid">Paid</option>
                        <option value="overdue">Overdue</option>
                        <option value="pending">Pending</option>
                    </select>

                    <select id="dateFilter" onchange="filterInvoices()">
                        <option value="">All Dates</option>
                        <option value="today">Today</option>
                        <option value="week">This Week</option>
                        <option value="month">This Month</option>
                        <option value="year">This Year</option>
                    </select>

                    <input type="text" id="searchInput" placeholder="Search invoices..." onkeyup="searchInvoices()">

                    <button class="btn btn-secondary" onclick="exportInvoices()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>

                <!-- Invoices Table -->
                <div class="card">
                    <div class="card-body">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Date</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="invoicesTable">
                                <!-- Populated by JavaScript -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="pagination">
                    <button onclick="previousPage()">&laquo; Previous</button>
                    <span id="pageNumbers"></span>
                    <button onclick="nextPage()">Next &raquo;</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Load invoice data from PHP
        const invoicesData = <?php echo $invoicesJson; ?>;

        let currentPage = 1;
        const itemsPerPage = 10;
        let filteredInvoices = [...invoicesData];

        function displayInvoices() {
            const start = (currentPage - 1) * itemsPerPage;
            const end = start + itemsPerPage;
            const pageInvoices = filteredInvoices.slice(start, end);

            let html = '';
            pageInvoices.forEach(invoice => {
                html += `
                    <tr>
                        <td><strong>${invoice.invoice_number}</strong></td>
                        <td>${invoice.customer || '-'}</td>
                        <td>₦${parseFloat(invoice.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>
                        <td>${invoice.created_date}</td>
                        <td>${invoice.due_date}</td>
                        <td><span class="status-badge status-${invoice.status}">${invoice.status.charAt(0).toUpperCase() + invoice.status.slice(1)}</span></td>
                        <td>
                            <div class="action-buttons">
                                <button class="action-view" onclick="viewInvoice(${invoice.id})">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <button class="action-edit" onclick="editInvoice(${invoice.id})">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <button class="action-delete" onclick="deleteInvoice(${invoice.id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                `;
            });

            document.getElementById('invoicesTable').innerHTML = html || '<tr><td colspan="7" style="text-align: center; padding: 30px;"><i class="fas fa-inbox"></i> No invoices found</td></tr>';

            // Update pagination
            updatePagination();
        }

        function updatePagination() {
            const totalPages = Math.ceil(filteredInvoices.length / itemsPerPage);
            let pageNumbersHtml = '';

            for (let i = 1; i <= totalPages; i++) {
                if (i === currentPage) {
                    pageNumbersHtml += `<button class="active">${i}</button>`;
                } else {
                    pageNumbersHtml += `<button onclick="goToPage(${i})">${i}</button>`;
                }
            }

            document.getElementById('pageNumbers').innerHTML = pageNumbersHtml;
        }

        function goToPage(page) {
            currentPage = page;
            displayInvoices();
        }

        function previousPage() {
            if (currentPage > 1) {
                currentPage--;
                displayInvoices();
            }
        }

        function nextPage() {
            const totalPages = Math.ceil(filteredInvoices.length / itemsPerPage);
            if (currentPage < totalPages) {
                currentPage++;
                displayInvoices();
            }
        }

        function filterInvoices() {
            const statusFilter = document.getElementById('statusFilter').value;
            const dateFilter = document.getElementById('dateFilter').value;

            filteredInvoices = invoicesData.filter(invoice => {
                let statusMatch = true;
                let dateMatch = true;

                if (statusFilter) {
                    statusMatch = invoice.status === statusFilter;
                }

                if (dateFilter) {
                    const invoiceDate = new Date(invoice.created_date);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);

                    switch(dateFilter) {
                        case 'today':
                            dateMatch = invoiceDate.toDateString() === today.toDateString();
                            break;
                        case 'week':
                            const weekAgo = new Date(today);
                            weekAgo.setDate(weekAgo.getDate() - 7);
                            dateMatch = invoiceDate >= weekAgo;
                            break;
                        case 'month':
                            dateMatch = invoiceDate.getMonth() === today.getMonth() && invoiceDate.getFullYear() === today.getFullYear();
                            break;
                        case 'year':
                            dateMatch = invoiceDate.getFullYear() === today.getFullYear();
                            break;
                    }
                }

                return statusMatch && dateMatch;
            });

            currentPage = 1;
            displayInvoices();
        }

        function searchInvoices() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            
            filteredInvoices = invoicesData.filter(invoice => {
                return invoice.invoice_number.toLowerCase().includes(searchTerm) ||
                       (invoice.customer && invoice.customer.toLowerCase().includes(searchTerm));
            });

            currentPage = 1;
            displayInvoices();
        }

        function viewInvoice(invoiceId) {
            window.location.href = `invoice-view.php?id=${invoiceId}`;
        }

        function editInvoice(invoiceId) {
            window.location.href = `invoice-create.php?id=${invoiceId}`;
        }

        function deleteInvoice(invoiceId) {
            if (confirm(`Are you sure you want to delete this invoice?`)) {
                fetch('../api/invoice-delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ invoiceId: invoiceId })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        // Remove from data and refresh
                        const index = invoicesData.findIndex(inv => inv.id === invoiceId);
                        if (index > -1) {
                            invoicesData.splice(index, 1);
                            filterInvoices();
                        }
                        alert('Invoice deleted successfully!');
                    } else {
                        alert('Error: ' + result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the invoice.');
                });
            }
        }

        function exportInvoices() {
            let csv = 'Invoice #,Customer,Amount,Date,Due Date,Status\n';
            filteredInvoices.forEach(invoice => {
                csv += `"${invoice.invoice_number}","${invoice.customer || ''}",${invoice.total_amount},"${invoice.created_date}","${invoice.due_date}","${invoice.status}"\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'invoices.csv';
            a.click();
        }

        // Initialize
        displayInvoices();
    </script>
</body>
</html>