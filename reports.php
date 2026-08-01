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

// ----------------------------------------------------
// TIME PERIOD FILTER SETUP
// ----------------------------------------------------
$period = $_GET['period'] ?? 'monthly'; // Options: weekly, monthly, 6months, yearly, all

switch ($period) {
    case 'weekly':
        $dateFilter = "AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $periodTitle = "Last 7 Days";
        break;
    case '6months':
        $dateFilter = "AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)";
        $periodTitle = "Last 6 Months";
        break;
    case 'yearly':
        $dateFilter = "AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $periodTitle = "Last 1 Year";
        break;
    case 'all':
        $dateFilter = "";
        $periodTitle = "All-Time";
        break;
    case 'monthly':
    default:
        $period = 'monthly';
        $dateFilter = "AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)";
        $periodTitle = "Last 30 Days";
        break;
}

// Check if party_name column exists dynamically to prevent crashes
$hasPartyCol = false;
$colCheck = $conn->query("SHOW COLUMNS FROM transactions LIKE 'party_name'");
if ($colCheck && $colCheck->num_rows > 0) {
    $hasPartyCol = true;
}

// ----------------------------------------------------
// 1. Fetch Category Totals for Selected Period
// ----------------------------------------------------
$query = "SELECT 
    transaction_type, 
    COUNT(*) as total_count, 
    SUM(amount) as total_amount 
    FROM transactions 
    WHERE user_id = ? {$dateFilter}
    GROUP BY transaction_type 
    ORDER BY total_amount DESC";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

$categories = [];
$grandTotalVolume = 0;
$totalEntries     = 0;

while ($row = $result->fetch_assoc()) {
    $categories[$row['transaction_type']] = [
        'count'  => (int)$row['total_count'],
        'amount' => (float)$row['total_amount']
    ];
    $grandTotalVolume += (float)$row['total_amount'];
    $totalEntries     += (int)$row['total_count'];
}

// Category Values
$starting = $categories['starting_balance']['amount'] ?? 0;
$income   = $categories['income']['amount']           ?? 0;
$expense  = $categories['expense']['amount']          ?? 0;
$lend     = $categories['lend']['amount']             ?? 0;
$borrow   = $categories['borrow']['amount']           ?? 0;
$invest   = $categories['investment']['amount']       ?? 0;
$loss     = $categories['loss']['amount']             ?? 0;

$totalInflow  = $starting + $income + $borrow;
$totalOutflow = $expense + $lend + $invest + $loss;
$netLiquidity = $totalInflow - $totalOutflow;

// ----------------------------------------------------
// 2. AGING ENGINE: Lend & Borrow Liability Audit
// ----------------------------------------------------
$partySelect = $hasPartyCol ? "party_name," : "'' as party_name,";

$agingQuery = "SELECT 
    id, transaction_type, {$partySelect} amount, date_ad,
    DATEDIFF(CURDATE(), date_ad) as days_passed
    FROM transactions 
    WHERE user_id = ? AND transaction_type IN ('lend', 'borrow') {$dateFilter}
    ORDER BY date_ad ASC";

$aStmt = $conn->prepare($agingQuery);
$aStmt->bind_param("i", $user_id);
$aStmt->execute();
$aResult = $aStmt->get_result();

$agingData = [
    'lend'   => ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0],
    'borrow' => ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0]
];

$oldestLendDays   = 0;
$oldestLendParty  = '';
$oldestBorrowDays  = 0;
$oldestBorrowParty = '';

while ($aRow = $aResult->fetch_assoc()) {
    $type  = $aRow['transaction_type'];
    $amt   = (float)$aRow['amount'];
    $days  = (int)$aRow['days_passed'];
    $party = $aRow['party_name'] ?: 'Unspecified';

    if ($days <= 30) {
        $agingData[$type]['0_30'] += $amt;
    } elseif ($days <= 60) {
        $agingData[$type]['31_60'] += $amt;
    } elseif ($days <= 90) {
        $agingData[$type]['61_90'] += $amt;
    } else {
        $agingData[$type]['90_plus'] += $amt;
    }

    if ($type === 'lend' && $days > $oldestLendDays) {
        $oldestLendDays  = $days;
        $oldestLendParty = $party;
    }
    if ($type === 'borrow' && $days > $oldestBorrowDays) {
        $oldestBorrowDays  = $days;
        $oldestBorrowParty = $party;
    }
}

