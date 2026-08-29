<?php
// ============================================================
//  Parkify — Bookings Management Page
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── DB Connection ────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'parkify_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// ── Filters ──────────────────────────────────────────────────
$status_filter = $_GET['status']    ?? '';
$search        = $_GET['search']    ?? '';
$date_from     = $_GET['date_from'] ?? '';
$date_to       = $_GET['date_to']   ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 10;
$offset        = ($page - 1) * $per_page;

// ── Build WHERE ───────────────────────────────────────────────
$where  = ["1=1"];
$params = [];
$types  = '';

if ($status_filter && $status_filter !== 'all') {
    $where[]  = "ps.status = ?";
    $params[] = $status_filter;
    $types   .= 's';
}
if ($search) {
    $like     = "%$search%";
    $where[]  = "(u.full_name LIKE ? OR v.plate_number LIKE ? OR CONCAT('BK-', LPAD(ps.id,5,'0')) LIKE ?)";
    $params[] = $like; $params[] = $like; $params[] = $like;
    $types   .= 'sss';
}
if ($date_from) {
    $where[]  = "ps.entry_time >= ?";
    $params[] = $date_from . ' 00:00:00';
    $types   .= 's';
}
if ($date_to) {
    $where[]  = "ps.entry_time <= ?";
    $params[] = $date_to . ' 23:59:59';
    $types   .= 's';
}
$where_sql = implode(' AND ', $where);

// ── Count total ───────────────────────────────────────────────
$count_sql  = "
    SELECT COUNT(*) AS total
    FROM   parking_sessions ps
    LEFT JOIN users         u  ON u.id  = ps.user_id
    LEFT JOIN vehicles      v  ON v.id  = ps.vehicle_id
    LEFT JOIN parking_slots sl ON sl.id = ps.slot_id
    WHERE  $where_sql
";
$count_stmt = $conn->prepare($count_sql);
if ($types && $params) $count_stmt->bind_param($types, ...$params);
$count_stmt->execute();
$total_bookings = $count_stmt->get_result()->fetch_assoc()['total'];
$total_pages    = max(1, (int)ceil($total_bookings / $per_page));

// ── Fetch rows ────────────────────────────────────────────────
$query = "
    SELECT
        ps.id,
        ps.entry_time,
        ps.exit_time,
        ps.duration_mins,
        ps.status,
        ps.plate_number,
        u.full_name,
        u.email,
        v.make,
        v.model,
        v.plate_number  AS reg_plate,
        sl.slot_code,
        sl.row_label,
        py.amount,
        py.status       AS payment_status
    FROM   parking_sessions ps
    LEFT JOIN users         u  ON u.id        = ps.user_id
    LEFT JOIN vehicles      v  ON v.id        = ps.vehicle_id
    LEFT JOIN parking_slots sl ON sl.id       = ps.slot_id
    LEFT JOIN payments      py ON py.session_id = ps.id
    WHERE  $where_sql
    ORDER  BY ps.entry_time DESC
    LIMIT  ? OFFSET ?
";
$stmt        = $conn->prepare($query);
$all_params  = array_merge($params, [$per_page, $offset]);
$all_types   = $types . 'ii';
$stmt->bind_param($all_types, ...$all_params);
$stmt->execute();
$bookings = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// ── Summary stats ─────────────────────────────────────────────
$stats = [];
foreach (['active', 'completed', 'upcoming', 'cancelled'] as $s) {
    $r = $conn->query("SELECT COUNT(*) AS n FROM parking_sessions WHERE status='$s'");
    $stats[$s] = (int)$r->fetch_assoc()['n'];
}
$stats['total'] = array_sum($stats);

// ── Helpers ───────────────────────────────────────────────────
function statusBadge(string $status): string {
    $map = [
        'active'    => ['Active',    'badge-active'],
        'completed' => ['Completed', 'badge-completed'],
        'upcoming'  => ['Upcoming',  'badge-upcoming'],
        'cancelled' => ['Cancelled', 'badge-cancelled'],
        'no-show'   => ['No-show',   'badge-noshow'],
    ];
    [$label, $cls] = $map[strtolower($status)] ?? [ucfirst($status), 'badge-default'];
    return "<span class='badge $cls'>$label</span>";
}

