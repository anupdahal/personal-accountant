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
$message  = '';
$msgType  = 'danger';

// Pre-select type from query param (quick-action shortcuts)
$preType  = $_GET['type'] ?? '';
$validTypes = ['starting_balance','income','expense','lend','borrow','investment','loss'];
if (!in_array($preType, $validTypes)) $preType = '';

$topicConfig = topicConfig();

// ----------------------------------------------------
// Schema safety: detect which optional columns exist
// ----------------------------------------------------
$hasPartyCol = columnExists($conn, 'transactions', 'party_name');
$notesCol    = notesColumn($conn);  // 'notes', 'remarks', or ''

// ----------------------------------------------------
// Handle POST
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type    = $_POST['transaction_type'] ?? '';
    $subject = trim($_POST['subject'] ?? '');
    $amount  = (float)($_POST['amount'] ?? 0);
    $notes   = trim($_POST['notes'] ?? '');
    $party   = trim($_POST['party_name'] ?? '');
    $dateAd  = trim($_POST['date_ad'] ?? date('Y-m-d'));

    // Validate transaction type
    if (!in_array($type, $validTypes)) {
        $message = 'Invalid transaction type selected.';
    } elseif (empty($subject)) {
        $message = 'Please enter a subject / description.';
    } elseif ($amount <= 0) {
        $message = 'Amount must be greater than zero.';
    } else {
        // Convert AD date to BS
        $date_bs = NepaliDateConverter::convertAdToBs($dateAd);

        // Build INSERT dynamically based on available columns
        $cols = ['user_id', 'transaction_type', 'subject', 'amount', 'date_ad', 'date_bs'];
        $vals = [$user_id, $type, $subject, $amount, $dateAd, $date_bs];
        $types = 'issdss'; // i, s, s, d, s, s — 6 params for 6 cols

        if ($hasPartyCol && $party !== '') {
            $cols[] = 'party_name';
            $vals[] = $party;
            $types .= 's';
        }

        // Always insert notes/remarks if either column exists (even if empty)
        if ($notesCol !== '') {
            $cols[] = $notesCol;
            $vals[] = $notes;
            $types .= 's';
        }

        $colList  = '`' . implode('`, `', $cols) . '`';
        $placeHld = implode(', ', array_fill(0, count($cols), '?'));

        $sql = "INSERT INTO transactions ({$colList}) VALUES ({$placeHld})";
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            // Build bind_param dynamically
            $stmt->bind_param($types, ...$vals);

            if ($stmt->execute()) {
                header("Location: dashboard.php?added=1");
                exit();
            } else {
                $message = "Error saving transaction: " . $conn->error;
            }
            $stmt->close();
        } else {
            $message = "Database prepare error: " . $conn->error;
        }
    }
}

$today_bs = NepaliDateConverter::todayBs();
renderAppHead('New Entry', '<style>.form-card{max-width:540px;margin:0 auto}.type-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:6px}.type-chip{display:flex;flex-direction:column;align-items:center;gap:4px;padding:10px 4px;border-radius:var(--r-md);border:2px solid var(--c-border);background:var(--c-surface);cursor:pointer;transition:all var(--t-base);user-select:none}.type-chip:active{transform:scale(0.96)}.type-chip.selected{border-color:var(--c-primary);background:var(--c-primary-light)}.type-chip .tc-icon{font-size:20px}.type-chip .tc-label{font-size:9px;font-weight:700;color:var(--c-text-2);text-align:center;line-height:1.1}.bs-preview{font-size:12px;color:var(--c-primary);font-weight:600;margin-top:4px}.party-field{display:none}</style>');
renderAppHeader($_SESSION['name'] ?? 'User', $_SESSION['profile_photo'] ?? 'default.png', $_SESSION['joined_date_bs'] ?? '2083-04-16', $today_bs);
?>

