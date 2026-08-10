<?php
/**
 * HackMatrix 1.0 - Admin Login Page
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Redirect to dashboard if already logged in
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$usernameOrEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameOrEmail = trim($_POST['username_or_email'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF
    if (!validateCSRFToken($csrfToken)) {
        $error = 'Invalid request security token (CSRF failure).';
        logActivity('LOGIN_FAILED', 'CSRF validation failed for user: ' . $usernameOrEmail);
    } elseif (empty($usernameOrEmail) || empty($password)) {
        $error = 'Please enter both username/email and password.';
    } else {
        try {
            $pdo = getDBConnection();
            // Allow login by either username or email
            $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ? OR email = ? LIMIT 1");
            $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
            $admin = $stmt->fetch();
            
            if ($admin && password_verify($password, $admin['password'])) {
                // Success! Set session variables
                login($admin['id'], $admin['username']);
                logActivity('LOGIN_SUCCESS', 'Admin user logged in: ' . $admin['username']);
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error = 'Invalid username/email or password.';
                logActivity('LOGIN_FAILED', 'Incorrect credentials for: ' . $usernameOrEmail);
            }
        } catch (PDOException $e) {
            $error = 'Database error occurred: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - HackMatrix 1.0</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #080d1a;
            --card-bg: rgba(20, 28, 47, 0.6);
            --border-color: rgba(255, 255, 255, 0.08);
            --accent-primary: #3b82f6;
            --accent-secondary: #2563eb;
            --text-main: #f3f4f6;
            --text-muted: #9ca3af;
            --danger-color: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            background-image: 
                radial-gradient(at 0% 0%, rgba(59, 130, 246, 0.1) 0px, transparent 50%),
                radial-gradient(at 100% 100%, rgba(99, 102, 241, 0.1) 0px, transparent 50%);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 440px;
        }

        .brand {
            text-align: center;
            margin-bottom: 32px;
        }

        .brand h1 {
            font-size: 28px;
            font-weight: 800;
            letter-spacing: -0.5px;
            margin-bottom: 6px;
            background: linear-gradient(135deg, #a5b4fc, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand p {
            color: var(--text-muted);
            font-size: 14px;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            backdrop-filter: blur(16px);
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 20px 40px -15px rgba(0, 0, 0, 0.5);
        }

        .form-group {
            margin-bottom: 24px;
            position: relative;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            background: rgba(8, 13, 26, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 14px;
            color: white;
            outline: none;
            transition: all 0.2s ease-in-out;
            font-family: inherit;
        }

        .form-input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }

        .btn-submit {
            width: 100%;
            background: linear-gradient(135deg, var(--accent-primary), var(--accent-secondary));
            color: white;
            border: none;
            border-radius: 10px;
            padding: 14px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            transition: transform 0.1s, opacity 0.2s;
            font-family: inherit;
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.25);
        }

        .btn-submit:hover {
            opacity: 0.95;
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        .error-message {
            background-color: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: var(--danger-color);
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 13px;
            margin-bottom: 24px;
            font-weight: 500;
            line-height: 1.4;
        }

        .footer-text {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="brand">
        <h1>HACKMATRIX 1.0</h1>
        <p>Certificate Generation & Email System</p>
    </div>
    
    <div class="card">
        <?php if (!empty($error)): ?>
            <div class="error-message">
                <?= e($error) ?>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="login.php">
            <?php csrfInput(); ?>
            
            <div class="form-group">
                <label for="username_or_email">EMAIL OR USERNAME</label>
                <input type="text" id="username_or_email" name="username_or_email" class="form-input" required 
                       value="<?= e($usernameOrEmail) ?>" placeholder="admin or admin@hackmatrix.com" autocomplete="username">
            </div>
            
            <div class="form-group">
                <label for="password">PASSWORD</label>
                <input type="password" id="password" name="password" class="form-input" required 
                       placeholder="••••••••" autocomplete="current-password">
            </div>
            
            <button type="submit" class="btn-submit">Sign In</button>
        </form>
    </div>
    
    <div class="footer-text">
        &copy; 2026 HackMatrix College Hackathon. All rights reserved.
    </div>
</div>

</body>
</html>
