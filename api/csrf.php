<?php
/**
 * CSRF Token API
 */

require_once __DIR__ . '/../config/config.php';

// Strict CORS and caching headers for protection
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

echo json_encode([
    'csrf_token' => generateCSRFToken()
]);
?>