function fmtTime(?string $dt): string {
    if (!$dt) return '—';
    $ts        = strtotime($dt);
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $day       = date('Y-m-d', $ts);
    $prefix    = match($day) {
        $today     => 'Today',
        $yesterday => 'Yesterday',
        default    => date('M j', $ts),
    };
    return $prefix . ', ' . date('g:i A', $ts);
}

function bookingId(int $id): string {
    return '#BK-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Bookings — Parkify</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="icon" href="../images/fabiconlogo.png" type="image/png" />
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* ── Reset & Variables ──────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:           #f5f6fa;
    --sidebar-bg:   #ffffff;
    --card:         #ffffff;
    --border:       #e8eaf0;
    --text:         #1a1d2e;
    --text-muted:   #8589a0;
    --text-light:   #b0b4c8;
    --accent:       #2563eb;
    --accent-light: #eff4ff;
    --accent-hover: #1d4ed8;
    --green:        #16a34a;
    --green-bg:     #f0fdf4;
    --amber:        #d97706;
    --amber-bg:     #fffbeb;
    --red:          #dc2626;
    --red-bg:       #fef2f2;
    --blue:         #2563eb;
    --blue-bg:      #eff6ff;
    --slate:        #64748b;
    --slate-bg:     #f8fafc;
    --orange:       #ea580c;
    --orange-bg:    #fff7ed;
    --sidebar-w:    230px;
    --header-h:     60px;
    --radius:       10px;
    --radius-sm:    6px;
    --shadow-sm:    0 1px 3px rgba(0,0,0,.07);
    --font:         'DM Sans', sans-serif;
    --mono:         'DM Mono', monospace;
}

html, body { height: 100%; }
body {
    font-family: var(--font);
    font-size: 14px;
    background: var(--bg);
    color: var(--text);
    display: flex;
    -webkit-font-smoothing: antialiased;
}
a { color: inherit; text-decoration: none; }

