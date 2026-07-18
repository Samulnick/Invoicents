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

// Get all customers for the user
$customersQuery = "
    SELECT 
        c.id,
        c.name,
        c.email,
        c.phone,
        c.address,
        c.city,
        c.notes,
        COUNT(i.id) as invoices,
        COALESCE(SUM(CASE WHEN i.status = 'paid' THEN i.total_amount ELSE 0 END), 0) as totalSpent
    FROM customers c
    LEFT JOIN invoices i ON c.id = i.customer_id AND i.user_id = ?
    WHERE c.user_id = ?
    GROUP BY c.id
    ORDER BY c.name ASC
";

$stmt = $db->prepare($customersQuery);
$stmt->bind_param('ii', $user_id, $user_id);
$stmt->execute();
$customersResult = $stmt->get_result();
$customers = [];
while ($row = $customersResult->fetch_assoc()) {
    $customers[] = $row;
}
$stmt->close();

// Convert to JSON for JavaScript
$customersJson = json_encode($customers);

$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];
$userEmail = $user['email'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers - Invoicent</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="../css/dashboard.css">
	<link rel="stylesheet" href="../css/customers.css">
   
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
                <li><a href="customers.php" class="active"><i class="fas fa-users"></i><span>Customers</span></a></li>
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
                    <h3 class="navbar-title">Customers</h3>
                </div>
                <div class="navbar-right">
                    <button class="btn btn-primary" onclick="openAddCustomerModal()">
                        <i class="fas fa-plus"></i> Add Customer
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
                <!-- Search Bar -->
                <div class="search-bar">
                    <input type="text" id="searchInput" placeholder="Search customers by name or email..." onkeyup="searchCustomers()">
                    <button class="btn btn-secondary" onclick="exportCustomers()">
                        <i class="fas fa-download"></i> Export
                    </button>
                </div>

                <!-- Customers Grid -->
                <div class="customer-grid" id="customersGrid">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Customer Modal -->
    <div id="customerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="modalTitle">Add New Customer</span>
                <button class="close-modal" onclick="closeCustomerModal()">×</button>
            </div>
            <form id="customerForm" onsubmit="saveCustomer(event)">
                <div class="form-group">
                    <label>Customer Name</label>
                    <input type="text" id="customerName" placeholder="Full name or business name" required>
                </div>

                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" id="customerEmail" placeholder="customer@example.com" required>
                </div>

                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" id="customerPhone" placeholder="+234...">
                </div>

                <div class="form-group">
                    <label>Address</label>
                    <textarea id="customerAddress" placeholder="Full address"></textarea>
                </div>

                <div class="form-group">
                    <label>City</label>
                    <input type="text" id="customerCity" placeholder="City">
                </div>

                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="customerNotes" placeholder="Additional notes about customer"></textarea>
                </div>

                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCustomerModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Customer Modal -->
    <div id="viewCustomerModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span id="viewModalTitle">Customer Details</span>
                <button class="close-modal" onclick="closeViewModal()">×</button>
            </div>
            <div class="view-mode" id="viewCustomerContent">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeViewModal()">Close</button>
                <button type="button" class="btn btn-primary" onclick="editCustomerFromView()">Edit</button>
            </div>
        </div>
    </div>

    <script>
        // Load customer data from PHP
        let customersData = <?php echo $customersJson; ?>;
        let filteredCustomers = [...customersData];
        let currentEditingId = null;

        function displayCustomers() {
            const grid = document.getElementById('customersGrid');
            
            if (filteredCustomers.length === 0) {
                grid.innerHTML = `
                    <div style="grid-column: 1/-1;">
                        <div class="empty-state">
                            <i class="fas fa-users"></i>
                            <p>No customers found</p>
                        </div>
                    </div>
                `;
                return;
            }

            grid.innerHTML = filteredCustomers.map(customer => {
                const initials = customer.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
                return `
                    <div class="customer-card">
                        <div class="customer-avatar">${initials}</div>
                        <div class="customer-name">${customer.name}</div>
                        <div class="customer-email" title="${customer.email}">${customer.email}</div>
                        
                        <div class="customer-stats">
                            <div>
                                <div class="customer-stats-value">${customer.invoices}</div>
                                <div class="customer-stats-label">Invoices</div>
                            </div>
                            <div>
                                <div class="customer-stats-value">₦${(customer.totalSpent / 1000).toFixed(0)}K</div>
                                <div class="customer-stats-label">Total</div>
                            </div>
                        </div>

                        <div class="customer-actions">
                            <button class="btn-view" onclick="viewCustomer(${customer.id})">
                                <i class="fas fa-eye"></i>
                            </button>
                            <button class="btn-edit" onclick="editCustomer(${customer.id})">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="btn-delete" onclick="deleteCustomer(${customer.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function searchCustomers() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            filteredCustomers = customersData.filter(customer => 
                customer.name.toLowerCase().includes(searchTerm) ||
                customer.email.toLowerCase().includes(searchTerm)
            );
            displayCustomers();
        }

        function openAddCustomerModal() {
            currentEditingId = null;
            document.getElementById('modalTitle').textContent = 'Add New Customer';
            document.getElementById('customerForm').reset();
            document.getElementById('customerModal').style.display = 'block';
        }

        function editCustomer(id) {
            currentEditingId = id;
            const customer = customersData.find(c => c.id === id);
            
            document.getElementById('modalTitle').textContent = 'Edit Customer';
            document.getElementById('customerName').value = customer.name;
            document.getElementById('customerEmail').value = customer.email;
            document.getElementById('customerPhone').value = customer.phone || '';
            document.getElementById('customerAddress').value = customer.address || '';
            document.getElementById('customerCity').value = customer.city || '';
            document.getElementById('customerNotes').value = customer.notes || '';
            
            document.getElementById('customerModal').style.display = 'block';
        }

        function closeCustomerModal() {
            document.getElementById('customerModal').style.display = 'none';
            currentEditingId = null;
        }

        function saveCustomer(event) {
            event.preventDefault();
            
            const customer = {
                name: document.getElementById('customerName').value,
                email: document.getElementById('customerEmail').value,
                phone: document.getElementById('customerPhone').value,
                address: document.getElementById('customerAddress').value,
                city: document.getElementById('customerCity').value,
                notes: document.getElementById('customerNotes').value
            };

            const data = {
                ...customer,
                customerId: currentEditingId
            };

            fetch('../api/customer-save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify(data)
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    // Update local data
                    if (currentEditingId) {
                        const index = customersData.findIndex(c => c.id === currentEditingId);
                        customersData[index] = { ...customersData[index], ...customer };
                        alert('Customer updated successfully!');
                    } else {
                        const newCustomer = {
                            id: result.customer_id,
                            ...customer,
                            invoices: 0,
                            totalSpent: 0
                        };
                        customersData.push(newCustomer);
                        alert('Customer added successfully!');
                    }

                    closeCustomerModal();
                    filteredCustomers = [...customersData];
                    displayCustomers();
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while saving the customer.');
            });
        }

        function viewCustomer(id) {
            const customer = customersData.find(c => c.id === id);
            currentEditingId = id;

            const content = `
                <div class="view-item">
                    <div class="view-item-label">Name</div>
                    <div class="view-item-value">${customer.name}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">Email</div>
                    <div class="view-item-value">${customer.email}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">Phone</div>
                    <div class="view-item-value">${customer.phone || '-'}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">Address</div>
                    <div class="view-item-value">${customer.address || '-'}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">City</div>
                    <div class="view-item-value">${customer.city || '-'}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">Total Invoices</div>
                    <div class="view-item-value">${customer.invoices}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">Total Spent</div>
                    <div class="view-item-value">₦${customer.totalSpent.toLocaleString()}</div>
                </div>
                <div class="view-item">
                    <div class="view-item-label">Notes</div>
                    <div class="view-item-value">${customer.notes || '-'}</div>
                </div>
            `;

            document.getElementById('viewCustomerContent').innerHTML = content;
            document.getElementById('viewCustomerModal').style.display = 'block';
        }

        function editCustomerFromView() {
            closeViewModal();
            editCustomer(currentEditingId);
        }

        function closeViewModal() {
            document.getElementById('viewCustomerModal').style.display = 'none';
            currentEditingId = null;
        }

        function deleteCustomer(id) {
            if (confirm('Are you sure you want to delete this customer?')) {
                fetch('../api/customer-delete.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ customerId: id })
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        customersData = customersData.filter(c => c.id !== id);
                        filteredCustomers = [...customersData];
                        displayCustomers();
                        alert('Customer deleted successfully!');
                    } else {
                        alert('Error: ' + result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while deleting the customer.');
                });
            }
        }

        function exportCustomers() {
            let csv = 'Name,Email,Phone,Address,City,Invoices,Total Spent\n';
            filteredCustomers.forEach(customer => {
                csv += `"${customer.name}","${customer.email}","${customer.phone || ''}","${customer.address || ''}","${customer.city || ''}",${customer.invoices},${customer.totalSpent}\n`;
            });

            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'customers.csv';
            a.click();
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const modal = document.getElementById('customerModal');
            const viewModal = document.getElementById('viewCustomerModal');
            if (event.target === modal) {
                modal.style.display = 'none';
            }
            if (event.target === viewModal) {
                viewModal.style.display = 'none';
            }
        }

        // Initialize
        displayCustomers();
    </script>
</body>
</html>