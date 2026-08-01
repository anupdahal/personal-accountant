<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';
require_once 'partials/app_layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id      = $_SESSION['user_id'];
$userName     = $_SESSION['name'] ?? 'User';
$profilePhoto = $_SESSION['profile_photo'] ?? 'default.png';
$joinedBs     = $_SESSION['joined_date_bs'] ?? '2083-04-16';
$today_bs     = NepaliDateConverter::todayBs();
$topicConfig  = topicConfig();

// ----------------------------------------------------
// Schema safety
// ----------------------------------------------------
$hasPartyCol = columnExists($conn, 'transactions', 'party_name');
$partySelect = $hasPartyCol ? "party_name" : "'' AS party_name";

// ----------------------------------------------------
// 1. TIME PERIOD FILTER
// ----------------------------------------------------
$period = $_GET['period'] ?? 'monthly';
$validPeriods = ['weekly', 'monthly', '6months', 'yearly', 'all'];
if (!in_array($period, $validPeriods)) $period = 'monthly';

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

// ----------------------------------------------------
// 2. CATEGORY TOTALS FOR SELECTED PERIOD
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
$stmt->close();

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
// 3. AGING ENGINE: Lend & Borrow Liability Audit
// ----------------------------------------------------
$agingQuery = "SELECT
    id, transaction_type, {$partySelect} AS party_name, amount, date_ad,
    DATEDIFF(CURDATE(), date_ad) as days_passed
    FROM transactions
    WHERE user_id = ? AND transaction_type IN ('lend', 'borrow')
    ORDER BY date_ad ASC";

$aStmt = $conn->prepare($agingQuery);
$aStmt->bind_param("i", $user_id);
$aStmt->execute();
$aResult = $aStmt->get_result();

$agingData = [
    'lend'   => ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0, 'items' => []],
    'borrow' => ['0_30' => 0, '31_60' => 0, '61_90' => 0, '90_plus' => 0, 'items' => []]
];

$oldestLendDays   = 0;
$oldestLendParty  = '';
$oldestBorrowDays  = 0;
$oldestBorrowParty = '';
$criticalItems     = [];

