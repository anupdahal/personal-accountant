<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type    = $_POST['transaction_type'];
    $subject = trim($_POST['subject']);
    $amount  = floatval($_POST['amount']);
    $remarks = trim($_POST['remarks']);

    $date_ad = date('Y-m-d');
    $date_bs = NepaliDateConverter::convertAdToBs($date_ad);

    if (!empty($subject) && $amount > 0) {
        $stmt = $conn->prepare("INSERT INTO transactions (user_id, transaction_type, subject, amount, date_ad, date_bs, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("issdsss", $user_id, $type, $subject, $amount, $date_ad, $date_bs, $remarks);

        if ($stmt->execute()) {
            header("Location: dashboard.php");
            exit();
        } else {
            $message = "Error saving transaction: " . $conn->error;
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>New Entry - AI Accountant</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background-color: #f8fafc; padding: 16px; padding-bottom: 80px; }
        .form-card { background: #ffffff; border-radius: 16px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,0.04); max-width: 480px; margin: 0 auto; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #475569; margin-bottom: 6px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 12px; font-size: 14px; border: 1px solid #cbd5e1; border-radius: 10px; background: #f8fafc; outline: none; }
        .btn-submit { width: 100%; padding: 14px; background: #2563eb; color: #ffffff; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; }
    </style>
</head>
<body>
    <div class="form-card">
        <h3 style="margin-bottom: 16px; font-size: 17px;">+ New Financial Entry</h3>
        <?php if (!empty($message)): ?>
            <p style="color: #dc2626; font-size: 13px; margin-bottom: 10px;"><?= $message ?></p>
        <?php endif; ?>

        <form action="add_entry.php" method="POST">
            <div class="form-group">
                <label>Transaction Category</label>
<select name="transaction_type" required>
    <option value="starting_balance">Starting Balance (Bank / eSewa / Khalti / Cash)</option>
    <option value="income">Income / Salary / Bonus / Gift / Share Profit</option>
    <option value="expense">Expense (Petrol, Food, Clothes, Snacks, Stationary)</option>
    <option value="lend">Lend (Money Given to Others)</option>
    <option value="borrow">Borrow (Money Owed / Ward or Office Debt)</option>
    <option value="investment">Investment (NEPSE Stock Buy / SIP Monthly)</option>
    <option value="loss">Loss (Share Sell Loss / Business Loss)</option>
</select>
            </div>

            <div class="form-group">
                <label>Subject</label>
                <input type="text" name="subject" placeholder="e.g. Petrol, Ward Tax Borrow, NABIL Share" required>
            </div>

            <div class="form-group">
                <label>Amount (NPR)</label>
                <input type="number" step="0.01" name="amount" placeholder="Rs. 0.00" required>
            </div>

            <div class="form-group">
                <label>Remarks / Notes</label>
                <textarea name="remarks" rows="3" placeholder="Optional context or reference notes..."></textarea>
            </div>

            <button type="submit" class="btn-submit">Save Entry (Auto BS Date)</button>
        </form>
    </div>
</body>
</html>