<?php
session_start();

// Fallback session variables for standalone testing
$userName     = $_SESSION['name'] ?? 'Anup Dahal';
$profilePhoto = $_SESSION['profile_photo'] ?? 'default.png';
$joinedBs     = $_SESSION['joined_date_bs'] ?? '2083-04-16';
$joinedAd     = $_SESSION['created_at_ad'] ?? '2026-08-01';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI Mobile Accountant</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: #f1f5f9;
            color: #0f172a;
            padding-bottom: 30px;
        }

        /* Mobile Header */
        .app-header {
            background: #ffffff;
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #2563eb;
        }

        .user-info h2 {
            font-size: 16px;
            font-weight: 700;
        }

        .user-info p {
            font-size: 11px;
            color: #64748b;
        }

        .joined-badge {
            background: #eff6ff;
            color: #2563eb;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 12px;
        }

        .container {
            padding: 16px;
            max-width: 480px;
            margin: 0 auto;
        }

        /* Horizontally Scrollable Summary Cards */
        .summary-scroll {
            display: flex;
            gap: 12px;
            overflow-x: auto;
            padding-bottom: 8px;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
        }

        .summary-scroll::-webkit-scrollbar {
            display: none;
        }

        .summary-card {
            flex: 0 0 140px;
            scroll-snap-align: start;
            background: #ffffff;
            padding: 14px;
            border-radius: 14px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
            border-left: 4px solid #2563eb;
        }

        .summary-card.income { border-left-color: #16a34a; }
        .summary-card.expense { border-left-color: #dc2626; }
        .summary-card.lend { border-left-color: #0284c7; }
        .summary-card.borrow { border-left-color: #d97706; }
        .summary-card.investment { border-left-color: #7c3aed; }

        .summary-card span {
            font-size: 11px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }

        .summary-card p {
            font-size: 15px;
            font-weight: 700;
            margin-top: 4px;
        }

        /* Accounting Terms Guide Accordion */
        .guide-section {
            background: #ffffff;
            border-radius: 14px;
            padding: 16px;
            margin-top: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }

        .guide-title {
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 12px;
            color: #334155;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .term-grid {
            display: grid;
            gap: 10px;
        }

        .term-item {
            background: #f8fafc;
            padding: 10px 12px;
            border-radius: 8px;
            font-size: 12px;
            line-height: 1.4;
        }

        .term-item strong {
            display: block;
            font-size: 13px;
            margin-bottom: 2px;
        }

        /* Touch Form */
        .form-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 16px;
            margin-top: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }

        .form-group {
            margin-bottom: 14px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #475569;
            margin-bottom: 6px;
        }

        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 12px;
            font-size: 14px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #f8fafc;
            outline: none;
        }

        .form-group input:focus, .form-group select:focus {
            border-color: #2563eb;
            background: #ffffff;
        }

        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #2563eb;
            color: #ffffff;
            border: none;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.2);
        }

        .btn-submit:active {
            transform: scale(0.98);
        }

        /* Ledger Table */
        .ledger-card {
            background: #ffffff;
            border-radius: 14px;
            padding: 16px;
            margin-top: 16px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.03);
        }

        .ledger-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f1f5f9;
        }

        .ledger-item:last-child {
            border-bottom: none;
        }

        .ledger-details strong {
            display: block;
            font-size: 14px;
            color: #0f172a;
        }

        .ledger-details small {
            font-size: 11px;
            color: #94a3b8;
        }

        .badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 6px;
            border-radius: 4px;
            text-transform: uppercase;
        }

        .badge-income { background: #dcfce7; color: #15803d; }
        .badge-expense { background: #fee2e2; color: #b91c1c; }
        .badge-lend { background: #e0f2fe; color: #0369a1; }
        .badge-borrow { background: #fef3c7; color: #b45309; }
        .badge-investment { background: #f3e8ff; color: #6b21a8; }
    </style>
</head>
<body>

    <!-- Sticky Mobile App Header -->
    <header class="app-header">
        <div class="user-profile">
            <img src="uploads/<?= htmlspecialchars($profilePhoto); ?>" alt="User Profile" class="avatar" onerror="this.src='https://via.placeholder.com/44'">
            <div class="user-info">
                <h2><?= htmlspecialchars($userName); ?></h2>
                <p>Started: <span class="joined-badge"><?= htmlspecialchars($joinedBs); ?> BS</span></p>
            </div>
        </div>
    </header>

    <div class="container">

        <!-- Horizontal Swipe Balance Chips -->
        <div class="summary-scroll">
            <div class="summary-card income">
                <span>Income</span>
                <p style="color: #16a34a;">Rs. 25,000</p>
            </div>
            <div class="summary-card expense">
                <span>Expenses</span>
                <p style="color: #dc2626;">Rs. 4,200</p>
            </div>
            <div class="summary-card lend">
                <span>Lend (Given)</span>
                <p style="color: #0284c7;">Rs. 1,500</p>
            </div>
            <div class="summary-card borrow">
                <span>Borrow (Owed)</span>
                <p style="color: #d97706;">Rs. 2,000</p>
            </div>
            <div class="summary-card investment">
                <span>Investments</span>
                <p style="color: #7c3aed;">Rs. 10,000</p>
            </div>
        </div>

        <!-- Account Words & Detail Guide -->
        <div class="guide-section">
            <div class="guide-title">
                <span>📖 Accounting Terms Guide</span>
            </div>
            <div class="term-grid">
                <div class="term-item">
                    <strong style="color: #16a34a;">1. Income (आम्दानी)</strong>
                    Money received coming into your wallet (e.g., Ward Salary, allowances).
                </div>
                <div class="term-item">
                    <strong style="color: #dc2626;">2. Expenses (खर्च)</strong>
                    Outflow of cash for daily needs (e.g., Petrol, Food, Stationery, Clothes).
                </div>
                <div class="term-item">
                    <strong style="color: #0284c7;">3. Lend (सापट दिएको)</strong>
                    Money you temporarily give to friends or colleagues. It is an **Asset** to collect back.
                </div>
                <div class="term-item">
                    <strong style="color: #d97706;">4. Borrow / Ward Cash (सापट लिएको)</strong>
                    Money taken from others or temporary use of Ward Tax funds. It is a **Liability** you must pay back.
                </div>
                <div class="term-item">
                    <strong style="color: #7c3aed;">5. Investment (लगानी)</strong>
                    Money put into wealth-building assets (e.g., NEPSE Secondary Shares, Mutual Fund SIPs).
                </div>
            </div>
        </div>

        <!-- Entry Form for Mobile -->
        <div class="form-card">
            <h3 style="font-size: 15px; margin-bottom: 12px;">+ Record Transaction</h3>
            <form action="#" method="POST">
                
                <div class="form-group">
                    <label for="type">Select Accounting Type</label>
                    <select id="type" name="type" required>
                        <option value="income">Income (+ Cash In)</option>
                        <option value="expense">Expense (- Cash Out)</option>
                        <option value="lend">Lend (Money Given to Others)</option>
                        <option value="borrow">Borrow (Money Taken / Ward Debt)</option>
                        <option value="investment">Investment (NEPSE Shares / SIP)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="subject">Subject / Category</label>
                    <input type="text" id="subject" name="subject" placeholder="e.g. Bike Petrol, Friend Lend, Ward Tax" required>
                </div>

                <div class="form-group">
                    <label for="amount">Amount (NPR)</label>
                    <input type="number" id="amount" name="amount" placeholder="Rs. 0.00" step="0.01" required>
                </div>

                <div class="form-group">
                    <label for="remarks">Remarks / Notes</label>
                    <textarea id="remarks" name="remarks" rows="2" placeholder="Add extra detail or reference bill note..."></textarea>
                </div>

                <button type="submit" class="btn-submit">Save Record (Auto BS Date)</button>
            </form>
        </div>

        <!-- Recent Ledger -->
        <div class="ledger-card">
            <h3 style="font-size: 15px; margin-bottom: 12px;">Recent Activity</h3>

            <div class="ledger-item">
                <div class="ledger-details">
                    <strong>Monthly Salary</strong>
                    <small>2083-04-16 BS • Base pay</small>
                </div>
                <div style="text-align: right;">
                    <span class="badge badge-income">Income</span>
                    <p style="font-size: 13px; font-weight: 700; color: #16a34a; margin-top: 2px;">+ Rs. 25,000</p>
                </div>
            </div>

            <div class="ledger-item">
                <div class="ledger-details">
                    <strong>Bike Petrol</strong>
                    <small>2083-04-16 BS • Fuel refill</small>
                </div>
                <div style="text-align: right;">
                    <span class="badge badge-expense">Expense</span>
                    <p style="font-size: 13px; font-weight: 700; color: #dc2626; margin-top: 2px;">- Rs. 500</p>
                </div>
            </div>

            <div class="ledger-item">
                <div class="ledger-details">
                    <strong>Ward Tax Collection Draw</strong>
                    <small>2083-04-16 BS • Temporary borrow</small>
                </div>
                <div style="text-align: right;">
                    <span class="badge badge-borrow">Borrow</span>
                    <p style="font-size: 13px; font-weight: 700; color: #d97706; margin-top: 2px;">Rs. 2,000</p>
                </div>
            </div>

            <div class="ledger-item">
                <div class="ledger-details">
                    <strong>NABIL Secondary Share Buy</strong>
                    <small>2083-04-16 BS • 10 Units</small>
                </div>
                <div style="text-align: right;">
                    <span class="badge badge-investment">Investment</span>
                    <p style="font-size: 13px; font-weight: 700; color: #7c3aed; margin-top: 2px;">Rs. 5,800</p>
                </div>
            </div>

        </div>

    </div>

</body>
</html>