while ($aRow = $aResult->fetch_assoc()) {
    $type  = $aRow['transaction_type'];
    $amt   = (float)$aRow['amount'];
    $days  = (int)$aRow['days_passed'];
    $party = $aRow['party_name'] ?: 'Unspecified';

    $bucket = '0_30';
    if ($days <= 30) $bucket = '0_30';
    elseif ($days <= 60) $bucket = '31_60';
    elseif ($days <= 90) $bucket = '61_90';
    else $bucket = '90_plus';

    $agingData[$type][$bucket] += $amt;
    $agingData[$type]['items'][] = [
        'party' => $party, 'amount' => $amt, 'days' => $days, 'bucket' => $bucket
    ];

    // Track critical 90+ items
    if ($days > 90) {
        $criticalItems[] = [
            'type' => $type, 'party' => $party, 'amount' => $amt, 'days' => $days
        ];
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
$aStmt->close();

// Calculate default risk percentages
$totalLendAmt   = $agingData['lend']['0_30'] + $agingData['lend']['31_60'] + $agingData['lend']['61_90'] + $agingData['lend']['90_plus'];
$totalBorrowAmt = $agingData['borrow']['0_30'] + $agingData['borrow']['31_60'] + $agingData['borrow']['61_90'] + $agingData['borrow']['90_plus'];

$lendRiskPct   = $totalLendAmt > 0 ? ($agingData['lend']['90_plus'] / $totalLendAmt) * 100 : 0;
$borrowRiskPct = $totalBorrowAmt > 0 ? ($agingData['borrow']['90_plus'] / $totalBorrowAmt) * 100 : 0;

// ----------------------------------------------------
// 4. COMPARATIVE TREND (this month vs last month)
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
$mStmt->close();

$thisMonthExpense = (float)($monthlyData[$currentMonthStr]['monthly_expense'] ?? 0);
$lastMonthExpense = (float)($monthlyData[$lastMonthStr]['monthly_expense']    ?? 0);
$expenseTrendDiff = $thisMonthExpense - $lastMonthExpense;
$expenseTrendPct  = $lastMonthExpense > 0 ? (($expenseTrendDiff / $lastMonthExpense) * 100) : 0;

// ----------------------------------------------------
// 5. 6-MONTH TREND FOR CHART
// ----------------------------------------------------
$trend6Query = "SELECT
    DATE_FORMAT(date_ad, '%Y-%m') as ym,
    SUM(CASE WHEN transaction_type IN ('income','starting_balance','borrow') THEN amount ELSE 0 END) as inflow,
    SUM(CASE WHEN transaction_type IN ('expense','lend','investment','loss') THEN amount ELSE 0 END) as outflow
    FROM transactions
    WHERE user_id = ? AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym ASC";
$tStmt = $conn->prepare($trend6Query);
$tStmt->bind_param("i", $user_id);
$tStmt->execute();
$tResult = $tStmt->get_result();

$trend6Labels = [];
$trend6Inflow = [];
$trend6Outflow = [];
$trend6Map = [];
while ($t = $tResult->fetch_assoc()) {
    $trend6Map[$t['ym']] = $t;
}
$tStmt->close();

// Fill 6 months
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-{$i} months"));
    $trend6Labels[] = date('M Y', strtotime($m . '-01'));
    $trend6Inflow[] = (float)($trend6Map[$m]['inflow'] ?? 0);
    $trend6Outflow[] = (float)($trend6Map[$m]['outflow'] ?? 0);
}

// ----------------------------------------------------
// 6. AI FINANCIAL AUDIT ENGINE
// ----------------------------------------------------
$aiInsights = [];
$recommendations = [];

// Audit A: Debt & Receivable Aging Risks
if ($oldestLendDays > 90) {
    $aiInsights[] = "⚠️ <strong>High Receivable Risk:</strong> Outstanding lend capital pending for <strong>{$oldestLendDays} days</strong>" . ($oldestLendParty !== 'Unspecified' ? " (Party: <em>{$oldestLendParty}</em>)" : "") . ".";
    $recommendations[] = "Follow up immediately to recover lent funds older than 90 days. Default risk: " . number_format($lendRiskPct, 1) . "%.";
} elseif ($oldestLendDays > 30) {
    $aiInsights[] = "⌛ <strong>Receivable Aging:</strong> Oldest uncollected loan pending for <strong>{$oldestLendDays} days</strong>.";
}

if ($oldestBorrowDays > 60) {
    $aiInsights[] = "🚨 <strong>Debt Maturity Alert:</strong> Borrowed liability unpaid for <strong>{$oldestBorrowDays} days</strong>" . ($oldestBorrowParty !== 'Unspecified' ? " (Creditor: <em>{$oldestBorrowParty}</em>)" : "") . ".";
    $recommendations[] = "Prioritize clearing debts older than 60 days to prevent compounding obligations.";
}

// Critical item details
if (!empty($criticalItems)) {
    $criticalLend = array_filter($criticalItems, fn($i) => $i['type'] === 'lend');
    $criticalBorrow = array_filter($criticalItems, fn($i) => $i['type'] === 'borrow');
    if (!empty($criticalLend)) {
        $aiInsights[] = "🔴 <strong>Critical Receivables:</strong> " . count($criticalLend) . " lend item(s) overdue 90+ days totaling Rs. " . number_format(array_sum(array_column($criticalLend, 'amount')), 2) . ".";
    }
    if (!empty($criticalBorrow)) {
        $aiInsights[] = "🔴 <strong>Critical Liabilities:</strong> " . count($criticalBorrow) . " borrow item(s) overdue 90+ days totaling Rs. " . number_format(array_sum(array_column($criticalBorrow, 'amount')), 2) . ".";
    }
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
        $recommendations[] = "Consider scaling investment allocation toward 20% of net income. Currently at " . number_format($investRatio, 1) . "%.";
    } else {
        $recommendations[] = "No investment logs found for this period. Try automating regular SIP installments.";
    }
}

// Audit C: Capital Loss Monitoring
if ($loss > 0) {
    $lossRatio = $totalEarnedCapital > 0 ? ($loss / $totalEarnedCapital) * 100 : 0;
    $aiInsights[] = "📉 <strong>Capital Loss Audit:</strong> Recorded <strong>Rs. " . number_format($loss, 2) . "</strong> in trading/operational losses (" . number_format($lossRatio, 1) . "% of capital).";
    if ($lossRatio > 10) {
        $recommendations[] = "High loss exposure detected. Consider pausing high-risk trading activity and reviewing strategy.";
    }
}

// Audit D: Liquidity Alert
if ($netLiquidity < 0) {
    $aiInsights[] = "🆘 <strong>Negative Liquidity:</strong> Net cash position is negative (Rs. " . number_format($netLiquidity, 2) . "). Immediate capital injection or expense reduction needed.";
    $recommendations[] = "Your outflows exceed inflows. Prioritize essential expenses and halt discretionary spending.";
}

