<?php
session_start();
require_once 'db.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (!empty($username) && !empty($password)) {
        $stmt = $conn->prepare("SELECT id, name, username, password, profile_photo, joined_date_bs, created_at FROM users WHERE username = ? OR email = ?");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                // Set session data including account start/created dates
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
    <title>User Login - AI Accountant</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="auth-card">
        <h2>User Login</h2>

        <?php if (!empty($message)): ?>
            <div class="alert alert-danger"><?= $message ?></div>
        <?php endif; ?>

        <form action="login.php" method="POST">
            <div class="form-group">
                <label>Username or Email</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <button type="submit" class="btn">Login</button>
        </form>

        <div class="switch-link">
            Don't have an account? <a href="register.php">Create New User</a>
        </div>
    </div>
</body>
</html>