/* ── Main ───────────────────────────────────────────────── */
.main {
    margin-left: var(--sidebar-w);
    flex: 1;
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/* ── Topbar ─────────────────────────────────────────────── */
.topbar {
    height: 60px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 28px;
    position: sticky; top: 0; z-index: 50;
}
.topbar-title h1 { font-size: 18px; font-weight: 700; letter-spacing: -.4px; }
.topbar-title p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right   { display: flex; align-items: center; gap: 16px; }

.search-wrap { position: relative; display: flex; align-items: center; }
.search-wrap svg { position: absolute; left: 10px; color: var(--text-muted); width: 15px; height: 15px; pointer-events: none; }
.search-wrap input {
    padding: 7px 12px 7px 32px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: var(--font);
    font-size: 13px;
    width: 220px;
    background: var(--bg);
    color: var(--text);
    outline: none;
    transition: border-color .15s;
}
.search-wrap input:focus { border-color: var(--accent); background: #fff; }
.search-wrap input::placeholder { color: var(--text-light); }

.admin-pill {
  display: flex;
  align-items: center;
  gap: 10px;
}
.admin-avatar {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #7c3aed);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: #fff;
}
.admin-info .name { font-size: 13px; font-weight: 600; }
.admin-info .role { font-size: 11px; color: var(--muted); }

/* ── Content ────────────────────────────────────────────── */
.content { padding: 24px 28px; flex: 1; }

/* ── Stat Cards ─────────────────────────────────────────── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 24px;
}
.stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 18px 20px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    box-shadow: var(--shadow-sm);
    animation: fadeUp .4s ease both;
}
.stat-card:nth-child(1) { animation-delay: .05s; }
.stat-card:nth-child(2) { animation-delay: .10s; }
.stat-card:nth-child(3) { animation-delay: .15s; }
.stat-card:nth-child(4) { animation-delay: .20s; }
.stat-label { font-size: 12px; color: var(--text-muted); font-weight: 500; margin-bottom: 6px; }
.stat-value { font-size: 28px; font-weight: 700; letter-spacing: -.8px; line-height: 1; }
.stat-icon { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; flex-shrink: 0; }
.stat-icon svg { width: 20px; height: 20px; }
.si-blue   { background: var(--blue-bg);   color: var(--blue); }
.si-green  { background: var(--green-bg);  color: var(--green); }
.si-amber  { background: var(--amber-bg);  color: var(--amber); }
.si-red    { background: var(--red-bg);    color: var(--red); }

/* ── Toolbar ─────────────────────────────────────────────── */
.toolbar {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 14px 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
    box-shadow: var(--shadow-sm);
}
.toolbar input[type="date"],
.toolbar select {
    padding: 7px 11px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    font-family: var(--font);
    font-size: 13px;
    color: var(--text);
    background: var(--bg);
    outline: none;
    cursor: pointer;
    transition: border-color .15s;
}
.toolbar input[type="date"]:focus,
.toolbar select:focus { border-color: var(--accent); background: #fff; }
.date-sep { color: var(--text-muted); font-size: 13px; }
.toolbar-right { margin-left: auto; display: flex; gap: 8px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius-sm);
    font-family: var(--font);
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all .15s;
    white-space: nowrap;
}
.btn svg { width: 15px; height: 15px; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-outline:hover { background: var(--bg); border-color: #c5c8d8; }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-hover); }

/* ── Table Card ─────────────────────────────────────────── */
.table-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    animation: fadeUp .4s .25s ease both;
}
table { width: 100%; border-collapse: collapse; }
thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11.5px;
    font-weight: 600;
    letter-spacing: .05em;
    text-transform: uppercase;
    color: var(--text-muted);
    background: var(--slate-bg);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .12s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #fafbfe; }
tbody td { padding: 14px 16px; vertical-align: middle; }

.col-id       { font-family: var(--mono); font-size: 13px; font-weight: 500; color: var(--text-muted); }
.col-name     { font-weight: 600; font-size: 13.5px; }
.col-email    { font-size: 12px; color: var(--text-muted); margin-top: 2px; }
.col-slot     { color: var(--accent); font-weight: 600; font-size: 13px; }
.col-plate    { font-family: var(--mono); font-size: 12px; color: var(--text-muted); margin-top: 3px; }
.col-time     { font-size: 12.5px; white-space: nowrap; }
.col-time small { color: var(--text-muted); font-size: 11.5px; display: block; margin-top: 2px; }
.col-amount   { font-weight: 700; font-size: 14px; white-space: nowrap; }
.col-amount small { font-size: 11px; font-weight: 400; color: var(--text-muted); display: block; }

/* ── Badges ─────────────────────────────────────────────── */
.badge { display: inline-flex; align-items: center; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; white-space: nowrap; }
.badge-active    { background: var(--green-bg);  color: var(--green); }
.badge-completed { background: var(--slate-bg);  color: var(--slate); }
.badge-upcoming  { background: var(--blue-bg);   color: var(--blue); }
.badge-cancelled { background: var(--red-bg);    color: var(--red); }
.badge-noshow    { background: var(--orange-bg); color: var(--orange); }
.badge-default   { background: var(--bg);        color: var(--text-muted); }

/* ── Actions ─────────────────────────────────────────────── */
.actions { display: flex; align-items: center; gap: 4px; }
.action-btn {
    width: 30px; height: 30px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: transparent;
    display: grid; place-items: center;
    cursor: pointer;
    color: var(--text-muted);
    transition: all .15s;
}
.action-btn:hover { background: var(--bg); color: var(--text); border-color: #c5c8d8; }
.action-btn svg { width: 14px; height: 14px; }

/* ── Pagination ──────────────────────────────────────────── */
.pager {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    border-top: 1px solid var(--border);
    font-size: 13px;
    color: var(--text-muted);
}
.pager-info strong { color: var(--text); font-weight: 600; }
.page-btns { display: flex; gap: 4px; }
.page-btn {
    min-width: 32px; height: 32px;
    padding: 0 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    background: transparent;
    font-family: var(--font);
    font-size: 13px;
    font-weight: 500;
    color: var(--text-muted);
    cursor: pointer;
    display: inline-grid; place-items: center;
    transition: all .15s;
}
.page-btn:hover           { background: var(--bg); color: var(--text); }
.page-btn.active          { background: var(--accent); border-color: var(--accent); color: #fff; }
.page-btn.disabled        { opacity: .4; pointer-events: none; }

/* ── Empty State ─────────────────────────────────────────── */
.empty-state { text-align: center; padding: 60px 20px; color: var(--text-muted); }
.empty-state svg { width: 48px; height: 48px; margin-bottom: 14px; opacity: .35; }
.empty-state h3  { font-size: 15px; font-weight: 600; color: var(--text); margin-bottom: 6px; }
.empty-state p   { font-size: 13px; }

/* ── Animation ───────────────────────────────────────────── */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body>

<?php
$current_page = 'bookings';
include 'sidebar.php';
?>


<!-- ═══════════════ MAIN ═══════════════ -->
<div class="main">

    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-title">
            <h1>Bookings Management</h1>
            <p>Monitor and manage all parking reservations</p>
        </div>
        <div class="topbar-right">
            <form method="GET" class="search-wrap">
                <?php foreach ($_GET as $k => $v): if ($k === 'search') continue; ?>
                    <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                <?php endforeach; ?>
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>
                <input type="text" name="search" placeholder="Search bookings, users..." value="<?= htmlspecialchars($search) ?>">
            </form>
            <div class="admin-pill">
                <?php
                $admin_name = $_SESSION['user_name'] ?? 'Admin';
                $admin_role = $_SESSION['role'] ?? 'admin';
                $words      = explode(' ', trim($admin_name));
                $initials   = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
                ?>
                <div class="admin-avatar"><?= htmlspecialchars($initials) ?></div>
                <div class="admin-info">
                <div class="name"><?= htmlspecialchars($admin_name) ?></div>
                <div class="role"><?= htmlspecialchars(ucfirst($admin_role)) ?></div>
                </div>
            </div>
        </div>
    </header>

    <!-- Content -->
    <div class="content">

        <!-- Stat Cards -->
        <div class="stat-grid">
            <div class="stat-card">
                <div>
                    <div class="stat-label">Total Bookings</div>
                    <div class="stat-value"><?= number_format($stats['total']) ?></div>
                </div>
                <div class="stat-icon si-blue">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Active Now</div>
                    <div class="stat-value"><?= number_format($stats['active']) ?></div>
                </div>
                <div class="stat-icon si-green">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>
                </div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Completed (Today)</div>
                    <div class="stat-value"><?= number_format($stats['completed']) ?></div>
                </div>
                <div class="stat-icon si-amber">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
            </div>
            <div class="stat-card">
                <div>
                    <div class="stat-label">Cancellations</div>
                    <div class="stat-value"><?= number_format($stats['cancelled']) ?></div>
                </div>
                <div class="stat-icon si-red">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                </div>
            </div>
        </div>

        <!-- Filters toolbar -->
        <form method="GET">
            <div class="toolbar">
                <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" title="From">
                <span class="date-sep">–</span>
                <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" title="To">

                <select name="status">
                    <option value="all"       <?= (!$status_filter || $status_filter === 'all')       ? 'selected' : '' ?>>All Status</option>
                    <option value="active"    <?= $status_filter === 'active'    ? 'selected' : '' ?>>Active</option>
                    <option value="upcoming"  <?= $status_filter === 'upcoming'  ? 'selected' : '' ?>>Upcoming</option>
                    <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    <option value="no-show"   <?= $status_filter === 'no-show'   ? 'selected' : '' ?>>No-show</option>
                </select>

                <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">

                <button type="submit" class="btn btn-outline">
                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>
                    Filter
                </button>
                <?php if ($search || ($status_filter && $status_filter !== 'all') || $date_from || $date_to): ?>
                    <a href="bookings.php" class="btn btn-outline">Clear</a>
                <?php endif; ?>

                <div class="toolbar-right">
                    <button type="button" class="btn btn-outline" onclick="window.print()">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Export
                    </button>
                    <a href="booking_new.php" class="btn btn-primary">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        New Booking
                    </a>
                </div>
            </div>
        </form>

        <!-- Table -->
        <div class="table-card">
            <table>
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>User Details</th>
                        <th>Slot &amp; Vehicle</th>
                        <th>Time Duration</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="7">
                        <div class="empty-state">
                            <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                            <h3>No bookings found</h3>
                            <p>Try adjusting your filters or create a new booking.</p>
                        </div>
                    </td></tr>
                <?php else: ?>
                    <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td class="col-id"><?= bookingId((int)$b['id']) ?></td>

                        <td>
                            <div class="col-name"><?= htmlspecialchars($b['full_name'] ?? 'Guest') ?></div>
                            <div class="col-email"><?= htmlspecialchars($b['email'] ?? $b['plate_number'] ?? '') ?></div>
                        </td>

                        <td>
                            <div class="col-slot"><?= htmlspecialchars($b['slot_code'] ?? '—') ?></div>
                            <div class="col-plate">
                                <?= htmlspecialchars(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) ?>
                                <?php if (!empty($b['reg_plate']) || !empty($b['plate_number'])): ?>
                                    • <?= htmlspecialchars($b['reg_plate'] ?? $b['plate_number']) ?>
                                <?php endif; ?>
                            </div>
                        </td>

                        <td class="col-time">
                            <?= fmtTime($b['entry_time']) ?>
                            <small>to <?= fmtTime($b['exit_time']) ?></small>
                        </td>

                        <td class="col-amount">
                            <?php if (isset($b['amount']) && $b['amount'] !== null): ?>
                                Rs <?= number_format((float)$b['amount'], 2) ?>
                                <?php if (!empty($b['payment_status']) && $b['payment_status'] !== 'paid'): ?>
                                    <small><?= htmlspecialchars(ucfirst($b['payment_status'])) ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--text-muted)">—</span>
                            <?php endif; ?>
                        </td>

                        <td><?= statusBadge($b['status']) ?></td>

                        <td>
                            <div class="actions">
                                <a href="booking_view.php?id=<?= (int)$b['id'] ?>" class="action-btn" title="View booking">
                                    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                </a>
                                <a href="booking_edit.php?id=<?= (int)$b['id'] ?>" class="action-btn" title="More options">
                                    <svg fill="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="5" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="12" cy="19" r="1.5"/></svg>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <div class="pager">
                <div class="pager-info">
                    Showing <strong><?= $offset + 1 ?> to <?= min($offset + $per_page, $total_bookings) ?></strong>
                    of <strong><?= number_format($total_bookings) ?></strong> bookings
                </div>
                <div class="page-btns">
                    <?php
                    function pageUrl(int $p): string {
                        $q = array_merge($_GET, ['page' => $p]);
                        return 'bookings.php?' . http_build_query($q);
                    }
                    ?>
                    <a href="<?= pageUrl($page - 1) ?>" class="page-btn <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>

                    <?php
                    $range_start = max(1, $page - 2);
                    $range_end   = min($total_pages, $range_start + 4);
                    for ($p = $range_start; $p <= $range_end; $p++): ?>
                        <a href="<?= pageUrl($p) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
                    <?php endfor; ?>

                    <?php if ($range_end < $total_pages): ?>
                        <span class="page-btn" style="pointer-events:none;cursor:default">…</span>
                    <?php endif; ?>

                    <a href="<?= pageUrl($page + 1) ?>" class="page-btn <?= $page >= $total_pages ? 'disabled' : '' ?>">Next</a>
                </div>
            </div>
        </div><!-- /table-card -->

    </div><!-- /content -->
</div><!-- /main -->

</body>
</html>
<?php $conn->close(); ?>