// ----------------------------------------------------
// 7. FINANCIAL HEALTH INDEX (0-100 Score)
// ----------------------------------------------------
$healthScore = 70; // baseline
$healthBreakdown = [];

// Factor 1: Burn Rate (max 25 points)
if ($totalEarnedCapital > 0) {
    $burnRatePct = ($expense / $totalEarnedCapital) * 100;
    if ($burnRatePct <= 30) { $healthScore += 15; $healthBreakdown[] = ['Burn Rate', '+15', 'var(--c-income)']; }
    elseif ($burnRatePct <= 50) { $healthScore += 8; $healthBreakdown[] = ['Burn Rate', '+8', 'var(--c-income)']; }
    elseif ($burnRatePct <= 75) { $healthScore += 0; $healthBreakdown[] = ['Burn Rate', '0', 'var(--c-borrow)']; }
    else { $healthScore -= 15; $healthBreakdown[] = ['Burn Rate', '-15', 'var(--c-expense)']; }
} else {
    $healthBreakdown[] = ['Burn Rate', 'N/A', 'var(--c-text-3)'];
}

// Factor 2: Investment Deployment Ratio (max 20 points)
if ($totalEarnedCapital > 0) {
    $investRatioPct = ($invest / $totalEarnedCapital) * 100;
    if ($investRatioPct >= 20) { $healthScore += 20; $healthBreakdown[] = ['Investment Ratio', '+20', 'var(--c-income)']; }
    elseif ($investRatioPct >= 10) { $healthScore += 12; $healthBreakdown[] = ['Investment Ratio', '+12', 'var(--c-income)']; }
    elseif ($investRatioPct > 0) { $healthScore += 5; $healthBreakdown[] = ['Investment Ratio', '+5', 'var(--c-borrow)']; }
    else { $healthScore -= 5; $healthBreakdown[] = ['Investment Ratio', '-5', 'var(--c-expense)']; }
} else {
    $healthBreakdown[] = ['Investment Ratio', 'N/A', 'var(--c-text-3)'];
}

// Factor 3: Overdue Debt Exposure (max 20 penalty)
$overdueExposure = $agingData['lend']['90_plus'] + $agingData['borrow']['90_plus'];
if ($overdueExposure > 0) {
    $healthScore -= 20;
    $healthBreakdown[] = ['Overdue Debt (90+)', '-20', 'var(--c-expense)'];
} else {
    $healthScore += 10;
    $healthBreakdown[] = ['Overdue Debt (90+)', '+10', 'var(--c-income)'];
}

// Factor 4: Capital Loss Monitoring (max 15 penalty)
if ($loss > 0) {
    $lossPctOfCapital = $totalEarnedCapital > 0 ? ($loss / $totalEarnedCapital) * 100 : 100;
    if ($lossPctOfCapital > 10) { $healthScore -= 15; $healthBreakdown[] = ['Capital Loss', '-15', 'var(--c-expense)']; }
    else { $healthScore -= 8; $healthBreakdown[] = ['Capital Loss', '-8', 'var(--c-borrow)']; }
} else {
    $healthScore += 5;
    $healthBreakdown[] = ['Capital Loss', '+5', 'var(--c-income)'];
}

// Factor 5: Liquidity Position
if ($netLiquidity > 0) { $healthScore += 5; $healthBreakdown[] = ['Net Liquidity', '+5', 'var(--c-income)']; }
else { $healthScore -= 10; $healthBreakdown[] = ['Net Liquidity', '-10', 'var(--c-expense)']; }

$healthScore = max(0, min(100, $healthScore));

if ($healthScore >= 85) { $grade = 'A+'; $gradeColor = '#16a34a'; $gradeLabel = 'Excellent'; }
elseif ($healthScore >= 70) { $grade = 'B'; $gradeColor = '#2563eb'; $gradeLabel = 'Good'; }
elseif ($healthScore >= 50) { $grade = 'C'; $gradeColor = '#d97706'; $gradeLabel = 'Fair'; }
else { $grade = 'D'; $gradeColor = '#dc2626'; $gradeLabel = 'At Risk'; }

// Chart data preparation
$chartLabels = [];
$chartData   = [];
$chartColors = [];
foreach ($categories as $type => $data) {
    $cfg = $topicConfig[$type] ?? null;
    if (!$cfg) continue;
    $chartLabels[] = $cfg['label'];
    $chartData[]   = $data['amount'];
    $chartColors[] = $cfg['color'];
}

