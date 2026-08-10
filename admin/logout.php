<?php
/**
 * HackMatrix 1.0 - Admin Logout Page
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Log activity before logging out
if (isLoggedIn()) {
    logActivity('LOGOUT', 'Admin user logged out: ' . $_SESSION['admin_username']);
}

logout();

header("Location: login.php");
exit();
