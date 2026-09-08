<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';
require_once 'partials/app_layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id      = (int)$_SESSION['user_id'];
$userName     = $_SESSION['name'] ?? 'User';
$profilePhoto = $_SESSION['profile_photo'] ?? 'default.png';
$joinedBs     = $_SESSION['joined_date_bs'] ?? '2083-04-16';
$today_bs     = NepaliDateConverter::todayBs();

// ----------------------------------------------------
// 1. DATA AGGREGATION ENGINE (REAL DATABASE VALUES)
// ----------------------------------------------------

// Lifetime Aggregates by Transaction Type
$sqlLife = "SELECT transaction_type, IFNULL(SUM(amount), 0) AS total, COUNT(id) as total_count 
            FROM transactions 
            WHERE user_id = {$user_id} 
            GROUP BY transaction_type";

$resLife = $conn->query($sqlLife);
$life = [
    'starting_balance' => 0.0, 'income' => 0.0, 'expense' => 0.0, 
    'investment' => 0.0, 'lend' => 0.0, 'lend_repayment' => 0.0, 
    'borrow' => 0.0, 'borrow_repayment' => 0.0, 'loss' => 0.0, 'bad_debt' => 0.0
];
$counts = [];

if ($resLife) {
    while ($r = $resLife->fetch_assoc()) {
        if (array_key_exists($r['transaction_type'], $life)) {
            $life[$r['transaction_type']] = (float)$r['total'];
            $counts[$r['transaction_type']] = (int)$r['total_count'];
        }
    }
}

// Payment Channel Balance Breakdown (Digital / Bank vs Hand Cash)
$sqlCash = "SELECT payment_method,
    SUM(CASE 
        WHEN transaction_type IN ('starting_balance', 'income', 'lend_repayment', 'borrow') THEN amount 
        WHEN transaction_type IN ('expense', 'lend', 'borrow_repayment', 'investment', 'loss', 'bad_debt') THEN -amount 
        ELSE 0 
    END) AS net_balance
    FROM transactions 
    WHERE user_id = {$user_id}
    GROUP BY payment_method";

$resCash = $conn->query($sqlCash);
$digitalCash = 0.0; $handCash = 0.0;
if ($resCash) {
    while ($row = $resCash->fetch_assoc()) {
        $method = strtolower(trim($row['payment_method'] ?? ''));
        if ($method === 'hand_cash' || $method === 'cash') {
            $handCash += (float)$row['net_balance'];
        } else {
            $digitalCash += (float)$row['net_balance'];
        }
    }
}
$netLiquidCash = $digitalCash + $handCash;