// ----------------------------------------------------
// 3. COMPARATIVE AUDIT ENGINE (Trend vs Previous Period)
// ----------------------------------------------------
$currentMonthStr = date('Y-m');
$lastMonthStr    = date('Y-m', strtotime('-1 month'));

$monthQuery = "SELECT 
    DATE_FORMAT(date_ad, '%Y-%m') as ym,
    SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) as monthly_expense,
    SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) as monthly_income
    FROM transactions 
    WHERE user_id = ? AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
    GROUP BY ym";

$mStmt = $conn->prepare($monthQuery);
$mStmt->bind_param("i", $user_id);
$mStmt->execute();
$mResult = $mStmt->get_result();

$monthlyData = [];
while ($mRow = $mResult->fetch_assoc()) {
    $monthlyData[$mRow['ym']] = $mRow;
}

$thisMonthExpense = $monthlyData[$currentMonthStr]['monthly_expense'] ?? 0;
$lastMonthExpense = $monthlyData[$lastMonthStr]['monthly_expense']    ?? 0;
$expenseTrendDiff = $thisMonthExpense - $lastMonthExpense;

// ----------------------------------------------------
// 4. ADVANCED AI FINANCIAL AUDIT ENGINE
// ----------------------------------------------------
$aiInsights = [];
$recommendations = [];

// Audit A: Debt & Receivable Aging Risks
if ($oldestLendDays > 90) {
    $aiInsights[] = "⚠️ <strong>High Receivable Risk:</strong> Outstanding lend capital pending for <strong>{$oldestLendDays} days</strong>" . ($oldestLendParty !== 'Unspecified' ? " (Party: <em>{$oldestLendParty}</em>)" : "") . ".";
    $recommendations[] = "Follow up immediately to recover lent funds older than 90 days.";
} elseif ($oldestLendDays > 30) {
    $aiInsights[] = "⌛ <strong>Receivable Aging:</strong> Oldest uncollected loan pending for <strong>{$oldestLendDays} days</strong>.";
}

if ($oldestBorrowDays > 60) {
    $aiInsights[] = "🚨 <strong>Debt Maturity Alert:</strong> Borrowed liability unpaid for <strong>{$oldestBorrowDays} days</strong>" . ($oldestBorrowParty !== 'Unspecified' ? " (Creditor: <em>{$oldestBorrowParty}</em>)" : "") . ".";
    $recommendations[] = "Prioritize clearing debts older than 60 days to prevent compounding obligations.";
}

// Audit B: Burn Rate & Income-to-Expense Ratios
$totalEarnedCapital = $starting + $income;

if ($totalEarnedCapital > 0) {
    $burnRate = ($expense / $totalEarnedCapital) * 100;
    if ($burnRate > 80) {
        $aiInsights[] = "🔥 <strong>Critical Burn Rate:</strong> Expenses account for <strong>" . number_format($burnRate, 1) . "%</strong> of total available capital.";
        $recommendations[] = "Cut non-essential operational expenses immediately to prevent liquidity loss.";
    } elseif ($burnRate > 50) {
        $aiInsights[] = "💡 <strong>Moderate Spending:</strong> Expense-to-income ratio sits at <strong>" . number_format($burnRate, 1) . "%</strong>.";
    }

    $investRatio = ($invest / $totalEarnedCapital) * 100;
    if ($investRatio >= 20) {
        $aiInsights[] = "📈 <strong>Wealth Creation:</strong> Excellent! <strong>" . number_format($investRatio, 1) . "%</strong> of income is deployed into wealth-generating assets.";
    } elseif ($investRatio > 0) {
        $recommendations[] = "Consider scaling investment allocation toward 20% of net income.";
    } else {
        $recommendations[] = "No investment logs found for this period. Try automating regular SIP installments.";
    }
}

// Audit C: Loss & Risk Exposure
if ($loss > 0) {
    $aiInsights[] = "📉 <strong>Capital Loss Audit:</strong> Recorded <strong>Rs. " . number_format($loss, 2) . "</strong> in trading/operational losses.";
}

