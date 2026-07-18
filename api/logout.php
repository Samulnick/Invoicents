<?php
// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Clear all session data
$_SESSION = array();
session_destroy();

// Redirect back to login using a relative path instead of APP_URL constant
header("Location: ../auth/login.php");
exit();
