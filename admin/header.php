<?php
/**
 * HackMatrix 1.0 - Admin Portal Header
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce login on all admin pages
requireLogin();

// Determine current page for active sidebar highlight
$currentPage = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HackMatrix 1.0 - Admin Portal</title>
    <link rel="stylesheet" href="../assets/css/index.css">
</head>
<body>

<div class="app-layout">
    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h2>HACKMATRIX 1.0</h2>
            <p>ADMIN CONTROL PANEL</p>
        </div>
        
        <ul class="sidebar-menu">
            <li class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
                <a href="dashboard.php">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></svg>
                    Dashboard
                </a>
            </li>
            
            <li class="<?= $currentPage === 'participants.php' ? 'active' : '' ?>">
                <a href="participants.php">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Participants
                </a>
            </li>
            
            <li class="<?= $currentPage === 'templates.php' ? 'active' : '' ?>">
                <a href="templates.php">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                    Certificate Template
                </a>
            </li>
            
            <li class="<?= $currentPage === 'certificate-editor.php' ? 'active' : '' ?>">
                <a href="certificate-editor.php">
                    <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    Certificate Editor
                </a>
            </li>
            
            <li class="<?= ($currentPage === 'certificates.php' && !isset($_GET['tab'])) ? 'active' : '' ?>">
                <a href="certificates.php">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    Generate Certificates
                </a>
            </li>

            <li class="<?= ($currentPage === 'certificates.php' && isset($_GET['tab']) && $_GET['tab'] === 'send') ? 'active' : '' ?>">
                <a href="certificates.php?tab=send">
                    <svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    Send Certificates
                </a>
            </li>
            
            <li class="<?= $currentPage === 'email-settings.php' ? 'active' : '' ?>">
                <a href="email-settings.php">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Email Settings
                </a>
            </li>
            
            <li class="<?= $currentPage === 'email-template.php' ? 'active' : '' ?>">
                <a href="email-template.php">
                    <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    Email Template
                </a>
            </li>

            <li class="<?= ($currentPage === 'logs.php' && isset($_GET['type']) && $_GET['type'] === 'email') ? 'active' : '' ?>">
                <a href="logs.php?type=email">
                    <svg viewBox="0 0 24 24"><path d="M22 12h-6l-2 3h-4l-2-3H2"/></svg>
                    Email Logs
                </a>
            </li>
            
            <li class="<?= ($currentPage === 'logs.php' && (!isset($_GET['type']) || $_GET['type'] === 'activity')) ? 'active' : '' ?>">
                <a href="logs.php?type=activity">
                    <svg viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
                    Activity Logs
                </a>
            </li>
        </ul>
        
        <div class="sidebar-footer">
            <a href="logout.php">
                <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Top bar for mobile menu toggle -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 15px; display: none;" class="menu-toggle-bar">
            <button class="menu-toggle" id="menuToggle">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h3 style="margin: 0; font-size: 16px; font-weight: 800;">HACKMATRIX 1.0</h3>
        </div>
