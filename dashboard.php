<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$user_id = (int)$_SESSION['user_id'];
$userName = $_SESSION['name'] ?? 'User';
$profilePhoto = $_SESSION['profile_photo'] ?? 'default.png';
$joinedBs = $_SESSION['joined_date_bs'] ?? '';
$today_ad = date('Y-m-d');
$today_bs = NepaliDateConverter::convertAdToBs($today_ad);
$monthStart = date('Y-m-01');

$summaryQuery = "SELECT
 SUM(CASE WHEN transaction_type='starting_balance' THEN amount ELSE 0 END) starting_balance,
 SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END) income,
 SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END) expense,
 SUM(CASE WHEN transaction_type='lend' THEN amount ELSE 0 END) lend,
 SUM(CASE WHEN transaction_type='borrow' THEN amount ELSE 0 END) borrow,
 SUM(CASE WHEN transaction_type='investment' THEN amount ELSE 0 END) investment,
 SUM(CASE WHEN transaction_type='loss' THEN amount ELSE 0 END) loss
 FROM transactions WHERE user_id=?";
$stmt=$conn->prepare($summaryQuery); $stmt->bind_param('i',$user_id); $stmt->execute();
$totals=$stmt->get_result()->fetch_assoc() ?: [];
$starting=(float)($totals['starting_balance']??0); $income=(float)($totals['income']??0); $expense=(float)($totals['expense']??0);
$lend=(float)($totals['lend']??0); $borrow=(float)($totals['borrow']??0); $investment=(float)($totals['investment']??0); $loss=(float)($totals['loss']??0);
$balance=($starting+$income+$borrow)-($expense+$lend+$investment+$loss);

$monthQuery="SELECT
 SUM(CASE WHEN transaction_type='income' THEN amount ELSE 0 END) income,
 SUM(CASE WHEN transaction_type='expense' THEN amount ELSE 0 END) expense,
 COUNT(*) entries FROM transactions WHERE user_id=? AND date_ad>=?";
$stmt=$conn->prepare($monthQuery); $stmt->bind_param('is',$user_id,$monthStart); $stmt->execute(); $month=$stmt->get_result()->fetch_assoc() ?: [];
$monthIncome=(float)($month['income']??0); $monthExpense=(float)($month['expense']??0); $monthEntries=(int)($month['entries']??0);
$savingsRate=$monthIncome>0 ? max(0,min(100,(($monthIncome-$monthExpense)/$monthIncome)*100)) : 0;
$health=(int)max(0,min(100,50+($savingsRate*0.4)+($balance>0?15:0)-($borrow>0?5:0)));

$recent=$conn->prepare("SELECT id,transaction_type,subject,amount,date_bs,remarks FROM transactions WHERE user_id=? ORDER BY id DESC LIMIT 6");
$recent->bind_param('i',$user_id); $recent->execute(); $recentRows=$recent->get_result();

$top=$conn->prepare("SELECT subject,SUM(amount) total FROM transactions WHERE user_id=? AND transaction_type='expense' GROUP BY subject ORDER BY total DESC LIMIT 1");
$top->bind_param('i',$user_id); $top->execute(); $topExpense=$top->get_result()->fetch_assoc();

