<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['name'] ?? 'User';
$profilePhoto = $_SESSION['profile_photo'] ?? 'default.png';
$joinedBs = $_SESSION['joined_date_bs'] ?? '—';
$todayAd = date('Y-m-d');
$todayBs = NepaliDateConverter::convertAdToBs($todayAd);
$period = $_GET['period'] ?? 'monthly';
$customStart = $_GET['start'] ?? '';
$customEnd = $_GET['end'] ?? '';

$validDate = static fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
$periodLabels = [
    'today' => 'Today', 'weekly' => 'Last 7 Days', 'monthly' => 'Last 30 Days',
    '6months' => 'Last 6 Months', 'yearly' => 'Last 1 Year', 'all' => 'All Time', 'custom' => 'Custom Range'
];
$period = array_key_exists($period, $periodLabels) ? $period : 'monthly';
$where = 'user_id = ?';
$types = 'i';
$params = [$userId];
$periodTitle = $periodLabels[$period];

if ($period === 'today') {
    $where .= ' AND date_ad = ?'; $types .= 's'; $params[] = $todayAd;
} elseif ($period === 'weekly') {
    $where .= ' AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)';
} elseif ($period === 'monthly') {
    $where .= ' AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)';
} elseif ($period === '6months') {
    $where .= ' AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)';
} elseif ($period === 'yearly') {
    $where .= ' AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)';
} elseif ($period === 'custom' && $validDate($customStart) && $validDate($customEnd) && $customStart <= $customEnd) {
    $where .= ' AND date_ad BETWEEN ? AND ?'; $types .= 'ss'; $params[] = $customStart; $params[] = $customEnd;
} elseif ($period === 'custom') {
    $period = 'monthly'; $periodTitle = $periodLabels['monthly'];
}

function fetchRows(mysqli $conn, string $sql, string $types, array $params): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) return [];
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

$categoryRows = fetchRows($conn, "SELECT transaction_type, COUNT(*) total_count, COALESCE(SUM(amount),0) total_amount FROM transactions WHERE $where GROUP BY transaction_type ORDER BY total_amount DESC", $types, $params);
$categories = [];
foreach ($categoryRows as $row) $categories[$row['transaction_type']] = ['count' => (int)$row['total_count'], 'amount' => (float)$row['total_amount']];
$getAmount = static fn ($key) => $categories[$key]['amount'] ?? 0;
$starting = $getAmount('starting_balance'); $income = $getAmount('income'); $expense = $getAmount('expense');
$lend = $getAmount('lend'); $borrow = $getAmount('borrow'); $invest = $getAmount('investment'); $loss = $getAmount('loss');
$totalInflow = $starting + $income + $borrow; $totalOutflow = $expense + $lend + $invest + $loss; $netLiquidity = $totalInflow - $totalOutflow;
$totalEntries = array_sum(array_column($categoryRows, 'total_count')); $grandTotal = array_sum(array_column($categoryRows, 'total_amount'));

$transactions = fetchRows($conn, "SELECT id, transaction_type, subject, amount, party_name, date_ad, date_bs, remarks FROM transactions WHERE $where ORDER BY date_ad DESC, id DESC", $types, $params);
$subjectRows = fetchRows($conn, "SELECT subject, transaction_type, COUNT(*) total_count, COALESCE(SUM(amount),0) total_amount FROM transactions WHERE $where GROUP BY subject, transaction_type ORDER BY total_amount DESC LIMIT 50", $types, $params);
$trendRows = fetchRows($conn, "SELECT DATE_FORMAT(date_ad, '%b %Y') label, DATE_FORMAT(date_ad, '%Y-%m') sort_key, COALESCE(SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END),0) income, COALESCE(SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END),0) expense FROM transactions WHERE user_id = ? AND date_ad >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY sort_key, label ORDER BY sort_key", 'i', [$userId]);

