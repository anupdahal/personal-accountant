<?php
session_start();
require_once 'db.php';
require_once 'partials/app_layout.php';

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
$topicConfig = topicConfig();

// ----------------------------------------------------
// 1. Fetch all-time summary totals
// ----------------------------------------------------
$summaryQuery = "SELECT
    SUM(CASE WHEN transaction_type = 'starting_balance' THEN amount ELSE 0 END) AS total_starting_balance,
    SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END) AS total_income,
    SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END) AS total_expense,
    SUM(CASE WHEN transaction_type = 'lend' THEN amount ELSE 0 END) AS total_lend,
    SUM(CASE WHEN transaction_type = 'borrow' THEN amount ELSE 0 END) AS total_borrow,
    SUM(CASE WHEN transaction_type = 'investment' THEN amount ELSE 0 END) AS total_investment,
    SUM(CASE WHEN transaction_type = 'loss' THEN amount ELSE 0 END) AS total_loss,
    COUNT(*) AS total_entries
    FROM transactions WHERE user_id = ?";

$sumStmt = $conn->prepare($summaryQuery);
$sumStmt->bind_param("i", $user_id);
$sumStmt->execute();
$totals = $sumStmt->get_result()->fetch_assoc();
$sumStmt->close();

$startingCapital = (float)($totals['total_starting_balance'] ?? 0);
$totalIncome     = (float)($totals['total_income'] ?? 0);
$totalExpense    = (float)($totals['total_expense'] ?? 0);
$totalLend       = (float)($totals['total_lend'] ?? 0);
$totalBorrow     = (float)($totals['total_borrow'] ?? 0);
$totalInvestment = (float)($totals['total_investment'] ?? 0);
$totalLoss       = (float)($totals['total_loss'] ?? 0);
$totalEntries    = (int)($totals['total_entries'] ?? 0);

// Net Liquidity = capital + income + borrow - expense - lend - investment - loss
$netLiquidity = ($startingCapital + $totalIncome + $totalBorrow) - ($totalExpense + $totalLend + $totalInvestment + $totalLoss);

// Total volume = all transaction amounts
$totalVolume = $startingCapital + $totalIncome + $totalExpense + $totalLend + $totalBorrow + $totalInvestment + $totalLoss;

// Inflow vs Outflow
$totalInflow  = $startingCapital + $totalIncome + $totalBorrow;
$totalOutflow = $totalExpense + $totalLend + $totalInvestment + $totalLoss;

// ----------------------------------------------------
// 2. Monthly Burn Rate (this month's expenses)
// ----------------------------------------------------
$currentMonthStart = date('Y-m-01');
$burnQuery = "SELECT
    COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) AS month_expense,
    COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) AS month_income
    FROM transactions WHERE user_id = ? AND date_ad >= ?";
$burnStmt = $conn->prepare($burnQuery);
$burnStmt->bind_param("is", $user_id, $currentMonthStart);
$burnStmt->execute();
$burnData = $burnStmt->get_result()->fetch_assoc();
$burnStmt->close();

$monthExpense = (float)($burnData['month_expense'] ?? 0);
$monthIncome  = (float)($burnData['month_income'] ?? 0);

// Burn Rate % = month expense / (starting + income) * 100
$availableCapital = $startingCapital + $totalIncome;
$burnRatePct = $availableCapital > 0 ? ($monthExpense / $availableCapital) * 100 : 0;
$burnRatePct = min($burnRatePct, 999);

// ----------------------------------------------------
// 3. Category breakdown for table + chart
// ----------------------------------------------------
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

$categoryBreakdown = [];
while ($row = $topicResults->fetch_assoc()) {
    $categoryBreakdown[$row['transaction_type']] = [
        'count'  => (int)$row['total_entries'],
        'amount' => (float)$row['total_amount']
    ];
}
$topicStmt->close();

// ----------------------------------------------------
// 4. Recent Ledger Stream (latest 8 transactions)
// ----------------------------------------------------
$notesCol = notesColumn($conn);
$partyCol = columnExists($conn, 'transactions', 'party_name') ? 'party_name' : "'' AS party_name";
$notesSelect = $notesCol !== '' ? ", `{$notesCol}` AS notes" : ", '' AS notes";

