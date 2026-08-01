<?php
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

$query = "SELECT * FROM transactions WHERE user_id = ? ORDER BY id DESC";
$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$transactions = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Transaction History - AI Accountant</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background-color: #f8fafc; padding: 16px; padding-bottom: 80px; }
        .ledger-card { background: #ffffff; border-radius: 16px; padding: 16px; max-width: 480px; margin: 0 auto; box-shadow: 0 2px 6px rgba(0,0,0,0.03); }
        .ledger-item { display: flex; justify-content: space-between; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
        .badge { font-size: 10px; font-weight: 700; padding: 3px 6px; border-radius: 4px; text-transform: uppercase; }
        .badge-income { background: #dcfce7; color: #15803d; }
        .badge-expense { background: #fee2e2; color: #b91c1c; }
        .badge-lend { background: #e0f2fe; color: #0369a1; }
        .badge-borrow { background: #fef3c7; color: #b45309; }
        .badge-investment { background: #f3e8ff; color: #6b21a8; }
    </style>
</head>
<body>
    <div class="ledger-card">
        <h3 style="font-size: 16px; margin-bottom: 12px;">Account History</h3>

        <?php if ($transactions->num_rows > 0): ?>
            <?php while ($row = $transactions->fetch_assoc()): ?>
                <div class="ledger-item">
                    <div>
                        <strong style="font-size: 14px;"><?= htmlspecialchars($row['subject']); ?></strong>
                        <small style="display: block; font-size: 11px; color: #94a3b8;"><?= htmlspecialchars($row['date_bs']); ?> BS • <?= htmlspecialchars($row['remarks'] ?? '-'); ?></small>
                    </div>
                    <div style="text-align: right;">
                        <span class="badge badge-<?= $row['transaction_type']; ?>"><?= $row['transaction_type']; ?></span>
                        <p style="font-size: 13px; font-weight: 700; margin-top: 2px;">Rs. <?= number_format($row['amount'], 2); ?></p>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="text-align: center; color: #888; padding: 20px 0;">No entries recorded yet.</p>
        <?php endif; ?>
    </div>
</body>
</html>