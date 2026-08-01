<?php
/**
 * partials/app_layout.php — Shared layout helpers for authenticated pages.
 * Call renderAppHead() in <head>, renderAppHeader() after <body>,
 * and renderBottomNav() before </body>.
 */

require_once __DIR__ . '/../nepali_date.php';

/** Topic / category configuration shared across pages */
function topicConfig(): array
{
    return [
        'starting_balance' => ['label' => 'Starting Balance / Wallets',  'badge' => 'badge-starting_balance', 'icon' => '🏦', 'color' => '#0284c7'],
        'income'           => ['label' => 'Income / Profit / Bonus',     'badge' => 'badge-income',          'icon' => '💰', 'color' => '#16a34a'],
        'expense'          => ['label' => 'Expenses (Food, Petrol)',     'badge' => 'badge-expense',         'icon' => '🛒', 'color' => '#dc2626'],
        'lend'             => ['label' => 'Lend (Money Given)',          'badge' => 'badge-lend',            'icon' => '📤', 'color' => '#2563eb'],
        'borrow'           => ['label' => 'Borrow (Debts / Ward)',       'badge' => 'badge-borrow',          'icon' => '📥', 'color' => '#d97706'],
        'investment'       => ['label' => 'Investments (NEPSE / SIP)',   'badge' => 'badge-investment',      'icon' => '📈', 'color' => '#7c3aed'],
        'loss'             => ['label' => 'Trading / Share Loss',        'badge' => 'badge-loss',            'icon' => '📉', 'color' => '#9f1239'],
    ];
}

/** Render the common <head> content */
function renderAppHead(string $title, string $extraCss = ''): void
{
    $today_bs = NepaliDateConverter::todayBs();
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="theme-color" content="#2563eb">
    <title>{$title} — AI Accountant</title>
    <link rel="stylesheet" href="assets/app.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    {$extraCss}
</head>
HTML;
}

/** Render the sticky app header with user profile */
function renderAppHeader(string $userName, string $profilePhoto, string $joinedBs, string $todayBs): void
{
    $safeName    = htmlspecialchars($userName);
    $safePhoto   = htmlspecialchars($profilePhoto);
    $safeJoined  = htmlspecialchars($joinedBs);
    $safeToday   = htmlspecialchars($todayBs);
    echo <<<HTML
<body>
    <header class="app-header">
        <div class="user-profile">
            <img src="uploads/{$safePhoto}" alt="Profile" class="avatar"
                 onerror="this.src='data:image/svg+xml,<svg xmlns=&quot;http://www.w3.org/2000/svg&quot; viewBox=&quot;0 0 44 44&quot;><rect fill=&quot;%232563eb&quot; width=&quot;44&quot; height=&quot;44&quot; rx=&quot;22&quot;/><text fill=&quot;white&quot; x=&quot;50%&quot; y=&quot;55%&quot; text-anchor=&quot;middle&quot; font-size=&quot;18&quot; font-weight=&quot;bold&quot;>A</text></svg>'">
            <div class="user-info">
                <h2>{$safeName}</h2>
                <p>Joined: <strong style="color: var(--c-primary);">{$safeJoined} BS</strong></p>
            </div>
        </div>
        <div class="header-right">
            <strong>{$safeToday} BS</strong>
            <a href="logout.php" class="logout-link">Logout</a>
        </div>
    </header>
HTML;
}

/** Render the fixed bottom navigation bar */
function renderBottomNav(string $active): void
{
    $pages = [
        'dashboard' => ['href' => 'dashboard.php', 'label' => 'Summary', 'svg' => '<path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/>'],
        'ledger'    => ['href' => 'ledger.php',    'label' => 'Ledger',  'svg' => '<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-2 10h-4v4h-2v-4H7v-2h4V7h2v4h4v2z"/>'],
        'reports'   => ['href' => 'reports.php',   'label' => 'Reports', 'svg' => '<path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2v-10h2v10zm4 0h-2v-4h2v4z"/>'],
    ];

    echo '<nav class="bottom-nav">';

    // Summary
    $cls = $active === 'dashboard' ? ' active' : '';
    echo "<a href=\"dashboard.php\" class=\"nav-item{$cls}\"><svg viewBox=\"0 0 24 24\">{$pages['dashboard']['svg']}</svg><span>Summary</span></a>";

    // Ledger
    $cls = $active === 'ledger' ? ' active' : '';
    echo "<a href=\"ledger.php\" class=\"nav-item{$cls}\"><svg viewBox=\"0 0 24 24\">{$pages['ledger']['svg']}</svg><span>Ledger</span></a>";

    // Add button (center)
    echo '<a href="add_entry.php" class="nav-add-btn"><svg viewBox="0 0 24 24"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg></a>';

    // Reports
    $cls = $active === 'reports' ? ' active' : '';
    echo "<a href=\"reports.php\" class=\"nav-item{$cls}\"><svg viewBox=\"0 0 24 24\">{$pages['reports']['svg']}</svg><span>Reports</span></a>";

    echo '</nav>';
}
