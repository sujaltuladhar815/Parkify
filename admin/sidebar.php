<?php
// ── Active page detection ─────────────────────────────────────
// Set $current_page before including this file, e.g.:
//   $current_page = 'dashboard';
// Possible values: dashboard, parking-slots, bookings, users, payments, reports, notifications, settings

$current_page = $current_page ?? '';

function nav_item(string $href, string $icon, string $label, string $page, string $current): string {
    $active = $current === $page ? 'active' : '';
    return "
    <a href=\"{$href}\" class=\"nav-item {$active}\">
        <i class=\"fa-solid {$icon}\"></i> {$label}
    </a>";
}
?>

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>

<style>
/* ── Sidebar base styles (shared across all pages) ─────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --blue:      #2563eb;
    --blue-d:    #1d4ed8;
    --blue-lt:   #eff6ff;
    --blue-mid:  #dbeafe;
    --sidebar:   #1e3a8a;
    --text:      #111827;
    --muted:     #6b7280;
    --border:    #e5e7eb;
    --bg:        #f3f4f6;
    --surface:   #ffffff;
    --green:     #10b981; --green-bg:  #ecfdf5; --green-bd: #a7f3d0;
    --red:       #ef4444; --red-bg:    #fef2f2; --red-bd:   #fecaca;
    --orange:    #f59e0b; --orange-bg: #fffbeb; --orange-bd:#fde68a;
    --purple:    #7c3aed; --purple-bg: #ede9fe;
    --gray:      #6b7280; --gray-bg:   #f9fafb; --gray-bd:  #e5e7eb;
    --font: 'Inter', sans-serif;
}

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    display: flex;
    min-height: 100vh;
    font-size: 14px;
}

/* ── Sidebar ─────────────────────────────────────────────── */
.sidebar {
    width: 220px;
    min-height: 100vh;
    background: var(--sidebar);
    display: flex;
    flex-direction: column;
    position: fixed;
    top: 0; left: 0;
    z-index: 100;
}

.sidebar-logo {
    height: 100px;
    padding: 20 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    text-decoration: none;
}
.sidebar-logo img { 
    margin-top: 30px;
    margin-left: 10px;
}
.sidebar-nav {
    padding: 12px;
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 8px;
    text-decoration: none;
    color: rgba(255,255,255,0.65);
    font-size: 13.5px;
    font-weight: 500;
    transition: all 0.15s;
}
.nav-item i { width: 16px; font-size: 14px; flex-shrink: 0; }
.nav-item:hover  { background: rgba(255,255,255,0.1);  color: #fff; }
.nav-item.active { background: rgba(255,255,255,0.15); color: #fff; }

.sidebar-bottom {
    padding: 12px;
    border-top: 1px solid rgba(255,255,255,0.1);
    display: flex;
    flex-direction: column;
    gap: 2px;
}

/* ── Main area (every page uses this) ────────────────────── */
.main {
    margin-left: 220px;
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* ── Topbar (shared) ─────────────────────────────────────── */
.topbar {
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
    height: 60px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
}
.topbar-title h1 { font-size: 18px; font-weight: 700; }
.topbar-title p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right    { display: flex; align-items: center; gap: 16px; }
.topbar-user     { display: flex; align-items: center; gap: 10px; }
.topbar-user .name { font-size: 13px; font-weight: 600; text-align: right; }
.topbar-user .role { font-size: 11px; color: var(--muted); text-align: right; }
.topbar-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.search-box {
    display: flex; align-items: center; gap: 8px;
    background: var(--bg); border: 1px solid var(--border);
    border-radius: 8px; padding: 7px 12px; width: 220px;
}
.search-box input {
    border: none; background: transparent;
    font-size: 13px; color: var(--text); outline: none; width: 100%;
    font-family: var(--font);
}
.search-box i { color: var(--muted); font-size: 13px; flex-shrink: 0; }
</style>

<aside class="sidebar">
    <a href="dashboard.php" class="sidebar-logo">
        <img src="../images/initialLogo.png" alt="" width="175px">
    </a>

    <nav class="sidebar-nav">
        <?= nav_item('dashboard.php',     'fa-gauge',          'Dashboard',     'dashboard',      $current_page) ?>
        <?= nav_item('parking-slots.php', 'fa-car',            'Parking Slots', 'parking-slots',  $current_page) ?>
        <?= nav_item('bookings.php',      'fa-calendar-check', 'Bookings',      'bookings',       $current_page) ?>
        <?= nav_item('users.php',         'fa-users',          'Users',         'users',          $current_page) ?>
        <?= nav_item('payments.php',      'fa-credit-card',    'Payments',      'payments',       $current_page) ?>
        <?= nav_item('reports.php',       'fa-chart-bar',      'Reports',       'reports',        $current_page) ?>
    </nav>

</aside>