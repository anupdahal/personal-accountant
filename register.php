<?php
require_once 'db.php';
require_once 'nepali_date.php';

$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    $today_ad = date('Y-m-d');
    $today_bs = NepaliDateConverter::convertAdToBs($today_ad);

    $photoName = 'default.png';
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['profile_photo']['tmp_name'];
        $fileName      = $_FILES['profile_photo']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName   = time() . '_' . uniqid() . '.' . $fileExtension;
            $uploadFileDir = __DIR__ . '/uploads/';

            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }

            if (move_uploaded_file($fileTmpPath, $uploadFileDir . $newFileName)) {
                $photoName = $newFileName;
            }
        }
    }

    if (!empty($name) && !empty($email) && !empty($username) && !empty($password)) {
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();

        if ($checkStmt->get_result()->num_rows > 0) {
            $message = "Username or Email already exists!";
            $alertType = "danger";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            $stmt = $conn->prepare("INSERT INTO users (name, email, phone, username, password, profile_photo, joined_date_bs) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssss", $name, $email, $phone, $username, $hashedPassword, $photoName, $today_bs);

            if ($stmt->execute()) {
                $message = "Account created successfully on <strong>$today_bs BS</strong>! <a href='login.php'>Login here</a>";
                $alertType = "success";
            } else {
                $message = "Error creating user: " . $conn->error;
                $alertType = "danger";
            }
            $stmt->close();
        }
        $checkStmt->close();
    } else {
        $message = "Please fill in all required fields.";
        $alertType = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2563eb">
    <title>Create New Account — AI Accountant</title>
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
            max-width: 440px;
        }
        .auth-logo { text-align: center; margin-bottom: 20px; }
        .auth-logo .logo-icon { font-size: 36px; margin-bottom: 6px; }
        .auth-logo h2 { font-size: 20px; font-weight: 800; }
        .auth-logo p { font-size: 12px; color: var(--c-text-3); margin-top: 4px; }
        .form-group { margin-bottom: 14px; }
        .form-group label {
            display: block; margin-bottom: 6px;
            font-weight: 600; font-size: 13px; color: var(--c-text-2);
        }
        .form-group input, .form-group textarea {
            width: 100%; padding: 12px 14px;
            border: 1px solid var(--c-border);
            border-radius: var(--r-md); font-size: 14px;
            background: var(--c-bg); outline: none;
            transition: border var(--t-base);
        }
        .form-group input:focus, .form-group textarea:focus {
            border-color: var(--c-primary); background: var(--c-surface);
        }
        .file-upload {
            display: flex; align-items: center; gap: 12px;
            padding: 12px; border: 2px dashed var(--c-border);
            border-radius: var(--r-md); background: var(--c-bg);
            cursor: pointer; transition: border var(--t-base);
        }
        .file-upload:active { border-color: var(--c-primary); }
        .file-upload input { display: none; }
        .file-upload .upload-icon {
            font-size: 24px; color: var(--c-primary);
        }
        .file-upload .upload-text {
            font-size: 12px; color: var(--c-text-3);
        }
        .btn-auth {
            width: 100%; padding: 14px;
            background: var(--c-primary); color: #fff;
            border: none; border-radius: var(--r-md);
            font-weight: 700; cursor: pointer; font-size: 15px;
            box-shadow: var(--shadow-blue); transition: transform var(--t-fast);
        }
        .btn-auth:active { transform: scale(0.98); }
        .switch-link {
            text-align: center; margin-top: 18px;
            font-size: 13px; color: var(--c-text-3);
        }
        .switch-link a {
            color: var(--c-primary); text-decoration: none; font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="auth-card">
        <div class="auth-logo">
            <div class="logo-icon">🤖</div>
            <h2>Create Account</h2>
            <p>Join AI Accountant today</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $alertType ?>"><?= $message ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" required autofocus>
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" required>
            </div>
            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone" required>
            </div>
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            <div class="form-group">
                <label>Profile Photo</label>
                <label class="file-upload">
                    <span class="upload-icon">📷</span>
                    <span class="upload-text">Tap to upload (JPG, PNG, WEBP)</span>
                    <input type="file" name="profile_photo" accept="image/*">
                </label>
            </div>
            <button type="submit" class="btn-auth">Register Account</button>
        </form>

        <div class="switch-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</body>
</html>
