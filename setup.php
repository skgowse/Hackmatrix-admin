<?php
/**
 * HackMatrix 1.0 - Database Setup Utility
 */

require_once __DIR__ . '/config/database.php';

$success = false;
$message = '';
$results = [];

if (isset($_POST['run_setup'])) {
    try {
        // Connect to MySQL server (without selecting DB)
        $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        
        // 1. Create Database
        $dbName = DB_NAME;
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        $results[] = ["Database `$dbName` created or already exists", "SUCCESS"];
        
        // Switch database
        $pdo->exec("USE `$dbName`;");

        // 2. Create tables
        $tables = [
            'admins' => "CREATE TABLE IF NOT EXISTS admins (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'teams' => "CREATE TABLE IF NOT EXISTS teams (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id VARCHAR(50) UNIQUE NOT NULL,
                team_name VARCHAR(100) UNIQUE NOT NULL,
                college VARCHAR(255) NOT NULL,
                team_size INT NOT NULL,
                domain VARCHAR(100) NOT NULL,
                project_title VARCHAR(255) NOT NULL,
                status VARCHAR(50) DEFAULT 'ACTIVE',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'team_members' => "CREATE TABLE IF NOT EXISTS team_members (
                id INT AUTO_INCREMENT PRIMARY KEY,
                team_id INT NOT NULL,
                salutation VARCHAR(15) DEFAULT '',
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) UNIQUE NOT NULL,
                mobile VARCHAR(20) NOT NULL,
                branch VARCHAR(100) NOT NULL,
                year VARCHAR(50) NOT NULL,
                role VARCHAR(50) NOT NULL,
                certificate_id VARCHAR(50) UNIQUE NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE,
                INDEX idx_email (email),
                INDEX idx_cert_id (certificate_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'participants_view' => "CREATE OR REPLACE VIEW participants AS
            SELECT 
                tm.id AS id,
                t.team_id AS team_no,
                t.team_name AS team_name,
                tm.name AS participant_name,
                tm.branch AS branch,
                tm.email AS email,
                tm.certificate_id AS certificate_id,
                tm.salutation AS salutation,
                t.project_title AS project_title,
                tm.created_at AS created_at,
                tm.updated_at AS updated_at
            FROM team_members tm
            JOIN teams t ON tm.team_id = t.id",
            
            'certificate_templates' => "CREATE TABLE IF NOT EXISTS certificate_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_name VARCHAR(100) NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                page_width FLOAT DEFAULT NULL,
                page_height FLOAT DEFAULT NULL,
                orientation ENUM('P', 'L') DEFAULT 'L',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'certificate_fields' => "CREATE TABLE IF NOT EXISTS certificate_fields (
                id INT AUTO_INCREMENT PRIMARY KEY,
                template_id INT NOT NULL,
                field_name VARCHAR(50) NOT NULL,
                x_position FLOAT NOT NULL,
                y_position FLOAT NOT NULL,
                font_name VARCHAR(50) DEFAULT 'helvetica',
                font_size INT DEFAULT 16,
                font_style VARCHAR(10) DEFAULT '',
                text_color VARCHAR(20) DEFAULT '#000000',
                alignment VARCHAR(10) DEFAULT 'L',
                FOREIGN KEY (template_id) REFERENCES certificate_templates(id) ON DELETE CASCADE,
                UNIQUE KEY uq_template_field (template_id, field_name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'certificates' => "CREATE TABLE IF NOT EXISTS certificates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                participant_id INT NOT NULL,
                certificate_id VARCHAR(50) UNIQUE NOT NULL,
                file_path VARCHAR(255) NOT NULL,
                status ENUM('PENDING', 'GENERATED', 'FAILED') DEFAULT 'PENDING',
                generated_at TIMESTAMP NULL DEFAULT NULL,
                FOREIGN KEY (participant_id) REFERENCES team_members(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'smtp_settings' => "CREATE TABLE IF NOT EXISTS smtp_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                smtp_host VARCHAR(255) NOT NULL,
                smtp_port INT NOT NULL,
                smtp_username VARCHAR(255) NOT NULL,
                smtp_password TEXT NOT NULL,
                smtp_encryption VARCHAR(10) NOT NULL,
                from_email VARCHAR(255) NOT NULL,
                from_name VARCHAR(255) NOT NULL,
                test_sent_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'email_templates' => "CREATE TABLE IF NOT EXISTS email_templates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                subject VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'email_logs' => "CREATE TABLE IF NOT EXISTS email_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                participant_id INT NOT NULL,
                certificate_id VARCHAR(50) NOT NULL,
                certificate_file VARCHAR(255) DEFAULT NULL,
                email VARCHAR(100) NOT NULL,
                recipient_name VARCHAR(100) DEFAULT NULL,
                status ENUM('PENDING', 'SENDING', 'SENT', 'FAILED') DEFAULT 'PENDING',
                sent_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                error_message TEXT NULL,
                retry_count INT DEFAULT 0,
                attempt_count INT DEFAULT 0,
                FOREIGN KEY (participant_id) REFERENCES team_members(id) ON DELETE CASCADE,
                INDEX idx_status (status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
            
            'activity_logs' => "CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id INT NULL,
                action VARCHAR(100) NOT NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"
        ];

        foreach ($tables as $name => $sql) {
            $pdo->exec($sql);
            $results[] = ["Table `$name` created or verified", "SUCCESS"];
        }

        // 3. Create Default Admin if none exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM admins");
        if ($stmt->fetchColumn() == 0) {
            $defaultUsername = 'admin';
            $defaultEmail = 'admin@hackmatrix.com';
            $defaultPass = 'admin123';
            $hashedPass = password_hash($defaultPass, PASSWORD_DEFAULT);
            
            $insert = $pdo->prepare("INSERT INTO admins (username, email, password) VALUES (?, ?, ?)");
            $insert->execute([$defaultUsername, $defaultEmail, $hashedPass]);
            $results[] = ["Default admin account created (User: <b>$defaultUsername</b> / Pass: <b>$defaultPass</b>)", "INFO"];
        } else {
            $results[] = ["Admin account already exists, skipping creation", "INFO"];
        }

        // 4. Create Default Email Template if none exists
        $stmt = $pdo->query("SELECT COUNT(*) FROM email_templates");
        if ($stmt->fetchColumn() == 0) {
            $defaultSubject = "HackMatrix 1.0 – Certificate of Participation";
            $defaultBody = "Dear {{participant_name}},\n\nThank you for participating in HackMatrix 1.0. We are pleased to provide you with your certificate of participation.\n\nPlease find your certificate attached to this email.\n\nRegards,\nHackMatrix 1.0 Technical Team";
            
            $insert = $pdo->prepare("INSERT INTO email_templates (subject, body) VALUES (?, ?)");
            $insert->execute([$defaultSubject, $defaultBody]);
            $results[] = ["Default email template created", "INFO"];
        } else {
            $results[] = ["Email template already exists, skipping creation", "INFO"];
        }

        $success = true;
        $message = "Database Setup Completed Successfully!";
    } catch (PDOException $e) {
        $success = false;
        $message = "Database Setup Failed: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Setup - HackMatrix 1.0</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #0b0f19;
            --card-bg: #141c2f;
            --accent-color: #3b82f6;
            --success-color: #10b981;
            --info-color: #6366f1;
            --danger-color: #ef4444;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }

        .container {
            width: 100%;
            max-width: 600px;
            padding: 20px;
        }

        .card {
            background-color: var(--card-bg);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
        }

        h1 {
            font-size: 24px;
            font-weight: 700;
            margin-top: 0;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        p {
            color: var(--text-muted);
            margin-bottom: 24px;
            font-size: 14px;
        }

        .btn {
            background-color: var(--accent-color);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px 24px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: opacity 0.2s;
        }

        .btn:hover {
            opacity: 0.9;
        }

        .console {
            background-color: #070a13;
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 8px;
            padding: 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 13px;
            max-height: 250px;
            overflow-y: auto;
            margin-top: 24px;
        }

        .console-line {
            margin-bottom: 8px;
            line-height: 1.5;
        }

        .console-line:last-child {
            margin-bottom: 0;
        }

        .tag {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            margin-right: 8px;
            display: inline-block;
        }

        .tag-success {
            background-color: rgba(16, 185, 129, 0.15);
            color: var(--success-color);
        }

        .tag-info {
            background-color: rgba(99, 102, 241, 0.15);
            color: var(--info-color);
        }

        .tag-danger {
            background-color: rgba(239, 68, 68, 0.15);
            color: var(--danger-color);
        }

        .alert {
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert-success {
            background-color: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: var(--success-color);
        }

        .alert-danger {
            background-color: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger-color);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h1>HackMatrix 1.0 DB Installer</h1>
            <p>This script initializes the MySQL database tables, indices, and baseline records.</p>
            
            <?php if (!empty($message)): ?>
                <div class="alert <?= $success ? 'alert-success' : 'alert-danger' ?>">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <button type="submit" name="run_setup" class="btn">Run Database Installer</button>
            </form>

            <?php if (!empty($results)): ?>
                <div class="console">
                    <?php foreach ($results as $res): ?>
                        <div class="console-line">
                            <?php if ($res[1] == 'SUCCESS'): ?>
                                <span class="tag tag-success">OK</span>
                            <?php elseif ($res[1] == 'INFO'): ?>
                                <span class="tag tag-info">INFO</span>
                            <?php else: ?>
                                <span class="tag tag-danger">ERR</span>
                            <?php endif; ?>
                            <span><?= $res[0] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
