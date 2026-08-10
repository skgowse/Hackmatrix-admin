<?php
/**
 * HackMatrix 1.0 - Main Router Entry
 */

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header("Location: admin/dashboard.php");
} else {
    header("Location: admin/login.php");
}
exit();
