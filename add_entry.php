<?php
session_start();
require_once 'db.php';
require_once 'nepali_date.php';
require_once 'partials/app_layout.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$message = '';
$msgType = 'danger';

// Pre-select type from query param
$preType = $_GET['type'] ?? '';
$validTypes = [
    'starting_balance', 'income', 'expense', 
    'lend', 'lend_repayment', 'borrow', 'borrow_repayment', 
    'investment', 'investment_sell', 'loss', 'ssf'
];
if (!in_array($preType, $validTypes)) $preType = '';

$topicConfig = function_exists('topicConfig') ? topicConfig() : [];

// Fetch open entries for linked settlement dropdowns
$openLends = $conn->query("SELECT id, subject, amount, party_name FROM transactions WHERE user_id = {$user_id} AND transaction_type = 'lend' AND status != 'settled' ORDER BY id DESC");
$openBorrows = $conn->query("SELECT id, subject, amount, party_name FROM transactions WHERE user_id = {$user_id} AND transaction_type = 'borrow' AND status != 'settled' ORDER BY id DESC");
$openInvestments = $conn->query("SELECT id, subject, amount, investment_type FROM transactions WHERE user_id = {$user_id} AND transaction_type = 'investment' AND status != 'settled' ORDER BY id DESC");

// ----------------------------------------------------
// Handle POST
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type             = $_POST['transaction_type'] ?? '';
    $subject          = trim($_POST['subject'] ?? '');
    $amount           = (float)($_POST['amount'] ?? 0);
    $paymentMethod    = $_POST['payment_method'] ?? 'bank_digital';
    
    // Automatically flag SSF investment_type if transaction_type is 'ssf'
    if ($type === 'ssf') {
        $investmentType = 'ssf';
    } else {
        $investmentType = !empty($_POST['investment_type']) ? $_POST['investment_type'] : null;
    }

    $party            = trim($_POST['party_name'] ?? '');
    $relatedTxnId     = !empty($_POST['related_transaction_id']) ? (int)$_POST['related_transaction_id'] : null;
    $notes            = trim($_POST['notes'] ?? '');
    $dateAd           = trim($_POST['date_ad'] ?? date('Y-m-d'));
    $isBadDebt        = isset($_POST['is_bad_debt']) && $_POST['is_bad_debt'] === '1';

    if (!in_array($type, $validTypes)) {
        $message = 'Invalid transaction type selected.';
    } elseif (empty($subject) && $type !== 'investment_sell' && $type !== 'lend_repayment' && $type !== 'borrow_repayment') {
        $message = 'Please enter a subject / description.';
    } elseif ($amount <= 0 && !$isBadDebt) {
        $message = 'Amount must be greater than zero.';
    } else {
        $conn->begin_transaction();
        try {
            $date_bs = NepaliDateConverter::convertAdToBs($dateAd);

            // 1. WORKFLOW: LEND REPAYMENT & BAD DEBT
            if ($type === 'lend_repayment' && $relatedTxnId) {
                $stmt = $conn->prepare("SELECT amount, subject, party_name FROM transactions WHERE id = ? AND user_id = ? FOR UPDATE");
                $stmt->bind_param("ii", $relatedTxnId, $user_id);
                $stmt->execute();
                $parent = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($parent) {
                    $stmtRep = $conn->prepare("SELECT SUM(amount) AS total_repaid FROM transactions WHERE related_transaction_id = ? AND transaction_type IN ('lend_repayment', 'loss')");
                    $stmtRep->bind_param("i", $relatedTxnId);
                    $stmtRep->execute();
                    $alreadyRepaid = (float)($stmtRep->get_result()->fetch_assoc()['total_repaid'] ?? 0);
                    $stmtRep->close();

                    $remainingBalance = (float)$parent['amount'] - $alreadyRepaid;
                    $partyName = !empty($party) ? $party : $parent['party_name'];

                    if ($isBadDebt) {
                        $lossAmount = $amount > 0 ? min($amount, $remainingBalance) : $remainingBalance;
                        $sql = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, party_name, related_transaction_id, date_ad, date_bs, notes, status) VALUES (?, 'loss', ?, ?, ?, ?, ?, ?, ?, ?, 'written_off')";
                        $stmt = $conn->prepare($sql);
                        $badDebtSubj = "Bad Debt Write-off: " . ($subject ?: $parent['subject']);
                        $stmt->bind_param("isdsissss", $user_id, $badDebtSubj, $lossAmount, $paymentMethod, $partyName, $relatedTxnId, $dateAd, $date_bs, $notes);
                        $stmt->execute();

                        $newStatus = ($alreadyRepaid + $lossAmount >= $parent['amount']) ? 'written_off' : 'partially_settled';
                        $stmtUpd = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
                        $stmtUpd->bind_param("si", $newStatus, $relatedTxnId);
                        $stmtUpd->execute();
                    } else {
                        $sql = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, party_name, related_transaction_id, date_ad, date_bs, notes, status) VALUES (?, 'lend_repayment', ?, ?, ?, ?, ?, ?, ?, ?, 'settled')";
                        $stmt = $conn->prepare($sql);
                        $repSubj = $subject ?: ("Repayment for " . $parent['subject']);
                        $stmt->bind_param("isdsissss", $user_id, $repSubj, $amount, $paymentMethod, $partyName, $relatedTxnId, $dateAd, $date_bs, $notes);
                        $stmt->execute();

                        $newTotal = $alreadyRepaid + $amount;
                        $newStatus = ($newTotal >= $parent['amount']) ? 'settled' : 'partially_settled';
                        $stmtUpd = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
                        $stmtUpd->bind_param("si", $newStatus, $relatedTxnId);
                        $stmtUpd->execute();
                    }
                }
            }
            // 2. WORKFLOW: BORROW REPAYMENT
            elseif ($type === 'borrow_repayment' && $relatedTxnId) {
                $stmt = $conn->prepare("SELECT amount, subject, party_name FROM transactions WHERE id = ? AND user_id = ? FOR UPDATE");
                $stmt->bind_param("ii", $relatedTxnId, $user_id);
                $stmt->execute();
                $parent = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($parent) {
                    $stmtRep = $conn->prepare("SELECT SUM(amount) AS total_repaid FROM transactions WHERE related_transaction_id = ? AND transaction_type = 'borrow_repayment'");
                    $stmtRep->bind_param("i", $relatedTxnId);
                    $stmtRep->execute();
                    $alreadyPaid = (float)($stmtRep->get_result()->fetch_assoc()['total_repaid'] ?? 0);
                    $stmtRep->close();

                    $partyName = !empty($party) ? $party : $parent['party_name'];
                    $repSubj = $subject ?: ("Paid Back: " . $parent['subject']);

                    $sql = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, party_name, related_transaction_id, date_ad, date_bs, notes, status) VALUES (?, 'borrow_repayment', ?, ?, ?, ?, ?, ?, ?, ?, 'settled')";
                    $stmt = $conn->prepare($sql);
                    $stmt->bind_param("isdsissss", $user_id, $repSubj, $amount, $paymentMethod, $partyName, $relatedTxnId, $dateAd, $date_bs, $notes);
                    $stmt->execute();

                    $newTotal = $alreadyPaid + $amount;
                    $newStatus = ($newTotal >= $parent['amount']) ? 'settled' : 'partially_settled';
                    $stmtUpd = $conn->prepare("UPDATE transactions SET status = ? WHERE id = ?");
                    $stmtUpd->bind_param("si", $newStatus, $relatedTxnId);
                    $stmtUpd->execute();
                }
            }
            // 3. WORKFLOW: SELLING SHARES / INVESTMENT LIQUIDATION
            elseif ($type === 'investment_sell' && $relatedTxnId) {
                $stmt = $conn->prepare("SELECT amount, subject FROM transactions WHERE id = ? AND user_id = ? FOR UPDATE");
                $stmt->bind_param("ii", $relatedTxnId, $user_id);
                $stmt->execute();
                $inv = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if ($inv) {
                    $costBasis = (float)$inv['amount'];
                    $returnAmount = $amount;

                    if ($returnAmount < $costBasis) {
                        $lossVal = $costBasis - $returnAmount;

                        // Return liquidity to Income
                        $incSubj = $subject ?: ("Share Liquidation Return: " . $inv['subject']);
                        $sqlInc = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, related_transaction_id, date_ad, date_bs, notes, status) VALUES (?, 'income', ?, ?, ?, ?, ?, ?, ?, 'settled')";
                        $stmt = $conn->prepare($sqlInc);
                        $stmt->bind_param("isdissss", $user_id, $incSubj, $returnAmount, $paymentMethod, $relatedTxnId, $dateAd, $date_bs, $notes);
                        $stmt->execute();

                        // Log realized loss
                        $lossSubj = "Realized Loss on Share Sale: " . $inv['subject'];
                        $sqlLoss = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, related_transaction_id, date_ad, date_bs, notes, status) VALUES (?, 'loss', ?, ?, ?, ?, ?, ?, ?, 'settled')";
                        $stmt = $conn->prepare($sqlLoss);
                        $stmt->bind_param("isdissss", $user_id, $lossSubj, $lossVal, $paymentMethod, $relatedTxnId, $dateAd, $date_bs, $notes);
                        $stmt->execute();
                    } else {
                        // Profit or Break-even
                        $incSubj = $subject ?: ("Share Sold Return: " . $inv['subject']);
                        $sqlInc = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, related_transaction_id, date_ad, date_bs, notes, status) VALUES (?, 'income', ?, ?, ?, ?, ?, ?, ?, 'settled')";
                        $stmt = $conn->prepare($sqlInc);
                        $stmt->bind_param("isdissss", $user_id, $incSubj, $returnAmount, $paymentMethod, $relatedTxnId, $dateAd, $date_bs, $notes);
                        $stmt->execute();
                    }

                    // Mark investment position as settled
                    $stmtUpd = $conn->prepare("UPDATE transactions SET status = 'settled' WHERE id = ?");
                    $stmtUpd->bind_param("i", $relatedTxnId);
                    $stmtUpd->execute();
                }
            }
            // 4. STANDARD DIRECT TRANSACTION (Includes Investment Entries & SSF)
            else {
                $sql = "INSERT INTO transactions (user_id, transaction_type, subject, amount, payment_method, investment_type, party_name, date_ad, date_bs, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open')";
                $stmt = $conn->prepare($sql);
                // Corrected bind_param string to exactly match parameter types: i s s d s s s s s s (10 parameters)
                $stmt->bind_param("issdssssss", $user_id, $type, $subject, $amount, $paymentMethod, $investmentType, $party, $dateAd, $date_bs, $notes);
                $stmt->execute();
            }

            $conn->commit();
            header("Location: dashboard.php?added=1");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message = "Error processing entry: " . $e->getMessage();
        }
    }
}