// Investment Asset Allocation Breakdown
$resInv = $conn->query("SELECT investment_type, IFNULL(SUM(amount), 0) AS total 
    FROM transactions 
    WHERE user_id = {$user_id} AND transaction_type = 'investment' AND status != 'settled' 
    GROUP BY investment_type");

$sipTotal = 0.0; $ssfTotal = 0.0; $sharesTotal = 0.0;
if ($resInv) {
    while ($inv = $resInv->fetch_assoc()) {
        $type = strtolower(trim($inv['investment_type'] ?? ''));
        if ($type === 'sip_long_term' || $type === 'sip') {
            $sipTotal += (float)$inv['total'];
        } elseif ($type === 'ssf' || $type === 'ssf_fund') {
            $ssfTotal += (float)$inv['total'];
        } else {
            $sharesTotal += (float)$inv['total'];
        }
    }
}
$defensiveAssets  = $sipTotal + $ssfTotal;
$totalInvestments = $defensiveAssets + $sharesTotal;

// Subject-wise Expenditure Breakdown
$sqlExpSubjects = "SELECT IFNULL(NULLIF(TRIM(subject), ''), 'General / Uncategorized') AS subject_name, 
                          IFNULL(SUM(amount), 0) AS total_amount, 
                          COUNT(id) as tx_count 
                   FROM transactions 
                   WHERE user_id = {$user_id} AND transaction_type = 'expense' 
                   GROUP BY IFNULL(NULLIF(TRIM(subject), ''), 'General / Uncategorized') 
                   ORDER BY total_amount DESC";

$resSub = $conn->query($sqlExpSubjects);
$expenseSubjects = [];
if ($resSub) {
    while ($sub = $resSub->fetch_assoc()) {
        $expenseSubjects[] = $sub;
    }
}

// Financial Metrics & Exposure Calculations
$totalInflow         = $life['starting_balance'] + $life['income'] + $life['lend_repayment'] + $life['borrow'];
$totalOutflow        = $life['expense'] + $life['lend'] + $life['borrow_repayment'] + $life['investment'] + $life['loss'] + $life['bad_debt'];
$accountsReceivable  = max(0, $life['lend'] - $life['lend_repayment'] - $life['bad_debt']);
$accountsPayable     = max(0, $life['borrow'] - $life['borrow_repayment']);
$equityRiskRatio     = $totalInvestments > 0 ? ($sharesTotal / $totalInvestments) * 100 : 0;

renderAppHead('Detailed Financial Audit Report');
renderAppHeader($userName, $profilePhoto, $joinedBs, $today_bs);
?>

<style>
  :root {
    --bg-main: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #cbd5e1;
    --text-primary: #0f172a;
    --text-secondary: #475569;
    --text-muted: #64748b;
    
    --accent-blue: #2563eb;
    --accent-green: #10b981;
    --accent-red: #ef4444;
    --accent-amber: #f59e0b;
  }

  body { background-color: var(--bg-main); color: var(--text-primary); }
  .report-wrapper { max-width: 900px; margin: 0 auto; padding: 0 12px 100px 12px; }

  /* Dashboard Matching Black Hero Banner */
  .dashboard-hero {
    background: #0f172a;
    color: #ffffff;
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 16px;
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
  }
  .hero-top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
  }
  .hero-title {
    font-size: 18px;
    font-weight: 800;
    display: flex;
    align-items: center;
    gap: 8px;
    color: #ffffff;
    letter-spacing: -0.2px;
  }
  .hero-badge {
    font-size: 10px;
    background: rgba(56, 189, 248, 0.15);
    color: #38bdf8;
    border: 1px solid rgba(56, 189, 248, 0.3);
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .hero-subtext {
    font-size: 12px;
    color: #94a3b8;
    line-height: 1.4;
  }

  /* Hero KPI Grid */
  .hero-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
    gap: 12px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #334155;
  }
  .hero-kpi-box { display: flex; flex-direction: column; }
  .hero-kpi-lbl { font-size: 9px; font-weight: 800; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.5px; }
  .hero-kpi-val { font-size: 16px; font-weight: 800; color: #ffffff; font-family: monospace; margin-top: 2px; }

  /* Light Section Cards */
  .section-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 16px;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.02);
  }
  .section-title {
    font-size: 12px;
    font-weight: 800;
    text-transform: uppercase;
    color: var(--text-primary);
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    letter-spacing: 0.4px;
  }
  
  /* Audit Summary Tables */
  .audit-table { width: 100%; border-collapse: collapse; font-size: 11px; margin-top: 8px; }
  .audit-table th { background: #f1f5f9; text-align: left; padding: 9px 10px; font-size: 9px; font-weight: 800; color: var(--text-primary); text-transform: uppercase; border-bottom: 1px solid var(--border-color); }
  .audit-table td { padding: 9px 10px; border-bottom: 1px solid #f1f5f9; color: var(--text-primary); }
  .audit-table tr:last-child td { border-bottom: none; }

  /* Split Directives Grid */
  .directives-container {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-top: 14px;
  }
  @media (max-width: 580px) { .directives-container { grid-template-columns: 1fr; } }

  .directive-box { padding: 12px; border-radius: 10px; font-size: 11px; line-height: 1.45; }
  .directive-positive { background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; }
  .directive-warning { background: #fef2f2; border: 1px solid #fecaca; color: #7f1d1d; }

  .directive-head { font-weight: 800; text-transform: uppercase; font-size: 10px; margin-bottom: 6px; display: flex; align-items: center; gap: 4px; }
  .directive-ul { margin: 0; padding-left: 16px; }
  .directive-ul li { margin-bottom: 4px; }
  .directive-ul li:last-child { margin-bottom: 0; }
</style>

<div class="report-wrapper">

    <!-- DARK HERO SECTION (DASHBOARD MATCHING STYLE) -->
    <div class="dashboard-hero">
        <div class="hero-top-bar">
            <div class="hero-title">
                <span>📊</span> Audit & Analysis
            </div>
            <span class="hero-badge">Live Data</span>
        </div>


        <div class="hero-kpi-grid">
            <div class="hero-kpi-box">
                <span class="hero-kpi-lbl">Net Liquid Balance</span>
                <span class="hero-kpi-val" style="color: <?= $netLiquidCash >= 0 ? '#4ade80' : '#f87171' ?>;">
                    NPR <?= number_format($netLiquidCash, 2) ?>
                </span>
            </div>
            <div class="hero-kpi-box">
                <span class="hero-kpi-lbl">Total Outflows</span>
                <span class="hero-kpi-val" style="color: #f87171;">
                    NPR <?= number_format($totalOutflow, 2) ?>
                </span>
            </div>
            <div class="hero-kpi-box">
                <span class="hero-kpi-lbl">Active Portfolio</span>
                <span class="hero-kpi-val" style="color: #38bdf8;">
                    NPR <?= number_format($totalInvestments, 2) ?>
                </span>
            </div>
            <div class="hero-kpi-box">
                <span class="hero-kpi-lbl">Net Receivables</span>
                <span class="hero-kpi-val" style="color: #fbbf24;">
                    NPR <?= number_format($accountsReceivable, 2) ?>
                </span>
            </div>
        </div>
    </div>

    <!-- 1. CASHFLOW & PAYMENT CHANNEL RECONCILIATION -->
    <div class="section-card">
        <div class="section-title">
            <span>1. Payment Channel & Liquidity Audit</span>
            <span style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Reconciliation</span>
        </div>

        <table class="audit-table">
            <thead>
                <tr>
                    <th>Payment Method</th>
                    <th>Current Balance</th>
                    <th>Share of Cash</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Digital / Bank Accounts</strong></td>
                    <td style="font-family: monospace; font-weight: 700;">NPR <?= number_format($digitalCash, 2) ?></td>
                    <td><?= $netLiquidCash > 0 ? number_format(($digitalCash / $netLiquidCash) * 100, 1) : 0 ?>%</td>
                    <td>
                        <span style="color: <?= $digitalCash >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>; font-weight: 800;">
                            <?= $digitalCash >= 0 ? 'SOLVENT' : 'OVERDRAWN' ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td><strong>Hand Cash</strong></td>
                    <td style="font-family: monospace; font-weight: 700;">NPR <?= number_format($handCash, 2) ?></td>
                    <td><?= $netLiquidCash > 0 ? number_format(($handCash / $netLiquidCash) * 100, 1) : 0 ?>%</td>
                    <td>
                        <span style="color: <?= $handCash >= 0 ? 'var(--accent-green)' : 'var(--accent-red)' ?>; font-weight: 800;">
                            <?= $handCash >= 0 ? 'SOLVENT' : 'DEFICIT' ?>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="directives-container">
            <div class="directive-box directive-positive">
                <div class="directive-head" style="color: #166534;">✅ Directives</div>
                <ul class="directive-ul">
                    <li>Maintain liquid balances to cover routine operational expenses.</li>
                    <li>Ensure digital balances form at least 70% of total liquid reserves.</li>
                </ul>
            </div>
            <div class="directive-box directive-warning">
                <div class="directive-head" style="color: #991b1b;">🚫 Mitigations</div>
                <ul class="directive-ul">
                    <li>Do not execute unrecorded physical cash transactions.</li>
                    <li>Avoid holding excessive cash in hand to reduce untracked leakage.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- 2. EXPENDITURE AUDIT BY SUBJECT -->
    <div class="section-card">
        <div class="section-title">
            <span>2. Expenditure Audit by Subject</span>
            <span style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Total Spent: NPR <?= number_format($life['expense'], 2) ?></span>
        </div>

        <table class="audit-table">
            <thead>
                <tr>
                    <th>Subject</th>
                    <th>Logs</th>
                    <th>Total Capital Spent</th>
                    <th>% Share</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($expenseSubjects)): ?>
                    <?php foreach ($expenseSubjects as $sub): 
                        $pct = $life['expense'] > 0 ? ($sub['total_amount'] / $life['expense']) * 100 : 0;
                    ?>
                        <tr>
                            <td><strong><?= htmlspecialchars(ucwords($sub['subject_name'])) ?></strong></td>
                            <td><?= number_format($sub['tx_count']) ?> txns</td>
                            <td style="font-family: monospace; font-weight: 800; color: var(--accent-red);">
                                NPR <?= number_format($sub['total_amount'], 2) ?>
                            </td>
                            <td><strong><?= number_format($pct, 1) ?>%</strong></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="4" style="text-align: center; color: var(--text-muted);">No recorded expenses to evaluate.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="directives-container">
            <div class="directive-box directive-positive">
                <div class="directive-head" style="color: #166534;">✅ Spending Directives</div>
                <ul class="directive-ul">
                    <li>Cap discretionary subject spending to below 25% of overall inflows.</li>
                    <li>Review top 2 spending subjects every month for cost optimizations.</li>
                </ul>
            </div>
            <div class="directive-box directive-warning">
                <div class="directive-head" style="color: #991b1b;">🚫 Cost Warnings</div>
                <ul class="directive-ul">
                    <li>Never leave the Subject description field blank when logging expenses.</li>
                    <li>Ensure unclassified costs remain below 5% of total monthly outflows.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- 3. INVESTMENT PORTFOLIO AUDIT -->
    <div class="section-card">
        <div class="section-title">
            <span>3. Portfolio Capital & Volatility Allocation</span>
            <span style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Invested: NPR <?= number_format($totalInvestments, 2) ?></span>
        </div>

        <table class="audit-table">
            <thead>
                <tr>
                    <th>Asset Category</th>
                    <th>Capital Deployed</th>
                    <th>Weight</th>
                    <th>Risk Class</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Defensive Assets (SIP / SSF)</strong></td>
                    <td style="font-family: monospace; font-weight: 700;">NPR <?= number_format($defensiveAssets, 2) ?></td>
                    <td><?= $totalInvestments > 0 ? number_format(($defensiveAssets / $totalInvestments) * 100, 1) : 0 ?>%</td>
                    <td><span style="color: var(--accent-green); font-weight: 800;">LOW VOLATILITY</span></td>
                </tr>
                <tr>
                    <td><strong>Secondary Market Equities</strong></td>
                    <td style="font-family: monospace; font-weight: 700;">NPR <?= number_format($sharesTotal, 2) ?></td>
                    <td><?= number_format($equityRiskRatio, 1) ?>%</td>
                    <td>
                        <span style="color: <?= $equityRiskRatio > 50 ? 'var(--accent-red)' : 'var(--accent-amber)' ?>; font-weight: 800;">
                            <?= $equityRiskRatio > 50 ? 'HIGH VOLATILITY' : 'MODERATE VOLATILITY' ?>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="directives-container">
            <div class="directive-box directive-positive">
                <div class="directive-head" style="color: #166534;">✅ Asset Directives</div>
                <ul class="directive-ul">
                    <li>Maintain steady recurring installments for long-term SIP and SSF holdings.</li>
                    <li>Rebalance secondary market equities if exposure surpasses target limits.</li>
                </ul>
            </div>
            <div class="directive-box directive-warning">
                <div class="directive-head" style="color: #991b1b;">🚫 Portfolio Rules</div>
                <ul class="directive-ul">
                    <li>Never use borrowed funds to purchase secondary equity shares.</li>
                    <li>Avoid liquidating defensive assets to cover short-term operational deficits.</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- 4. CREDIT, DEBT & RECEIVABLES AUDIT -->
    <div class="section-card">
        <div class="section-title">
            <span>4. Credit Exposure & Bad Debt Recovery</span>
            <span style="font-size: 10px; color: var(--text-muted); font-weight: 600;">Counterparty Exposure</span>
        </div>

        <table class="audit-table">
            <thead>
                <tr>
                    <th>Ledger Item</th>
                    <th>Total Capital Value</th>
                    <th>Audit Status</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Gross Lent Capital</strong></td>
                    <td style="font-family: monospace; font-weight: 700;">NPR <?= number_format($life['lend'], 2) ?></td>
                    <td>Lifetime Credit Extended</td>
                </tr>
                <tr>
                    <td><strong>Recovered Capital</strong></td>
                    <td style="font-family: monospace; font-weight: 700; color: var(--accent-green);">NPR <?= number_format($life['lend_repayment'], 2) ?></td>
                    <td>Recovered Funds</td>
                </tr>
                <tr>
                    <td><strong>Bad Debt Write-offs</strong></td>
                    <td style="font-family: monospace; font-weight: 700; color: var(--accent-red);">NPR <?= number_format($life['bad_debt'], 2) ?></td>
                    <td>Written-off Losses</td>
                </tr>
                <tr>
                    <td><strong>Net Collectible Receivables</strong></td>
                    <td style="font-family: monospace; font-weight: 700; color: var(--accent-amber);">NPR <?= number_format($accountsReceivable, 2) ?></td>
                    <td><strong>Active Outstanding Credit</strong></td>
                </tr>
            </tbody>
        </table>

        <div class="directives-container">
            <div class="directive-box directive-positive">
                <div class="directive-head" style="color: #166534;">✅ Debt Recovery Strategy</div>
                <ul class="directive-ul">
                    <li>Set strict recovery schedules for active outstanding loans.</li>
                    <li>Prioritize paying down active debt obligations promptly.</li>
                </ul>
            </div>
            <div class="directive-box directive-warning">
                <div class="directive-head" style="color: #991b1b;">🚫 Credit Risk Warnings</div>
                <ul class="directive-ul">
                    <li>Halt extending credit to borrowers who have uncollected defaults.</li>
                    <li>Promptly write off uncollectible receivables as Bad Debt to maintain clear ledger integrity.</li>
                </ul>
            </div>
        </div>
    </div>

</div>

<?php renderBottomNav('reports'); ?>
</body>
</html>
