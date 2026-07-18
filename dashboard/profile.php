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
$userQuery = "SELECT 
    id, first_name, last_name, email, phone,
    created_at
FROM users WHERE id = ?";

$stmt = $db->prepare($userQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$userResult = $stmt->get_result();
$user = $userResult->fetch_assoc();
$stmt->close();

// Get invoice count for this user
$invoiceCountQuery = "SELECT COUNT(*) as total FROM invoices WHERE user_id = ?";
$stmt = $db->prepare($invoiceCountQuery);
$stmt->bind_param('i', $user_id);
$stmt->execute();
$invoiceCountResult = $stmt->get_result();
$invoiceCountRow = $invoiceCountResult->fetch_assoc();
$stmt->close();
$invoiceCount = $invoiceCountRow['total'];

$userName = $user['first_name'] . ' ' . $user['last_name'];
$userFirstName = $user['first_name'];
$userEmail = $user['email'];
$joinDate = date('F j, Y', strtotime($user['created_at']));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Invoicent</title>
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
                <li><a href="settings.php"><i class="fas fa-cog"></i><span>Settings</span></a></li>
                <li><a href="profile.php" class="active"><i class="fas fa-user-circle"></i><span>Profile</span></a></li>
                <li><hr style="border: none; border-top: 1px solid rgba(255, 255, 255, 0.1); margin: 20px 0;"></li>
                <li><a href="../api/logout.php"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a></li>
            </nav>
        </aside>

        <!-- Main Content -->
        <div class="main-content">
            <!-- Top Navigation Bar -->
            <nav class="navbar">
                <div class="navbar-left">
                    <h3 class="navbar-title">Profile</h3>
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
                <div style="display: grid; grid-template-columns: 300px 1fr; gap: 30px;">
                    <!-- Profile Avatar Section -->
                    <div style="text-align: center;">
                        <div class="card">
                            <div class="card-body" style="text-align: center; padding: 30px;">
                                <div style="
                                    width: 150px;
                                    height: 150px;
                                    border-radius: 50%;
                                    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    color: white;
                                    font-size: 50px;
                                    font-weight: 700;
                                    margin: 0 auto 20px;
                                "><?php echo strtoupper(substr($userFirstName, 0, 1) . substr($user['last_name'], 0, 1)); ?></div>
                                <h3><?php echo htmlspecialchars($userName); ?></h3>
                                <p style="color: #999; margin-bottom: 20px;"><?php echo htmlspecialchars($userEmail); ?></p>
                                <button type="button" class="btn btn-primary" onclick="document.getElementById('profilePhoto').click()">
                                    <i class="fas fa-camera"></i> Change Photo
                                </button>
                                <input type="file" id="profilePhoto" accept="image/*" style="display: none;" onchange="handlePhotoUpload(event)">
                            </div>
                        </div>

                        <div class="card" style="margin-top: 20px;">
                            <div class="card-header">
                                <h3>Account Status</h3>
                            </div>
                            <div class="card-body">
                                <div style="text-align: left;">
                                    <p><strong>Status:</strong> <span style="color: #28a745;">✓ Active</span></p>
                                    <p><strong>Joined:</strong> <?php echo htmlspecialchars($joinDate); ?></p>
                                    <p><strong>Plan:</strong> Professional</p>
                                    <p><strong>Invoices Created:</strong> <?php echo $invoiceCount; ?></p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Profile Form Section -->
                    <div>
                        <!-- Personal Information -->
                        <div class="card">
                            <div class="card-header">
                                <h3>Personal Information</h3>
                            </div>
                            <form class="card-body" id="profileForm" onsubmit="saveProfile(event)">
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                                    <div>
                                        <label style="display: block; margin-bottom: 8px; font-weight: 600;">First Name</label>
                                        <input type="text" id="firstName" value="<?php echo htmlspecialchars($user['first_name']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" required>
                                    </div>
                                    <div>
                                        <label style="display: block; margin-bottom: 8px; font-weight: 600;">Last Name</label>
                                        <input type="text" id="lastName" value="<?php echo htmlspecialchars($user['last_name']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" required>
                                    </div>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Email Address</label>
                                    <input type="email" id="email" value="<?php echo htmlspecialchars($user['email']); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" required>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Phone Number</label>
                                    <input type="tel" id="phone" placeholder="+234..." value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save"></i> Save Profile
                                </button>
                            </form>
                        </div>

                        <!-- Change Password -->
                        <div class="card" style="margin-top: 20px;">
                            <div class="card-header">
                                <h3>Change Password</h3>
                            </div>
                            <form class="card-body" id="passwordForm" onsubmit="changePassword(event)">
                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Current Password</label>
                                    <input type="password" id="currentPassword" placeholder="Enter current password" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" required>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">New Password</label>
                                    <input type="password" id="newPassword" placeholder="Enter new password (min 8 characters)" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" required>
                                </div>

                                <div style="margin-bottom: 20px;">
                                    <label style="display: block; margin-bottom: 8px; font-weight: 600;">Confirm Password</label>
                                    <input type="password" id="confirmPassword" placeholder="Confirm new password" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;" required>
                                </div>

                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-lock"></i> Update Password
                                </button>
                            </form>
                        </div>

                        <!-- Two-Factor Authentication -->
                        <div class="card" style="margin-top: 20px;">
                            <div class="card-header">
                                <h3>Two-Factor Authentication</h3>
                            </div>
                            <div class="card-body">
                                <p style="margin-bottom: 15px;">Enhance your account security with two-factor authentication.</p>
                                <button type="button" class="btn btn-success" onclick="enableTwoFA()">
                                    <i class="fas fa-shield-alt"></i> Enable 2FA
                                </button>
                            </div>
                        </div>

                        <!-- Delete Account -->
                        <div class="card" style="margin-top: 20px; border-left-color: #dc3545;">
                            <div class="card-header" style="background: #f8f9fa;">
                                <h3 style="color: #dc3545;">Danger Zone</h3>
                            </div>
                            <div class="card-body">
                                <p style="margin-bottom: 15px; color: #666;">Once you delete your account, there is no going back. Please be certain.</p>
                                <button type="button" class="btn btn-danger" onclick="confirmDelete()">
                                    <i class="fas fa-trash-alt"></i> Delete Account
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function saveProfile(event) {
            event.preventDefault();
            
            const data = {
                first_name: document.getElementById('firstName').value,
                last_name: document.getElementById('lastName').value,
                email: document.getElementById('email').value,
                phone: document.getElementById('phone').value
            };

            fetch('../api/profile-update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'profile' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Profile updated successfully!');
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating profile.');
            });
        }

        function changePassword(event) {
            event.preventDefault();
            
            const newPassword = document.getElementById('newPassword').value;
            const confirmPassword = document.getElementById('confirmPassword').value;

            if (newPassword !== confirmPassword) {
                alert('Passwords do not match!');
                return;
            }

            if (newPassword.length < 8) {
                alert('Password must be at least 8 characters!');
                return;
            }

            const data = {
                current_password: document.getElementById('currentPassword').value,
                new_password: newPassword
            };

            fetch('../api/profile-update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ ...data, type: 'password' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    alert('Password updated successfully!');
                    document.getElementById('passwordForm').reset();
                } else {
                    alert('Error: ' + result.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while updating password.');
            });
        }

        function handlePhotoUpload(event) {
            if (event.target.files[0]) {
                const file = event.target.files[0];
                const formData = new FormData();
                formData.append('photo', file);

                fetch('../api/upload-photo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        alert('Profile photo updated successfully!');
                    } else {
                        alert('Error: ' + result.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('An error occurred while uploading photo.');
                });
            }
        }

        function enableTwoFA() {
            alert('Two-factor authentication setup will be implemented soon.');
        }

        function confirmDelete() {
            if (confirm('Are you absolutely sure you want to delete your account? This action cannot be undone.')) {
                if (confirm('This will permanently delete all your data. Continue?')) {
                    fetch('../api/account-delete.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json'
                        },
                        body: JSON.stringify({ confirm: true })
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            alert('Account deletion initiated. You will be logged out.');
                            window.location.href = '../login.html';
                        } else {
                            alert('Error: ' + result.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while deleting account.');
                    });
                }
            }
        }
		
		const formData = new FormData();
			formData.append('action', 'update_profile');
			formData.append('first_name', 'John');
			formData.append('last_name', 'Doe');
			formData.append('email', 'john@example.com');
			formData.append('phone', '+234 812 345 6789');

			fetch('../api/profile-update.php', {
			method: 'POST',
			body: formData
		}).then(res => res.json()).then(data => console.log(data));

		const formData = new FormData();
			formData.append('action', 'update_password');
			formData.append('current_password', 'oldpass123');
			formData.append('new_password', 'newpass456');
			formData.append('confirm_password', 'newpass456');

			fetch('../api/profile-update.php', {
			method: 'POST',
			body: formData
		}).then(res => res.json()).then(data => console.log(data));

		const formData = new FormData();
			formData.append('logo', fileInput.files[0]);

			fetch('../api/upload-logo.php', {
			method: 'POST',
			body: formData
		}).then(res => res.json()).then(data => console.log(data));

    </script>
</body>
</html>