$today_bs = NepaliDateConverter::todayBs();

renderAppHead('New Financial Entry', '
<style>
  :root {
    --bg-page: #f8fafc;
    --card-bg: #ffffff;
    --border-color: #cbd5e1;
    --border-light: #f1f5f9;
    --text-dark: #0f172a;
    --text-sub: #475569;
    --text-muted: #64748b;
    --brand-blue: #2563eb;
    --brand-red: #dc2626;
  }

  .form-container { max-width: 620px; margin: 0 auto; padding: 0 12px 100px 12px; }

  .entry-card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 14px;
    padding: 20px;
    margin-bottom: 16px;
  }

  .card-header-title {
    font-size: 16px;
    font-weight: 800;
    color: var(--text-dark);
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
  }

  .type-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 6px;
    margin-bottom: 16px;
  }
  @media (max-width: 520px) {
    .type-grid { grid-template-columns: repeat(4, 1fr); gap: 6px; }
  }

  .type-chip {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px 4px;
    border-radius: 10px;
    border: 1px solid #e2e8f0;
    background: #f8fafc;
    cursor: pointer;
    transition: all 0.15s ease;
    user-select: none;
  }
  .type-chip:hover { border-color: #cbd5e1; background: #ffffff; }
  .type-chip.selected {
    border-color: #0f172a;
    background: #0f172a;
    box-shadow: 0 2px 4px rgba(15,23,42,0.12);
  }
  .type-chip .tc-icon { font-size: 18px; }
  .type-chip .tc-label {
    font-size: 9px;
    font-weight: 800;
    color: var(--text-sub);
    text-align: center;
    line-height: 1.1;
  }
  .type-chip.selected .tc-label { color: #ffffff; }

  .segmented-control {
    display: flex;
    gap: 8px;
    margin-top: 4px;
  }
  .segmented-control label {
    flex: 1;
    text-align: center;
    padding: 10px;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #f8fafc;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
    color: var(--text-sub);
    transition: all 0.15s;
  }
  .segmented-control label:has(input:checked) {
    border-color: #0f172a;
    background: #0f172a;
    color: #ffffff;
  }

  .form-group { margin-bottom: 14px; }
  .form-group label {
    font-size: 10px;
    font-weight: 800;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 5px;
    display: block;
  }

  .form-control {
    width: 100%;
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid var(--border-color);
    border-radius: 8px;
    background: #f8fafc;
    color: var(--text-dark);
    outline: none;
    transition: all 0.2s;
  }
  .form-control:focus {
    border-color: var(--brand-blue);
    background: #ffffff;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }

  .bs-preview {
    font-size: 10px;
    color: var(--brand-blue);
    font-weight: 700;
    margin-top: 4px;
  }

  .conditional-field { display: none; margin-bottom: 14px; }

  .btn-submit {
    width: 100%;
    background: #0f172a;
    color: #ffffff;
    border: none;
    padding: 12px;
    font-size: 12px;
    font-weight: 800;
    border-radius: 8px;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-top: 8px;
    transition: background 0.15s ease;
  }
  .btn-submit:hover { background: #1e293b; }

  .alert-danger {
    background: #fef2f2;
    color: var(--brand-red);
    border: 1px solid #fecaca;
    padding: 10px 12px;
    border-radius: 8px;
    font-size: 11px;
    font-weight: 700;
    margin-bottom: 14px;
  }
</style>');

renderAppHeader($_SESSION['name'] ?? 'User', $_SESSION['profile_photo'] ?? 'default.png', $_SESSION['joined_date_bs'] ?? '2083-04-16', $today_bs);
?>

<div class="container form-container">
    <div class="entry-card">
        <div class="card-header-title">
            <span>➕</span> Record New Entry
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form action="add_entry.php" method="POST" id="entryForm">

            <!-- Category Selector -->
            <div class="form-group">
                <label>Transaction Category</label>
                <div class="type-grid">
                    <?php 
                    $chips = [
                        'starting_balance'  => ['icon' => '🏦', 'label' => 'Starting Bal'],
                        'income'            => ['icon' => '💰', 'label' => 'Income'],
                        'expense'           => ['icon' => '🛒', 'label' => 'Expense'],
                        'lend'              => ['icon' => '📤', 'label' => 'Lend Out'],
                        'lend_repayment'    => ['icon' => '📥', 'label' => 'Lend Return'],
                        'borrow'            => ['icon' => '🤝', 'label' => 'Borrow'],
                        'borrow_repayment'  => ['icon' => '💸', 'label' => 'Pay Borrow'],
                        'investment'        => ['icon' => '📈', 'label' => 'Investment'],
                        'investment_sell'   => ['icon' => '📊', 'label' => 'Sell Shares'],
                        'loss'              => ['icon' => '📉', 'label' => 'Loss']
                    ];
                    foreach ($chips as $key => $cfg): ?>
                        <div class="type-chip <?= ($preType === $key || ($preType === '' && $key === 'income')) ? 'selected' : '' ?>"
                             data-type="<?= $key ?>"
                             onclick="selectType(this)">
                            <div class="tc-icon"><?= $cfg['icon'] ?></div>
                            <div class="tc-label"><?= $cfg['label'] ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="transaction_type" id="typeInput" value="<?= htmlspecialchars($preType ?: 'income') ?>" required>
            </div>

            <!-- Payment Account Selector -->
            <div class="form-group">
                <label>Payment Account / Method</label>
                <div class="segmented-control">
                    <label>
                        <input type="radio" name="payment_method" value="bank_digital" checked hidden>
                        <span>🏦 Bank / Digital</span>
                    </label>
                    <label>
                        <input type="radio" name="payment_method" value="hand_cash" hidden>
                        <span>💵 Hand Cash</span>
                    </label>
                </div>
            </div>

            <!-- Conditional Dropdown: Investment Sub-type -->
            <div class="form-group conditional-field" id="investmentTypeGroup">
                <label>Investment Portfolio Category</label>
                <select name="investment_type" class="form-control">
                    <option value="sip_long_term">📈 Long-Term SIP / Mutual Fund</option>
                    <option value="ssf">🏦 SSF (Social Security Fund)</option>
                    <option value="secondary_short_term">📊 Secondary Market Shares</option>
                </select>
            </div>

            <!-- Conditional Dropdown: Linked Lend Record -->
            <div class="form-group conditional-field" id="linkedLendGroup">
                <label>Select Outstanding Loan to Settle</label>
                <select name="related_transaction_id" id="linkedLendSelect" class="form-control">
                    <option value="">-- Choose Active Loan --</option>
                    <?php while ($l = $openLends->fetch_assoc()): ?>
                        <option value="<?= $l['id'] ?>">
                            <?= htmlspecialchars($l['subject']) ?> (Due: Rs. <?= number_format($l['amount'], 2) ?>) - <?= htmlspecialchars($l['party_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <div style="margin-top: 8px;">
                    <label style="font-size: 11px; font-weight: 600; color: var(--brand-red); text-transform: none;">
                        <input type="checkbox" name="is_bad_debt" value="1"> Mark remaining uncollected balance as Bad Debt (Write-off to Loss)
                    </label>
                </div>
            </div>

            <!-- Conditional Dropdown: Linked Borrow Record -->
            <div class="form-group conditional-field" id="linkedBorrowGroup">
                <label>Select Active Debt to Pay Back</label>
                <select name="related_transaction_id" id="linkedBorrowSelect" class="form-control">
                    <option value="">-- Choose Active Debt --</option>
                    <?php while ($b = $openBorrows->fetch_assoc()): ?>
                        <option value="<?= $b['id'] ?>">
                            <?= htmlspecialchars($b['subject']) ?> (Owed: Rs. <?= number_format($b['amount'], 2) ?>) - <?= htmlspecialchars($b['party_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </div>

            <!-- Conditional Dropdown: Linked Share Sale Record -->
            <div class="form-group conditional-field" id="linkedShareGroup">
                <label>Select Investment Position to Sell</label>
                <select name="related_transaction_id" id="linkedShareSelect" class="form-control">
                    <option value="">-- Choose Active Investment --</option>
                    <?php while ($inv = $openInvestments->fetch_assoc()): ?>
                        <option value="<?= $inv['id'] ?>">
                            <?= htmlspecialchars($inv['subject']) ?> (Cost Basis: Rs. <?= number_format($inv['amount'], 2) ?>)
                        </option>
                    <?php endwhile; ?>
                </select>
                <span style="font-size: 10px; color: var(--text-muted); margin-top: 4px; display: block;">
                    Entering net return calculates profit/loss automatically and settles the original position.
                </span>
            </div>

            <!-- Subject / Description -->
            <div class="form-group" id="subjectGroup">
                <label>Subject / Description</label>
                <input type="text" name="subject" class="form-control" placeholder="e.g. Petrol, Nabil SIP, Salary"
                       value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>">
            </div>

            <!-- Amount + Date -->
            <div class="form-row">
                <div class="form-group">
                    <label id="amountLabel">Amount (NPR)</label>
                    <input type="number" step="0.01" name="amount" class="form-control" placeholder="0.00"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Date (AD)</label>
                    <input type="date" name="date_ad" id="dateAd" class="form-control"
                           value="<?= htmlspecialchars($_POST['date_ad'] ?? date('Y-m-d')) ?>"
                           onchange="updateBsDate()">
                    <div class="bs-preview" id="bsPreview"><?= $today_bs ?> BS</div>
                </div>
            </div>

            <!-- Party Name Field -->
            <div class="form-group conditional-field" id="partyField">
                <label>Counterparty / Entity Name</label>
                <input type="text" name="party_name" class="form-control" placeholder="e.g. Ram Sharma, Broker, Bank"
                       value="<?= htmlspecialchars($_POST['party_name'] ?? '') ?>">
            </div>

            <!-- Notes / Remarks -->
            <div class="form-group">
                <label>Notes / Context Remarks</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Optional notes or references..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-submit">Save Transaction</button>
        </form>
    </div>
</div>

<script>
function selectType(el) {
    document.querySelectorAll('.type-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    
    const type = el.dataset.type;
    document.getElementById('typeInput').value = type;

    // Reset visibility for conditional field groups
    document.getElementById('partyField').style.display = 'none';
    document.getElementById('investmentTypeGroup').style.display = 'none';
    document.getElementById('linkedLendGroup').style.display = 'none';
    document.getElementById('linkedBorrowGroup').style.display = 'none';
    document.getElementById('linkedShareGroup').style.display = 'none';
    document.getElementById('amountLabel').textContent = 'Amount (NPR)';

    // Toggle conditional visual groups based on transaction category workflow
    if (type === 'lend' || type === 'borrow') {
        document.getElementById('partyField').style.display = 'block';
    } else if (type === 'investment') {
        document.getElementById('investmentTypeGroup').style.display = 'block';
    } else if (type === 'lend_repayment') {
        document.getElementById('linkedLendGroup').style.display = 'block';
        document.getElementById('partyField').style.display = 'block';
    } else if (type === 'borrow_repayment') {
        document.getElementById('linkedBorrowGroup').style.display = 'block';
        document.getElementById('partyField').style.display = 'block';
    } else if (type === 'investment_sell') {
        document.getElementById('linkedShareGroup').style.display = 'block';
        document.getElementById('amountLabel').textContent = 'Net Cash Return (NPR)';
    }
}

// Initialize on page DOM load
(function() {
    const selected = document.querySelector('.type-chip.selected');
    if (selected) selectType(selected);
})();

function updateBsDate() {
    const dateInput = document.getElementById('dateAd');
    const preview = document.getElementById('bsPreview');
    if (dateInput.value) {
        const d = new Date(dateInput.value);
        preview.textContent = 'Selected AD: ' + d.toLocaleDateString('en-CA');
    }
}
</script>

<?php renderBottomNav('add'); ?>
</body>
</html>