<div class="container">
    <div class="card form-card" style="padding: 20px;">

        <h3 style="font-size: 18px; margin-bottom: 16px;">+ New Financial Entry</h3>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $msgType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form action="add_entry.php" method="POST" id="entryForm">

            <!-- Visual Type Selector -->
            <div class="form-group">
                <label>Transaction Category</label>
                <div class="type-grid">
                    <?php foreach ($topicConfig as $key => $cfg): ?>
                        <div class="type-chip <?= $preType === $key ? 'selected' : '' ?>"
                             data-type="<?= $key ?>"
                             data-party="<?= in_array($key, ['lend','borrow']) ? '1' : '0' ?>"
                             onclick="selectType(this)">
                            <div class="tc-icon"><?= $cfg['icon'] ?></div>
                            <div class="tc-label"><?= ucwords(str_replace('_', ' ', $key)) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="transaction_type" id="typeInput" value="<?= htmlspecialchars($preType ?: 'income') ?>" required>
            </div>

            <!-- Subject -->
            <div class="form-group">
                <label>Subject / Description</label>
                <input type="text" name="subject" placeholder="e.g. Petrol, NABIL Share, Salary"
                       value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
            </div>

            <!-- Amount + Date -->
            <div class="form-row">
                <div class="form-group">
                    <label>Amount (NPR)</label>
                    <input type="number" step="0.01" name="amount" placeholder="Rs. 0.00"
                           value="<?= htmlspecialchars($_POST['amount'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Date (AD)</label>
                    <input type="date" name="date_ad" id="dateAd"
                           value="<?= htmlspecialchars($_POST['date_ad'] ?? date('Y-m-d')) ?>"
                           onchange="updateBsDate()">
                    <div class="bs-preview" id="bsPreview"><?= $today_bs ?> BS</div>
                </div>
            </div>

            <!-- Party Name (conditional) -->
            <div class="form-group party-field" id="partyField">
                <label>Party Name (who?)</label>
                <input type="text" name="party_name" placeholder="e.g. Ram Sharma, Office"
                       value="<?= htmlspecialchars($_POST['party_name'] ?? '') ?>">
            </div>

            <!-- Notes / Remarks -->
            <div class="form-group">
                <label>Notes / Remarks</label>
                <textarea name="notes" rows="2" placeholder="Optional context or reference notes..."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-submit">Save Entry</button>
        </form>
    </div>

    <!-- Quick category guide -->
    <div class="card">
        <h4>📖 Quick Category Guide</h4>
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <?php foreach ($topicConfig as $key => $cfg): ?>
                <div style="font-size: 12px; color: var(--c-text-2); display: flex; gap: 8px; align-items: flex-start;">
                    <span style="font-size: 16px;"><?= $cfg['icon'] ?></span>
                    <span><strong style="color: <?= $cfg['color'] ?>;"><?= $cfg['label'] ?></strong></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script>
function selectType(el) {
    document.querySelectorAll('.type-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('typeInput').value = el.dataset.type;

    // Show/hide party name field for lend/borrow
    const partyField = document.getElementById('partyField');
    if (el.dataset.party === '1') {
        partyField.style.display = 'block';
    } else {
        partyField.style.display = 'none';
        const partyInput = partyField.querySelector('input[name="party_name"]');
        if (partyInput) partyInput.value = '';
    }
}

// Initialize on load
(function() {
    const selected = document.querySelector('.type-chip.selected');
    if (selected) selectType(selected);
    else {
        const first = document.querySelector('.type-chip');
        if (first) selectType(first);
    }
})();

// BS date live preview via AJAX-free approximation
// (Uses PHP-provided value; updates on date change via form resubmit-free JS approximation)
function updateBsDate() {
    const dateInput = document.getElementById('dateAd');
    const preview = document.getElementById('bsPreview');
    if (dateInput.value) {
        preview.textContent = 'Converting...';
        // Simple fetch to self with AJAX flag would go here;
        // For now show the AD date and let PHP handle exact BS on save
        const d = new Date(dateInput.value);
        preview.textContent = 'Selected: ' + d.toLocaleDateString('en-CA');
    }
}
</script>

<?php renderBottomNav(''); ?>
</body>
</html>