// ----------------------------------------------------
// 5. HEALTH GRADE COMPUTATION
// ----------------------------------------------------
$healthScore = 75;

if ($totalEarnedCapital > 0) {
    $burnRate = ($expense / $totalEarnedCapital) * 100;
    if ($burnRate > 75) $healthScore -= 20;
    $investRatio = ($invest / $totalEarnedCapital) * 100;
    if ($investRatio >= 20) $healthScore += 15;
}

if ($agingData['lend']['90_plus'] > 0 || $agingData['borrow']['90_plus'] > 0) {
    $healthScore -= 15;
}
if ($loss > 0) {
    $healthScore -= 10;
}

$healthScore = max(0, min(100, $healthScore));
if ($healthScore >= 85) { $grade = 'A+ (Excellent)'; $gradeColor = '#16a34a'; }
elseif ($healthScore >= 70) { $grade = 'B (Good)'; $gradeColor = '#2563eb'; }
elseif ($healthScore >= 50) { $grade = 'C (Fair)'; $gradeColor = '#d97706'; }
else { $grade = 'D (At Risk)'; $gradeColor = '#dc2626'; }

$topicConfig = [
    'starting_balance' => ['label' => 'Starting Balance', 'badge' => 'badge-starting_balance'],
    'income'           => ['label' => 'Income / Profit',   'badge' => 'badge-income'],
    'expense'          => ['label' => 'Expenses',          'badge' => 'badge-expense'],
    'lend'             => ['label' => 'Lend (Receivable)', 'badge' => 'badge-lend'],
    'borrow'           => ['label' => 'Borrow (Liabilities)','badge' => 'badge-borrow'],
    'investment'       => ['label' => 'Investments (SIP)', 'badge' => 'badge-investment'],
    'loss'             => ['label' => 'Trading Losses',    'badge' => 'badge-loss']
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI Accountant - Multi-Period Financial Intelligence</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; -webkit-tap-highlight-color: transparent; }
        body { background-color: #f8fafc; color: #0f172a; padding-bottom: 85px; }
        .app-header { background: #ffffff; padding: 14px 16px; position: sticky; top: 0; z-index: 90; box-shadow: 0 1px 3px rgba(0,0,0,0.05); display: flex; align-items: center; justify-content: space-between; }
        .user-profile { display: flex; align-items: center; gap: 12px; }
        .avatar { width: 44px; height: 44px; border-radius: 50%; object-fit: cover; border: 2px solid #2563eb; }
        .user-info h2 { font-size: 15px; font-weight: 700; }
        .user-info p { font-size: 11px; color: #64748b; }
        .container { padding: 16px; max-width: 500px; margin: 0 auto; }
        
        /* Time Filter Nav Bar */
        .filter-scroll { display: flex; gap: 8px; overflow-x: auto; padding-bottom: 12px; margin-bottom: 12px; scrollbar-width: none; }
        .filter-scroll::-webkit-scrollbar { display: none; }
        .filter-btn { padding: 8px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-decoration: none; background: #ffffff; color: #64748b; border: 1px solid #e2e8f0; white-space: nowrap; transition: all 0.2s ease; }
        .filter-btn.active { background: #2563eb; color: #ffffff; border-color: #2563eb; box-shadow: 0 4px 10px rgba(37, 99, 235, 0.25); }

        .ai-card { background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%); color: #ffffff; padding: 18px; border-radius: 18px; box-shadow: 0 8px 20px rgba(15, 23, 42, 0.2); margin-bottom: 16px; border: 1px solid #312e81; }
        .ai-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
        .ai-header span { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: #818cf8; }
        .ai-bullet-list { list-style: none; display: flex; flex-direction: column; gap: 8px; }
        .ai-bullet-list li { font-size: 12px; line-height: 1.4; color: #e0e7ff; background: rgba(255,255,255,0.05); padding: 8px 10px; border-radius: 8px; border-left: 3px solid #6366f1; }
        .aging-card { background: #ffffff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 16px; }
        .aging-card h4 { font-size: 13px; color: #334155; margin-bottom: 12px; display: flex; justify-content: space-between; align-items: center; }
        .aging-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; text-align: center; }
        .aging-box { background: #f8fafc; padding: 8px 4px; border-radius: 8px; border: 1px solid #e2e8f0; }
        .aging-box .days-lbl { font-size: 9px; font-weight: 700; color: #64748b; text-transform: uppercase; }
        .aging-box .amt-val { font-size: 11px; font-weight: 700; margin-top: 2px; }
        .risk-fresh { color: #16a34a; }
        .risk-mild  { color: #d97706; }
        .risk-high  { color: #dc2626; }
        .risk-crit  { color: #9f1239; background: #ffe4e6; border-color: #fecdd3; }
        .health-card { background: #ffffff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 16px; display: flex; justify-content: space-between; align-items: center; }
        .health-score-ring { font-size: 26px; font-weight: 800; color: <?= $gradeColor ?>; }
        .stats-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
        .stat-box { background: #ffffff; padding: 14px; border-radius: 14px; border: 1px solid #f1f5f9; }
        .stat-box span { font-size: 11px; color: #64748b; font-weight: 600; display: block; margin-bottom: 4px; }
        .stat-box h3 { font-size: 15px; font-weight: 700; color: #0f172a; }
        .rec-card { background: #ffffff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 16px; }
        .rec-card h4 { font-size: 13px; margin-bottom: 10px; color: #334155; }
        .rec-item { font-size: 12px; color: #475569; padding: 8px 0; border-bottom: 1px solid #f1f5f9; display: flex; gap: 8px; align-items: flex-start; }
        .rec-item:last-child { border-bottom: none; }
        .report-card { background: #ffffff; border-radius: 16px; padding: 16px; box-shadow: 0 2px 6px rgba(0,0,0,0.02); border: 1px solid #f1f5f9; margin-bottom: 16px; }
        .report-card h4 { font-size: 13px; margin-bottom: 12px; color: #334155; display: flex; justify-content: space-between; align-items: center; }
        .report-table { width: 100%; border-collapse: collapse; text-align: left; }
        .report-table th { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; padding: 8px 4px; border-bottom: 2px solid #f1f5f9; }
        .report-table td { padding: 10px 4px; font-size: 12px; border-bottom: 1px solid #f8fafc; vertical-align: middle; }
        .badge { font-size: 10px; font-weight: 700; padding: 3px 6px; border-radius: 6px; display: inline-block; }
        .badge-starting_balance { background: #e0f2fe; color: #0369a1; }
        .badge-income { background: #dcfce7; color: #15803d; }
        .badge-expense { background: #fee2e2; color: #b91c1c; }
        .badge-lend { background: #eff6ff; color: #1d4ed8; }
        .badge-borrow { background: #fef3c7; color: #b45309; }
        .badge-investment { background: #f3e8ff; color: #6b21a8; }
        .badge-loss { background: #ffe4e6; color: #9f1239; }
        .bar-bg { background: #f1f5f9; height: 6px; border-radius: 3px; overflow: hidden; margin-top: 4px; width: 100%; }
        .bar-fill { height: 100%; background: #2563eb; border-radius: 3px; }
        .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; height: 65px; background: #ffffff; border-top: 1px solid #e2e8f0; display: flex; justify-content: space-around; align-items: center; z-index: 1000; }
        .nav-item { display: flex; flex-direction: column; align-items: center; text-decoration: none; color: #64748b; font-size: 10px; font-weight: 600; gap: 3px; }
        .nav-item.active { color: #2563eb; }
        .nav-item svg { width: 22px; height: 22px; fill: currentColor; }
        .nav-add-btn { background: #2563eb; color: #ffffff; width: 48px; height: 48px; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin-top: -24px; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.4); text-decoration: none; }
        .nav-add-btn svg { width: 26px; height: 26px; fill: #ffffff; }
    </style>
</head>
<body>

    <header class="app-header">
        <div class="user-profile">
            <img src="uploads/<?= htmlspecialchars($profilePhoto); ?>" alt="Profile" class="avatar" onerror="this.src='https://via.placeholder.com/44'">
            <div class="user-info">
                <h2><?= htmlspecialchars($userName); ?></h2>
                <p>Joined: <strong style="color: #2563eb;"><?= htmlspecialchars($joinedBs); ?> BS</strong></p>
            </div>
        </div>
        <div style="font-size: 11px; color: #64748b; text-align: right;">
            <strong><?= $today_bs ?> BS</strong><br>
            <a href="logout.php" style="color: #dc2626; text-decoration: none; font-weight: 600;">Logout</a>
        </div>
    </header>

    <div class="container">

        <!-- Dynamic Time Filter Bar -->
        <div class="filter-scroll">
            <a href="reports.php?period=weekly" class="filter-btn <?= $period === 'weekly' ? 'active' : '' ?>">Weekly</a>
            <a href="reports.php?period=monthly" class="filter-btn <?= $period === 'monthly' ? 'active' : '' ?>">Monthly</a>
            <a href="reports.php?period=6months" class="filter-btn <?= $period === '6months' ? 'active' : '' ?>">6 Months</a>
            <a href="reports.php?period=yearly" class="filter-btn <?= $period === 'yearly' ? 'active' : '' ?>">1 Year</a>
            <a href="reports.php?period=all" class="filter-btn <?= $period === 'all' ? 'active' : '' ?>">All Time</a>
        </div>

        <!-- AI Executive Audit Card -->
        <div class="ai-card">
            <div class="ai-header">
                <span>🤖 AI Audit (<?= htmlspecialchars($periodTitle) ?>)</span>
                <span style="font-size: 10px; background: rgba(255,255,255,0.1); padding: 2px 8px; border-radius: 10px;">Automated Risk Check</span>
            </div>
            <ul class="ai-bullet-list">
                <?php if (!empty($aiInsights)): ?>
                    <?php foreach ($aiInsights as $insight): ?>
                        <li><?= $insight ?></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li>🤖 <strong>Balanced Position:</strong> No critical risks or debt anomalies detected in this timeline.</li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Aging Engine: Lend -->
        <div class="aging-card">
            <h4>
                <span>📤 Receivable Aging (Money Lent)</span>
                <span style="font-size: 11px; color: #2563eb; font-weight: 700;">Rs. <?= number_format($lend, 2) ?></span>
            </h4>
            <div class="aging-grid">
                <div class="aging-box">
                    <div class="days-lbl">0-30 Days</div>
                    <div class="amt-val risk-fresh">Rs. <?= number_format($agingData['lend']['0_30'], 0) ?></div>
                </div>
                <div class="aging-box">
                    <div class="days-lbl">31-60 Days</div>
                    <div class="amt-val risk-mild">Rs. <?= number_format($agingData['lend']['31_60'], 0) ?></div>
                </div>
                <div class="aging-box">
                    <div class="days-lbl">61-90 Days</div>
                    <div class="amt-val risk-high">Rs. <?= number_format($agingData['lend']['61_90'], 0) ?></div>
                </div>
                <div class="aging-box <?= $agingData['lend']['90_plus'] > 0 ? 'risk-crit' : '' ?>">
                    <div class="days-lbl">90+ Days</div>
                    <div class="amt-val">Rs. <?= number_format($agingData['lend']['90_plus'], 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Aging Engine: Borrow -->
        <div class="aging-card">
            <h4>
                <span>📥 Debt Liability Aging (Money Borrowed)</span>
                <span style="font-size: 11px; color: #b45309; font-weight: 700;">Rs. <?= number_format($borrow, 2) ?></span>
            </h4>
            <div class="aging-grid">
                <div class="aging-box">
                    <div class="days-lbl">0-30 Days</div>
                    <div class="amt-val risk-fresh">Rs. <?= number_format($agingData['borrow']['0_30'], 0) ?></div>
                </div>
                <div class="aging-box">
                    <div class="days-lbl">31-60 Days</div>
                    <div class="amt-val risk-mild">Rs. <?= number_format($agingData['borrow']['31_60'], 0) ?></div>
                </div>
                <div class="aging-box">
                    <div class="days-lbl">61-90 Days</div>
                    <div class="amt-val risk-high">Rs. <?= number_format($agingData['borrow']['61_90'], 0) ?></div>
                </div>
                <div class="aging-box <?= $agingData['borrow']['90_plus'] > 0 ? 'risk-crit' : '' ?>">
                    <div class="days-lbl">90+ Days</div>
                    <div class="amt-val">Rs. <?= number_format($agingData['borrow']['90_plus'], 0) ?></div>
                </div>
            </div>
        </div>

        <!-- Health Grade Metric -->
        <div class="health-card">
            <div>
                <span style="font-size: 11px; color: #64748b; font-weight: 600; text-transform: uppercase;">Financial Health Score</span>
                <p style="font-size: 11px; color: #475569; margin-top: 2px;">Calculated on debt ratios and burn rate</p>
            </div>
            <div style="text-align: right;">
                <div class="health-score-ring"><?= $healthScore ?>/100</div>
                <span style="font-size: 11px; font-weight: 700; color: <?= $gradeColor ?>;"><?= $grade ?></span>
            </div>
        </div>

        <!-- Metric Overview -->
        <div class="stats-grid">
            <div class="stat-box">
                <span>Period Expenses</span>
                <h3 style="color: #dc2626;">Rs. <?= number_format($expense, 2); ?></h3>
                <?php if ($expenseTrendDiff > 0): ?>
                    <small style="color: #dc2626; font-size: 10px; font-weight: 600;">▲ +Rs. <?= number_format($expenseTrendDiff, 0) ?> vs prior mo.</small>
                <?php else: ?>
                    <small style="color: #16a34a; font-size: 10px; font-weight: 600;">▼ Savings trend active</small>
                <?php endif; ?>
            </div>
            <div class="stat-box">
                <span>Total Capital Volume</span>
                <h3>Rs. <?= number_format($grandTotalVolume, 2); ?></h3>
                <small style="color: #64748b; font-size: 10px;"><?= $totalEntries ?> logs in filter</small>
            </div>
        </div>

        <!-- Recommendations Card -->
        <?php if (!empty($recommendations)): ?>
        <div class="rec-card">
            <h4>💡 AI Action Directives</h4>
            <?php foreach ($recommendations as $rec): ?>
                <div class="rec-item">
                    <span>🎯</span>
                    <span><?= $rec ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Breakdown Table -->
        <div class="report-card">
            <h4>
                <span>📊 Distribution Breakdown</span>
                <span style="font-size: 11px; color: #64748b;"><?= htmlspecialchars($periodTitle) ?></span>
            </h4>
            
            <table class="report-table">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th style="text-align: center;">Logs</th>
                        <th style="text-align: right;">Amount</th>
                        <th style="text-align: right;">Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($categories)): ?>
                        <?php foreach ($categories as $type => $data): ?>
                            <?php 
                                $badgeCls  = $topicConfig[$type]['badge'] ?? 'badge-income';
                                $labelName = $topicConfig[$type]['label'] ?? ucfirst($type);
                                $share     = $grandTotalVolume > 0 ? ($data['amount'] / $grandTotalVolume) * 100 : 0;
                            ?>
                            <tr>
                                <td>
                                    <span class="badge <?= $badgeCls; ?>"><?= htmlspecialchars($labelName); ?></span>
                                    <div class="bar-bg"><div class="bar-fill" style="width: <?= min(100, $share) ?>%;"></div></div>
                                </td>
                                <td style="text-align: center; color: #64748b; font-weight: 600;">
                                    <?= $data['count']; ?>
                                </td>
                                <td style="text-align: right; font-weight: 700; color: #0f172a;">
                                    Rs. <?= number_format($data['amount'], 2); ?>
                                </td>
                                <td style="text-align: right; color: #2563eb; font-weight: 600; font-size: 11px;">
                                    <?= number_format($share, 1); ?>%
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" style="text-align: center; color: #94a3b8; font-size: 12px; padding: 16px 0;">
                                No transaction records for <?= htmlspecialchars($periodTitle) ?>.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

    </div>

    <nav class="bottom-nav">
        <a href="dashboard.php" class="nav-item">
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
        <a href="reports.php" class="nav-item active">
            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2v-10h2v10zm4 0h-2v-4h2v4z"/></svg>
            <span>Reports</span>
        </a>
    </nav>
</body>
</html>