$labels=[
 'starting_balance'=>['Opening','start','＋'],'income'=>['Income','income','＋'],'expense'=>['Expense','expense','−'],
 'lend'=>['Lent','lend','−'],'borrow'=>['Borrowed','borrow','＋'],'investment'=>['Invested','investment','−'],'loss'=>['Loss','loss','−']
];
function money($n){ return 'Rs. '.number_format((float)$n,2); }
function e($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0f172a"><title>My Money — Dashboard</title>
<style>
:root{--bg:#f5f7fb;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#e8edf3;--primary:#2563eb;--dark:#0f172a;--green:#16a34a;--red:#dc2626;--amber:#d97706;--purple:#7c3aed;--blue:#0284c7}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--text);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;padding-bottom:92px;-webkit-font-smoothing:antialiased}.app{width:100%;max-width:520px;margin:auto}.top{position:sticky;top:0;z-index:20;background:rgba(245,247,251,.94);backdrop-filter:blur(16px);padding:12px 16px 10px;display:flex;align-items:center;justify-content:space-between}.profile{display:flex;align-items:center;gap:11px}.avatar{width:42px;height:42px;border-radius:14px;object-fit:cover;border:1px solid #dbe4f0;background:#e2e8f0}.hello{font-size:11px;color:var(--muted);margin:0 0 2px}.name{font-size:16px;font-weight:800;margin:0}.today{text-align:right;font-size:10px;color:var(--muted);line-height:1.5}.today strong{color:var(--text);font-size:11px}.wrap{padding:8px 16px 0}.hero{position:relative;overflow:hidden;background:linear-gradient(145deg,#172033,#0b1220);border-radius:24px;padding:22px;color:#fff;box-shadow:0 14px 30px rgba(15,23,42,.18)}.hero:after{content:"";position:absolute;width:150px;height:150px;border:1px solid rgba(255,255,255,.08);border-radius:50%;right:-50px;top:-50px}.eyebrow{font-size:11px;color:#aab5c7;font-weight:700;text-transform:uppercase;letter-spacing:.6px}.balance{font-size:30px;font-weight:850;letter-spacing:-1px;margin:5px 0 18px}.hero-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}.hero-stat{border-top:1px solid rgba(255,255,255,.1);padding-top:10px}.hero-stat small{display:block;color:#9ba8bb;font-size:10px}.hero-stat b{display:block;margin-top:3px;font-size:13px}.positive{color:#4ade80}.negative{color:#fb7185}.section-head{display:flex;align-items:center;justify-content:space-between;margin:20px 1px 10px}.section-head h2{font-size:15px;margin:0;font-weight:800}.section-head a{font-size:11px;color:var(--primary);font-weight:700;text-decoration:none}.quick{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.quick a{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:12px 6px;text-align:center;text-decoration:none;color:var(--text);box-shadow:0 3px 10px rgba(15,23,42,.03);transition:.15s}.quick a:active{transform:scale(.96)}.quick .ico{width:34px;height:34px;margin:0 auto 6px;border-radius:11px;display:grid;place-items:center;font-size:18px;background:#eff6ff}.quick span{font-size:10px;font-weight:750}.grid{display:grid;grid-template-columns:1fr 1fr;gap:9px}.stat{background:var(--card);border:1px solid var(--line);border-radius:17px;padding:14px;min-height:88px;box-shadow:0 3px 10px rgba(15,23,42,.025)}.stat .label{font-size:10px;color:var(--muted);font-weight:700}.stat .value{font-size:15px;font-weight:850;margin-top:5px}.income{color:var(--green)}.expense{color:var(--red)}.lend{color:var(--blue)}.borrow{color:var(--amber)}.investment{color:var(--purple)}.loss{color:#be123c}.progress-card,.recent,.insight{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:15px;box-shadow:0 3px 10px rgba(15,23,42,.025)}.month-row{display:flex;justify-content:space-between;align-items:flex-end}.month-row small{font-size:10px;color:var(--muted)}.month-row strong{font-size:18px}.bar{height:9px;background:#eef2f7;border-radius:20px;overflow:hidden;margin:12px 0 8px}.bar i{display:block;height:100%;width:<?= (int)$savingsRate ?>%;background:linear-gradient(90deg,#2563eb,#16a34a);border-radius:inherit}.subrow{display:flex;justify-content:space-between;font-size:10px;color:var(--muted)}.health{display:flex;align-items:center;gap:12px;margin-top:13px;padding-top:12px;border-top:1px solid var(--line)}.ring{width:48px;height:48px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--primary) <?= $health ?>%,#e9eef5 0)}.ring:after{content:"";width:38px;height:38px;border-radius:50%;background:#fff}.ring b{position:absolute;font-size:11px}.health strong{font-size:12px}.health p{font-size:10px;color:var(--muted);margin:3px 0 0}.recent-item{display:flex;align-items:center;gap:10px;padding:12px 0;border-bottom:1px solid #f0f3f7}.recent-item:last-child{border-bottom:0;padding-bottom:2px}.tx-icon{width:36px;height:36px;border-radius:12px;display:grid;place-items:center;font-size:14px;background:#f1f5f9}.tx-main{min-width:0;flex:1}.tx-main strong{display:block;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.tx-main small{display:block;color:#94a3b8;font-size:9px;margin-top:3px}.tx-amount{text-align:right;font-size:12px;font-weight:800}.tx-amount small{display:block;font-size:9px;color:#94a3b8;font-weight:500;margin-top:2px}.insight{display:flex;gap:12px;align-items:center}.insight-icon{width:42px;height:42px;border-radius:14px;background:#fff7ed;display:grid;place-items:center;font-size:19px}.insight h3{font-size:12px;margin:0}.insight p{font-size:10px;color:var(--muted);margin:4px 0 0;line-height:1.45}.bottom{position:fixed;left:0;right:0;bottom:0;height:72px;padding:8px max(12px,env(safe-area-inset-left)) calc(8px + env(safe-area-inset-bottom));background:rgba(255,255,255,.96);backdrop-filter:blur(18px);border-top:1px solid var(--line);display:flex;justify-content:space-around;align-items:center;z-index:50}.nav{width:25%;display:flex;flex-direction:column;align-items:center;gap:3px;color:#64748b;text-decoration:none;font-size:9px;font-weight:750}.nav svg{width:21px;height:21px;fill:currentColor}.nav.active{color:var(--primary)}.add{width:52px;height:52px;margin-top:-25px;border-radius:18px;background:var(--primary);color:#fff;display:grid;place-items:center;box-shadow:0 9px 20px rgba(37,99,235,.32);border:4px solid #f5f7fb}.add svg{width:25px;height:25px;fill:#fff}@media(min-width:700px){body{padding-bottom:30px}.app{max-width:900px}.wrap{display:grid;grid-template-columns:1.25fr .75fr;gap:14px}.hero,.quick,.section-head:first-child{grid-column:1/-1}.quick{grid-template-columns:repeat(4,1fr)}.bottom{max-width:520px;left:50%;right:auto;transform:translateX(-50%);border:1px solid var(--line);border-radius:22px 22px 0 0}}
</style>
</head><body>
<div class="app">
<header class="top"><div class="profile"><img class="avatar" src="uploads/<?=e($profilePhoto)?>" onerror="this.src='https://via.placeholder.com/42'" alt="Profile"><div><p class="hello">Good to see you 👋</p><h1 class="name"><?=e($userName)?></h1></div></div><div class="today"><strong><?=e($today_bs)?> BS</strong><br><?=e(date('d M Y'))?></div></header>
<main class="wrap">
<section class="hero"><div class="eyebrow">Available balance</div><div class="balance"><?=money($balance)?></div><div class="hero-row"><div class="hero-stat"><small>Income</small><b class="positive">+ <?=money($income)?></b></div><div class="hero-stat"><small>Outflow</small><b class="negative">− <?=money($expense+$lend+$investment+$loss)?></b></div></div></section>
<div class="section-head"><h2>Quick actions</h2><a href="ledger.php">View ledger →</a></div>
<div class="quick"><a href="add_entry.php"><div class="ico">＋</div><span>Add entry</span></a><a href="add_entry.php?type=income"><div class="ico">↗</div><span>Income</span></a><a href="add_entry.php?type=expense"><div class="ico">↘</div><span>Expense</span></a><a href="reports.php"><div class="ico">▥</div><span>Reports</span></a></div>
<div class="section-head"><h2>Money overview</h2><a href="reports.php">Insights →</a></div>
<div class="grid">
<div class="stat"><div class="label">Opening capital</div><div class="value"><?=money($starting)?></div></div>
<div class="stat"><div class="label">Income / profit</div><div class="value income"><?=money($income)?></div></div>
<div class="stat"><div class="label">Expenses</div><div class="value expense"><?=money($expense)?></div></div>
<div class="stat"><div class="label">Money lent</div><div class="value lend"><?=money($lend)?></div></div>
<div class="stat"><div class="label">Money borrowed</div><div class="value borrow"><?=money($borrow)?></div></div>
<div class="stat"><div class="label">Investments</div><div class="value investment"><?=money($investment)?></div></div>
</div>
<div class="section-head"><h2>This month</h2><span style="font-size:10px;color:#94a3b8"><?=e(date('F Y'))?></span></div>
<section class="progress-card"><div class="month-row"><div><small>Income</small><br><strong class="income">+<?=money($monthIncome)?></strong></div><div style="text-align:right"><small>Spent</small><br><strong class="expense">−<?=money($monthExpense)?></strong></div></div><div class="bar"><i></i></div><div class="subrow"><span><?=number_format($savingsRate,0)?>% income retained</span><span><?=$monthEntries?> entries</span></div><div class="health"><div class="ring"><b><?=$health?></b></div><div><strong>Financial health</strong><p><?= $health>=75?'Great control — keep it consistent.':($health>=50?'Healthy start — watch your spending.':'Needs attention — review your outflows.') ?></p></div></div></section>
<div class="section-head"><h2>Recent activity</h2><a href="ledger.php">See all →</a></div>
<section class="recent">
<?php if($recentRows->num_rows): while($r=$recentRows->fetch_assoc()): $meta=$labels[$r['transaction_type']]??['Transaction','income','•']; $isOut=in_array($r['transaction_type'],['expense','lend','investment','loss']); ?>
<div class="recent-item"><div class="tx-icon <?=e($meta[1])?>"><?=e($meta[2])?></div><div class="tx-main"><strong><?=e($r['subject'])?></strong><small><?=e($meta[0])?> • <?=e($r['date_bs'])?> BS</small></div><div class="tx-amount <?= $isOut?'expense':'income' ?>"><?= $isOut?'−':'+' ?><?=money($r['amount']) ?><small><?=e($r['remarks']?:'No note')?></small></div></div>
<?php endwhile; else: ?><div style="text-align:center;padding:18px;color:#94a3b8;font-size:11px">No transactions yet. Tap + to record your first one.</div><?php endif; ?></section>
<div class="section-head"><h2>Smart insight</h2></div>
<section class="insight"><div class="insight-icon">💡</div><div><h3><?= $topExpense ? 'Highest expense: '.e($topExpense['subject']) : 'Start building your money history' ?></h3><p><?= $topExpense ? money($topExpense['total']).' is your largest expense category. Review it in Reports to understand the trend.' : 'Add a few income and expense entries and the dashboard will calculate your monthly savings and financial health.' ?></p></div></section>
</main>
<nav class="bottom"><a class="nav active" href="dashboard.php"><svg viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/></svg>Home</a><a class="nav" href="ledger.php"><svg viewBox="0 0 24 24"><path d="M5 3h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2zm2 4v2h10V7zm0 4v2h7v-2zm0 4v2h10v-2z"/></svg>Ledger</a><a class="add" href="add_entry.php" aria-label="Add transaction"><svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6z"/></svg></a><a class="nav" href="reports.php"><svg viewBox="0 0 24 24"><path d="M4 19h16v2H2V3h2zm2-2V9h3v8zm5 0V5h3v12zm5 0v-5h3v5z"/></svg>Reports</a><a class="nav" href="logout.php"><svg viewBox="0 0 24 24"><path d="M10 17v2H5V5h5v2H7v10zm6-4h-5v-2h5l-2-2 1.4-1.4L20.8 12l-5.4 5.4L14 16z"/></svg>Logout</a></nav>
</div></body></html>