$trend6LabelsJson = json_encode($trend6Labels);
$trend6InflowJson = json_encode($trend6Inflow);
$trend6OutflowJson = json_encode($trend6Outflow);
$chartLabelsJson  = json_encode($chartLabels);
$chartDataJson    = json_encode($chartData);
$chartColorsJson  = json_encode($chartColors);
$inflowOutflowJson = json_encode([$totalInflow, $totalOutflow]);

renderAppHead('Multi-Period Financial Intelligence');
renderAppHeader($userName, $profilePhoto, $joinedBs, $today_bs);
?>

<div class="container">

    <!-- ===== Dynamic Time Filter Bar ===== -->
    <div class="filter-scroll">
        <a href="reports.php?period=weekly" class="filter-btn <?= $period === 'weekly' ? 'active' : '' ?>">Weekly</a>
        <a href="reports.php?period=monthly" class="filter-btn <?= $period === 'monthly' ? 'active' : '' ?>">Monthly</a>
        <a href="reports.php?period=6months" class="filter-btn <?= $period === '6months' ? 'active' : '' ?>">6 Months</a>
        <a href="reports.php?period=yearly" class="filter-btn <?= $period === 'yearly' ? 'active' : '' ?>">1 Year</a>
        <a href="reports.php?period=all" class="filter-btn <?= $period === 'all' ? 'active' : '' ?>">All Time</a>
    </div>

    <!-- ===== AI Executive Audit Card ===== -->
    <div class="ai-card fade-in">
        <div class="ai-header">
            <span class="ai-title">🤖 AI Audit Engine (<?= htmlspecialchars($periodTitle) ?>)</span>
            <span class="ai-tag">Automated Risk Check</span>
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

    <!-- ===== Inflow vs Outflow Chart ===== -->
    <div class="card">
        <h4>
            <span>📊 Inflow vs Outflow</span>
            <span style="font-size: 11px; color: var(--c-text-3);"><?= htmlspecialchars($periodTitle) ?></span>
        </h4>
        <div style="height: 200px; position: relative;">
            <canvas id="inflowOutflowChart"></canvas>
        </div>
        <div style="display: flex; justify-content: space-between; margin-top: 12px; text-align: center;">
            <div style="flex:1;">
                <div style="font-size: 11px; color: var(--c-text-3);">Total Inflow</div>
                <div style="font-size: 16px; font-weight: 800; color: var(--c-income);">+ Rs. <?= number_format($totalInflow, 0) ?></div>
            </div>
            <div style="flex:1;">
                <div style="font-size: 11px; color: var(--c-text-3);">Total Outflow</div>
                <div style="font-size: 16px; font-weight: 800; color: var(--c-expense);">- Rs. <?= number_format($totalOutflow, 0) ?></div>
            </div>
            <div style="flex:1;">
                <div style="font-size: 11px; color: var(--c-text-3);">Net Position</div>
                <div style="font-size: 16px; font-weight: 800; color: <?= $netLiquidity >= 0 ? 'var(--c-income)' : 'var(--c-expense)' ?>;">
                    Rs. <?= number_format($netLiquidity, 0) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== 6-Month Trend Chart ===== -->
    <div class="card">
        <h4>
            <span>📈 6-Month Cash Flow Trend</span>
        </h4>
        <div style="height: 200px; position: relative;">
            <canvas id="trend6Chart"></canvas>
        </div>
    </div>

    <!-- ===== Receivable Aging (Lend) ===== -->
    <div class="card">
        <h4>
            <span>📤 Receivable Aging (Money Lent)</span>
            <span style="font-size: 11px; color: var(--c-primary); font-weight: 700;">Rs. <?= number_format($lend, 2) ?></span>
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
                <?php if ($lendRiskPct > 0): ?>
                    <div class="risk-pct"><?= number_format($lendRiskPct, 0) ?>% risk</div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($agingData['lend']['items'])): ?>
            <details style="margin-top: 10px;">
                <summary style="font-size: 11px; color: var(--c-primary); cursor: pointer; font-weight: 600;">View <?= count($agingData['lend']['items']) ?> item(s)</summary>
                <div style="margin-top: 8px;">
                    <?php foreach ($agingData['lend']['items'] as $item): ?>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; padding: 4px 0; border-bottom: 1px solid var(--c-border-light);">
                            <span><?= htmlspecialchars($item['party']) ?> (<?= $item['days'] ?>d)</span>
                            <span style="font-weight: 700;">Rs. <?= number_format($item['amount'], 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <!-- ===== Debt Liability Aging (Borrow) ===== -->
    <div class="card">
        <h4>
            <span>📥 Debt Liability Aging (Borrowed)</span>
            <span style="font-size: 11px; color: var(--c-borrow); font-weight: 700;">Rs. <?= number_format($borrow, 2) ?></span>
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
                <?php if ($borrowRiskPct > 0): ?>
                    <div class="risk-pct"><?= number_format($borrowRiskPct, 0) ?>% risk</div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!empty($agingData['borrow']['items'])): ?>
            <details style="margin-top: 10px;">
                <summary style="font-size: 11px; color: var(--c-borrow); cursor: pointer; font-weight: 600;">View <?= count($agingData['borrow']['items']) ?> item(s)</summary>
                <div style="margin-top: 8px;">
                    <?php foreach ($agingData['borrow']['items'] as $item): ?>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; padding: 4px 0; border-bottom: 1px solid var(--c-border-light);">
                            <span><?= htmlspecialchars($item['party']) ?> (<?= $item['days'] ?>d)</span>
                            <span style="font-weight: 700;">Rs. <?= number_format($item['amount'], 0) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </details>
        <?php endif; ?>
    </div>

    <!-- ===== Financial Health Index ===== -->
    <div class="card health-card">
        <div>
            <span style="font-size: 11px; color: var(--c-text-3); font-weight: 600; text-transform: uppercase;">Financial Health Index</span>
            <p style="font-size: 11px; color: var(--c-text-2); margin-top: 2px;">Weighted score: burn rate, investments, debt, losses</p>
            <!-- Health Breakdown -->
            <div style="margin-top: 10px; display: flex; flex-direction: column; gap: 4px;">
                <?php foreach ($healthBreakdown as $hb): ?>
                    <div style="display: flex; justify-content: space-between; font-size: 10px;">
                        <span style="color: var(--c-text-3);"><?= htmlspecialchars($hb[0]) ?></span>
                        <span style="font-weight: 700; color: <?= $hb[2] ?>;"><?= $hb[1] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <div style="text-align: right;">
            <div class="health-ring"
                 style="background: conic-gradient(<?= htmlspecialchars($gradeColor) ?> <?= $healthScore * 3.6 ?>deg, var(--c-border-light) <?= $healthScore * 3.6 ?>deg);">
                <div style="width: 50px; height: 50px; border-radius: 50%; background: var(--c-surface); display: flex; align-items: center; justify-content: center;">
                    <span class="health-score-num" style="color: <?= htmlspecialchars($gradeColor) ?>; font-size: 16px;"><?= $healthScore ?></span>
                </div>
            </div>
            <div class="health-grade" style="color: <?= htmlspecialchars($gradeColor) ?>;">Grade: <?= $grade ?> (<?= $gradeLabel ?>)</div>
        </div>
    </div>

    <!-- ===== KPI Stats Grid ===== -->
    <div class="kpi-grid">
        <div class="kpi-box">
            <span class="kpi-label">Period Expenses</span>
            <div class="kpi-value kpi-down">Rs. <?= number_format($expense, 2) ?></div>
            <?php if ($expenseTrendDiff > 0): ?>
                <div class="kpi-sub kpi-down">▲ +Rs. <?= number_format($expenseTrendDiff, 0) ?> (<?= number_format(abs($expenseTrendPct), 0) ?>%)</div>
            <?php elseif ($expenseTrendDiff < 0): ?>
                <div class="kpi-sub kpi-up">▼ -Rs. <?= number_format(abs($expenseTrendDiff), 0) ?> (improved)</div>
            <?php else: ?>
                <div class="kpi-sub" style="color: var(--c-text-3);">No change vs last month</div>
            <?php endif; ?>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Total Capital Volume</span>
            <div class="kpi-value kpi-info">Rs. <?= number_format($grandTotalVolume, 2) ?></div>
            <div class="kpi-sub" style="color: var(--c-text-3);"><?= $totalEntries ?> logs in filter</div>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Investment Deployed</span>
            <div class="kpi-value kpi-invest">Rs. <?= number_format($invest, 2) ?></div>
            <div class="kpi-sub kpi-invest"><?= $totalEarnedCapital > 0 ? number_format(($invest / $totalEarnedCapital) * 100, 1) . '% of capital' : 'N/A' ?></div>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Capital Loss</span>
            <div class="kpi-value" style="color: var(--c-loss);">Rs. <?= number_format($loss, 2) ?></div>
            <div class="kpi-sub" style="color: var(--c-loss);"><?= $loss > 0 ? 'Loss detected' : 'No losses' ?></div>
        </div>
    </div>

    <!-- ===== AI Action Directives ===== -->
    <?php if (!empty($recommendations)): ?>
    <div class="card">
        <h4>💡 AI Action Directives</h4>
        <?php foreach ($recommendations as $rec): ?>
            <div class="rec-item">
                <span>🎯</span>
                <span><?= $rec ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ===== Category Distribution Doughnut ===== -->
    <?php if (!empty($chartData)): ?>
    <div class="card">
        <h4>
            <span>🥧 Distribution Breakdown</span>
            <span style="font-size: 11px; color: var(--c-text-3);"><?= htmlspecialchars($periodTitle) ?></span>
        </h4>
        <div style="height: 200px; position: relative;">
            <canvas id="distChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== Detailed Breakdown Table ===== -->
    <div class="card">
        <h4>
            <span>📋 Category Distribution Table</span>
            <span style="font-size: 11px; color: var(--c-text-3);"><?= htmlspecialchars($periodTitle) ?></span>
        </h4>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="text-center">Logs</th>
                    <th class="text-right">Amount</th>
                    <th class="text-right">Share</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($categories)): ?>
                    <?php foreach ($categories as $type => $data):
                        $cfg = $topicConfig[$type] ?? null;
                        if (!$cfg) continue;
                        $share = $grandTotalVolume > 0 ? ($data['amount'] / $grandTotalVolume) * 100 : 0;
                    ?>
                        <tr>
                            <td>
                                <span class="badge <?= $cfg['badge'] ?>"><?= htmlspecialchars($cfg['label']) ?></span>
                                <div class="bar-bg"><div class="bar-fill bar-fill-blue" style="width: <?= min(100, $share) ?>%;"></div></div>
                            </td>
                            <td class="text-center" style="color: var(--c-text-3); font-weight: 600;"><?= $data['count'] ?></td>
                            <td class="text-right" style="font-weight: 700;">Rs. <?= number_format($data['amount'], 2) ?></td>
                            <td class="text-right" style="color: var(--c-primary); font-weight: 600; font-size: 11px;"><?= number_format($share, 1) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" class="empty-state">
                        <div class="empty-icon">📭</div>
                        No transaction records for <?= htmlspecialchars($periodTitle) ?>.
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
// Inflow vs Outflow Bar Chart
const ioCtx = document.getElementById('inflowOutflowChart');
if (ioCtx) {
    new Chart(ioCtx, {
        type: 'bar',
        data: {
            labels: ['Inflow', 'Outflow'],
            datasets: [{
                data: <?= $inflowOutflowJson ?>,
                backgroundColor: ['#16a34a', '#dc2626'],
                borderRadius: 8,
                barThickness: 60,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, callback: v => 'Rs.' + v } }
            }
        }
    });
}

