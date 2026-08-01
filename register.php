<?php
require_once 'db.php';
require_once 'nepali_date.php';

$message = '';
$alertType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']);
    $email    = trim($_POST['email']);
    $phone    = trim($_POST['phone']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    // Auto-calculate Account Joined / Start Date (AD & BS)
    $today_ad = date('Y-m-d');
    $today_bs = NepaliDateConverter::convertAdToBs($today_ad);

    // Profile photo upload handling
    $photoName = 'default.png';
    if (isset($_FILES['profile_photo']) && $_FILES['profile_photo']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath   = $_FILES['profile_photo']['tmp_name'];
        $fileName      = $_FILES['profile_photo']['name'];
        $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($fileExtension, $allowedExtensions)) {
            $newFileName   = time() . '_' . uniqid() . '.' . $fileExtension;
            $uploadFileDir = './uploads/';
            
            if (!is_dir($uploadFileDir)) {
                mkdir($uploadFileDir, 0755, true);
            }
            
            if (move_uploaded_file($fileTmpPath, $uploadFileDir . $newFileName)) {
                $photoName = $newFileName;
            }
        }
    }

    if (!empty($name) && !empty($email) && !empty($username) && !empty($password)) {
        // Check if username or email already exists
        $checkStmt = $conn->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $checkStmt->bind_param("ss", $username, $email);
        $checkStmt->execute();

        if ($checkStmt->get_result()->num_rows > 0) {
            $message = "Username or Email already exists!";
            $alertType = "danger";
        } else {
            // Hash password securely
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

            // Insert user with joined_date_bs
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
    <title>Create New Account - AI Accountant</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="auth-card">
        <h2>Create Account</h2>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $alertType ?>"><?= $message ?></div>
        <?php endif; ?>

        <form action="register.php" method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="name" required>
            </div>
            <div class="form-group">
                <label>Gmail / Email</label>
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
                <input type="file" name="profile_photo" accept="image/*">
            </div>
            <button type="submit" class="btn">Register Account</button>
        </form>

        <div class="switch-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</body>
</html>