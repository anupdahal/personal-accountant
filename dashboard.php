<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id      = $_SESSION['user_id'];
$userName     = $_SESSION['name'] ?? 'User';
$profilePhoto = $_SESSION['profile_photo'] ?? 'default.png';
$joinedBs     = $_SESSION['joined_date_bs'] ?? '2083-04-16';

$today_ad = date('Y-m-d');
$today_bs = NepaliDateConverter::convertAdToBs($today_ad);

// 1. Fetch Summary Totals per Category for Logged-in User
$summaryQuery = "SELECT 
    SUM(CASE WHEN transaction_type = 'starting_balance' THEN amount ELSE 0 END) AS total_starting_balance,
    SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) AS total_income,
    SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) AS total_expense,
    SUM(CASE WHEN transaction_type = 'lend' THEN amount ELSE 0 END) AS total_lend,
    SUM(CASE WHEN transaction_type = 'borrow' THEN amount ELSE 0 END) AS total_borrow,
    SUM(CASE WHEN transaction_type = 'investment' THEN amount ELSE 0 END) AS total_investment,
    SUM(CASE WHEN transaction_type = 'loss' THEN amount ELSE 0 END) AS total_loss
    FROM transactions WHERE user_id = ?";

$sumStmt = $conn->prepare($summaryQuery);
$sumStmt->bind_param("i", $user_id);
$sumStmt->execute();
$totals = $sumStmt->get_result()->fetch_assoc();

// Calculate Net Available Balance:
$startingCapital = $totals['total_starting_balance'] ?? 0;
$totalIncome     = $totals['total_income'] ?? 0;
$totalExpense    = $totals['total_expense'] ?? 0;
$totalLend       = $totals['total_lend'] ?? 0;
$totalBorrow     = $totals['total_borrow'] ?? 0;
$totalInvestment = $totals['total_investment'] ?? 0;
$totalLoss       = $totals['total_loss'] ?? 0;

$netBalance = ($startingCapital + $totalIncome + $totalBorrow) - ($totalExpense + $totalLend + $totalInvestment + $totalLoss);

// 2. Fetch Grouped Totals Topic-by-Topic for the Dashboard Table
$topicQuery = "SELECT 
    transaction_type, 
    COUNT(*) AS total_entries, 
    SUM(amount) AS total_amount 
    FROM transactions 
    WHERE user_id = ? 
    GROUP BY transaction_type 
    ORDER BY total_amount DESC";

$topicStmt = $conn->prepare($topicQuery);
$topicStmt->bind_param("i", $user_id);
$topicStmt->execute();
$topicResults = $topicStmt->get_result();

