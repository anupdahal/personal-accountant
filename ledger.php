<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';
require_once 'partials/app_layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id     = (int)$_SESSION['user_id'];
$topicConfig = topicConfig();

// Generate CSRF Token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ----------------------------------------------------
// Handle Transaction Deletion (POST with CSRF)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], $csrf_token)) {
        die("Security check failed: Invalid CSRF token.");
    }

    $delete_id = (int)($_POST['txn_id'] ?? 0);
    if ($delete_id > 0) {
        $delQuery = "DELETE FROM transactions WHERE id = ? AND user_id = ?";
        $delStmt  = $conn->prepare($delQuery);
        if ($delStmt) {
            $delStmt->bind_param("ii", $delete_id, $user_id);
            $delStmt->execute();
            $delStmt->close();
        }
    }
    
    $queryParams = $_GET;
    $queryString = !empty($queryParams) ? '?' . http_build_query($queryParams) : '';
    header("Location: ledger.php" . $queryString);
    exit();
}

// ----------------------------------------------------
// Schema Safety Checks
// ----------------------------------------------------
$hasPartyCol = columnExists($conn, 'transactions', 'party_name');
$hasModeCol  = columnExists($conn, 'transactions', 'payment_mode');
$notesCol    = notesColumn($conn);
$notesSelect = $notesCol !== '' ? ", `{$notesCol}` AS notes" : ", '' AS notes";
$partySelect = $hasPartyCol ? "party_name" : "'' AS party_name";
$modeSelect  = $hasModeCol  ? "payment_mode" : "'' AS payment_mode";

// ----------------------------------------------------
// Filter Parameters
// ----------------------------------------------------
$search    = trim($_GET['search']    ?? '');
$catFilt   = trim($_GET['category']  ?? '');
$partyFilt = trim($_GET['party']     ?? '');
$dateFrom  = trim($_GET['date_from'] ?? '');
$dateTo    = trim($_GET['date_to']   ?? '');

$whereParts = ["user_id = ?"];
$bindTypes  = 'i';
$bindVals   = [$user_id];

if ($search !== '') {
    $whereParts[] = "(subject LIKE ? OR remarks LIKE ?" . ($notesCol !== '' ? " OR `{$notesCol}` LIKE ?" : "") . ")";
    $searchLike = "%{$search}%";
    $bindTypes .= 'ss';
    $bindVals[] = $searchLike;
    $bindVals[] = $searchLike;
    if ($notesCol !== '') {
        $bindTypes .= 's';
        $bindVals[] = $searchLike;
    }
}

if ($catFilt !== '') {
    $whereParts[] = "transaction_type = ?";
    $bindTypes .= 's';
    $bindVals[] = $catFilt;
}

if ($partyFilt !== '' && $hasPartyCol) {
    $whereParts[] = "party_name = ?";
    $bindTypes .= 's';
    $bindVals[] = $partyFilt;
}

if ($dateFrom !== '') {
    $whereParts[] = "date_ad >= ?";
    $bindTypes .= 's';
    $bindVals[] = $dateFrom;
}

if ($dateTo !== '') {
    $whereParts[] = "date_ad <= ?";
    $bindTypes .= 's';
    $bindVals[] = $dateTo;
}

$whereClause = implode(' AND ', $whereParts);

// ----------------------------------------------------
// Main Query - Transactions Execution
// ----------------------------------------------------
$query = "SELECT id, transaction_type, subject, amount, {$partySelect}, {$modeSelect}, date_ad, date_bs, created_at{$notesSelect}
    FROM transactions
    WHERE {$whereClause}
    ORDER BY id DESC";

$stmt = $conn->prepare($query);

$allTxns       = [];
$totalInflow   = 0;
$totalOutflow  = 0;
$openingCap    = 0;
$cashInHand    = 0;
$bankBalance   = 0;

if ($stmt) {
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $transactions = $stmt->get_result();

    while ($row = $transactions->fetch_assoc()) {
        $allTxns[] = $row;
        $amt  = (float)$row['amount'];
        $type = $row['transaction_type'];
        $mode = strtolower($row['payment_mode'] ?? '');

        $isInflow = in_array($type, ['income', 'starting_balance', 'borrow', 'lend_repayment']);

        if ($type === 'starting_balance') {
            $openingCap += $amt;
        }

        if ($isInflow) {
            $totalInflow += $amt;
            if (in_array($mode, ['bank', 'online', 'cheque'])) {
                $bankBalance += $amt;
            } else {
                $cashInHand += $amt;
            }
        } else {
            $totalOutflow += $amt;
            if (in_array($mode, ['bank', 'online', 'cheque'])) {
                $bankBalance -= $amt;
            } else {
                $cashInHand -= $amt;
            }
        }
    }
    $stmt->close();
}

