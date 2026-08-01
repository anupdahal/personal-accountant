<?php
session_start();
require_once 'nepali_date.php';

// 1. If already logged in, redirect straight to the summary dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

// 2. Fetch today's BS date for display on the landing page
$today_ad = date('Y-m-d');
$today_bs = NepaliDateConverter::convertAdToBs($today_ad);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>AI Accountant - Smart Personal Finance</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            -webkit-tap-highlight-color: transparent;
        }

        body {
            background-color: #f8fafc;
            color: #0f172a;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        .hero-container {
            padding: 32px 20px 20px 20px;
            max-width: 480px;
            margin: 0 auto;
            width: 100%;
        }

        /* Top Date Tag */
        .date-badge {
            display: inline-block;
            background: #e0e7ff;
            color: #3730a3;
            font-size: 11px;
            font-weight: 700;
            padding: 6px 12px;
            border-radius: 20px;
            margin-bottom: 20px;
            letter-spacing: 0.3px;
        }

        .hero-title {
            font-size: 30px;
            font-weight: 800;
            line-height: 1.25;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .hero-title span {
            color: #2563eb;
        }

        .hero-subtitle {
            font-size: 14px;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 28px;
        }

        /* Feature Highlights List */
        .features-grid {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 32px;
        }

        .feature-card {
            background: #ffffff;
            border: 1px solid #f1f5f9;
            padding: 16px;
            border-radius: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .feature-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 18px;
            flex-shrink: 0;
        }

        .feature-text h4 {
            font-size: 14px;
            font-weight: 700;
            color: #1e293b;
        }

        .feature-text p {
            font-size: 11px;
            color: #64748b;
            margin-top: 2px;
        }

        /* Action Buttons */
        .cta-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
        }

        .btn {
            display: block;
            width: 100%;
            padding: 14px;
            text-align: center;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: #2563eb;
            color: #ffffff;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.25);
        }

        .btn-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
        }

        .footer-text {
            text-align: center;
            font-size: 11px;
            color: #94a3b8;
            padding: 16px;
        }
    </style>
</head>
<body>

    <div class="hero-container">
        
        <!-- Today BS Date Header -->
        <div class="date-badge">
            📅 Today: <?= htmlspecialchars($today_bs); ?> BS
        </div>

        <h1 class="hero-title">
            Smart Multi-User <span>AI Accountant</span>
        </h1>
        <p class="hero-subtitle">
            Track daily expenses, NEPSE portfolio investments, ward borrows, and lends in automated Bikram Sambat dates.
        </p>

        <!-- Feature Cards -->
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">📊</div>
                <div class="feature-text">
                    <h4>Executive Summary Dashboard</h4>
                    <p>Instant view of liquid cash, income, expenses, and portfolio balances.</p>
                </div>
            </div>

            <div class="feature-card">
                <div class="feature-icon">🇳🇵</div>
                <div class="feature-text">
                    <h4>Automated BS Engine</h4>
                    <p>All dates automatically convert and record in Bikram Sambat calendar.</p>
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

        <!-- Call to Action Buttons -->
        <div class="cta-group">
            <a href="login.php" class="btn btn-primary">Login to Account</a>
            <a href="register.php" class="btn btn-secondary">Create New Account</a>
        </div>

    </div>

    <footer class="footer-text">
        AI Accountant System &bull; Multi-User Edition
    </footer>

</body>
</html>