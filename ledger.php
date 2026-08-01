<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';
require_once 'partials/app_layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id  = $_SESSION['user_id'];
$topicConfig = topicConfig();

// ----------------------------------------------------
// Schema safety: detect optional columns
// ----------------------------------------------------
$hasPartyCol = columnExists($conn, 'transactions', 'party_name');
$notesCol    = notesColumn($conn);
$notesSelect = $notesCol !== '' ? ", `{$notesCol}` AS notes" : ", '' AS notes";
$partySelect = $hasPartyCol ? "party_name" : "'' AS party_name";

// ----------------------------------------------------
// Server-side filter parameters
// ----------------------------------------------------
$search  = trim($_GET['search']  ?? '');
$catFilt = trim($_GET['category'] ?? '');
$partyFilt = trim($_GET['party'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo   = trim($_GET['date_to']   ?? '');

// Build WHERE clause dynamically
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

if ($catFilt !== '' && in_array($catFilt, array_keys($topicConfig))) {
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
// Fetch transactions with filters
// ----------------------------------------------------
$query = "SELECT id, transaction_type, subject, amount, {$partySelect}, date_ad, date_bs, created_at{$notesSelect}
    FROM transactions
    WHERE {$whereClause}
    ORDER BY id DESC";

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($bindTypes, ...$bindVals);
    $stmt->execute();
    $transactions = $stmt->get_result();
} else {
    // Fallback without optional columns
    $query = "SELECT id, transaction_type, subject, amount, '' AS party_name, date_ad, date_bs, created_at, '' AS notes
        FROM transactions WHERE user_id = ? ORDER BY id DESC";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $transactions = $stmt->get_result();
}

// Collect all transactions into array for JS instant filtering
$allTxns = [];
while ($row = $transactions->fetch_assoc()) {
    $allTxns[] = $row;
}
$stmt->close();

// ----------------------------------------------------
// Fetch distinct party names for filter dropdown
// ----------------------------------------------------
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

// Summary for current filter
$filterCount = count($allTxns);
$filterTotal = 0;
foreach ($allTxns as $t) $filterTotal += (float)$t['amount'];

$today_bs = NepaliDateConverter::todayBs();

renderAppHead('Transaction Ledger', '<style>.filter-panel{background:var(--c-surface);border-radius:var(--r-lg);padding:14px;margin-bottom:16px;border:1px solid var(--c-border-light);box-shadow:var(--shadow-md)}.date-range{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}.txn-count{font-size:11px;color:var(--c-text-3);font-weight:600;margin-bottom:12px;padding:0 4px}.txn-list .txn-item{cursor:pointer}.party-select{width:100%;padding:10px;font-size:13px;border:1px solid var(--c-border);border-radius:var(--r-md);background:var(--c-surface);margin-top:6px}.clear-btn{font-size:11px;color:var(--c-expense);text-decoration:none;font-weight:600;margin-left:8px}</style>');
renderAppHeader($_SESSION['name'] ?? 'User', $_SESSION['profile_photo'] ?? 'default.png', $_SESSION['joined_date_bs'] ?? '2083-04-16', $today_bs);
?>

<div class="container">

    <!-- ===== Search & Filter Panel ===== -->
    <div class="filter-panel">
        <!-- Instant Text Search -->
        <form method="GET" action="ledger.php" id="filterForm">
            <div class="search-bar">
                <span class="search-icon">🔍</span>
                <input type="text" name="search" id="searchInput"
                       placeholder="Search subject, notes..."
                       value="<?= htmlspecialchars($search) ?>"
                       oninput="instantFilter()">
            </div>

            <!-- Category Filter Chips -->
            <div class="filter-chips">
                <span class="chip <?= $catFilt === '' ? 'active' : '' ?>" onclick="setFilter('category','')">
                    All
                </span>
                <?php foreach ($topicConfig as $key => $cfg): ?>
                    <span class="chip <?= $catFilt === $key ? 'active' : '' ?>" onclick="setFilter('category','<?= $key ?>')">
                        <?= $cfg['icon'] ?> <?= ucwords(str_replace('_', ' ', $key)) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <!-- Party Filter Dropdown -->
            <?php if (!empty($parties)): ?>
            <select name="party" class="party-select" onchange="this.form.submit()">
                <option value="">All Parties</option>
                <?php foreach ($parties as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>" <?= $partyFilt === $p ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php endif; ?>

            <!-- Date Range Picker -->
            <div class="date-range">
                <div>
                    <label style="font-size:10px;color:var(--c-text-3);font-weight:600;">From Date</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>"
                           style="width:100%;padding:8px;font-size:12px;border:1px solid var(--c-border);border-radius:var(--r-sm);">
                </div>
                <div>
                    <label style="font-size:10px;color:var(--c-text-3);font-weight:600;">To Date</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>"
                           style="width:100%;padding:8px;font-size:12px;border:1px solid var(--c-border);border-radius:var(--r-sm);">
                </div>
            </div>

            <div style="margin-top: 10px; display: flex; justify-content: space-between; align-items: center;">
                <button type="submit" class="btn-submit" style="width:auto;padding:8px 20px;font-size:13px;">Apply Filters</button>
                <?php if ($search || $catFilt || $partyFilt || $dateFrom || $dateTo): ?>
                    <a href="ledger.php" class="clear-btn">✕ Clear All</a>
                <?php endif; ?>
            </div>

            <!-- Hidden category field for chip-based selection -->
            <input type="hidden" name="category" id="catInput" value="<?= htmlspecialchars($catFilt) ?>">
        </form>
    </div>

    <!-- ===== Results Count ===== -->
    <div class="txn-count">
        <?= $filterCount ?> transaction<?= $filterCount !== 1 ? 's' : '' ?>
        &middot; Total: Rs. <?= number_format($filterTotal, 2) ?>
    </div>

    <!-- ===== Transaction List ===== -->
    <div class="card txn-list" id="txnList">
        <?php if (!empty($allTxns)): ?>
            <div class="txn-stream">
                <?php foreach ($allTxns as $row):
                    $cfg = $topicConfig[$row['transaction_type']] ?? ['icon' => '💳', 'badge' => 'badge-income', 'color' => '#64748b'];
                    $isInflow = in_array($row['transaction_type'], ['income', 'starting_balance', 'borrow']);
                    $sign = $isInflow ? '+' : '-';
                    $amtColor = $isInflow ? 'var(--c-income)' : 'var(--c-expense)';
                    if ($row['transaction_type'] === 'lend') $amtColor = 'var(--c-lend)';
                    if ($row['transaction_type'] === 'investment') $amtColor = 'var(--c-invest)';
                    if ($row['transaction_type'] === 'loss') $amtColor = 'var(--c-loss)';
                    $partyText = !empty($row['party_name']) ? ' &middot; ' . htmlspecialchars($row['party_name']) : '';
                    $noteText = !empty($row['notes']) ? htmlspecialchars($row['notes']) : 'No notes';
                ?>
                    <div class="txn-item"
                         data-search="<?= htmlspecialchars(strtolower($row['subject'] . ' ' . $row['notes'] . ' ' . $row['party_name'])) ?>"
                         data-type="<?= $row['transaction_type'] ?>">
                        <div class="txn-icon" style="background: <?= $cfg['color'] ?>1a; color: <?= $cfg['color'] ?>;">
                            <?= $cfg['icon'] ?>
                        </div>
                        <div class="txn-body">
                            <strong><?= htmlspecialchars($row['subject']) ?><?= $partyText ?></strong>
                            <small><?= htmlspecialchars($row['date_bs']) ?> BS &middot; <?= htmlspecialchars($row['date_ad']) ?> AD</small>
                            <small style="display:block;margin-top:2px;"><?= $noteText ?></small>
                        </div>
                        <div class="txn-amount">
                            <div class="amt" style="color: <?= $amtColor ?>;"><?= $sign ?> Rs. <?= number_format($row['amount'], 0) ?></div>
                            <span class="badge <?= $cfg['badge'] ?>"><?= $row['transaction_type'] ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🔍</div>
                No transactions found matching your filters.<br>
                <a href="ledger.php" style="color: var(--c-primary); font-weight: 600;">Clear filters</a> or <a href="add_entry.php" style="color: var(--c-primary); font-weight: 600;">add a new entry</a>.
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
// Instant client-side search filtering (no page reload)
function instantFilter() {
    const query = document.getElementById('searchInput').value.toLowerCase().trim();
    const items = document.querySelectorAll('#txnList .txn-item');
    let visible = 0;

    items.forEach(item => {
        const searchText = item.dataset.search || '';
        if (query === '' || searchText.includes(query)) {
            item.style.display = '';
            visible++;
        } else {
            item.style.display = 'none';
        }
    });

    // Update count
    const countEl = document.querySelector('.txn-count');
    if (countEl) {
        countEl.textContent = visible + ' transactions visible';
    }
}

// Chip-based category selection (submits form)
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