// 6-Month Trend Chart
const t6Ctx = document.getElementById('trend6Chart');
if (t6Ctx) {
    new Chart(t6Ctx, {
        type: 'line',
        data: {
            labels: <?= $trend6LabelsJson ?>,
            datasets: [
                {
                    label: 'Inflow', data: <?= $trend6InflowJson ?>,
                    borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.1)',
                    fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3,
                },
                {
                    label: 'Outflow', data: <?= $trend6OutflowJson ?>,
                    borderColor: '#dc2626', backgroundColor: 'rgba(220,38,38,0.1)',
                    fill: true, tension: 0.4, borderWidth: 2, pointRadius: 3,
                }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: true, position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } } },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, callback: v => 'Rs.' + v } }
            }
        }
    });
}

// Distribution Doughnut
const distCtx = document.getElementById('distChart');
if (distCtx) {
    new Chart(distCtx, {
        type: 'doughnut',
        data: {
            labels: <?= $chartLabelsJson ?>,
            datasets: [{
                data: <?= $chartDataJson ?>,
                backgroundColor: <?= $chartColorsJson ?>,
                borderWidth: 2, borderColor: '#ffffff',
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12, padding: 8 } } },
            cutout: '65%',
        }
    });
}
</script>

<?php renderBottomNav('reports'); ?>
</body>
</html>