$netProfitLoss = $totalInflow - $totalOutflow;
$filterCount   = count($allTxns);

// ----------------------------------------------------
// Specific Lend/Borrow Detailed Tracking per Person
// ----------------------------------------------------
$partyLendBorrowSummary = [];
if ($hasPartyCol) {
    $lbQuery = "SELECT party_name, transaction_type, SUM(amount) as total_amount 
                FROM transactions 
                WHERE user_id = ? AND party_name IS NOT NULL AND party_name != '' 
                AND transaction_type IN ('lend', 'borrow', 'lend_repayment', 'borrow_repayment')
                GROUP BY party_name, transaction_type";
    $lbStmt = $conn->prepare($lbQuery);
    if ($lbStmt) {
        $lbStmt->bind_param("i", $user_id);
        $lbStmt->execute();
        $lbRes = $lbStmt->get_result();
        while ($lbRow = $lbRes->fetch_assoc()) {
            $pName = $lbRow['party_name'];
            $tType = $lbRow['transaction_type'];
            $amt   = (float)$lbRow['total_amount'];

            if (!isset($partyLendBorrowSummary[$pName])) {
                $partyLendBorrowSummary[$pName] = ['lent' => 0, 'lent_repaid' => 0, 'borrowed' => 0, 'borrowed_repaid' => 0];
            }

            if ($tType === 'lend') $partyLendBorrowSummary[$pName]['lent'] += $amt;
            if ($tType === 'lend_repayment') $partyLendBorrowSummary[$pName]['lent_repaid'] += $amt;
            if ($tType === 'borrow') $partyLendBorrowSummary[$pName]['borrowed'] += $amt;
            if ($tType === 'borrow_repayment') $partyLendBorrowSummary[$pName]['borrowed_repaid'] += $amt;
        }
        $lbStmt->close();
    }
}

// Fetch all distinct parties for filtering dropdown
$parties = [];
if ($hasPartyCol) {
    $pQuery = "SELECT DISTINCT party_name FROM transactions WHERE user_id = ? AND party_name IS NOT NULL AND party_name != '' ORDER BY party_name";
    $pStmt = $conn->prepare($pQuery);
    if ($pStmt) {
        $pStmt->bind_param("i", $user_id);
        $pStmt->execute();
        $pRes = $pStmt->get_result();
        while ($p = $pRes->fetch_assoc()) {
            $parties[] = $p['party_name'];
        }
        $pStmt->close();
    }
}

$today_bs = NepaliDateConverter::todayBs();

