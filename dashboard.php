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
// 1. LIFETIME CATEGORY AGGREGATIONS
// ----------------------------------------------------
$sqlLife = "SELECT transaction_type, IFNULL(SUM(amount), 0) AS total 
            FROM transactions 
            WHERE user_id = {$user_id} 
            GROUP BY transaction_type";

$resLife = $conn->query($sqlLife);
$d = [
    'starting_balance' => 0,
    'income'           => 0,
    'expense'          => 0,
    'investment'       => 0,
    'lend'             => 0,
    'lend_repayment'   => 0,
    'borrow'           => 0,
    'borrow_repayment' => 0,
    'loss'             => 0,
    'bad_debt'         => 0
];

while ($r = $resLife->fetch_assoc()) {
    if (isset($d[$r['transaction_type']])) {
        $d[$r['transaction_type']] = (float)$r['total'];
    }
}

// ----------------------------------------------------
// 2. LIQUIDITY BREAKDOWN (BANK vs HAND CASH)
// ----------------------------------------------------
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
$bankBalance = 0;
$handCash = 0;

while ($row = $resCash->fetch_assoc()) {
    $method = strtolower($row['payment_method'] ?? '');
    if ($method === 'hand_cash' || $method === 'cash') {
        $handCash += (float)$row['net_balance'];
    } else {
        $bankBalance += (float)$row['net_balance'];
    }
}
$totalLiquidCash = $bankBalance + $handCash;

