<?php
session_start();
require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, name, username, password, profile_photo, joined_date_bs, created_at FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id']        = $user['id'];
                $_SESSION['username']       = $user['username'];
                $_SESSION['name']           = $user['name'];
                $_SESSION['profile_photo']  = $user['profile_photo'];
                $_SESSION['joined_date_bs'] = $user['joined_date_bs'] ?? '2083-04-16';
                $_SESSION['created_at_ad']  = date('Y-m-d', strtotime($user['created_at']));

                header("Location: dashboard.php");
                exit();
            } else {
                $message = "Invalid password!";
            }
        } else {
            $message = "User not found!";
        }
        $stmt->close();
    } else {
        $message = "Please enter both username and password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2563eb">
    <title>User Login — AI Accountant</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body {
            background: linear-gradient(180deg, #eff6ff 0%, var(--c-bg) 40%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .auth-card {
            background: var(--c-surface);
            padding: 32px 24px;
            border-radius: var(--r-xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 420px;
        }
        .auth-logo {
            text-align: center;
            margin-bottom: 24px;
        }
        .auth-logo .logo-icon {
            font-size: 40px;
            margin-bottom: 8px;
        }
        .auth-logo h2 {
            font-size: 20px;
            font-weight: 800;
        }
        .auth-logo p {
            font-size: 12px;
            color: var(--c-text-3);
            margin-top: 4px;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            font-size: 13px;
            color: var(--c-text-2);
        }
        .form-group input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid var(--c-border);
            border-radius: var(--r-md);
            font-size: 14px;
            background: var(--c-bg);
            outline: none;
            transition: border var(--t-base);
        }
        .form-group input:focus {
            border-color: var(--c-primary);
            background: var(--c-surface);
        }
        .btn-auth {
            width: 100%;
            padding: 14px;
            background: var(--c-primary);
            color: #fff;
            border: none;
            border-radius: var(--r-md);
            font-weight: 700;
            cursor: pointer;
            font-size: 15px;
            box-shadow: var(--shadow-blue);
            transition: transform var(--t-fast);
        }
        .btn-auth:active { transform: scale(0.98); }
        .switch-link {
            text-align: center;
            margin-top: 18px;
            font-size: 13px;
            color: var(--c-text-3);
        }
        .switch-link a {
            color: var(--c-primary);
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-logo">
            <div class="logo-icon">🤖</div>
            <h2>AI Accountant</h2>
            <p>Smart Personal Finance Manager</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Username or Email</label>
                <input type="text" name="username" required autofocus>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn-auth">Login</button>
        </form>

        <div class="switch-link">
            Don't have an account? <a href="register.php">Create New User</a>
        </div>
    </div>
</body>
</html>
