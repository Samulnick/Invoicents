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

// Get user information
$user_id = $_SESSION['user_id'];
$userQuery = "SELECT id, first_name, last_name, email FROM users WHERE id = ?";
$stmt = $db->prepare($userQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

// Get dashboard statistics
$statsQuery = "
    SELECT 
        COUNT(*) as total_invoices,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_invoices,
        SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft_invoices,
        COALESCE(SUM(CASE WHEN status = 'paid' THEN total_amount ELSE 0 END), 0) as total_revenue,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_invoices
    FROM invoices 
    WHERE user_id = ?
";
$stmt = $db->prepare($statsQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$statsResult = $stmt->get_result();
$stats = $statsResult->fetch_assoc();
$stmt->close();

// Get recent invoices
$invoicesQuery = "
    SELECT 
        id, 
        invoice_number, 
        customer_id,
        (SELECT name FROM customers WHERE id = invoices.customer_id) as customer_name,
        created_date, 
        total_amount, 
        status 
    FROM invoices 
    WHERE user_id = ? 
    ORDER BY created_date DESC 
    LIMIT 5
";
$stmt = $db->prepare($invoicesQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$invoicesResult = $stmt->get_result();
$recentInvoices = [];
while ($row = $invoicesResult->fetch_assoc()) {
    $recentInvoices[] = $row;
}
$stmt->close();

// Get unread messages count
$messagesQuery = "SELECT COUNT(*) as unread_count FROM messages WHERE user_id = ? AND is_read = 0";
$stmt = $db->prepare($messagesQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$messagesResult = $stmt->get_result();
$messages = $messagesResult->fetch_assoc();
$stmt->close();

$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];
$userEmail = $user['email'];
$totalInvoices = $stats['total_invoices'] ?? 0;
$sentInvoices = $stats['sent_invoices'] ?? 0;
$draftInvoices = $stats['draft_invoices'] ?? 0;
$totalRevenue = $stats['total_revenue'] ?? 0;
$pendingInvoices = $stats['pending_invoices'] ?? 0;
$unreadMessages = $messages['unread_count'] ?? 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Invoicent - Professional Invoice Management SaaS">
    <meta name="theme-color" content="#667eea">
    <title>Dashboard - Invoicent</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
    <link rel="stylesheet" href="../css/dashboard-mobile.css">
</head>
<body>
    <div class="dashboard-container">
        <!-- Hamburger Menu Button -->
        <button class="hamburger-menu" id="hamburgerMenu" aria-label="Toggle menu" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <!-- Sidebar Overlay (Mobile) -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <!-- Sidebar Navigation -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-header">
                <i class="fas fa-file-invoice-dollar sidebar-logo"></i>
                <h2>Invoicent</h2>
            </div>

            <nav class="sidebar-nav">
                <li>
                    <a href="index.php" class="active">
                        <i class="fas fa-home"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li>
                    <a href="invoices.php">
                        <i class="fas fa-file-invoice"></i>
                        <span>Invoices</span>
                    </a>
                </li>
                <li>
                    <a href="invoice-create.php">
                        <i class="fas fa-plus-circle"></i>
                        <span>New Invoice</span>
                    </a>
                </li>
                <li>
                    <a href="customers.php">
                        <i class="fas fa-users"></i>
                        <span>Customers</span>
                    </a>
                </li>
                <li>
                    <a href="settings.php">
                        <i class="fas fa-cog"></i>
                        <span>Settings</span>
                    </a>
                </li>
                <li>
                    <a href="profile.php">
                        <i class="fas fa-user-circle"></i>
                        <span>Profile</span>
                    </a>
                </li>
                <li>
                    <hr style="border: none; border-top: 1px solid rgba(255, 255, 255, 0.1); margin: 20px 0;">
                </li>
                <li>
                    <a href="../api/logout.php">
                        <i class="fas fa-sign-out-alt"></i>
                        <span>Logout</span>
                    </a>
                </li>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation Bar -->
            <nav class="navbar">
                <div class="navbar-left">
                    <h3 class="navbar-title">Dashboard</h3>
                </div>

                <div class="navbar-right">
                    <div class="search-box">
                        <i class="fas fa-search"></i>
                        <input type="text" id="globalSearch" placeholder="Search invoices, customers...">
                    </div>

                    <button class="notifications-btn" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <span class="notification-badge"><?php echo $unreadMessages; ?></span>
                    </button>

                    <div class="user-profile">
                        <div class="user-avatar" id="userAvatar"><?php echo strtoupper(substr($userFirstName, 0, 1)); ?></div>
                        <div>
                            <strong id="userName"><?php echo htmlspecialchars($userName); ?></strong>
                            <div style="font-size: 12px; color: #999;" id="userEmail"><?php echo htmlspecialchars($userEmail); ?></div>
                        </div>
                    </div>
                </div>
            </nav>

            <!-- Content Area -->
            <div class="content">
                <!-- Welcome Card -->
                <div class="welcome-card">
                    <h1>Welcome back, <span id="welcomeName"><?php echo htmlspecialchars($userFirstName); ?></span>! 👋</h1>
                    <p>You have <strong id="pendingCount"><?php echo $pendingInvoices; ?></strong> pending invoices and <strong id="unreadCount"><?php echo $unreadMessages; ?></strong> unread messages</p>
                    <a href="invoice-create.php" class="btn btn-primary">
                        <i class="fas fa-plus"></i> Create New Invoice
                    </a>
                </div>

                <!-- Statistics Grid -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <i class="fas fa-file-alt stat-icon"></i>
                        <div class="stat-value" id="totalInvoices"><?php echo $totalInvoices; ?></div>
                        <div class="stat-label">Total Invoices</div>
                    </div>

                    <div class="stat-card success">
                        <i class="fas fa-check-circle stat-icon"></i>
                        <div class="stat-value" id="sentInvoices"><?php echo $sentInvoices; ?></div>
                        <div class="stat-label">Sent Invoices</div>
                    </div>

                    <div class="stat-card warning">
                        <i class="fas fa-edit stat-icon"></i>
                        <div class="stat-value" id="draftInvoices"><?php echo $draftInvoices; ?></div>
                        <div class="stat-label">Draft Invoices</div>
                    </div>

                    <div class="stat-card danger">
                        <i class="fas fa-money-bill-wave stat-icon"></i>
                        <div class="stat-value" id="totalRevenue">₦<?php echo number_format($totalRevenue, 0); ?></div>
                        <div class="stat-label">Total Revenue</div>
                    </div>
                </div>

                <!-- Recent Invoices Section -->
                <div class="card">
                    <div class="card-header">
                        <h3>Recent Invoices</h3>
                        <a href="invoices.php" class="btn btn-outline btn-sm">
                            View All <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="card-body table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Invoice #</th>
                                    <th>Customer</th>
                                    <th>Date</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="recentInvoicesTable">
                                <?php if (count($recentInvoices) > 0): ?>
                                    <?php foreach ($recentInvoices as $invoice): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($invoice['invoice_number']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($invoice['customer_name']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($invoice['created_date'])); ?></td>
                                            <td>₦<?php echo number_format($invoice['total_amount'], 2); ?></td>
                                            <td>
                                                <span style="
                                                    padding: 5px 10px;
                                                    border-radius: 4px;
                                                    font-size: 12px;
                                                    font-weight: 600;
                                                    background: <?php echo getStatusColor($invoice['status']); ?>;
                                                    color: white;
                                                ">
                                                    <?php echo ucfirst(htmlspecialchars($invoice['status'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-primary btn-sm" onclick="viewInvoice(<?php echo $invoice['id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; padding: 30px;">
                                            <i class="fas fa-inbox"></i> No invoices yet
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Quick Actions Section -->
                <div class="card">
                    <div class="card-header">
                        <h3>Quick Actions</h3>
                    </div>
                    <div class="card-body btn-group">
                        <a href="invoice-create.php" class="btn btn-primary">
                            <i class="fas fa-file-export"></i> Create Invoice
                        </a>
                        <button class="btn btn-success" onclick="generatePDF()">
                            <i class="fas fa-download"></i> Download Invoice
                        </button>
                        <button class="btn btn-info" style="background: #17a2b8;" onclick="sendEmail()">
                            <i class="fas fa-envelope"></i> Send Email
                        </button>
                        <a href="customers.php" class="btn btn-secondary">
                            <i class="fas fa-user-plus"></i> Add Customer
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Hamburger Menu Toggle
        const hamburgerMenu = document.getElementById('hamburgerMenu');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebarOverlay');

        function toggleMenu() {
            hamburgerMenu.classList.toggle('active');
            sidebar.classList.toggle('active');
            sidebarOverlay.classList.toggle('active');
            document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
        }

        hamburgerMenu.addEventListener('click', toggleMenu);
        sidebarOverlay.addEventListener('click', toggleMenu);

        // Close sidebar when a link is clicked
        document.querySelectorAll('.sidebar-nav a').forEach(link => {
            link.addEventListener('click', () => {
                hamburgerMenu.classList.remove('active');
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            });
        });

        // Close sidebar on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('active')) {
                toggleMenu();
            }
        });

        function getStatusColor(status) {
            const colors = {
                'draft': '#ffc107',
                'sent': '#17a2b8',
                'paid': '#28a745',
                'pending': '#ff9800',
                'overdue': '#dc3545'
            };
            return colors[status] || '#6c757d';
        }

        function generatePDF() {
            alert('PDF generation will be implemented');
        }

        function sendEmail() {
            alert('Email sending will be implemented');
        }

        function viewInvoice(invoiceId) {
            window.location.href = `invoice-view.php?id=${invoiceId}`;
        }

        // Search functionality
        document.getElementById('globalSearch').addEventListener('input', function(e) {
            const query = e.target.value;
            if (query.length > 2) {
                // Implement search via AJAX
                fetch('../api/search.php?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        console.log('Search results:', data);
                    });
            }
        });

        // Handle window resize to close mobile menu on larger screens
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) {
                hamburgerMenu.classList.remove('active');
                sidebar.classList.remove('active');
                sidebarOverlay.classList.remove('active');
                document.body.style.overflow = '';
            }
        });
    </script>
</body>
</html>

<?php
// Helper function for status colors
function getStatusColor($status) {
    $colors = [
        'draft' => '#ffc107',
        'sent' => '#17a2b8',
        'paid' => '#28a745',
        'pending' => '#ff9800',
        'overdue' => '#dc3545'
    ];
    return $colors[$status] ?? '#6c757d';
}
?>