$score = 75;
if ($income + $starting > 0) { $burn = ($expense / ($income + $starting)) * 100; if ($burn > 75) $score -= 20; if ($invest / ($income + $starting) >= .2) $score += 15; }
if ($loss > 0) $score -= 10; $score = max(0, min(100, $score));
$grade = $score >= 85 ? 'A+ / Excellent' : ($score >= 70 ? 'B / Good' : ($score >= 50 ? 'C / Fair' : 'D / At Risk'));
$gradeTone = $score >= 70 ? 'positive' : ($score >= 50 ? 'warning' : 'danger');
$topicLabels = ['starting_balance'=>'Starting balance','income'=>'Income','expense'=>'Expense','lend'=>'Receivable','borrow'=>'Liability','investment'=>'Investment','loss'=>'Loss'];
$topicTone = ['starting_balance'=>'blue','income'=>'green','expense'=>'red','lend'=>'blue','borrow'=>'amber','investment'=>'violet','loss'=>'rose'];
$exportRows = array_map(static fn($row) => [$row['date_ad'], $row['date_bs'], $row['transaction_type'], $row['subject'], $row['amount'], $row['party_name'] ?? '', $row['remarks'] ?? ''], $transactions);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reports · AI Accountant</title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<style>
:root{--navy:#0f172a;--blue:#2563eb;--ink:#172033;--muted:#667085;--line:#e7ebf2;--surface:#fff;--bg:#f5f7fb;--green:#16a34a;--red:#dc2626;--amber:#d97706;--violet:#7c3aed}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif;line-height:1.5;padding-bottom:32px}.shell{max-width:1280px;margin:auto;padding:24px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:22px}.identity{display:flex;align-items:center;gap:12px}.avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;border:2px solid #dbe5ff}.eyebrow,.label{font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--muted)}h1,h2,h3,p{margin:0}.identity h1{font-size:18px}.identity p{font-size:12px;color:var(--muted)}.actions{display:flex;gap:8px;align-items:center}.button{border:1px solid var(--line);background:var(--surface);color:var(--ink);border-radius:9px;padding:10px 13px;font-weight:750;font-size:12px;text-decoration:none;cursor:pointer;transition:.18s}.button:hover{transform:translateY(-1px);box-shadow:0 5px 14px #16213d12}.button.primary{background:var(--blue);color:#fff;border-color:var(--blue)}.button.danger{color:var(--red)}.hero{background:var(--navy);color:#fff;border-radius:18px;padding:26px;margin-bottom:18px;box-shadow:0 12px 28px #0f172a24}.hero-head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:23px}.hero h2{font-size:25px;letter-spacing:-.03em}.hero-copy{color:#aab6ca;font-size:13px;margin-top:5px}.health{border:1px solid #ffffff26;border-radius:12px;padding:11px 14px;text-align:right}.health strong{display:block;font-size:18px;color:#fff}.health span{font-size:11px;color:#aab6ca}.metrics{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.metric{background:#ffffff0d;border:1px solid #ffffff16;border-radius:11px;padding:14px}.metric .label{color:#9eabc0}.metric strong{display:block;font-size:21px;margin-top:5px}.metric small{color:#9eabc0;font-size:11px}.filter-card,.card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:17px;margin-bottom:18px}.filter-card{display:flex;gap:10px;align-items:end;flex-wrap:wrap}.filter-card label{display:grid;gap:5px;font-size:11px;font-weight:750;color:var(--muted)}select,input{font:inherit;border:1px solid #dfe5ee;border-radius:8px;padding:10px 11px;background:#fff;color:var(--ink);font-size:12px}.custom-date{display:none}.custom-date.visible{display:grid}.grid-2{display:grid;grid-template-columns:1.4fr 1fr;gap:18px}.grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.card-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:15px}.card h3{font-size:14px}.card-note{font-size:11px;color:var(--muted)}.chart-wrap{height:245px}.allocation{display:flex;align-items:center;gap:18px}.allocation .chart-wrap{height:190px;width:52%}.legend{display:grid;gap:10px;flex:1}.legend-row{display:flex;align-items:center;gap:8px;font-size:12px}.dot{width:9px;height:9px;border-radius:50%}.legend-row strong{margin-left:auto}.table-scroll{overflow:auto}.data-table{width:100%;border-collapse:collapse;min-width:620px}.data-table th{text-align:left;color:var(--muted);font-size:10px;text-transform:uppercase;letter-spacing:.06em;padding:9px 8px;border-bottom:1px solid var(--line)}.data-table td{padding:12px 8px;border-bottom:1px solid #f0f2f6;font-size:12px}.data-table tr:last-child td{border-bottom:0}.amount{font-weight:800;text-align:right;white-space:nowrap}.pill{display:inline-flex;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:800}.pill.green{color:#147a38;background:#eaf8ef}.pill.red,.pill.rose{color:#b42318;background:#fff0ef}.pill.blue{color:#1d4ed8;background:#edf3ff}.pill.amber{color:#a15c00;background:#fff6df}.pill.violet{color:#6431c8;background:#f2edff}.progress{height:6px;background:#eef1f6;border-radius:10px;overflow:hidden;min-width:100px}.progress i{display:block;height:100%;border-radius:10px;background:var(--blue)}.insight{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid var(--line);font-size:12px}.insight:last-child{border-bottom:0}.search-row{display:flex;gap:9px;justify-content:space-between;align-items:center;margin-bottom:10px}.search-row input{max-width:250px;width:100%}.pagination{display:flex;justify-content:flex-end;gap:6px;margin-top:12px}.pagination button{border:1px solid var(--line);background:#fff;border-radius:7px;padding:6px 10px;font-size:11px;cursor:pointer}.pagination button.active{background:var(--navy);color:#fff}.bottom-nav{display:none}
@media(max-width:760px){.shell{padding:16px}.topbar{align-items:flex-start}.actions .button:not(.primary){display:none}.hero-head{display:block}.health{text-align:left;margin-top:16px;display:inline-block}.metrics{grid-template-columns:1fr 1fr}.grid-2,.grid-3{grid-template-columns:1fr}.filter-card{align-items:stretch}.filter-card label,.filter-card select,.filter-card input,.filter-card .button{width:100%}.allocation .chart-wrap{width:48%}.bottom-nav{display:flex;position:fixed;bottom:0;left:0;right:0;background:#fff;border-top:1px solid var(--line);justify-content:space-around;padding:11px;z-index:5}.bottom-nav a{font-size:11px;color:var(--muted);text-decoration:none}.bottom-nav a.active{color:var(--blue);font-weight:800}}
@media print{body{background:#fff;padding:0}.shell{max-width:none;padding:0}.topbar,.filter-card,.bottom-nav,.actions,.search-row input,.pagination{display:none!important}.hero{box-shadow:none;border-radius:0}.card{break-inside:avoid;box-shadow:none}.grid-2,.grid-3{gap:10px}.data-table{min-width:0}}
</style>
</head>
<body>
<div class="shell">
<header class="topbar"><div class="identity"><img class="avatar" src="uploads/<?= htmlspecialchars($profilePhoto) ?>" alt="<?= htmlspecialchars($userName) ?>" onerror="this.style.display='none'"><div><h1><?= htmlspecialchars($userName) ?></h1><p>Joined <?= htmlspecialchars($joinedBs) ?> BS · Today <?= htmlspecialchars($todayBs) ?> BS</p></div></div><div class="actions"><button class="button" onclick="window.print()">Print / PDF</button><button class="button primary" id="exportButton">Export CSV</button><a class="button danger" href="logout.php">Logout</a></div></header>
<main>
<section class="hero"><div class="hero-head"><div><div class="eyebrow">Financial command center</div><h2><?= htmlspecialchars($periodTitle) ?> overview</h2><p class="hero-copy">A clear read on liquidity, spending, and portfolio movement.</p></div><div class="health"><span>Financial health</span><strong><?= htmlspecialchars($grade) ?></strong><span><?= $score ?>/100 score</span></div></div><div class="metrics"><div class="metric"><span class="label">Net liquidity</span><strong>Rs. <?= number_format($netLiquidity,2) ?></strong><small>Inflow less outflow</small></div><div class="metric"><span class="label">Total income</span><strong>Rs. <?= number_format($income,2) ?></strong><small><?= $categories['income']['count'] ?? 0 ?> entries</small></div><div class="metric"><span class="label">Total expenses</span><strong>Rs. <?= number_format($expense,2) ?></strong><small><?= $categories['expense']['count'] ?? 0 ?> entries</small></div><div class="metric"><span class="label">Tracked volume</span><strong>Rs. <?= number_format($grandTotal,2) ?></strong><small><?= $totalEntries ?> transactions</small></div></div></section>
<form class="filter-card" method="get" id="filterForm"><label>Time period<select name="period" id="periodSelect"><option value="today">Today</option><option value="weekly">Last 7 Days</option><option value="monthly" selected>Last 30 Days</option><option value="6months">Last 6 Months</option><option value="yearly">Last 1 Year</option><option value="all">All Time</option><option value="custom">Custom AD Date Range</option></select></label><label class="custom-date" id="startWrap">From AD<input type="date" name="start" value="<?= htmlspecialchars($customStart) ?>"></label><label class="custom-date" id="endWrap">To AD<input type="date" name="end" value="<?= htmlspecialchars($customEnd) ?>"></label><button class="button primary" type="submit">Apply filters</button><span class="card-note">Showing <?= htmlspecialchars($periodTitle) ?></span></form>
<section class="grid-2"><div class="card"><div class="card-head"><h3>Income vs expense</h3><span class="card-note">Six-month trend</span></div><div class="chart-wrap"><canvas id="trendChart"></canvas></div></div><div class="card"><div class="card-head"><h3>Asset allocation</h3><span class="card-note">Current period</span></div><div class="allocation"><div class="chart-wrap"><canvas id="allocationChart"></canvas></div><div class="legend"><div class="legend-row"><i class="dot" style="background:#2563eb"></i>SIP / investments <strong>Rs. <?= number_format($invest,0) ?></strong></div><div class="legend-row"><i class="dot" style="background:#16a34a"></i>Starting balance <strong>Rs. <?= number_format($starting,0) ?></strong></div><div class="legend-row"><i class="dot" style="background:#d97706"></i>Receivables <strong>Rs. <?= number_format($lend,0) ?></strong></div></div></div></div></section>
<section class="grid-3"><div class="card"><div class="card-head"><h3>Spending by subject</h3><span class="card-note">Top categories</span></div><div class="chart-wrap"><canvas id="subjectChart"></canvas></div></div><div class="card"><div class="card-head"><h3>Cash flow mix</h3></div><?php foreach($topicLabels as $key=>$label): $amount=$getAmount($key); $share=$grandTotal > 0 ? min(100, ($amount/$grandTotal)*100) : 0; ?><div style="margin-bottom:13px"><div style="display:flex;justify-content:space-between;font-size:12px"><span><?= htmlspecialchars($label) ?></span><strong>Rs. <?= number_format($amount,0) ?></strong></div><div class="progress"><i style="width:<?= $share ?>%;background:<?= $key==='expense' || $key==='loss' ? 'var(--red)' : 'var(--blue)' ?>"></i></div></div><?php endforeach; ?></div><div class="card"><div class="card-head"><h3>Audit signals</h3><span class="pill <?= $gradeTone ?>"><?= $score >= 70 ? 'Stable' : 'Review' ?></span></div><div class="insight"><span class="pill <?= $expense > $income && $income > 0 ? 'red' : 'green' ?>">Cash</span><span><?= $expense > $income && $income > 0 ? 'Expenses exceed recorded income in this period.' : 'Cash flow is currently within recorded income.' ?></span></div><div class="insight"><span class="pill <?= $loss > 0 ? 'red' : 'green' ?>">Risk</span><span><?= $loss > 0 ? 'Loss entries need an active review.' : 'No loss entries recorded.' ?></span></div><div class="insight"><span class="pill blue">Scope</span><span><?= $totalEntries ?> ledger entries included in this report.</span></div></div></section>
<section class="card"><div class="card-head"><h3>Subject expenditure</h3><span class="card-note">Searchable summary</span></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Subject</th><th>Type</th><th>Entries</th><th>Share</th><th class="amount">Amount</th></tr></thead><tbody><?php foreach($subjectRows as $row): $share=$grandTotal>0 ? min(100,((float)$row['total_amount']/$grandTotal)*100):0; ?><tr><td><?= htmlspecialchars($row['subject']) ?></td><td><span class="pill <?= $topicTone[$row['transaction_type']] ?? 'blue' ?>"><?= htmlspecialchars($topicLabels[$row['transaction_type']] ?? ucfirst($row['transaction_type'])) ?></span></td><td><?= (int)$row['total_count'] ?></td><td><div class="progress"><i style="width:<?= $share ?>%"></i></div></td><td class="amount">Rs. <?= number_format((float)$row['total_amount'],2) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
<section class="card"><div class="card-head"><h3>Filtered transaction ledger</h3><span class="card-note"><?= count($transactions) ?> records</span></div><div class="search-row"><span class="card-note">Live search and pagination</span><input id="ledgerSearch" type="search" placeholder="Search subject or type"></div><div class="table-scroll"><table class="data-table" id="ledgerTable"><thead><tr><th>Date</th><th>Type</th><th>Subject</th><th>Party</th><th>Remarks</th><th class="amount">Amount</th></tr></thead><tbody><?php foreach($transactions as $row): ?><tr><td><?= htmlspecialchars($row['date_ad']) ?><br><small class="card-note"><?= htmlspecialchars($row['date_bs']) ?></small></td><td><span class="pill <?= $topicTone[$row['transaction_type']] ?? 'blue' ?>"><?= htmlspecialchars($topicLabels[$row['transaction_type']] ?? ucfirst($row['transaction_type'])) ?></span></td><td><?= htmlspecialchars($row['subject']) ?></td><td><?= htmlspecialchars($row['party_name'] ?? '—') ?></td><td><?= htmlspecialchars($row['remarks'] ?? '—') ?></td><td class="amount">Rs. <?= number_format((float)$row['amount'],2) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="pagination" id="pagination"></div></section>
</main></div><nav class="bottom-nav"><a class="active" href="reports.php">Reports</a><a href="dashboard.php">Dashboard</a><a href="add_transaction.php">Add transaction</a></nav>
<script>
const trendData = <?= json_encode($trendRows, JSON_UNESCAPED_SLASHES) ?>, subjectData = <?= json_encode(array_slice($subjectRows,0,8), JSON_UNESCAPED_SLASHES) ?>;
const chartOpts={responsive:true,maintainAspectRatio:false,plugins:{legend:{labels:{boxWidth:10,font:{size:10}}}},scales:{x:{grid:{display:false},ticks:{font:{size:10}}},y:{grid:{color:'#eef1f6'},ticks:{font:{size:10}}}}};
new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:trendData.map(x=>x.label),datasets:[{label:'Income',data:trendData.map(x=>x.income),borderColor:'#16a34a',backgroundColor:'#16a34a18',fill:true,tension:.35},{label:'Expense',data:trendData.map(x=>x.expense),borderColor:'#dc2626',backgroundColor:'#dc262618',fill:true,tension:.35}]},options:chartOpts});
new Chart(document.getElementById('allocationChart'),{type:'doughnut',data:{labels:['Investments','Starting balance','Receivables'],datasets:[{data:[<?= $invest ?>,<?= $starting ?>,<?= $lend ?>],backgroundColor:['#2563eb','#16a34a','#d97706'],borderWidth:0}]},options:{responsive:true,maintainAspectRatio:false,cutout:'70%',plugins:{legend:{display:false}}}});
new Chart(document.getElementById('subjectChart'),{type:'bar',data:{labels:subjectData.map(x=>x.subject),datasets:[{label:'Amount',data:subjectData.map(x=>x.total_amount),backgroundColor:'#2563eb',borderRadius:5}]},options:{...chartOpts,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{grid:{color:'#eef1f6'},ticks:{font:{size:9}}},y:{grid:{display:false},ticks:{font:{size:9}}}}}});
const periodSelect=document.getElementById('periodSelect'); periodSelect.value='<?= htmlspecialchars($period) ?>'; const toggleDates=()=>document.querySelectorAll('.custom-date').forEach(x=>x.classList.toggle('visible',periodSelect.value==='custom')); periodSelect.addEventListener('change',toggleDates); toggleDates();
const rows=[...document.querySelectorAll('#ledgerTable tbody tr')], search=document.getElementById('ledgerSearch'), pager=document.getElementById('pagination'); let page=1, perPage=8; function render(){const q=search.value.toLowerCase(), filtered=rows.filter(r=>r.innerText.toLowerCase().includes(q)), pages=Math.max(1,Math.ceil(filtered.length/perPage)); page=Math.min(page,pages); rows.forEach(r=>r.style.display='none'); filtered.slice((page-1)*perPage,page*perPage).forEach(r=>r.style.display=''); pager.innerHTML=''; for(let i=1;i<=pages;i++){const b=document.createElement('button');b.textContent=i;b.className=i===page?'active':'';b.onclick=()=>{page=i;render()};pager.appendChild(b)}} search.addEventListener('input',()=>{page=1;render()}); render();
const exportRows=<?= json_encode($exportRows, JSON_UNESCAPED_SLASHES) ?>; document.getElementById('exportButton').onclick=()=>{const csv=[['Date AD','Date BS','Type','Subject','Amount','Party','Remarks'],...exportRows].map(r=>r.map(v=>'"'+String(v??'').replaceAll('"','""')+'"').join(',')).join('\n');const a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='accountant-<?= $period ?>-report.csv';a.click();URL.revokeObjectURL(a.href)};
</script></body></html>