renderAppHead('Transaction Ledger', '
<style>
  :root {
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #cbd5e1;
    --border-light: #f1f5f9;
    --text-dark: #0f172a;
    --text-sub: #475569;
    --text-muted: #64748b;
    --brand-blue: #1e40af;
    --brand-green: #166534;
    --brand-red: #991b1b;
    --brand-amber: #b45309;
    --brand-purple: #6b21a8;
  }

  .ledger-container { max-width: 850px; margin: 0 auto; padding: 0 12px 100px 12px; }

  .ledger-banner {
    background: #0f172a;
    color: #ffffff;
    border-radius: 14px;
    padding: 16px 18px;
    margin-bottom: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .banner-title { font-size: 11px; font-weight: 800; text-transform: uppercase; color: #38bdf8; letter-spacing: 0.5px; }
  .banner-val { font-size: 22px; font-weight: 800; color: #ffffff; margin-top: 2px; font-family: monospace; }
  .banner-count { font-size: 10px; font-weight: 700; background: #1e293b; padding: 6px 12px; border-radius: 20px; color: #94a3b8; border: 1px solid #334155; }

  .metrics-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 8px;
    margin-bottom: 14px;
  }
  .metric-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 10px;
    padding: 10px 12px;
  }
  .metric-label { font-size: 9px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; }
  .metric-value { font-size: 14px; font-weight: 800; font-family: monospace; margin-top: 2px; }

  .filter-panel {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    padding: 14px;
    margin-bottom: 14px;
  }
  .search-bar {
    position: relative;
    display: flex;
    align-items: center;
    margin-bottom: 10px;
  }
  .search-icon { position: absolute; left: 12px; font-size: 13px; color: var(--text-muted); }
  .search-input {
    width: 100%;
    padding: 10px 10px 10px 36px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #f8fafc;
    color: var(--text-dark);
    outline: none;
    transition: all 0.2s;
  }
  .search-input:focus { border-color: #2563eb; background: #ffffff; }

  .filter-chips {
    display: flex;
    gap: 6px;
    overflow-x: auto;
    padding-bottom: 6px;
    margin-bottom: 8px;
    scrollbar-width: none;
  }
  .filter-chips::-webkit-scrollbar { display: none; }
  .chip {
    padding: 6px 12px;
    font-size: 10px;
    font-weight: 700;
    border-radius: 20px;
    background: #f1f5f9;
    color: var(--text-sub);
    border: 1px solid #e2e8f0;
    white-space: nowrap;
    cursor: pointer;
    transition: all 0.15s ease;
  }
  .chip.active { background: #0f172a; color: #ffffff; border-color: #0f172a; }

  .party-select {
    width: 100%;
    padding: 8px 10px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #f8fafc;
    color: var(--text-dark);
    margin-bottom: 8px;
    outline: none;
  }

  .date-range { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
  .date-field label { font-size: 9px; font-weight: 800; color: var(--text-muted); text-transform: uppercase; margin-bottom: 3px; display: block; }
  .date-field input {
    width: 100%;
    padding: 7px 8px;
    font-size: 11px;
    border: 1px solid var(--border-color);
    border-radius: 6px;
    background: #f8fafc;
    color: var(--text-dark);
    outline: none;
  }

  .filter-actions {
    margin-top: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-top: 1px dashed var(--border-color);
    padding-top: 10px;
  }
  .btn-submit {
    background: #0f172a;
    color: #ffffff;
    width: 75%;
    border: none;
    padding: 8px 16px;
    font-size: 11px;
    font-weight: 700;
    border-radius: 6px;
    cursor: pointer;
  }
  .clear-btn { font-size: 11px; color: var(--brand-red); text-decoration: none; margin-left: 10px; width: 25%; font-weight: 700; }

  /* Lend / Borrow Summary Card */
  .person-summary-card {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 12px;
    padding: 12px 16px;
    margin-bottom: 14px;
  }
  .person-summary-title { font-size: 12px; font-weight: 800; color: #166534; margin-bottom: 6px; }
  .person-details-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 8px; }
  .person-stat-box { background: #ffffff; padding: 8px; border-radius: 6px; border: 1px solid #dcfce7; }
  .person-stat-label { font-size: 9px; font-weight: 700; color: #475569; text-transform: uppercase; }
  .person-stat-val { font-size: 12px; font-weight: 800; font-family: monospace; margin-top: 2px; }

  .ledger-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 12px;
    overflow: hidden;
  }
  .txn-stream { display: flex; flex-direction: column; }
  .txn-item {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
    border-bottom: 1px solid var(--border-light);
    transition: background 0.15s ease;
  }
  .txn-item:last-child { border-bottom: none; }
  .txn-item:hover { background: #f8fafc; }

  .txn-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
  }

  .txn-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
  .txn-body strong { font-size: 13px; color: var(--text-dark); font-weight: 700; word-break: break-word; }
  .txn-body small { font-size: 10px; color: var(--text-muted); font-weight: 600; }
  .txn-body .notes-text { font-size: 11px; color: var(--text-sub); margin-top: 2px; word-break: break-word; font-style: italic; }

  .txn-right { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; flex-shrink: 0; }
  .txn-right .amt { font-size: 13px; font-weight: 800; font-family: monospace; white-space: nowrap; }
  
  .type-tag {
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    padding: 2px 8px;
    border-radius: 4px;
    background: #f1f5f9;
    color: var(--text-sub);
    border: 1px solid #cbd5e1;
    display: inline-block;
  }

  .txn-actions { display: flex; align-items: center; gap: 4px; margin-top: 2px; }
  .btn-action-sm {
    display: inline-flex;
    align-items: center;
    padding: 3px 6px;
    font-size: 10px;
    font-weight: 700;
    border-radius: 4px;
    text-decoration: none;
    border: none;
    cursor: pointer;
    line-height: 1;
  }
  .btn-edit-sm { color: #2563eb; background: #eff6ff; }
  .btn-delete-sm { color: var(--brand-red); background: #fef2f2; }

  .empty-state {
    padding: 40px 20px;
    text-align: center;
    font-size: 12px;
    color: var(--text-muted);
  }
  .empty-icon { font-size: 28px; margin-bottom: 8px; }
</style>');

renderAppHeader($_SESSION['name'] ?? 'User', $_SESSION['profile_photo'] ?? 'default.png', $_SESSION['joined_date_bs'] ?? '2083-04-16', $today_bs);
?>

<div class="container ledger-container">

    <!-- Banner -->
    <div class="ledger-banner">
        <div>
            <div class="banner-title">Ledger Turnover Volume</div>
            <div class="banner-val">NPR <?= number_format($totalInflow + $totalOutflow, 2) ?></div>
        </div>
        <div class="banner-count">
            <?= $filterCount ?> Transaction Entry<?= $filterCount !== 1 ? 's' : '' ?>
        </div>
    </div>

  
    <!-- Filter Form -->
    <div class="filter-panel">
        <form method="GET" action="ledger.php" id="filterForm">
            <div class="search-bar">
                <span class="search-icon">🔍</span>
                <input type="text" name="search" id="searchInput" class="search-input"
                       placeholder="Search subjects, descriptions, or party names..."
                       value="<?= htmlspecialchars($search) ?>"
                       oninput="instantFilter()">
            </div>

            <!-- Categories Chips -->
            <div class="filter-chips">
                <span class="chip <?= $catFilt === '' ? 'active' : '' ?>" onclick="setFilter('category','')">
                    All Categories
                </span>
                <?php foreach ($topicConfig as $key => $cfg): ?>
                    <span class="chip <?= $catFilt === $key ? 'active' : '' ?>" onclick="setFilter('category','<?= $key ?>')">
                        <?= $cfg['icon'] ?> <?= ucwords(str_replace('_', ' ', $key)) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- Counterparty / Person Dropdown Filter -->
            <?php if (!empty($parties)): ?>
            <select name="party" class="party-select" onchange="this.form.submit()">
                <option value="">All Counterparties / Persons</option>
                <?php foreach ($parties as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $partyFilt === $p ? 'selected' : '' ?>>
                        👤 <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <!-- Date Range Filters -->
            <div class="date-range">
                <div class="date-field">
                    <label>From Date (AD)</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="date-field">
                    <label>To Date (AD)</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn-submit">Apply Filter Criteria</button>
                <?php if ($search || $catFilt || $partyFilt || $dateFrom || $dateTo): ?>
                    <a href="ledger.php" class="clear-btn"> ✕ Clear Filter</a>
                <?php endif; ?>
            </div>

            <input type="hidden" name="category" id="catInput" value="<?= htmlspecialchars($catFilt) ?>">
        </form>
    </div>

    <!-- Person Detailed Lend / Borrow Summary (Shows when a specific party is selected or lend/borrow filter is active) -->
    <?php if ($partyFilt !== '' && isset($partyLendBorrowSummary[$partyFilt])): 
        $pStat = $partyLendBorrowSummary[$partyFilt];
        $netLendToReceive = $pStat['lent'] - $pStat['lent_repaid'];
        $netBorrowToPay   = $pStat['borrowed'] - $pStat['borrowed_repaid'];
    ?>
        <div class="person-summary-card">
            <div class="person-summary-title">📊 Individual Balance Sheet: <?= htmlspecialchars($partyFilt) ?></div>
            <div class="person-details-grid">
                <div class="person-stat-box">
                    <div class="person-stat-label">Total Amount Lent</div>
                    <div class="person-stat-val" style="color: var(--brand-amber);">NPR <?= number_format($pStat['lent'], 2) ?></div>
                </div>
                <div class="person-stat-box">
                    <div class="person-stat-label">Returned To You</div>
                    <div class="person-stat-val" style="color: var(--brand-green);">NPR <?= number_format($pStat['lent_repaid'], 2) ?></div>
                </div>
                <div class="person-stat-box">
                    <div class="person-stat-label">Pending Receivable</div>
                    <div class="person-stat-val" style="color: <?= $netLendToReceive > 0 ? 'var(--brand-red)' : 'var(--brand-green)' ?>;">
                        NPR <?= number_format($netLendToReceive, 2) ?> <?= $netLendToReceive <= 0 ? '(Paid Off)' : '' ?>
                    </div>
                </div>
                <div class="person-stat-box">
                    <div class="person-stat-label">Total Borrowed</div>
                    <div class="person-stat-val" style="color: var(--brand-blue);">NPR <?= number_format($pStat['borrowed'], 2) ?></div>
                </div>
                <div class="person-stat-box">
                    <div class="person-stat-label">Paid Back By You</div>
                    <div class="person-stat-val" style="color: var(--brand-green);">NPR <?= number_format($pStat['borrowed_repaid'], 2) ?></div>
                </div>
                <div class="person-stat-box">
                    <div class="person-stat-label">Pending Payable</div>
                    <div class="person-stat-val" style="color: <?= $netBorrowToPay > 0 ? 'var(--brand-red)' : 'var(--brand-green)' ?>;">
                        NPR <?= number_format($netBorrowToPay, 2) ?> <?= $netBorrowToPay <= 0 ? '(Cleared)' : '' ?>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Ledger Transaction Stream -->
    <div class="ledger-card" id="txnList">
        <?php if (!empty($allTxns)): ?>
            <div class="txn-stream">
                <?php foreach ($allTxns as $row):
                    $cfg = $topicConfig[$row['transaction_type']] ?? ['icon' => '💳', 'badge' => 'badge-income', 'color' => '#64748b'];
                    $isInflow = in_array($row['transaction_type'], ['income', 'starting_balance', 'borrow', 'lend_repayment']);
                    $sign = $isInflow ? '+' : '-';
                    
                    $amtColor = 'var(--brand-red)';
                    if ($isInflow) $amtColor = 'var(--brand-green)';
                    if ($row['transaction_type'] === 'lend') $amtColor = 'var(--brand-amber)';
                    if ($row['transaction_type'] === 'investment') $amtColor = 'var(--brand-blue)';
                    if ($row['transaction_type'] === 'loss') $amtColor = 'var(--brand-purple)';
                    
                    $partyText = !empty($row['party_name']) ? ' &middot; 👤 ' . htmlspecialchars($row['party_name']) : '';
                    $modeText  = !empty($row['payment_mode']) ? ' [' . strtoupper(htmlspecialchars($row['payment_mode'])) . ']' : '';
                    $noteText  = !empty($row['notes']) ? htmlspecialchars($row['notes']) : '';
                    $readableType = strtoupper(str_replace('_', ' ', $row['transaction_type']));
                ?>
                    <div class="txn-item"
                         data-search="<?= htmlspecialchars(strtolower($row['subject'] . ' ' . $row['notes'] . ' ' . $row['party_name'])) ?>"
                         data-type="<?= $row['transaction_type'] ?>">
                        
                        <div class="txn-icon" style="background: <?= $cfg['color'] ?>1a; color: <?= $cfg['color'] ?>;">
                            <?= $cfg['icon'] ?>
                        </div>

                        <div class="txn-body">
                            <strong><?= htmlspecialchars($row['subject']) ?><?= $partyText ?><?= $modeText ?></strong>
                            <small>📅 <?= htmlspecialchars($row['date_bs']) ?> BS (<?= htmlspecialchars($row['date_ad']) ?> AD)</small>
                            <?php if ($noteText): ?>
                                <div class="notes-text">📝 <?= $noteText ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="txn-right">
                            <div class="amt" style="color: <?= $amtColor ?>;"><?= $sign ?> NPR <?= number_format($row['amount'], 2) ?></div>
                            <span class="type-tag"><?= $readableType ?> (<?= $isInflow ? 'INFLOW' : 'OUTFLOW' ?>)</span>
                            
                            <div class="txn-actions">
                                <a href="edit_transaction.php?id=<?= $row['id'] ?>" class="btn-action-sm btn-edit-sm">
                                    ✏️ Edit
                                </a>
                                <form method="POST" action="ledger.php<?= !empty($_SERVER['QUERY_STRING']) ? '?' . htmlspecialchars($_SERVER['QUERY_STRING']) : '' ?>" style="display:inline;" onsubmit="return confirm('Delete this transaction record permanently?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="txn_id" value="<?= $row['id'] ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <button type="submit" class="btn-action-sm btn-delete-sm">
                                        🗑️
                                    </button>
                                </form>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                No transaction records match the specified query criteria.<br>
                <a href="ledger.php" style="color: #2563eb; font-weight: 700;">Reset all filters</a> or <a href="add_entry.php" style="color: #2563eb; font-weight: 700;">add a new entry</a>.
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function instantFilter() {
    const query = document.getElementById('searchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('#txnList .txn-item');
    let visible = 0;

    items.forEach(item => {
        const searchText = item.dataset.search || '';
        if (query === '' || searchText.includes(query)) {
            item.style.display = 'flex';
            visible++;
        } else {
            item.style.display = 'none';
        }
    });

    const countEl = document.querySelector('.banner-count');
    if (countEl) {
        countEl.textContent = visible + ' Entry' + (visible !== 1 ? 's' : '') + ' Visible';
    }
}

function setFilter(field, value) {
    if (field === 'category') {
        document.getElementById('catInput').value = value;
        document.getElementById('filterForm').submit();
    }
}
</script>

<?php renderBottomNav('ledger'); ?>
</body>
</html>