// ----------------------------------------------------
// 3. INVESTMENTS (SHORT-TERM vs LONG-TERM)
// ----------------------------------------------------
$resInv = $conn->query("SELECT investment_type, IFNULL(SUM(amount), 0) AS total 
    FROM transactions 
    WHERE user_id = {$user_id} AND transaction_type = 'investment' AND status != 'settled' 
    GROUP BY investment_type");

$sipTotal = 0;
$ssfTotal = 0;
$sharesTotal = 0;

while ($inv = $resInv->fetch_assoc()) {
    $type = strtolower($inv['investment_type'] ?? '');
    if ($type === 'sip_long_term' || $type === 'sip') {
        $sipTotal += (float)$inv['total'];
    } elseif ($type === 'ssf' || $type === 'ssf_fund') {
        $ssfTotal += (float)$inv['total'];
    } else {
        $sharesTotal += (float)$inv['total'];
    }
}

$longTermInvestments  = $sipTotal + $ssfTotal;
$shortTermInvestments = $sharesTotal;
$totalInvestments     = $longTermInvestments + $shortTermInvestments;

// ----------------------------------------------------
// 4. RECEIVABLES & PAYABLES (AFTER SUBTRACTIONS)
// ----------------------------------------------------
$totalLent          = $d['lend'];
$lendRecovered      = $d['lend_repayment'];
$badDebts           = $d['bad_debt'];
$netLendAmount      = max(0, $totalLent - $lendRecovered - $badDebts);

$totalBorrowed      = $d['borrow'];
$borrowRepaid       = $d['borrow_repayment'];
$netBorrowAmount    = max(0, $totalBorrowed - $borrowRepaid);

// Expenses & Earnings Metrics
$totalExpenses      = $d['expense'];
$tradingLoss        = $d['loss'];
$totalEffectiveLoss = $tradingLoss + $badDebts;
$totalEarnings      = $d['starting_balance'] + $d['income'];

renderAppHead('Personal Audit Dashboard');
renderAppHeader($userName, $profilePhoto, $joinedBs, $today_bs);
?>

<style>
  :root {
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #cbd5e1;
    --text-dark: #0f172a;
    --text-sub: #475569;
    --brand-blue: #0284c7;
    --brand-green: #16a34a;
    --brand-red: #dc2626;
    --brand-amber: #d97706;
    --brand-purple: #9333ea;
  }

  .dash-container { max-width: 800px; margin: 0 auto; padding: 0 12px 100px 12px; }

  /* Hero Section: Executive Summary */
  .audit-hero { 
    background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); 
    color: #fff; 
    border-radius: 16px; 
    padding: 20px; 
    margin-bottom: 16px; 
    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
  }
  .hero-lbl { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #38bdf8; letter-spacing: 0.8px; }
  .hero-main-val { font-size: 26px; font-weight: 800; margin: 4px 0 2px 0; color: #ffffff; font-family: monospace; }
  .hero-bracket-sub { font-size: 11px; color: #cbd5e1; font-weight: 600; margin-bottom: 16px; font-family: monospace; }

  /* Executive KPI Grid inside Hero */
  .hero-kpi-grid { 
    display: grid; 
    grid-template-columns: repeat(2, 1fr); 
    gap: 12px; 
    border-top: 1px solid #334155; 
    padding-top: 14px; 
  }
  .kpi-cell .lbl { font-size: 9px; font-weight: 800; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }
  .kpi-cell .val { font-size: 14px; font-weight: 800; margin-top: 2px; font-family: monospace; }
  .kpi-cell .sub { font-size: 9px; color: #cbd5e1; font-weight: 500; }

  /* Section Cards Below Hero */
  .audit-card { background: var(--card-bg); border: 1px solid var(--border-color); border-radius: 12px; padding: 14px; margin-bottom: 14px; }
  .audit-header { font-size: 11px; font-weight: 800; text-transform: uppercase; color: var(--text-dark); margin-bottom: 10px; padding-bottom: 6px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }

  /* Data Table Row */
  .data-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; font-size: 11px; border-bottom: 1px dashed #e2e8f0; }
  .data-row:last-child { border-bottom: none; }
  .data-title { font-weight: 600; color: var(--text-sub); }
  .data-subtitle { font-size: 9px; color: #64748b; font-family: monospace; display: block; }
  .data-val { font-weight: 800; color: var(--text-dark); font-family: monospace; }

  /* 2-Column Metric Grid */
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 14px; }
  @media (max-width: 480px) { .grid-2 { grid-template-columns: 1fr; } }
</style>

<div class="container dash-container">

    <!-- ==================================================== -->
    <!-- 1. AUDIT HERO SECTION (HIGH-LEVEL CONSOLIDATED VIEW) -->
    <!-- ==================================================== -->
    <div class="audit-hero">
        <div class="hero-lbl">Total Available Liquid Cash</div>
        <div class="hero-main-val">NPR <?= number_format($totalLiquidCash, 2) ?></div>
        <div class="hero-bracket-sub">
            [ Bank: NPR <?= number_format($bankBalance, 2) ?> | Hand Cash: NPR <?= number_format($handCash, 2) ?> ]
        </div>

        <div class="hero-kpi-grid">
            <!-- Active Investments KPI -->
            <div class="kpi-cell">
                <div class="lbl">Total Investments</div>
                <div class="val" style="color: #38bdf8;">NPR <?= number_format($totalInvestments, 2) ?></div>
                <div class="sub">Short: <?= number_format($shortTermInvestments, 0) ?> | Long: <?= number_format($longTermInvestments, 0) ?></div>
            </div>

            <!-- Net Expenses KPI -->
            <div class="kpi-cell">
                <div class="lbl">Total Expenses</div>
                <div class="val" style="color: #f87171;">NPR <?= number_format($totalExpenses, 2) ?></div>
                <div class="sub">Lifetime Personal Spending</div>
            </div>

            <!-- Net Receivables (Lend After Subtractions) -->
            <div class="kpi-cell">
                <div class="lbl">Net Lend (Receivable)</div>
                <div class="val" style="color: #4ade80;">NPR <?= number_format($netLendAmount, 2) ?></div>
                <div class="sub">After Repayments & Write-offs</div>
            </div>

            <!-- Net Payables (Borrow After Subtractions) -->
            <div class="kpi-cell">
                <div class="lbl">Net Borrow (Payable)</div>
                <div class="val" style="color: #fbbf24;">NPR <?= number_format($netBorrowAmount, 2) ?></div>
                <div class="sub">After Repaid Obligations</div>
            </div>
        </div>
    </div>

    <!-- ==================================================== -->
    <!-- 2. DETAILED BREAKDOWN SECTIONS (BELOW HERO)          -->
    <!-- ==================================================== -->

    <!-- A. LIQUIDITY & BANK/CASH BREAKDOWN -->
    <div class="audit-card">
        <div class="audit-header">
            <span>🏦 Liquidity & Cash Accounts</span>
            <span style="color: var(--brand-blue); font-family: monospace;">NPR <?= number_format($totalLiquidCash, 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Bank & Digital Wallets</span>
                <span class="data-subtitle">Commercial Banks, E-Sewa, Khalti</span>
            </div>
            <span class="data-val" style="color: var(--brand-blue);">NPR <?= number_format($bankBalance, 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Physical Hand Cash</span>
                <span class="data-subtitle">On-hand Cash Holdings</span>
            </div>
            <span class="data-val" style="color: var(--brand-green);">NPR <?= number_format($handCash, 2) ?></span>
        </div>
    </div>

    <!-- B. INVESTMENT ASSETS DETAILED BREAKDOWN -->
    <div class="audit-card">
        <div class="audit-header">
            <span>📈 Investment Assets Breakdown</span>
            <span style="color: var(--brand-purple); font-family: monospace;">NPR <?= number_format($totalInvestments, 2) ?></span>
        </div>

        <div style="font-size: 10px; font-weight: 800; color: var(--text-sub); text-transform: uppercase; margin: 4px 0 4px 0;">
            1. Long-Term Capital Investments
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">SIP Mutual Funds</span>
                <span class="data-subtitle">Systematic Investment Plans</span>
            </div>
            <span class="data-val">NPR <?= number_format($sipTotal, 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Social Security Fund (SSF)</span>
                <span class="data-subtitle">Retirement Accumulation</span>
            </div>
            <span class="data-val">NPR <?= number_format($ssfTotal, 2) ?></span>
        </div>

        <div style="font-size: 10px; font-weight: 800; color: var(--text-sub); text-transform: uppercase; margin: 10px 0 4px 0;">
            2. Short-Term Capital Investments
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Secondary Share Market</span>
                <span class="data-subtitle">Equities & Short-term Trading</span>
            </div>
            <span class="data-val" style="color: var(--brand-purple);">NPR <?= number_format($sharesTotal, 2) ?></span>
        </div>
    </div>

    <!-- C. RECEIVABLES & PAYABLES DETAILED LEDGER -->
    <div class="grid-2">
        <!-- LEND / RECEIVABLES CARD -->
        <div class="audit-card" style="margin-bottom: 0;">
            <div class="audit-header">
                <span>🤝 Lend Ledger</span>
            </div>
            <div class="data-row">
                <span class="data-title">Total Capital Lent</span>
                <span class="data-val">NPR <?= number_format($totalLent, 2) ?></span>
            </div>
            <div class="data-row">
                <span class="data-title">(-) Recovered</span>
                <span class="data-val" style="color: var(--brand-green);">NPR <?= number_format($lendRecovered, 2) ?></span>
            </div>
            <div class="data-row">
                <span class="data-title">(-) Bad Debt / Write-off</span>
                <span class="data-val" style="color: var(--brand-red);">NPR <?= number_format($badDebts, 2) ?></span>
            </div>
            <div class="data-row" style="background: #f8fafc; padding: 6px; border-radius: 4px; margin-top: 4px;">
                <span class="data-title" style="font-weight: 800; color: #0f172a;">Active Net Lend</span>
                <span class="data-val" style="color: var(--brand-green);">NPR <?= number_format($netLendAmount, 2) ?></span>
            </div>
        </div>

        <!-- BORROW / PAYABLES CARD -->
        <div class="audit-card" style="margin-bottom: 0;">
            <div class="audit-header">
                <span>💳 Borrow Ledger</span>
            </div>
            <div class="data-row">
                <span class="data-title">Total Capital Borrowed</span>
                <span class="data-val">NPR <?= number_format($totalBorrowed, 2) ?></span>
            </div>
            <div class="data-row">
                <span class="data-title">(-) Repaid Principal</span>
                <span class="data-val" style="color: var(--brand-green);">NPR <?= number_format($borrowRepaid, 2) ?></span>
            </div>
            <div class="data-row" style="background: #f8fafc; padding: 6px; border-radius: 4px; margin-top: 22px;">
                <span class="data-title" style="font-weight: 800; color: #0f172a;">Active Net Borrow</span>
                <span class="data-val" style="color: var(--brand-red);">NPR <?= number_format($netBorrowAmount, 2) ?></span>
            </div>
        </div>
    </div>

    <!-- D. EXPENSES, LOSSES & DEFAULT WRITE-OFFS -->
    <div class="audit-card">
        <div class="audit-header">
            <span>💸 Expenses & Deficit Audit</span>
            <span style="color: var(--brand-red); font-family: monospace;">NPR <?= number_format($totalExpenses + $totalEffectiveLoss, 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Living & Personal Expenses</span>
                <span class="data-subtitle">Daily Spending & Outflows</span>
            </div>
            <span class="data-val" style="color: var(--brand-red);">- NPR <?= number_format($totalExpenses, 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Secondary Market Trading Losses</span>
                <span class="data-subtitle">Realized Share Market Deficits</span>
            </div>
            <span class="data-val" style="color: var(--brand-amber);">- NPR <?= number_format($tradingLoss, 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Bad Debts (Uncollectible Loans)</span>
                <span class="data-subtitle">Defaulted Lent Capital</span>
            </div>
            <span class="data-val" style="color: var(--brand-red);">- NPR <?= number_format($badDebts, 2) ?></span>
        </div>
    </div>

    <!-- E. CASH INFLOW & SAVINGS PERFORMANCE -->
    <div class="audit-card">
        <div class="audit-header">
            <span>💵 Inflow & Earnings Summary</span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Opening Balances</span>
                <span class="data-subtitle">Initial Capital</span>
            </div>
            <span class="data-val">NPR <?= number_format($d['starting_balance'], 2) ?></span>
        </div>
        <div class="data-row">
            <div>
                <span class="data-title">Earned Personal Income</span>
                <span class="data-subtitle">Salary, Freelance & Services</span>
            </div>
            <span class="data-val" style="color: var(--brand-green);">+ NPR <?= number_format($d['income'], 2) ?></span>
        </div>
    </div>

</div>

<?php renderBottomNav('dashboard'); ?>
</body>
</html>
