<?php
// ── Auth & DB ──────────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: ../login.php'); exit;
}
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'parkify_db';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: bookings.php'); exit; }

// ── Fetch full session detail ──────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        ps.id,
        ps.entry_time,
        ps.exit_time,
        ps.duration_mins,
        ps.status,
        ps.plate_number,
        u.id          AS user_id,
        u.full_name,
        u.email,
        u.role,
        v.id          AS vehicle_id,
        v.make,
        v.model,
        v.color,
        v.plate_number  AS reg_plate,
        sl.id           AS slot_id,
        sl.slot_code,
        sl.row_label,
        py.id           AS payment_id,
        py.amount,
        py.rate_per_hour,
        py.method,
        py.status       AS payment_status,
        py.transaction_id,
        py.paid_at
    FROM   parking_sessions ps
    LEFT JOIN users         u  ON u.id         = ps.user_id
    LEFT JOIN vehicles      v  ON v.id         = ps.vehicle_id
    LEFT JOIN parking_slots sl ON sl.id        = ps.slot_id
    LEFT JOIN payments      py ON py.session_id = ps.id
    WHERE  ps.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$b = $stmt->get_result()->fetch_assoc();

if (!$b) { header('Location: bookings.php?error=not_found'); exit; }

// ── Helpers ────────────────────────────────────────────────────
function bookingId(int $id): string {
    return 'BK-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}

function fmtDatetime(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y  g:i A', strtotime($dt));
}

function duration(?int $mins): string {
    if ($mins === null) return '—';
    if ($mins < 60) return "{$mins}m";
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $m ? "{$h}h {$m}m" : "{$h}h";
}

function statusBadge(string $status): string {
    $map = [
        'active'    => ['Active',     '#dbeafe', '#2563eb'],
        'completed' => ['Completed',  '#dcfce7', '#16a34a'],
        'cancelled' => ['Cancelled',  '#fee2e2', '#dc2626'],
        'pending'   => ['Pending',    '#fef9c3', '#ca8a04'],
        'no-show'   => ['No-show',    '#f3e8ff', '#7c3aed'],
    ];
    [$label, $bg, $color] = $map[strtolower($status)] ?? [ucfirst($status), '#f3f4f6', '#374151'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600;background:$bg;color:$color\">
        <span style=\"width:6px;height:6px;border-radius:50%;background:$color;flex-shrink:0;\"></span>$label</span>";
}

function paymentBadge(?string $status): string {
    if (!$status) return '<span style="color:#9ca3af;font-size:13px;">—</span>';
    $map = [
        'success' => ['Paid',    '#dcfce7', '#16a34a'],
        'paid'    => ['Paid',    '#dcfce7', '#16a34a'],
        'pending' => ['Pending', '#fef9c3', '#ca8a04'],
        'failed'  => ['Failed',  '#fee2e2', '#dc2626'],
        'refunded'=> ['Refunded','#f3e8ff', '#7c3aed'],
    ];
    [$label, $bg, $color] = $map[strtolower($status)] ?? [ucfirst($status), '#f3f4f6', '#374151'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600;background:$bg;color:$color\">
        <span style=\"width:6px;height:6px;border-radius:50%;background:$color;flex-shrink:0;\"></span>$label</span>";
}

// Admin initials for topbar
$admin_name = $_SESSION['user_name'] ?? 'Admin';
$admin_role = $_SESSION['role']      ?? 'admin';
$words      = explode(' ', trim($admin_name));
$initials   = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));

$current_page = 'bookings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Booking <?= bookingId($id) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet"/>
<link rel="icon" href="../images/fabiconlogo.png" type="image/png"/>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:         #f5f6fa;
    --surface:    #ffffff;
    --border:     #e8eaf0;
    --text:       #1a1d2e;
    --muted:      #8589a0;
    --accent:     #2563eb;
    --accent-lt:  #eff4ff;
    --green:      #16a34a;  --green-bg:  #f0fdf4;
    --red:        #dc2626;  --red-bg:    #fef2f2;
    --amber:      #d97706;  --amber-bg:  #fffbeb;
    --purple:     #7c3aed;  --purple-bg: #f3e8ff;
    --font: 'DM Sans', sans-serif;
    --sidebar: #1e3a8a;
    --radius: 12px;
}

