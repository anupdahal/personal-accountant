<?php
session_start();
require_once 'nepali_date.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$today_ad = date('Y-m-d');
$today_bs = NepaliDateConverter::convertAdToBs($today_ad);
$today_bs_fmt = NepaliDateConverter::formatBs($today_bs);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2563eb">
    <title>AI Accountant — Smart Personal Finance</title>
    <link rel="stylesheet" href="assets/app.css">
    <style>
        body {
            background: linear-gradient(180deg, #eff6ff 0%, var(--c-bg) 40%);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding-bottom: 0;
        }
        .hero-container {
            padding: 36px 20px 20px;
            max-width: 480px;
            margin: 0 auto;
            width: 100%;
        }
        .hero-title {
            font-size: 32px;
            font-weight: 800;
            line-height: 1.2;
            margin-bottom: 12px;
        }
        .hero-title span { color: var(--c-primary); }
        .hero-subtitle {
            font-size: 14px;
            color: var(--c-text-3);
            line-height: 1.5;
            margin-bottom: 28px;
        }
        .features-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 32px;
        }
        .feature-card {
            background: var(--c-surface);
            border: 1px solid var(--c-border-light);
            padding: 16px;
            border-radius: var(--r-lg);
            box-shadow: var(--shadow-md);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: transform var(--t-fast);
        }
        .feature-card:active { transform: scale(0.98); }
        .feature-icon {
            width: 44px; height: 44px;
            border-radius: var(--r-md);
            background: var(--c-primary-light);
            color: var(--c-primary);
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 20px;
            flex-shrink: 0;
        }
        .feature-text h4 { font-size: 14px; font-weight: 700; }
        .feature-text p { font-size: 11px; color: var(--c-text-3); margin-top: 2px; }
        .cta-group { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
        .btn {
            display: block; width: 100%; padding: 15px;
            text-align: center; border-radius: var(--r-md);
            font-size: 15px; font-weight: 700; text-decoration: none;
            transition: all var(--t-base);
        }
        .btn:active { transform: scale(0.98); }
        .btn-primary {
            background: var(--c-primary); color: #fff;
            box-shadow: var(--shadow-blue);
        }
        .btn-secondary {
            background: var(--c-surface); color: var(--c-text-2);
            border: 1px solid var(--c-border);
        }
        .footer-text {
            text-align: center; font-size: 11px; color: var(--c-text-4); padding: 16px;
        }
    </style>
</head>
<body>

    <div class="hero-container">

        <div class="date-badge">📅 Today: <?= htmlspecialchars($today_bs_fmt); ?></div>

        <h1 class="hero-title">
            Smart Multi-User <span>AI Accountant</span>
        </h1>
        <p class="hero-subtitle">
            Track daily expenses, NEPSE portfolio investments, ward borrows, and lends with automated Bikram Sambat dates and AI-powered financial health scoring.
        </p>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <div class="feature-text">
                    <h4>Executive Dashboard</h4>
                    <p>Instant KPIs: net liquidity, burn rate, inflow vs. outflow, and capital distribution charts.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🇳🇵</div>
                <div class="feature-text">
                    <h4>Automated BS Engine</h4>
                    <p>All dates auto-convert to Bikram Sambat. AD ↔ BS dual-calendar support built-in.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">🤖</div>
                <div class="feature-text">
                    <h4>AI Audit Engine</h4>
                    <p>Receivable/liability aging matrix, financial health index (0–100), and actionable directives.</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">📈</div>
                <div class="feature-text">
                    <h4>NEPSE & SIP Tracking</h4>
                    <p>Keep secondary market share purchases and mutual fund SIPs separate from daily expenses.</p>
                </div>
            </div>
        </div>

        <div class="cta-group">
            <a href="login.php" class="btn btn-primary">Login to Account</a>
            <a href="register.php" class="btn btn-secondary">Create New Account</a>
        </div>

    </div>

    <footer class="footer-text">
        AI Accountant System &bull; Multi-User Edition &bull; PHP 8.x / MySQL
    </footer>

</body>
</html>