// Topic Mapping Labels & Styles
$topicLabels = [
    'starting_balance' => ['label' => 'Starting Balance / Wallets', 'badge' => 'badge-starting_balance'],
    'income'           => ['label' => 'Income / Profit / Bonus',    'badge' => 'badge-income'],
    'expense'          => ['label' => 'Expenses (Food, Petrol)',    'badge' => 'badge-expense'],
    'lend'             => ['label' => 'Lend (Money Given)',         'badge' => 'badge-lend'],
    'borrow'           => ['label' => 'Borrow (Debts / Ward)',       'badge' => 'badge-borrow'],
    'investment'       => ['label' => 'Investments (NEPSE / SIP)',  'badge' => 'badge-investment'],
    'loss'             => ['label' => 'Trading / Share Loss',       'badge' => 'badge-loss']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI Accountant - Executive Summary</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: #f8fafc; color: #0f172a; padding-bottom: 85px; }
        
        /* App Header */
        .app-header { background: #ffffff; padding: 14px 16px; position: sticky; top: 0; z-index: 90; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #2563eb; }
        .user-info h2 { font-size: 15px; font-weight: 700; }
        .user-info p { font-size: 11px; color: #64748b; }
        
        .container { padding: 16px; max-width: 500px; margin: 0 auto; }
        
        /* Net Liquidity Banner */
        .net-worth-card { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); color: #ffffff; padding: 20px; border-radius: 18px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.15); margin-bottom: 16px; }
        .net-worth-card span { font-size: 11px; color: #94a3b8; text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
        .net-worth-card h1 { font-size: 28px; margin: 6px 0 14px 0; font-weight: 800; }
        .card-row { display: flex; justify-content: space-between; border-top: 1px solid rgba(255, 255, 255, 0.1); padding-top: 12px; }
        
        /* 2x3 Category Summary Grid */
        .summary-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
        .grid-box { background: #ffffff; padding: 14px; border-radius: 14px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; }
        .grid-box span { font-size: 11px; color: #64748b; font-weight: 600; display: block; margin-bottom: 4px; }
        .grid-box h3 { font-size: 16px; font-weight: 700; }
        
        .box-start h3 { color: #0284c7; }
        .box-income h3 { color: #16a34a; }
        .box-expense h3 { color: #dc2626; }
        .box-lend h3 { color: #2563eb; }
        .box-borrow h3 { color: #d97706; }
        .box-invest h3 { color: #7c3aed; }
        
        /* Topic Breakdown Table Styling */
        .table-card { background: #ffffff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 16px; overflow-x: auto; }
        .table-card h4 { font-size: 13px; margin-bottom: 12px; color: #334155; display: flex; justify-content: space-between; align-items: center; }
        
        .topic-table { width: 100%; border-collapse: collapse; text-align: left; }
        .topic-table th { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; padding: 8px 6px; border-bottom: 2px solid #f1f5f9; }
        .topic-table td { padding: 10px 6px; font-size: 12px; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
        .topic-table tr:last-child td { border-bottom: none; }

        /* Dynamic Badges for Category Topics */
        .badge { font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 6px; display: inline-block; }
        .badge-starting_balance { background: #e0f2fe; color: #0369a1; }
        .badge-income { background: #dcfce7; color: #15803d; }
        .badge-expense { background: #fee2e2; color: #b91c1c; }
        .badge-lend { background: #eff6ff; color: #1d4ed8; }
        .badge-borrow { background: #fef3c7; color: #b45309; }
        .badge-investment { background: #f3e8ff; color: #6b21a8; }
        .badge-loss { background: #ffe4e6; color: #9f1239; }

        /* Bottom Fixed Navigation Bar */
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 65px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-around; align-items: center; z-index: 1000; }
        .nav-item { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #64748b; font-size: 10px; font-weight: 600; gap: 3px; }
        .nav-item.active { color: #2563eb; }
        .nav-item svg { width: 22px; height: 22px; fill: currentColor; }
        .nav-add-btn { background: #2563eb; color: #ffffff; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-top: -24px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4); text-decoration: none; }
        .nav-add-btn svg { width: 26px; height: 26px; fill: #ffffff; }
    </style>
</head>
<body>

    <!-- Header Section -->
    <header class="app-header">
        <div class="user-profile">
            <img src="uploads/<?= htmlspecialchars($profilePhoto); ?>" alt="Profile" class="avatar" onerror="this.src='https://via.placeholder.com/44'">
            <div class="user-info">
                <h2><?= htmlspecialchars($userName); ?></h2>
                <p>Joined ID: <strong style="color: #2563eb;"><?= htmlspecialchars($joinedBs); ?> BS</strong></p>
            </div>
        </div>
        <div style="font-size: 11px; color: #64748b; text-align: right;">
            <strong><?= $today_bs ?> BS</strong><br>
            <a href="logout.php" style="color: #dc2626; text-decoration: none; font-weight: 600;">Logout</a>
        </div>
    </header>

    <div class="container">

        <!-- Central Net Balance Card -->
        <div class="net-worth-card">
            <span>Net Available Cash & Wallet Balance</span>
            <h1>Rs. <?= number_format($netBalance, 2); ?></h1>
            <div class="card-row">
                <div>
                    <p style="font-size:11px; color:#94a3b8;">Starting Bank/Wallet</p>
                    <strong style="color: #38bdf8;">Rs. <?= number_format($startingCapital, 2); ?></strong>
                </div>
                <div style="text-align: right;">
                    <p style="font-size:11px; color:#94a3b8;">Earned Income</p>
                    <strong style="color: #4ade80;">+ Rs. <?= number_format($totalIncome, 2); ?></strong>
                </div>
            </div>
        </div>

        <!-- 2x3 Category Summary Grid -->
        <div class="summary-grid">
            <div class="grid-box box-start">
                <span>Opening Capital</span>
                <h3>Rs. <?= number_format($startingCapital, 2); ?></h3>
            </div>
            <div class="grid-box box-income">
                <span>Income / Profit / Bonus</span>
                <h3>Rs. <?= number_format($totalIncome, 2); ?></h3>
            </div>
            <div class="grid-box box-expense">
                <span>Expenses (Food, Petrol)</span>
                <h3>Rs. <?= number_format($totalExpense, 2); ?></h3>
            </div>
            <div class="grid-box box-lend">
                <span>Lend (Given Out)</span>
                <h3>Rs. <?= number_format($totalLend, 2); ?></h3>
            </div>
            <div class="grid-box box-borrow">
                <span>Borrow (Owed / Debt)</span>
                <h3>Rs. <?= number_format($totalBorrow, 2); ?></h3>
            </div>
            <div class="grid-box box-invest">
                <span>Investment (NEPSE/SIP)</span>
                <h3>Rs. <?= number_format($totalInvestment, 2); ?></h3>
            </div>
        </div>

        <!-- Topic Breakdown Table Block -->
        <div class="table-card">
            <h4>
                <span>📊 Category & Topic Summary Table</span>
                <a href="ledger.php" style="color: #2563eb; text-decoration: none; font-size: 11px;">Detailed Ledger &rarr;</a>
            </h4>
            
            <table class="topic-table">
                <thead>
                    <tr>
                        <th>Topic / Category</th>
                        <th style="text-align: center;">Entries</th>
                        <th style="text-align: right;">Total Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($topicResults->num_rows > 0): ?>
                        <?php while ($row = $topicResults->fetch_assoc()): ?>
                            <?php 
                                $typeKey  = $row['transaction_type'];
                                $badgeCls = $topicLabels[$typeKey]['badge'] ?? 'badge-income';
                                $labelName= $topicLabels[$typeKey]['label'] ?? ucfirst($typeKey);
                            ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $badgeCls; ?>"><?= htmlspecialchars($labelName); ?></span>
                                </td>
                                <td style="text-align: center; color: #64748b; font-weight: 600;">
                                    <?= $row['total_entries']; ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #0f172a;">
                                    Rs. <?= number_format($row['total_amount'], 2); ?>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="3" style="text-align: center; color: #94a3b8; font-size: 12px; padding: 16px 0;">
                                No transactions logged yet. Tap <strong>+</strong> to start recording!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <!-- Fixed Bottom Navigation Bar -->
    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item active">
            <svg viewBox="0 0 24 24"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg>
            <span>Summary</span>
        </a>
        <a href="ledger.php" class="nav-item">
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/></svg>
            <span>Ledger</span>
        </a>
        <a href="add_entry.php" class="nav-add-btn">
            <svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>
        </a>
        <a href="reports.php" class="nav-item">
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2v-10h2v10zm4 0h-2v-4h2v4z"/></svg>
            <span>Reports</span>
        </a>
    </nav>
</body>
</html>