$recentQuery = "SELECT id, transaction_type, subject, amount, party_name, date_ad, date_bs, created_at{$notesSelect}
    FROM transactions WHERE user_id = ?
    ORDER BY id DESC LIMIT 8";
$recentStmt = $conn->prepare($recentQuery);
$recentStmt->bind_param("i", $user_id);
$recentStmt->execute();
$recentResult = $recentStmt->get_result();
$recentStmt->close();

// ----------------------------------------------------
// 5. 7-day trend data for mini sparkline chart
// ----------------------------------------------------
$trendQuery = "SELECT
    date_ad,
    SUM(CASE WHEN transaction_type IN ('income','starting_balance','borrow') THEN amount ELSE 0 END) AS inflow,
    SUM(CASE WHEN transaction_type IN ('expense','lend','investment','loss') THEN amount ELSE 0 END) AS outflow
    FROM transactions
    WHERE user_id = ? AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
    GROUP BY date_ad ORDER BY date_ad ASC";
$trendStmt = $conn->prepare($trendQuery);
$trendStmt->bind_param("i", $user_id);
$trendStmt->execute();
$trendResult = $trendStmt->get_result();

$trendLabels = [];
$trendInflow = [];
$trendOutflow = [];
$trendMap = [];
while ($t = $trendResult->fetch_assoc()) {
    $trendMap[$t['date_ad']] = $t;
}
$trendStmt->close();

// Fill 7 days
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $trendLabels[] = date('D', strtotime($d));
    $trendInflow[] = (float)($trendMap[$d]['inflow'] ?? 0);
    $trendOutflow[] = (float)($trendMap[$d]['outflow'] ?? 0);
}

$trendLabelsJson = json_encode($trendLabels);
$trendInflowJson = json_encode($trendInflow);
$trendOutflowJson = json_encode($trendOutflow);

// Chart data for category breakdown (doughnut)
$chartLabels = [];
$chartData   = [];
$chartColors = [];
foreach ($categoryBreakdown as $type => $data) {
    $cfg = $topicConfig[$type] ?? null;
    if (!$cfg) continue;
    $chartLabels[] = $cfg['label'];
    $chartData[]   = $data['amount'];
    $chartColors[] = $cfg['color'];
}
$chartLabelsJson = json_encode($chartLabels);
$chartDataJson   = json_encode($chartData);
$chartColorsJson = json_encode($chartColors);

// Burn rate status
$burnStatus = 'Good';
$burnColor  = 'var(--c-income)';
if ($burnRatePct > 80) { $burnStatus = 'Critical'; $burnColor = 'var(--c-expense)'; }
elseif ($burnRatePct > 50) { $burnStatus = 'Moderate'; $burnColor = 'var(--c-borrow)'; }

renderAppHead('Executive Summary');
renderAppHeader($userName, $profilePhoto, $joinedBs, $today_bs);
?>

