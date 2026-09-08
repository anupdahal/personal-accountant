<?php
session_start(); require_once 'db.php'; require_once 'nepali_date.php';
if(!isset($_SESSION['user_id'])){header('Location: login.php');exit();}
$user_id=(int)$_SESSION['user_id']; $message='';
$type=$_GET['type']??'expense';
$allowed=['starting_balance','income','expense','lend','borrow','investment','loss']; if(!in_array($type,$allowed,true))$type='expense';
if($_SERVER['REQUEST_METHOD']==='POST'){
 $type=$_POST['transaction_type']??'expense'; $subject=trim($_POST['subject']??''); $amount=(float)($_POST['amount']??0); $remarks=trim($_POST['remarks']??'');
 $date_ad=date('Y-m-d'); $date_bs=NepaliDateConverter::convertAdToBs($date_ad);
 if(!in_array($type,$allowed,true))$message='Invalid transaction category.';
 elseif($subject===''||$amount<=0)$message='Please enter a subject and a valid amount.';
 else{
  $stmt=$conn->prepare("INSERT INTO transactions (user_id,transaction_type,subject,amount,date_ad,date_bs,remarks) VALUES (?,?,?,?,?,?,?)");
  $stmt->bind_param('issdsss',$user_id,$type,$subject,$amount,$date_ad,$date_bs,$remarks);
  if($stmt->execute()){header('Location: dashboard.php');exit();} $message='Unable to save this entry. Please try again.';
 }
}
$names=['starting_balance'=>'Opening balance','income'=>'Income','expense'=>'Expense','lend'=>'Money lent','borrow'=>'Money borrowed','investment'=>'Investment','loss'=>'Loss'];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#0f172a"><title>Add Transaction — My Money</title>
<style>:root{--bg:#f5f7fb;--card:#fff;--text:#0f172a;--muted:#64748b;--line:#e5eaf1;--blue:#2563eb}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:var(--text);padding-bottom:30px}.app{max-width:520px;margin:auto}.top{padding:15px 16px;display:flex;align-items:center;gap:12px}.back{text-decoration:none;width:40px;height:40px;border-radius:13px;background:#fff;border:1px solid var(--line);display:grid;place-items:center;color:var(--text);font-size:21px}.top h1{font-size:18px;margin:0}.top p{font-size:10px;color:var(--muted);margin:3px 0 0}.card{background:#fff;margin:4px 16px;padding:18px;border-radius:22px;border:1px solid var(--line);box-shadow:0 8px 25px rgba(15,23,42,.05)}.label{font-size:11px;font-weight:800;color:#475569;display:block;margin:0 0 7px}.types{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:17px}.types label{cursor:pointer}.types input{display:none}.type{display:flex;align-items:center;gap:8px;padding:12px;border:1px solid var(--line);border-radius:14px;font-size:11px;font-weight:750;background:#f8fafc}.type b{width:28px;height:28px;border-radius:9px;display:grid;place-items:center;background:#eaf1ff;font-size:15px}.types input:checked+.type{border-color:#2563eb;background:#eff6ff;box-shadow:0 0 0 2px rgba(37,99,235,.08)}.field{margin-bottom:15px}.field input,.field textarea{width:100%;border:1px solid #d7dee8;background:#f8fafc;border-radius:13px;padding:14px;font-size:15px;outline:none;color:var(--text)}.field input:focus,.field textarea:focus{background:#fff;border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.09)}.amount-wrap{position:relative}.amount-wrap span{position:absolute;left:14px;top:14px;font-weight:800;color:#64748b}.amount-wrap input{padding-left:55px;font-size:21px;font-weight:800}.hint{font-size:9px;color:#94a3b8;margin-top:5px}.alert{padding:11px 12px;border-radius:12px;background:#fff1f2;color:#be123c;font-size:11px;margin-bottom:14px}.save{width:100%;border:0;border-radius:14px;padding:15px;background:var(--blue);color:#fff;font-size:15px;font-weight:800;box-shadow:0 9px 20px rgba(37,99,235,.24);cursor:pointer}.save:active{transform:scale(.98)}.date{margin-top:12px;text-align:center;font-size:10px;color:#94a3b8}.nav{margin:14px 16px;text-align:center}.nav a{color:#2563eb;text-decoration:none;font-size:11px;font-weight:700}</style></head><body><div class="app">
<header class="top"><a class="back" href="dashboard.php">‹</a><div><h1>New transaction</h1><p>Record your money in a few taps</p></div></header>
<form class="card" method="post" action="add_entry.php">
<?php if($message):?><div class="alert"><?=htmlspecialchars($message)?></div><?php endif;?>
<label class="label">What kind of transaction?</label><div class="types">
<?php $icons=['starting_balance'=>'◉','income'=>'↗','expense'=>'↘','lend'=>'→','borrow'=>'←','investment'=>'◆','loss'=>'!']; foreach($names as $key=>$label):?><label><input type="radio" name="transaction_type" value="<?=$key?>" <?=$type===$key?'checked':''?>><span class="type"><b><?=$icons[$key]?></b><?=$label?></span></label><?php endforeach;?></div>
<div class="field"><label class="label">Description</label><input name="subject" maxlength="100" placeholder="e.g. Salary, Petrol, NABIL shares" value="<?=htmlspecialchars($_POST['subject']??'')?>" required></div>
<div class="field"><label class="label">Amount</label><div class="amount-wrap"><span>Rs.</span><input type="number" name="amount" step="0.01" min="0.01" inputmode="decimal" placeholder="0.00" value="<?=htmlspecialchars($_POST['amount']??'')?>" required></div><div class="hint">Enter the exact amount in Nepalese Rupees.</div></div>
<div class="field"><label class="label">Note <span style="font-weight:500;color:#94a3b8">(optional)</span></label><textarea name="remarks" rows="3" maxlength="500" placeholder="Bill number, person name, account, or any useful note..."><?=htmlspecialchars($_POST['remarks']??'')?></textarea></div>
<button class="save" type="submit">Save transaction</button><div class="date">Date will be saved automatically • <?=$date_bs??NepaliDateConverter::convertAdToBs(date('Y-m-d'))?> BS</div>
</form><div class="nav"><a href="ledger.php">View transaction history →</a></div></div></body></html>