body {
    font-family: var(--font);
    background: var(--bg);
    color: var(--text);
    display: flex;
    min-height: 100vh;
    font-size: 14px;
}

/* ── Sidebar (reuse from sidebar.php) ─────────────────────── */
.sidebar {
    width: 220px; min-height: 100vh;
    background: var(--sidebar);
    display: flex; flex-direction: column;
    position: fixed; top: 0; left: 0; z-index: 100;
}
.sidebar-logo {
    height: 100px; padding: 20px;
    display: flex; align-items: center; gap: 10px;
    border-bottom: 1px solid rgba(255,255,255,0.1);
    text-decoration: none;
}
.sidebar-logo img { margin-top: 30px; margin-left: 10px; }
.sidebar-nav {
    padding: 12px; flex: 1;
    display: flex; flex-direction: column; gap: 2px;
}
.nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 8px;
    text-decoration: none; color: rgba(255,255,255,0.65);
    font-size: 13.5px; font-weight: 500; transition: all 0.15s;
}
.nav-item i { width: 16px; font-size: 14px; flex-shrink: 0; }
.nav-item:hover  { background: rgba(255,255,255,0.1);  color: #fff; }
.nav-item.active { background: rgba(255,255,255,0.15); color: #fff; }
.sidebar-bottom {
    padding: 12px;
    border-top: 1px solid rgba(255,255,255,0.1);
    display: flex; flex-direction: column; gap: 2px;
}

/* ── Main ──────────────────────────────────────────────────── */
.main {
    margin-left: 220px; flex: 1;
    display: flex; flex-direction: column; min-height: 100vh;
}

/* ── Topbar ────────────────────────────────────────────────── */
.topbar {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 0 28px; height: 60px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 50;
}
.topbar-title { display: flex; align-items: center; gap: 10px; }
.topbar-title .back-btn {
    display: flex; align-items: center; gap: 6px;
    text-decoration: none; color: var(--muted);
    font-size: 13px; font-weight: 500;
    padding: 5px 10px; border-radius: 7px;
    border: 1px solid var(--border);
    transition: all 0.15s;
}
.topbar-title .back-btn:hover { background: var(--bg); color: var(--text); }
.topbar-title .back-btn svg { width: 14px; height: 14px; }
.topbar-title .divider { width: 1px; height: 20px; background: var(--border); }
.topbar-title h1 { font-size: 18px; font-weight: 700; }
.topbar-title p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.admin-pill   { display: flex; align-items: center; gap: 10px; }
.admin-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.admin-info .name { font-size: 13px; font-weight: 600; }
.admin-info .role { font-size: 11px; color: var(--muted); }

/* ── Content ───────────────────────────────────────────────── */
.content { padding: 28px; display: flex; flex-direction: column; gap: 20px; }

/* ── Page header ───────────────────────────────────────────── */
.page-header {
    display: flex; align-items: center; justify-content: space-between;
}
.page-header-left { display: flex; align-items: center; gap: 14px; }
.booking-id-badge {
    font-size: 20px; font-weight: 700; color: var(--text);
    font-variant-numeric: tabular-nums;
}
.page-header-actions { display: flex; align-items: center; gap: 8px; }
.btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px; border-radius: 8px; border: none;
    font-size: 13px; font-weight: 600; cursor: pointer;
    text-decoration: none; transition: all 0.15s; font-family: var(--font);
}
.btn svg { width: 14px; height: 14px; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-outline:hover { background: var(--bg); }
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: #1d4ed8; }
.btn-danger  { background: var(--red-bg); color: var(--red); border: 1px solid #fecaca; }
.btn-danger:hover  { background: #fee2e2; }

/* ── Grid layout ───────────────────────────────────────────── */
.detail-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
}
.detail-grid .full-width { grid-column: 1 / -1; }

/* ── Card ──────────────────────────────────────────────────── */
.card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
}
.card-header {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.card-header-icon {
    width: 32px; height: 32px; border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 15px; flex-shrink: 0;
}
.card-header h2 { font-size: 14px; font-weight: 700; }
.card-header p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.card-body { padding: 20px; }

/* ── Info rows ─────────────────────────────────────────────── */
.info-row {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 16px;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
}
.info-row:last-child { border-bottom: none; padding-bottom: 0; }
.info-row:first-child { padding-top: 0; }
.info-label { font-size: 12px; color: var(--muted); font-weight: 500; flex-shrink: 0; min-width: 120px; }
.info-value { font-size: 13px; font-weight: 600; text-align: right; }
.info-value.mono { font-family: 'DM Mono', monospace; letter-spacing: 0.5px; }

/* ── Timeline ──────────────────────────────────────────────── */
.timeline { display: flex; flex-direction: column; gap: 0; }
.timeline-row {
    display: flex; align-items: stretch; gap: 14px; position: relative;
}
.timeline-row:not(:last-child)::after {
    content: '';
    position: absolute; left: 15px; top: 34px;
    width: 2px; height: calc(100% - 14px);
    background: var(--border);
}
.tl-dot {
    width: 32px; height: 32px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    margin-top: 4px; position: relative; z-index: 1;
}
.tl-dot svg { width: 14px; height: 14px; }
.tl-body { padding-bottom: 20px; padding-top: 2px; flex: 1; }
.tl-label { font-size: 11px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; }
.tl-time  { font-size: 14px; font-weight: 700; margin-top: 2px; }
.tl-sub   { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* ── Amount highlight ──────────────────────────────────────── */
.amount-big {
    font-size: 32px; font-weight: 800;
    color: var(--text); line-height: 1;
}
.amount-sub { font-size: 12px; color: var(--muted); margin-top: 4px; }
.amount-row {
    display: flex; align-items: center;
    justify-content: space-between; gap: 16px;
    padding: 10px 0; border-bottom: 1px solid var(--border);
    font-size: 13px;
}
.amount-row:last-child { border-bottom: none; padding-bottom: 0; }
.amount-row:first-child { padding-top: 0; }

/* ── Avatar ────────────────────────────────────────────────── */
.user-avatar {
    width: 40px; height: 40px; border-radius: 50%;
    background: linear-gradient(135deg, #2563eb, #7c3aed);
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.user-block { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
.user-block .name { font-size: 14px; font-weight: 700; }
.user-block .email { font-size: 12px; color: var(--muted); margin-top: 2px; }
</style>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
</head>
<body>

<?php
$current_page = 'bookings';
include 'sidebar.php';
?>

<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <a href="bookings.php" class="back-btn">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back
      </a>
      <div class="divider"></div>
      <div>
        <h1>Booking Details</h1>
        <p><?= bookingId($id) ?></p>
      </div>
    </div>
    <div class="topbar-right">
      <div class="admin-pill">
        <div class="admin-avatar"><?= htmlspecialchars($initials) ?></div>
        <div class="admin-info">
          <div class="name"><?= htmlspecialchars($admin_name) ?></div>
          <div class="role"><?= htmlspecialchars(ucfirst($admin_role)) ?></div>
        </div>
      </div>
    </div>
  </header>

  <div class="content">

    <!-- Page header -->
    <div class="page-header">
      <div class="page-header-left">
        <span class="booking-id-badge"><?= bookingId($id) ?></span>
        <?= statusBadge($b['status']) ?>
        <?= paymentBadge($b['payment_status']) ?>
      </div>
      <div class="page-header-actions">
        <a href="booking_edit.php?id=<?= $id ?>" class="btn btn-outline">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          Manage
        </a>
      </div>
    </div>

    <div class="detail-grid">

      <!-- Session Timeline -->
      <div class="card">
        <div class="card-header">
          <div class="card-header-icon" style="background:#eff6ff;color:#2563eb;">
            <i class="fa-solid fa-clock"></i>
          </div>
          <div>
            <h2>Session Timeline</h2>
            <p>Entry, exit and duration</p>
          </div>
        </div>
        <div class="card-body">
          <div class="timeline">
            <div class="timeline-row">
              <div class="tl-dot" style="background:#dcfce7;color:#16a34a;">
                <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
              </div>
              <div class="tl-body">
                <div class="tl-label">Entry</div>
                <div class="tl-time"><?= fmtDatetime($b['entry_time']) ?></div>
                <div class="tl-sub">Vehicle entered lot</div>
              </div>
            </div>
            <div class="timeline-row">
              <div class="tl-dot" style="background:<?= $b['exit_time'] ? '#fee2e2' : '#f3f4f6' ?>;color:<?= $b['exit_time'] ? '#dc2626' : '#9ca3af' ?>;">
                <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M17 3a2.828 2.828 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5L17 3z"/></svg>
              </div>
              <div class="tl-body">
                <div class="tl-label">Exit</div>
                <div class="tl-time"><?= $b['exit_time'] ? fmtDatetime($b['exit_time']) : '<span style="color:#9ca3af;font-weight:500;">Still parked</span>' ?></div>
                <div class="tl-sub">
                  <?php if ($b['duration_mins'] !== null): ?>
                    Duration: <?= duration((int)$b['duration_mins']) ?>
                  <?php elseif ($b['entry_time']): ?>
                    Duration: <?= duration((int)round((time() - strtotime($b['entry_time'])) / 60)) ?> (ongoing)
                  <?php else: ?>
                    —
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>
          <div style="margin-top:16px;padding-top:16px;border-top:1px solid var(--border);display:flex;gap:20px;">
            <div>
              <div style="font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;">Slot</div>
              <div style="font-size:18px;font-weight:800;margin-top:3px;color:var(--accent);">
                <?= htmlspecialchars($b['slot_code'] ?? '—') ?>
              </div>
              <div style="font-size:12px;color:var(--muted);">Zone <?= htmlspecialchars($b['row_label'] ?? '—') ?></div>
            </div>
            <div style="width:1px;background:var(--border);"></div>
            <div>
              <div style="font-size:11px;color:var(--muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;">Duration</div>
              <div style="font-size:18px;font-weight:800;margin-top:3px;">
                <?= duration($b['duration_mins']) ?>
              </div>
              <div style="font-size:12px;color:var(--muted);"><?= $b['exit_time'] ? 'Completed' : 'Ongoing' ?></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment Info -->
      <div class="card">
        <div class="card-header">
          <div class="card-header-icon" style="background:#f0fdf4;color:#16a34a;">
            <i class="fa-solid fa-credit-card"></i>
          </div>
          <div>
            <h2>Payment</h2>
            <p>Fee and transaction details</p>
          </div>
        </div>
        <div class="card-body">
          <?php if ($b['amount'] !== null): ?>
            <div style="margin-bottom:18px;padding-bottom:18px;border-bottom:1px solid var(--border);">
              <div class="amount-big">Rs <?= number_format((float)$b['amount'], 2) ?></div>
              <div class="amount-sub">Total amount charged</div>
            </div>
            <div class="amount-row">
              <span style="color:var(--muted);">Status</span>
              <?= paymentBadge($b['payment_status']) ?>
            </div>
            <div class="amount-row">
              <span style="color:var(--muted);">Method</span>
              <span style="font-weight:600;"><?= htmlspecialchars(ucfirst($b['method'] ?? '—')) ?></span>
            </div>
            <div class="amount-row">
              <span style="color:var(--muted);">Rate</span>
              <span style="font-weight:600;">Rs <?= number_format((float)($b['rate_per_hour'] ?? 0), 2) ?>/hr</span>
            </div>
            <?php if ($b['transaction_id']): ?>
            <div class="amount-row">
              <span style="color:var(--muted);">Transaction ID</span>
              <span style="font-weight:600;font-family:monospace;font-size:12px;"><?= htmlspecialchars($b['transaction_id']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($b['paid_at']): ?>
            <div class="amount-row">
              <span style="color:var(--muted);">Paid at</span>
              <span style="font-weight:600;"><?= fmtDatetime($b['paid_at']) ?></span>
            </div>
            <?php endif; ?>
          <?php else: ?>
            <div style="text-align:center;padding:28px 0;color:var(--muted);">
              <i class="fa-solid fa-receipt" style="font-size:28px;margin-bottom:8px;opacity:.4;display:block;"></i>
              No payment record yet
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- User Info -->
      <div class="card">
        <div class="card-header">
          <div class="card-header-icon" style="background:#ede9fe;color:#7c3aed;">
            <i class="fa-solid fa-user"></i>
          </div>
          <div>
            <h2>User</h2>
            <p>Account and contact info</p>
          </div>
        </div>
        <div class="card-body">
          <?php
            $uname = $b['full_name'] ?? 'Guest';
            $uparts = array_filter(explode(' ', trim($uname)));
            $uinitials = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice($uparts, 0, 2))));
          ?>
          <div class="user-block">
            <div class="user-avatar"><?= htmlspecialchars($uinitials ?: 'G') ?></div>
            <div>
              <div class="name"><?= htmlspecialchars($uname) ?></div>
              <div class="email"><?= htmlspecialchars($b['email'] ?? 'No email') ?></div>
            </div>
          </div>
          <div class="info-row">
            <span class="info-label">User ID</span>
            <span class="info-value mono"><?= $b['user_id'] ? '#' . $b['user_id'] : 'Guest' ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Role</span>
            <span class="info-value"><?= htmlspecialchars(ucfirst($b['role'] ?? 'guest')) ?></span>
          </div>
          <?php if ($b['user_id']): ?>
          <div class="info-row" style="border-bottom:none;padding-bottom:0;">
            <span class="info-label"></span>
            <a href="users.php?search=<?= urlencode($b['email'] ?? '') ?>" class="btn btn-outline" style="padding:5px 12px;font-size:12px;">
              View profile
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Vehicle Info -->
      <div class="card">
        <div class="card-header">
          <div class="card-header-icon" style="background:#fff7ed;color:#ea580c;">
            <i class="fa-solid fa-car"></i>
          </div>
          <div>
            <h2>Vehicle</h2>
            <p>Plate and registration details</p>
          </div>
        </div>
        <div class="card-body">
          <div style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border);">
            <div style="font-size:22px;font-weight:800;letter-spacing:2px;font-family:monospace;color:var(--text);">
              <?= htmlspecialchars($b['reg_plate'] ?? $b['plate_number'] ?? '—') ?>
            </div>
            <div style="font-size:12px;color:var(--muted);margin-top:3px;">License plate</div>
          </div>
          <div class="info-row">
            <span class="info-label">Make & Model</span>
            <span class="info-value">
              <?= htmlspecialchars(trim(($b['make'] ?? '') . ' ' . ($b['model'] ?? ''))) ?: '—' ?>
            </span>
          </div>
          <div class="info-row">
            <span class="info-label">Color</span>
            <span class="info-value"><?= htmlspecialchars($b['color'] ?? '—') ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Vehicle ID</span>
            <span class="info-value mono"><?= $b['vehicle_id'] ? '#' . $b['vehicle_id'] : '—' ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Scanned plate</span>
            <span class="info-value mono"><?= htmlspecialchars($b['plate_number'] ?? '—') ?></span>
          </div>
        </div>
      </div>

    </div><!-- /.detail-grid -->
  </div><!-- /.content -->
</div><!-- /.main -->

</body>
</html>
