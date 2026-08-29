<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// ── DB Connection ─────────────────────────────────────────────
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'parkify_db';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// ── Stat: Total Slots ─────────────────────────────────────────
$total_slots     = $conn->query("SELECT COUNT(*) AS c FROM parking_slots")->fetch_assoc()['c'];
$available_slots = $conn->query("SELECT COUNT(*) AS c FROM parking_slots WHERE status='available'")->fetch_assoc()['c'];
$occupied_slots  = $conn->query("SELECT COUNT(*) AS c FROM parking_slots WHERE status='occupied'")->fetch_assoc()['c'];
$occupied_pct    = $total_slots > 0 ? round(($occupied_slots / $total_slots) * 100) : 0;
$available_pct   = 100 - $occupied_pct;

// ── Stat: Users ───────────────────────────────────────────────
$total_users = $conn->query("SELECT COUNT(*) AS c FROM users")->fetch_assoc()['c'];

// ── Stat: Bookings Today ──────────────────────────────────────
$bookings_today = $conn->query("
    SELECT COUNT(*) AS c FROM parking_sessions
    WHERE DATE(entry_time) = CURDATE()
")->fetch_assoc()['c'];

// ── Stat: Today's Revenue ─────────────────────────────────────
$revenue_today = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS r FROM payments
    WHERE status IN ('success','paid') AND DATE(paid_at) = CURDATE()
")->fetch_assoc()['r'];

// ── Revenue last 7 days (for chart) ──────────────────────────
$revenue_chart = [];
$labels_chart  = [];
$r7 = $conn->query("
    SELECT DATE(paid_at) AS day, SUM(amount) AS total
    FROM payments
    WHERE status IN ('success','paid') AND paid_at >= CURDATE() - INTERVAL 6 DAY
    GROUP BY day ORDER BY day ASC
");
$revenue_map = [];
while ($row = $r7->fetch_assoc()) {
    $revenue_map[$row['day']] = (float)$row['total'];
}
for ($i = 6; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels_chart[]  = date('D', strtotime($date));
    $revenue_chart[] = $revenue_map[$date] ?? 0;
}

// ── Recent Bookings ───────────────────────────────────────────
$recent = $conn->query("
    SELECT
        u.full_name,
        ps.plate_number,
        sl.slot_code,
        ps.entry_time,
        ps.exit_time,
        ps.duration_mins,
        ps.status,
        COALESCE(p.amount, 0) AS amount
    FROM parking_sessions ps
    LEFT JOIN users u    ON ps.user_id    = u.id
    LEFT JOIN parking_slots sl ON ps.slot_id = sl.id
    LEFT JOIN payments p ON p.session_id  = ps.id AND p.status IN ('success','paid')
    ORDER BY ps.entry_time DESC
    LIMIT 5
");

// ── Slot rows for live grid ───────────────────────────────────
$slot_rows_raw = $conn->query("
    SELECT slot_code, row_label, slot_number, status
    FROM parking_slots
    ORDER BY row_label, slot_number
");
$slot_rows = [];
while ($s = $slot_rows_raw->fetch_assoc()) {
    $slot_rows[$s['row_label']][] = $s;
}

// ── System alerts ─────────────────────────────────────────────
$alerts = [];
// Near-capacity rows
$cap = $conn->query("
    SELECT row_label,
           COUNT(*) AS total,
           SUM(status='occupied') AS occ
    FROM parking_slots
    GROUP BY row_label
    HAVING occ/total >= 0.75
");
while ($row = $cap->fetch_assoc()) {
    $pct = round(($row['occ'] / $row['total']) * 100);
    $alerts[] = ['type'=>'warning','icon'=>'fa-triangle-exclamation',
                 'title'=>"Zone {$row['row_label']} Nearing Capacity",
                 'msg'  =>"$pct% of slots in row {$row['row_label']} are occupied."];
}
// Pending payments > 10
$pending_count = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE status='pending'")->fetch_assoc()['c'];
if ($pending_count > 0) {
    $alerts[] = ['type'=>'error','icon'=>'fa-circle-exclamation',
                 'title'=>'Pending Payments',
                 'msg'  =>"$pending_count payment(s) are still pending."];
}
if (empty($alerts)) {
    $alerts[] = ['type'=>'success','icon'=>'fa-circle-check',
                 'title'=>'All Systems Normal',
                 'msg'  =>'No issues detected at this time.'];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Dashboard</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
<link rel="icon" href="../images/fabiconlogo.png" type="image/png" />
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
  --blue:       #2563eb;
  --blue-dark:  #1d4ed8;
  --blue-light: #eff6ff;
  --blue-mid:   #dbeafe;
  --sidebar-bg: #1e3a8a;
  --sidebar-hover: rgba(255,255,255,0.1);
  --sidebar-active: rgba(255,255,255,0.15);
  --text:       #111827;
  --muted:      #6b7280;
  --border:     #e5e7eb;
  --bg:         #f3f4f6;
  --surface:    #ffffff;
  --green:      #10b981;
  --green-bg:   #ecfdf5;
  --orange:     #f59e0b;
  --orange-bg:  #fffbeb;
  --red:        #ef4444;
  --red-bg:     #fef2f2;
  --purple:     #8b5cf6;
  --purple-bg:  #f5f3ff;
  --font:       'Inter', sans-serif;
}

body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  display: flex;
  min-height: 100vh;
}

/* ── Main ─────────────────────────────────────────────── */
.main {
  margin-left: 220px;
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

/* ── Topbar ───────────────────────────────────────────── */
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

.topbar-right {
  display: flex;
  align-items: center;
  gap: 16px;
}
.search-box {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  padding: 7px 12px;
  width: 220px;
}
.search-box input {
  border: none;
  background: transparent;
  font-size: 13px;
  color: var(--text);
  outline: none;
  width: 100%;
}
.search-box i { color: var(--muted); font-size: 13px; }

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

/* ── Page body ────────────────────────────────────────── */
.content { padding: 24px 28px; display: flex; flex-direction: column; gap: 20px; }

/* ── Stat cards ───────────────────────────────────────── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  gap: 14px;
}

.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.stat-card.revenue-card {
  background: var(--blue);
  border-color: var(--blue);
  color: #fff;
  position: relative;
  overflow: hidden;
}
.stat-card.revenue-card::after {
  content: '';
  position: absolute;
  top: -20px; right: -20px;
  width: 80px; height: 80px;
  background: rgba(255,255,255,0.08);
  border-radius: 50%;
}

.stat-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.stat-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
}
.stat-icon.blue   { background: var(--blue-mid);   color: var(--blue); }
.stat-icon.green  { background: var(--green-bg);   color: var(--green); }
.stat-icon.red    { background: var(--red-bg);     color: var(--red); }
.stat-icon.purple { background: var(--purple-bg);  color: var(--purple); }
.stat-icon.orange { background: var(--orange-bg);  color: var(--orange); }
.stat-icon.white  { background: rgba(255,255,255,0.2); color: #fff; }

.stat-badge {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 7px;
  border-radius: 20px;
}
.stat-badge.up   { background: var(--green-bg); color: var(--green); }
.stat-badge.down { background: var(--red-bg); color: var(--red); }
.stat-badge.white { background: rgba(255,255,255,0.2); color: #fff; }

.stat-label {
  font-size: 11.5px;
  color: var(--muted);
  font-weight: 500;
}
.revenue-card .stat-label { color: rgba(255,255,255,0.75); }

.stat-value {
  font-size: 26px;
  font-weight: 700;
  color: var(--text);
  line-height: 1;
}
.revenue-card .stat-value { color: #fff; }

.stat-sub {
  font-size: 11px;
  color: var(--muted);
}
.revenue-card .stat-sub { color: rgba(255,255,255,0.65); }

/* ── Middle row ───────────────────────────────────────── */
.mid-row {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 16px;
}

.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}
.card-head {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.card-title { font-size: 14px; font-weight: 600; }
.card-sub   { font-size: 11.5px; color: var(--muted); margin-top: 2px; }

.toggle-btns {
  display: flex;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 6px;
  overflow: hidden;
}
.toggle-btn {
  padding: 5px 12px;
  font-size: 12px;
  font-weight: 500;
  color: var(--muted);
  cursor: pointer;
  border: none;
  background: transparent;
  transition: all 0.15s;
}
.toggle-btn.active { background: var(--blue); color: #fff; }

.chart-wrap { padding: 16px 20px; height: 220px; }
canvas { width: 100% !important; }

/* ── Slot status donut ────────────────────────────────── */
.donut-wrap {
  padding: 20px;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}
.donut-container {
  position: relative;
  width: 160px; height: 160px;
}
.donut-center {
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
}
.donut-pct  { font-size: 28px; font-weight: 700; color: var(--text); line-height: 1; }
.donut-lbl  { font-size: 11px; color: var(--muted); }
.donut-legend {
  display: flex;
  gap: 20px;
}
.legend-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: var(--muted);
}
.legend-dot { width: 10px; height: 10px; border-radius: 50%; }

/* ── Bottom row ───────────────────────────────────────── */
.bottom-row {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 16px;
}

/* ── Table ────────────────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 10px 16px;
  font-size: 11px;
  font-weight: 600;
  color: var(--muted);
  text-align: left;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  background: var(--bg);
  border-bottom: 1px solid var(--border);
}
tbody td {
  padding: 12px 16px;
  font-size: 13px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: var(--bg); }

.user-cell { display: flex; align-items: center; gap: 10px; }
.user-av {
  width: 30px; height: 30px;
  border-radius: 50%;
  background: var(--blue-mid);
  color: var(--blue);
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; flex-shrink: 0;
}
.user-name  { font-size: 13px; font-weight: 500; }
.user-plate { font-size: 11px; color: var(--muted); }

.slot-tag {
  display: inline-block;
  background: var(--blue-light);
  color: var(--blue);
  font-size: 12px;
  font-weight: 600;
  padding: 2px 9px;
  border-radius: 5px;
}

.badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 20px;
  font-size: 11px;
  font-weight: 600;
}
.badge-active    { background: var(--green-bg);  color: var(--green); }
.badge-completed { background: var(--blue-mid);  color: var(--blue);  }
.badge-cancelled { background: var(--red-bg);    color: var(--red);   }

.view-all {
  color: var(--blue);
  font-size: 12.5px;
  font-weight: 500;
  text-decoration: none;
}
.view-all:hover { text-decoration: underline; }

/* ── Alerts ───────────────────────────────────────────── */
.alerts-body { padding: 16px; display: flex; flex-direction: column; gap: 10px; }
.alert-item {
  display: flex;
  gap: 12px;
  padding: 12px;
  border-radius: 8px;
  border: 1px solid transparent;
}
.alert-item.warning { background: var(--orange-bg); border-color: #fde68a; }
.alert-item.error   { background: var(--red-bg);    border-color: #fecaca; }
.alert-item.success { background: var(--green-bg);  border-color: #a7f3d0; }

.alert-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
}
.alert-item.warning .alert-icon { background: #fef3c7; color: var(--orange); }
.alert-item.error   .alert-icon { background: #fee2e2; color: var(--red);    }
.alert-item.success .alert-icon { background: #d1fae5; color: var(--green);  }

.alert-title { font-size: 13px; font-weight: 600; margin-bottom: 3px; }
.alert-msg   { font-size: 12px; color: var(--muted); line-height: 1.4; }

/* ── Camera Control Panel ─────────────────────────────────── */
.camera-row {
  display: grid;
  grid-template-columns: 340px 1fr;
  gap: 16px;
  align-items: start;
}

.camera-ctrl-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}

.camera-ctrl-body {
  padding: 20px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

/* Status indicator pill */
.cam-status-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.cam-status-pill {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  transition: all 0.25s;
}
.cam-status-pill.stopped {
  background: var(--red-bg);
  color: var(--red);
}
.cam-status-pill.running {
  background: var(--green-bg);
  color: var(--green);
}
.cam-status-dot {
  width: 8px; height: 8px;
  border-radius: 50%;
  flex-shrink: 0;
}
.cam-status-pill.stopped .cam-status-dot { background: var(--red); }
.cam-status-pill.running .cam-status-dot {
  background: var(--green);
  animation: pulse-dot 1.4s ease-in-out infinite;
}
@keyframes pulse-dot {
  0%,100% { opacity: 1; transform: scale(1); }
  50%      { opacity: 0.4; transform: scale(0.7); }
}

/* Camera illustration area */
.cam-visual {
  background: #0f172a;
  border-radius: 10px;
  height: 140px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  position: relative;
  overflow: hidden;
}
.cam-visual::before {
  content: '';
  position: absolute;
  inset: 0;
  background: radial-gradient(ellipse at center, rgba(37,99,235,0.15) 0%, transparent 70%);
}
.cam-visual i {
  font-size: 36px;
  color: rgba(255,255,255,0.2);
  z-index: 1;
  transition: color 0.3s;
}
.cam-visual.active i { color: #2563eb; }
.cam-visual-text {
  font-size: 11px;
  color: rgba(255,255,255,0.35);
  z-index: 1;
  font-weight: 500;
  letter-spacing: 0.5px;
  transition: color 0.3s;
}
.cam-visual.active .cam-visual-text { color: rgba(255,255,255,0.7); }

/* Scanning bar animation */
.scan-bar {
  position: absolute;
  left: 0; right: 0;
  height: 2px;
  background: linear-gradient(90deg, transparent, #2563eb, transparent);
  opacity: 0;
  transition: opacity 0.3s;
  animation: none;
}
.cam-visual.active .scan-bar {
  opacity: 1;
  animation: scan-sweep 2s ease-in-out infinite;
}
@keyframes scan-sweep {
  0%   { top: 10%; }
  50%  { top: 90%; }
  100% { top: 10%; }
}

/* Start / Stop buttons */
.cam-btn-group {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.cam-btn {
  padding: 9px 0;
  border-radius: 8px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 7px;
  transition: all 0.18s;
}
.cam-btn:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}
.cam-btn-start {
  background: var(--blue);
  color: #fff;
}
.cam-btn-start:not(:disabled):hover { background: var(--blue-dark); }
.cam-btn-stop {
  background: var(--red-bg);
  color: var(--red);
  border: 1px solid #fecaca;
}
.cam-btn-stop:not(:disabled):hover { background: #fee2e2; }

/* PID info */
.cam-pid {
  font-size: 11px;
  color: var(--muted);
  text-align: center;
}
.cam-pid span { font-weight: 600; color: var(--text); }

/* ── Scan Log Table ───────────────────────────────────────── */
.scan-log-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  overflow: hidden;
}

.scan-log-body { overflow-x: auto; min-height: 120px; }

.scan-action-badge {
  display: inline-block;
  padding: 2px 9px;
  border-radius: 20px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.4px;
}
.action-entry      { background: #dcfce7; color: #15803d; }
.action-exit       { background: var(--blue-mid); color: var(--blue); }
.action-registered { background: #fef9c3; color: #a16207; }
.action-rejected   { background: #f1f5f9; color: #64748b; }
.action-no_slot    { background: var(--red-bg); color: var(--red); }
.action-db_error   { background: var(--purple-bg); color: var(--purple); }
.action-unknown    { background: var(--orange-bg); color: var(--orange); }

.scan-empty {
  text-align: center;
  padding: 32px 16px;
  color: var(--muted);
  font-size: 13px;
}
.scan-empty i { font-size: 28px; margin-bottom: 8px; display: block; opacity: 0.35; }

/* ── Toast notification ───────────────────────────────────── */
#cam-toast {
  position: fixed;
  bottom: 28px;
  right: 28px;
  background: #1e293b;
  color: #fff;
  padding: 12px 20px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 500;
  box-shadow: 0 8px 24px rgba(0,0,0,0.2);
  opacity: 0;
  transform: translateY(12px);
  transition: all 0.25s;
  pointer-events: none;
  z-index: 9999;
  max-width: 320px;
}
#cam-toast.show { opacity: 1; transform: translateY(0); }
#cam-toast.toast-ok  { border-left: 4px solid var(--green); }
#cam-toast.toast-err { border-left: 4px solid var(--red); }
</style>
</head>
<body>

<!-- ── Sidebar ─────────────────────────────────────────────── -->
<?php
$current_page = 'dashboard';
include 'sidebar.php';
?>

<!-- ── Main ───────────────────────────────────────────────── -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <h1>Dashboard Overview</h1>
      <p>Real-time monitoring of parking operations</p>
    </div>
    <div class="topbar-right">
      <div class="search-box">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" placeholder="Search bookings, users…"/>
      </div>
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

  <!-- Page content -->
  <div class="content">

    <!-- ── Stats ─────────────────────────────────────────── -->
    <div class="stats-grid">

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon blue"><i class="fa-solid fa-square-parking"></i></div>
          <span class="stat-badge up">Total</span>
        </div>
        <div class="stat-label">Total Slots</div>
        <div class="stat-value"><?= number_format($total_slots) ?></div>
        <div class="stat-sub">Across all zones</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
          <span class="stat-badge up">Open</span>
        </div>
        <div class="stat-label">Available</div>
        <div class="stat-value"><?= number_format($available_slots) ?></div>
        <div class="stat-sub"><?= $available_pct ?>% of total</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon red"><i class="fa-solid fa-car"></i></div>
          <span class="stat-badge down">In Use</span>
        </div>
        <div class="stat-label">Occupied</div>
        <div class="stat-value"><?= number_format($occupied_slots) ?></div>
        <div class="stat-sub"><?= $occupied_pct ?>% occupancy</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon purple"><i class="fa-solid fa-users"></i></div>
          <span class="stat-badge up">+12%</span>
        </div>
        <div class="stat-label">Total Users</div>
        <div class="stat-value"><?= number_format($total_users) ?></div>
        <div class="stat-sub">Registered accounts</div>
      </div>

      <div class="stat-card">
        <div class="stat-top">
          <div class="stat-icon orange"><i class="fa-solid fa-calendar-day"></i></div>
          <span class="stat-badge up">Today</span>
        </div>
        <div class="stat-label">Bookings Today</div>
        <div class="stat-value"><?= number_format($bookings_today) ?></div>
        <div class="stat-sub">Active sessions</div>
      </div>

      <div class="stat-card revenue-card">
        <div class="stat-top">
          <div class="stat-icon white"><i class="fa-solid fa-dollar-sign"></i></div>
          <span class="stat-badge white">+8.6%</span>
        </div>
        <div class="stat-label">Today's Revenue</div>
        <div class="stat-value">Rs. <?= number_format($revenue_today, 0) ?></div>
        <div class="stat-sub">Collected today</div>
      </div>

    </div>

    <!-- ── Chart + Donut ─────────────────────────────────── -->
    <div class="mid-row">

      <!-- Revenue chart -->
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">Revenue &amp; Usage Trend</div>
            <div class="card-sub">Weekly performance overview</div>
          </div>
          <div class="toggle-btns">
            <button class="toggle-btn active">Week</button>
            <button class="toggle-btn">Month</button>
          </div>
        </div>
        <div class="chart-wrap">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>

      <!-- Live slot donut -->
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">Live Slot Status</div>
            <div class="card-sub">Current occupancy distribution</div>
          </div>
        </div>
        <div class="donut-wrap">
          <div class="donut-container">
            <canvas id="slotDonut"></canvas>
            <div class="donut-center">
              <div class="donut-pct"><?= $occupied_pct ?>%</div>
              <div class="donut-lbl">Occupied</div>
            </div>
          </div>
          <div class="donut-legend">
            <div class="legend-item">
              <div class="legend-dot" style="background:#2563eb"></div>
              Occupied (<?= $occupied_pct ?>%)
            </div>
            <div class="legend-item">
              <div class="legend-dot" style="background:#e2e8f0"></div>
              Available (<?= $available_pct ?>%)
            </div>
          </div>
        </div>
      </div>

    </div>

    <!-- ── Recent Bookings + Alerts ──────────────────────── -->
    <div class="bottom-row">

      <!-- Recent bookings table -->
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">Recent Bookings</div>
            <div class="card-sub">Latest transactions &amp; active sessions</div>
          </div>
          <a href="bookings.php" class="view-all">View All</a>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>User / Vehicle</th>
                <th>Slot</th>
                <th>Duration</th>
                <th>Amount</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php while ($row = $recent->fetch_assoc()): ?>
              <?php
                $initials = '';
                foreach (explode(' ', trim($row['full_name'] ?? 'Guest')) as $w) {
                    $initials .= strtoupper($w[0] ?? '');
                }
                $initials = substr($initials, 0, 2);

                $duration = $row['duration_mins']
                    ? $row['duration_mins'] . ' min'
                    : (strtotime($row['exit_time']) > 0
                        ? round((strtotime($row['exit_time']) - strtotime($row['entry_time'])) / 60) . ' min'
                        : 'Ongoing');

                $status_class = match($row['status']) {
                    'active'    => 'badge-active',
                    'completed' => 'badge-completed',
                    'cancelled' => 'badge-cancelled',
                    default     => 'badge-completed',
                };
              ?>
              <tr>
                <td>
                  <div class="user-cell">
                    <div class="user-av"><?= $initials ?: 'G' ?></div>
                    <div>
                      <div class="user-name"><?= htmlspecialchars($row['full_name'] ?? 'Guest') ?></div>
                      <div class="user-plate"><?= htmlspecialchars($row['plate_number']) ?></div>
                    </div>
                  </div>
                </td>
                <td><span class="slot-tag"><?= htmlspecialchars($row['slot_code'] ?? '—') ?></span></td>
                <td><?= $duration ?></td>
                <td>Rs. <?= number_format($row['amount'], 0) ?></td>
                <td><span class="badge <?= $status_class ?>"><?= ucfirst($row['status']) ?></span></td>
              </tr>
              <?php endwhile; ?>
              <?php if ($recent->num_rows === 0): ?>
              <tr><td colspan="5" style="text-align:center;color:var(--muted);padding:24px">No bookings yet</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- System alerts -->
      <div class="card">
        <div class="card-head">
          <div>
            <div class="card-title">System Alerts</div>
            <div class="card-sub">Requires your attention</div>
          </div>
        </div>
        <div class="alerts-body">
          <?php foreach ($alerts as $a): ?>
          <div class="alert-item <?= $a['type'] ?>">
            <div class="alert-icon"><i class="fa-solid <?= $a['icon'] ?>"></i></div>
            <div>
              <div class="alert-title"><?= htmlspecialchars($a['title']) ?></div>
              <div class="alert-msg"><?= htmlspecialchars($a['msg']) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

    </div>

    <!-- ── Camera Control + Live Scan Feed ──────────────────── -->
    <div class="camera-row">

      <!-- Control card -->
      <div class="camera-ctrl-card">
        <div class="card-head">
          <div>
            <div class="card-title"><i class="fa-solid fa-camera" style="color:var(--blue);margin-right:6px"></i>Plate Detection Camera</div>
            <div class="card-sub">Start / stop the ALPR server</div>
          </div>
          <div id="camStatusPill" class="cam-status-pill stopped">
            <div class="cam-status-dot"></div>
            <span id="camStatusText">Offline</span>
          </div>
        </div>

        <div class="camera-ctrl-body">

          <!-- Live camera feed -->
          <div id="camVisual" class="cam-visual">
            <div class="scan-bar"></div>
            <i class="fa-solid fa-camera-slash" id="camIcon"></i>
            <div class="cam-visual-text" id="camVisualText">CAMERA OFFLINE</div>
            <!-- Live feed: shown when camera is ON -->
            <img id="camFeed" src="" alt="Live camera feed"
                 style="display:none;position:absolute;inset:0;width:100%;height:100%;object-fit:cover;border-radius:10px;z-index:2;" />
          </div>

          <!-- Start / Stop buttons -->
          <div class="cam-btn-group">
            <button class="cam-btn cam-btn-start" id="btnStart" onclick="cameraAction('start')">
              <i class="fa-solid fa-play"></i> Start Camera
            </button>
            <button class="cam-btn cam-btn-stop"  id="btnStop"  onclick="cameraAction('stop')" disabled>
              <i class="fa-solid fa-stop"></i> Stop Camera
            </button>
          </div>

          <!-- PID info line -->
          <div class="cam-pid" id="camPidInfo">PID: <span>—</span></div>

        </div>
      </div>

      <!-- Live scan log -->
      <div class="scan-log-card">
        <div class="card-head">
          <div>
            <div class="card-title"><i class="fa-solid fa-list-check" style="color:var(--blue);margin-right:6px"></i>Live Scan Feed</div>
            <div class="card-sub">Auto-refreshes every 3 s while camera is running</div>
          </div>
          <div style="display:flex;align-items:center;gap:10px">
            <span id="scanCount" style="font-size:12px;color:var(--muted)">0 scans</span>
            <div id="pollDot" style="width:8px;height:8px;border-radius:50%;background:var(--border);flex-shrink:0;transition:background .3s"></div>
          </div>
        </div>
        <div class="scan-log-body">
          <table id="scanTable">
            <thead>
              <tr>
                <th>Time</th>
                <th>Plate</th>
                <th>Action</th>
                <th>Confidence</th>
                <th>Vehicle</th>
              </tr>
            </thead>
            <tbody id="scanTbody">
              <tr>
                <td colspan="5">
                  <div class="scan-empty">
                    <i class="fa-solid fa-camera"></i>
                    Start the camera to see live scan results
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

    </div><!-- /camera-row -->

  </div><!-- /content -->
</div><!-- /main -->

<script>
// ── Revenue Line Chart ───────────────────────────────────────
const rCtx = document.getElementById('revenueChart').getContext('2d');
const labels = <?= json_encode($labels_chart) ?>;
const data   = <?= json_encode($revenue_chart) ?>;

const gradient = rCtx.createLinearGradient(0, 0, 0, 200);
gradient.addColorStop(0, 'rgba(37,99,235,0.25)');
gradient.addColorStop(1, 'rgba(37,99,235,0)');

new Chart(rCtx, {
  type: 'line',
  data: {
    labels,
    datasets: [{
      label: 'Revenue (Rs.)',
      data,
      borderColor: '#2563eb',
      backgroundColor: gradient,
      borderWidth: 2.5,
      fill: true,
      tension: 0.45,
      pointBackgroundColor: '#2563eb',
      pointRadius: 4,
      pointHoverRadius: 6,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#9ca3af' } },
      y: { grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, color: '#9ca3af',
            callback: v => 'Rs.' + v.toLocaleString() } }
    }
  }
});

// ── Donut Chart ──────────────────────────────────────────────
const dCtx = document.getElementById('slotDonut').getContext('2d');
new Chart(dCtx, {
  type: 'doughnut',
  data: {
    datasets: [{
      data: [<?= $occupied_pct ?>, <?= $available_pct ?>],
      backgroundColor: ['#2563eb', '#e2e8f0'],
      borderWidth: 0,
      hoverOffset: 4,
    }]
  },
  options: {
    responsive: true,
    cutout: '72%',
    plugins: { legend: { display: false }, tooltip: { enabled: true } }
  }
});

// ── Toggle week / month ──────────────────────────────────────
document.querySelectorAll('.toggle-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  });
});
</script>

<!-- ── Toast notification ──────────────────────────────────── -->
<div id="cam-toast"></div>

<script>
// ════════════════════════════════════════════════════════════════
//  Parkify — Camera Control + Live Scan Feed
// ════════════════════════════════════════════════════════════════

// ── State ─────────────────────────────────────────────────────
let camRunning  = false;
let pollTimer   = null;
const POLL_MS   = 3000;   // refresh interval (ms)

// ── DOM refs ──────────────────────────────────────────────────
const btnStart      = document.getElementById('btnStart');
const btnStop       = document.getElementById('btnStop');
const camVisual     = document.getElementById('camVisual');
const camIcon       = document.getElementById('camIcon');
const camVisualText = document.getElementById('camVisualText');
const camStatusPill = document.getElementById('camStatusPill');
const camStatusText = document.getElementById('camStatusText');
const camPidInfo    = document.getElementById('camPidInfo');
const scanTbody     = document.getElementById('scanTbody');
const scanCount     = document.getElementById('scanCount');
const pollDot       = document.getElementById('pollDot');

// ── Toast helper ──────────────────────────────────────────────
function showToast(msg, ok = true) {
  const t = document.getElementById('cam-toast');
  t.textContent = msg;
  t.className = 'show ' + (ok ? 'toast-ok' : 'toast-err');
  clearTimeout(t._timer);
  t._timer = setTimeout(() => { t.className = ''; }, 3200);
}

// ── UI: apply running / stopped state ─────────────────────────
function setRunningUI(running, pid = null) {
  camRunning = running;

  // Status pill
  camStatusPill.className = 'cam-status-pill ' + (running ? 'running' : 'stopped');
  camStatusText.textContent = running ? 'Live' : 'Offline';

  // Visual area
  camVisual.className = 'cam-visual' + (running ? ' active' : '');
  camIcon.className   = running
    ? 'fa-solid fa-camera'
    : 'fa-solid fa-camera-slash';
  camVisualText.textContent = running ? 'SCANNING FOR PLATES…' : 'CAMERA OFFLINE';

  // Buttons
  btnStart.disabled = running;
  btnStop.disabled  = !running;

  // PID
  camPidInfo.innerHTML = 'PID: <span>' + (pid ? pid : '—') + '</span>';

  // Poll dot colour
  pollDot.style.background = running ? 'var(--green)' : 'var(--border)';

  // Live feed image
  const camFeed = document.getElementById('camFeed');
  if (running) {
    camFeed.style.display = 'block';
    if (!window._feedTimer) {
      window._feedTimer = setInterval(() => {
        camFeed.src = 'frame.php?_=' + Date.now();
      }, 200);
    }
  } else {
    camFeed.style.display = 'none';
    camFeed.src = '';
    if (window._feedTimer) { clearInterval(window._feedTimer); window._feedTimer = null; }
  }

  // Start / stop polling loop
  if (running) {
    if (!pollTimer) pollTimer = setInterval(pollStatus, POLL_MS);
  } else {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    pollDot.style.background = 'var(--border)';
  }
}

// ── Start / Stop button handler ───────────────────────────────
async function cameraAction(action) {
  btnStart.disabled = true;
  btnStop.disabled  = true;

  try {
    const res  = await fetch('camera_control.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action }),
    });
    const data = await res.json();

    if (!data.ok) {
      showToast('❌ ' + (data.message || 'Unknown error'), false);
      // Re-check actual state
      await pollStatus();
      return;
    }

    const running = ['started', 'already_running', 'running'].includes(data.status);
    setRunningUI(running, data.pid ?? null);
    showToast((data.message || (running ? 'Camera started' : 'Camera stopped')), true);

    if (running) pollStatus();   // immediate first scan fetch

  } catch (err) {
    showToast('❌ Network error: ' + err.message, false);
    btnStart.disabled = false;
    btnStop.disabled  = false;
  }
}

// ── Poll: status + scan log ───────────────────────────────────
async function pollStatus() {
  // Blink poll dot
  pollDot.style.background = 'var(--blue)';
  setTimeout(() => {
    pollDot.style.background = camRunning ? 'var(--green)' : 'var(--border)';
  }, 300);

  try {
    const res  = await fetch('camera_status.php?_=' + Date.now());
    const data = await res.json();

    if (!data.ok) return;

    // If server stopped the process externally, sync UI
    if (camRunning && !data.running) {
      setRunningUI(false);
      showToast('⚠️ Camera process ended unexpectedly.', false);
    } else if (!camRunning && data.running) {
      setRunningUI(true, data.pid);
    }

    // Re-render scan table
    renderScans(data.scans || []);

  } catch (_) { /* network blip — ignore */ }
}

// ── Render scan rows ──────────────────────────────────────────
const ACTION_CLASS = {
  entry:      'action-entry',
  exit:       'action-exit',
  registered: 'action-registered',
  rejected:   'action-rejected',
  no_slot:    'action-no_slot',
  db_error:   'action-db_error',
  unknown:    'action-unknown',
};

function renderScans(scans) {
  scanCount.textContent = scans.length + ' scan' + (scans.length !== 1 ? 's' : '');

  if (!scans.length) {
    scanTbody.innerHTML = `<tr><td colspan="5">
      <div class="scan-empty">
        <i class="fa-solid fa-camera"></i>
        No scans logged yet
      </div></td></tr>`;
    return;
  }

  scanTbody.innerHTML = scans.map(s => {
    const time    = s.scanned_at ? s.scanned_at.slice(11, 19) : '—';
    const plate   = s.plate  || '—';
    const action  = s.action || 'unknown';
    const cls     = ACTION_CLASS[action] || 'action-unknown';
    const conf    = s.confidence || '—';
    const vehicle = [s.make, s.model, s.color].filter(Boolean).join(' ') || '—';
    return `<tr>
      <td style="font-size:12px;color:var(--muted);white-space:nowrap">${time}</td>
      <td><strong style="font-family:monospace;letter-spacing:1px">${plate}</strong></td>
      <td><span class="scan-action-badge ${cls}">${action}</span></td>
      <td style="font-size:12px">${conf}</td>
      <td style="font-size:12px;color:var(--muted)">${vehicle}</td>
    </tr>`;
  }).join('');
}

// ── On page load: check camera status ────────────────────────
(async () => {
  try {
    const res  = await fetch('camera_status.php?_=' + Date.now());
    const data = await res.json();
    if (data.ok) {
      setRunningUI(data.running, data.pid ?? null);
      renderScans(data.scans || []);
      if (data.running) pollStatus();
    }
  } catch (_) {}
})();
</script>
</body>
</html>