<div class="container">

    <!-- ===== Net Liquidity Hero Card ===== -->
    <div class="hero-card fade-in">
        <span class="label">Net Liquidity &mdash; Available Cash</span>
        <h1>Rs. <?= number_format($netLiquidity, 2) ?></h1>
        <div class="card-row">
            <div>
                <p>Starting Capital</p>
                <strong style="color: #38bdf8;">Rs. <?= number_format($startingCapital, 2) ?></strong>
            </div>
            <div style="text-align: right;">
                <p>Total Earned Income</p>
                <strong style="color: #4ade80;">+ Rs. <?= number_format($totalIncome, 2) ?></strong>
            </div>
        </div>
    </div>

    <!-- ===== KPI Grid ===== -->
    <div class="kpi-grid">
        <div class="kpi-box">
            <span class="kpi-label">Monthly Burn Rate</span>
            <div class="kpi-value" style="color: <?= htmlspecialchars($burnColor) ?>;"><?= number_format($burnRatePct, 1) ?>%</div>
            <div class="kpi-sub" style="color: <?= htmlspecialchars($burnColor) ?>;"><?= $burnStatus ?> &middot; Rs. <?= number_format($monthExpense, 0) ?></div>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Total Volume</span>
            <div class="kpi-value kpi-info">Rs. <?= number_format($totalVolume, 0) ?></div>
            <div class="kpi-sub" style="color: var(--c-text-3);"><?= $totalEntries ?> entries</div>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Total Inflow</span>
            <div class="kpi-value kpi-up">+ Rs. <?= number_format($totalInflow, 0) ?></div>
            <div class="kpi-sub kpi-up">Capital + Income + Borrow</div>
        </div>
        <div class="kpi-box">
            <span class="kpi-label">Total Outflow</span>
            <div class="kpi-value kpi-down">- Rs. <?= number_format($totalOutflow, 0) ?></div>
            <div class="kpi-sub kpi-down">Expense + Lend + Invest</div>
        </div>
    </div>

    <!-- ===== Quick-Action Floating Bar ===== -->
    <div class="quick-actions">
        <a href="add_entry.php?type=income" class="quick-btn qb-income">
            <div class="qb-icon">💰</div>
            <span class="qb-label">Add Income</span>
        </a>
        <a href="add_entry.php?type=expense" class="quick-btn qb-expense">
            <div class="qb-icon">🛒</div>
            <span class="qb-label">Add Expense</span>
        </a>
        <a href="add_entry.php?type=lend" class="quick-btn qb-lend">
            <div class="qb-icon">📤</div>
            <span class="qb-label">Lend Money</span>
        </a>
        <a href="add_entry.php?type=borrow" class="quick-btn qb-borrow">
            <div class="qb-icon">📥</div>
            <span class="qb-label">Borrow</span>
        </a>
    </div>

    <!-- ===== 7-Day Cash Flow Trend Chart ===== -->
    <div class="card">
        <h4>
            <span>📊 7-Day Cash Flow</span>
            <span style="font-size: 11px; color: var(--c-text-3);">Inflow vs Outflow</span>
        </h4>
        <div style="height: 180px; position: relative;">
            <canvas id="trendChart"></canvas>
        </div>
    </div>

    <!-- ===== Category Summary Grid (2x3) ===== -->
    <div class="kpi-grid">
        <div class="kpi-box" style="border-left: 4px solid var(--c-start);">
            <span class="kpi-label">Opening Capital</span>
            <div class="kpi-value" style="color: var(--c-start);">Rs. <?= number_format($startingCapital, 2) ?></div>
        </div>
        <div class="kpi-box" style="border-left: 4px solid var(--c-income);">
            <span class="kpi-label">Income / Profit</span>
            <div class="kpi-value kpi-up">Rs. <?= number_format($totalIncome, 2) ?></div>
        </div>
        <div class="kpi-box" style="border-left: 4px solid var(--c-expense);">
            <span class="kpi-label">Expenses</span>
            <div class="kpi-value kpi-down">Rs. <?= number_format($totalExpense, 2) ?></div>
        </div>
        <div class="kpi-box" style="border-left: 4px solid var(--c-lend);">
            <span class="kpi-label">Lend (Given Out)</span>
            <div class="kpi-value kpi-info">Rs. <?= number_format($totalLend, 2) ?></div>
        </div>
        <div class="kpi-box" style="border-left: 4px solid var(--c-borrow);">
            <span class="kpi-label">Borrow (Owed)</span>
            <div class="kpi-value kpi-warn">Rs. <?= number_format($totalBorrow, 2) ?></div>
        </div>
        <div class="kpi-box" style="border-left: 4px solid var(--c-invest);">
            <span class="kpi-label">Investment</span>
            <div class="kpi-value kpi-invest">Rs. <?= number_format($totalInvestment, 2) ?></div>
        </div>
    </div>

    <!-- ===== Category Distribution Doughnut ===== -->
    <?php if (!empty($chartData)): ?>
    <div class="card">
        <h4>
            <span>🥧 Capital Distribution</span>
        </h4>
        <div style="height: 200px; position: relative;">
            <canvas id="distChart"></canvas>
        </div>
    </div>
    <?php endif; ?>

    <!-- ===== Category Breakdown Table ===== -->
    <div class="card">
        <h4>
            <span>📋 Category Summary</span>
            <a href="ledger.php" class="card-link">Detailed Ledger &rarr;</a>
        </h4>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th class="text-center">Entries</th>
                    <th class="text-right">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($categoryBreakdown)): ?>
                    <?php foreach ($categoryBreakdown as $type => $data):
                        $cfg = $topicConfig[$type] ?? null;
                        if (!$cfg) continue;
                        $share = $totalVolume > 0 ? ($data['amount'] / $totalVolume) * 100 : 0;
                    ?>
                        <tr>
                            <td>
                                <span class="badge <?= $cfg['badge'] ?>"><?= htmlspecialchars($cfg['label']) ?></span>
                                <div class="bar-bg"><div class="bar-fill bar-fill-blue" style="width: <?= min(100, $share) ?>%;"></div></div>
                            </td>
                            <td class="text-center" style="color: var(--c-text-3); font-weight: 600;"><?= $data['count'] ?></td>
                            <td class="text-right" style="font-weight: 700;">Rs. <?= number_format($data['amount'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="3" class="empty-state">
                        <div class="empty-icon">📝</div>
                        No transactions logged yet. Tap <strong>+</strong> to start recording!
                    </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- ===== Recent Ledger Stream ===== -->
    <div class="card">
        <h4>
            <span>🕒 Recent Activity</span>
            <a href="ledger.php" class="card-link">View All &rarr;</a>
        </h4>
        <?php if ($recentResult->num_rows > 0): ?>
            <div class="txn-stream">
                <?php while ($row = $recentResult->fetch_assoc()):
                    $cfg = $topicConfig[$row['transaction_type']] ?? ['icon' => '💳', 'badge' => 'badge-income', 'color' => '#64748b'];
                    $isInflow = in_array($row['transaction_type'], ['income', 'starting_balance', 'borrow']);
                    $sign = $isInflow ? '+' : '-';
                    $amtColor = $isInflow ? 'var(--c-income)' : 'var(--c-expense)';
                    if ($row['transaction_type'] === 'lend') $amtColor = 'var(--c-lend)';
                    if ($row['transaction_type'] === 'investment') $amtColor = 'var(--c-invest)';
                    if ($row['transaction_type'] === 'loss') $amtColor = 'var(--c-loss)';
                    $partyText = !empty($row['party_name']) ? ' &middot; ' . htmlspecialchars($row['party_name']) : '';
                ?>
                    <div class="txn-item">
                        <div class="txn-icon" style="background: <?= $cfg['color'] ?>1a; color: <?= $cfg['color'] ?>;">
                            <?= $cfg['icon'] ?>
                        </div>
                        <div class="txn-body">
                            <strong><?= htmlspecialchars($row['subject']) ?><?= $partyText ?></strong>
                            <small><?= htmlspecialchars($row['date_bs']) ?> BS &middot; <?= htmlspecialchars($row['notes'] ?: $row['remarks'] ?? '') ?: 'No notes' ?></small>
                        </div>
                        <div class="txn-amount">
                            <div class="amt" style="color: <?= $amtColor ?>;"><?= $sign ?> Rs. <?= number_format($row['amount'], 0) ?></div>
                            <span class="badge <?= $cfg['badge'] ?>"><?= $row['transaction_type'] ?></span>
                        </div>
                    </div>
                <?php endwhile; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📭</div>
                No recent transactions. Start by adding your first entry!
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// 7-Day Cash Flow Trend Chart
const trendCtx = document.getElementById('trendChart');
if (trendCtx) {
    new Chart(trendCtx, {
        type: 'line',
        data: {
            labels: <?= $trendLabelsJson ?>,
            datasets: [
                {
                    label: 'Inflow',
                    data: <?= $trendInflowJson ?>,
                    borderColor: '#16a34a',
                    backgroundColor: 'rgba(22,163,74,0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#16a34a',
                },
                {
                    label: 'Outflow',
                    data: <?= $trendOutflowJson ?>,
                    borderColor: '#dc2626',
                    backgroundColor: 'rgba(220,38,38,0.1)',
                    fill: true,
                    tension: 0.4,
                    borderWidth: 2,
                    pointRadius: 3,
                    pointBackgroundColor: '#dc2626',
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: true, position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12 } },
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 10 } } },
                y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, callback: v => 'Rs.' + v } }
            }
        }
    });
}

// Category Distribution Doughnut
const distCtx = document.getElementById('distChart');
if (distCtx) {
    new Chart(distCtx, {
        type: 'doughnut',
        data: {
            labels: <?= $chartLabelsJson ?>,
            datasets: [{
                data: <?= $chartDataJson ?>,
                backgroundColor: <?= $chartColorsJson ?>,
                borderWidth: 2,
                borderColor: '#ffffff',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 12, padding: 8 } },
            },
            cutout: '65%',
        }
    });
}
</script>

<?php renderBottomNav('dashboard'); ?>
</body>
</html>
