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
if (!$id) { header('Location: payments.php'); exit; }

// ── Handle POST actions ────────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Mark as Paid ───────────────────────────────────────────
    if ($action === 'mark_paid') {
        $method = $conn->real_escape_string($_POST['method'] ?? 'cash');
        $amount = (float)($_POST['amount'] ?? 0);
        $conn->query("UPDATE payments
                      SET status='success', method='$method', amount=$amount,
                          paid_at=NOW(), transaction_id=IFNULL(transaction_id, CONCAT('TXN-', LPAD(id,6,'0')))
                      WHERE id=$id");
        $success = 'Payment marked as paid.';
    }

    // ── Mark as Refunded ───────────────────────────────────────
    elseif ($action === 'refund') {
        $conn->query("UPDATE payments SET status='refunded', paid_at=NULL WHERE id=$id");
        $success = 'Payment marked as refunded.';
    }

    // ── Mark as Failed ─────────────────────────────────────────
    elseif ($action === 'mark_failed') {
        $conn->query("UPDATE payments SET status='failed' WHERE id=$id");
        $success = 'Payment marked as failed.';
    }

    // ── Mark as Pending ────────────────────────────────────────
    elseif ($action === 'mark_pending') {
        $conn->query("UPDATE payments SET status='pending', paid_at=NULL WHERE id=$id");
        $success = 'Payment reset to pending.';
    }

    // ── Update amount & method ─────────────────────────────────
    elseif ($action === 'update') {
        $amount = (float)($_POST['amount'] ?? 0);
        $method = $conn->real_escape_string($_POST['method'] ?? 'cash');
        $rate   = (float)($_POST['rate_per_hour'] ?? 20);
        $conn->query("UPDATE payments
                      SET amount=$amount, method='$method', rate_per_hour=$rate
                      WHERE id=$id");
        $success = 'Payment record updated.';
    }

    // ── Delete ─────────────────────────────────────────────────
    elseif ($action === 'delete') {
        $conn->query("DELETE FROM payments WHERE id=$id");
        header('Location: payments.php?deleted=1'); exit;
    }
}

// ── Fetch payment + related data ──────────────────────────────
$stmt = $conn->prepare("
    SELECT
        p.id,
        p.session_id,
        p.user_id,
        p.amount,
        p.rate_per_hour,
        p.method,
        p.status,
        p.transaction_id,
        p.paid_at,
        p.created_at,
        u.full_name,
        u.email,
        u.role        AS user_role,
        ps.plate_number,
        ps.entry_time,
        ps.exit_time,
        ps.duration_mins,
        ps.status     AS session_status,
        sl.slot_code,
        sl.row_label,
        v.make,
        v.model,
        v.plate_number AS reg_plate
    FROM   payments p
    LEFT JOIN users         u  ON u.id         = p.user_id
    LEFT JOIN parking_sessions ps ON ps.id     = p.session_id
    LEFT JOIN parking_slots sl ON sl.id        = ps.slot_id
    LEFT JOIN vehicles      v  ON v.id         = ps.vehicle_id
    WHERE  p.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$p = $stmt->get_result()->fetch_assoc();

if (!$p) { header('Location: payments.php?error=not_found'); exit; }

// ── Helpers ────────────────────────────────────────────────────
function fmtDatetime(?string $dt): string {
    if (!$dt) return '—';
    return date('M j, Y  g:i A', strtotime($dt));
}

function duration(?int $mins): string {
    if ($mins === null) return '—';
    if ($mins < 60) return "{$mins}m";
    $h = intdiv($mins, 60); $m = $mins % 60;
    return $m ? "{$h}h {$m}m" : "{$h}h";
}

function statusBadge(string $status): string {
    $map = [
        'success'  => ['Paid',     '#dcfce7', '#16a34a'],
        'paid'     => ['Paid',     '#dcfce7', '#16a34a'],
        'pending'  => ['Pending',  '#fef9c3', '#ca8a04'],
        'failed'   => ['Failed',   '#fee2e2', '#dc2626'],
        'refunded' => ['Refunded', '#f3e8ff', '#7c3aed'],
    ];
    [$label, $bg, $color] = $map[strtolower($status)] ?? [ucfirst($status), '#f3f4f6', '#374151'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:99px;
            font-size:12px;font-weight:600;background:$bg;color:$color\">
        <span style=\"width:6px;height:6px;border-radius:50%;background:$color;flex-shrink:0;\"></span>$label</span>";
}

function sessionBadge(?string $status): string {
    if (!$status) return '<span style="color:#9ca3af;font-size:13px;">—</span>';
    $map = [
        'active'    => ['Active',    '#dbeafe', '#2563eb'],
        'completed' => ['Completed', '#dcfce7', '#16a34a'],
        'cancelled' => ['Cancelled', '#fee2e2', '#dc2626'],
    ];
    [$label, $bg, $color] = $map[strtolower($status)] ?? [ucfirst($status), '#f3f4f6', '#374151'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;
            font-size:12px;font-weight:600;background:$bg;color:$color\">
        <span style=\"width:6px;height:6px;border-radius:50%;background:$color;flex-shrink:0;\"></span>$label</span>";
}

$admin_name = $_SESSION['user_name'] ?? 'Admin';
$admin_role = $_SESSION['role']      ?? 'admin';
$words      = explode(' ', trim($admin_name));
$initials   = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));

$pstatus    = strtolower($p['status'] ?? 'pending');
$is_paid    = in_array($pstatus, ['success', 'paid']);
$is_pending = $pstatus === 'pending';
$is_failed  = $pstatus === 'failed';
$is_refunded= $pstatus === 'refunded';

$tx_id = $p['transaction_id'] ?? ('TXN-' . str_pad($id, 6, '0', STR_PAD_LEFT));

// User initials
$uname  = $p['full_name'] ?? 'Guest';
$uparts = array_filter(explode(' ', trim($uname)));
$uinit  = strtoupper(implode('', array_map(fn($w) => substr($w, 0, 1), array_slice($uparts, 0, 2)))) ?: 'G';

$current_page = 'payments';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Payment <?= htmlspecialchars($tx_id) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com"/>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&display=swap" rel="stylesheet"/>
<link rel="icon" href="../images/fabiconlogo.png" type="image/png"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
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
    --green:      #16a34a;  --green-bg:  #f0fdf4;  --green-bd: #bbf7d0;
    --red:        #dc2626;  --red-bg:    #fef2f2;  --red-bd:   #fecaca;
    --amber:      #d97706;  --amber-bg:  #fffbeb;  --amber-bd: #fde68a;
    --purple:     #7c3aed;  --purple-bg: #f3e8ff;  --purple-bd:#ddd6fe;
    --font: 'DM Sans', sans-serif;
    --sidebar: #1e3a8a;
    --radius: 12px;
}

body {
    font-family: var(--font); background: var(--bg);
    color: var(--text); display: flex; min-height: 100vh; font-size: 14px;
}

/* ── Sidebar ──────────────────────────────────────────────── */
.sidebar { width: 220px; min-height: 100vh; background: var(--sidebar); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; z-index: 100; }
.sidebar-logo { height: 100px; padding: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); text-decoration: none; }
.sidebar-logo img { margin-top: 30px; margin-left: 10px; }
.sidebar-nav { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 2px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; text-decoration: none; color: rgba(255,255,255,0.65); font-size: 13.5px; font-weight: 500; transition: all 0.15s; }
.nav-item i { width: 16px; font-size: 14px; flex-shrink: 0; }
.nav-item:hover  { background: rgba(255,255,255,0.1);  color: #fff; }
.nav-item.active { background: rgba(255,255,255,0.15); color: #fff; }
.sidebar-bottom { padding: 12px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column; gap: 2px; }

/* ── Main ──────────────────────────────────────────────────── */
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* ── Topbar ─────────────────────────────────────────────────── */
.topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 28px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-title { display: flex; align-items: center; gap: 10px; }
.back-btn { display: flex; align-items: center; gap: 6px; text-decoration: none; color: var(--muted); font-size: 13px; font-weight: 500; padding: 5px 10px; border-radius: 7px; border: 1px solid var(--border); transition: all .15s; }
.back-btn:hover { background: var(--bg); color: var(--text); }
.back-btn svg { width: 14px; height: 14px; }
.divider { width: 1px; height: 20px; background: var(--border); }
.topbar-title h1 { font-size: 18px; font-weight: 700; }
.topbar-title p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.admin-pill { display: flex; align-items: center; gap: 10px; }
.admin-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #7c3aed); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
.admin-info .name { font-size: 13px; font-weight: 600; }
.admin-info .role { font-size: 11px; color: var(--muted); }

/* ── Content ───────────────────────────────────────────────── */
.content { padding: 28px; display: flex; flex-direction: column; gap: 20px; }

/* ── Page header ────────────────────────────────────────────── */
.page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
.page-header-left { display: flex; align-items: center; gap: 12px; }
.tx-id-badge { font-size: 18px; font-weight: 700; color: var(--text); font-family: 'DM Mono', monospace; letter-spacing: .5px; }

/* ── Alert ──────────────────────────────────────────────────── */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.alert-success { background: var(--green-bg); color: var(--green); border: 1px solid var(--green-bd); }
.alert-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid var(--red-bd); }
.alert i { font-size: 15px; flex-shrink: 0; }

/* ── Grid ───────────────────────────────────────────────────── */
.detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.full-width { grid-column: 1 / -1; }

/* ── Card ───────────────────────────────────────────────────── */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
.card-header { padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 10px; }
.card-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 14px; flex-shrink: 0; }
.card-header h2 { font-size: 14px; font-weight: 700; }
.card-header p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.card-body { padding: 20px; }

/* ── Info rows ──────────────────────────────────────────────── */
.info-row { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.info-row:last-child { border-bottom: none; padding-bottom: 0; }
.info-row:first-child { padding-top: 0; }
.info-label { font-size: 12px; color: var(--muted); font-weight: 500; }
.info-value { font-size: 13px; font-weight: 600; text-align: right; }
.info-value.mono { font-family: monospace; letter-spacing: .5px; font-size: 12px; }

/* ── Amount hero ────────────────────────────────────────────── */
.amount-hero { padding: 20px; background: linear-gradient(135deg, #1e3a8a, #2563eb); border-radius: var(--radius); color: #fff; margin-bottom: 16px; }
.amount-hero .label { font-size: 12px; font-weight: 500; opacity: .75; text-transform: uppercase; letter-spacing: .5px; }
.amount-hero .value { font-size: 36px; font-weight: 800; line-height: 1.1; margin-top: 4px; }
.amount-hero .sub { font-size: 12px; opacity: .7; margin-top: 4px; }

/* ── Section title ──────────────────────────────────────────── */
.section-title { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }

/* ── Action cards ───────────────────────────────────────────── */
.action-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; display: flex; align-items: flex-start; gap: 16px; }
.action-card.disabled { opacity: .45; pointer-events: none; }
.action-icon { width: 38px; height: 38px; border-radius: 9px; display: flex; align-items: center; justify-content: center; font-size: 15px; flex-shrink: 0; }
.action-body { flex: 1; }
.action-body h3 { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
.action-body p  { font-size: 12px; color: var(--muted); line-height: 1.5; margin-bottom: 12px; }

/* ── Form fields inline ─────────────────────────────────────── */
.field-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.field-row label { font-size: 12px; color: var(--muted); font-weight: 500; }
.field-row input[type="number"],
.field-row select {
    padding: 7px 10px; border: 1px solid var(--border); border-radius: 7px;
    font-size: 13px; font-family: var(--font); color: var(--text);
    background: var(--bg); outline: none; transition: border-color .15s;
}
.field-row input:focus, .field-row select:focus { border-color: var(--accent); background: #fff; }

/* ── Buttons ────────────────────────────────────────────────── */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .15s; font-family: var(--font); }
.btn i { font-size: 13px; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-outline:hover { background: var(--bg); }
.btn-green  { background: var(--green-bg);  color: var(--green);  border: 1px solid var(--green-bd); }
.btn-green:hover  { background: #dcfce7; }
.btn-amber  { background: var(--amber-bg);  color: var(--amber);  border: 1px solid var(--amber-bd); }
.btn-amber:hover  { background: #fef3c7; }
.btn-red    { background: var(--red-bg);    color: var(--red);    border: 1px solid var(--red-bd); }
.btn-red:hover    { background: #fee2e2; }
.btn-purple { background: var(--purple-bg); color: var(--purple); border: 1px solid var(--purple-bd); }
.btn-purple:hover { background: #ede9fe; }
.btn-blue   { background: var(--accent-lt); color: var(--accent); border: 1px solid #bfdbfe; }
.btn-blue:hover { background: #dbeafe; }
.btn-danger { background: var(--red); color: #fff; }
.btn-danger:hover { background: #b91c1c; }

/* ── User block ─────────────────────────────────────────────── */
.user-block { display: flex; align-items: center; gap: 12px; margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
.user-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #7c3aed); display: flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 700; color: #fff; flex-shrink: 0; }
.user-block .uname { font-size: 14px; font-weight: 700; }
.user-block .uemail { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* ── Confirm modal ──────────────────────────────────────────── */
.confirm-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 200; align-items: center; justify-content: center; }
.confirm-overlay.open { display: flex; }
.confirm-box { background: var(--surface); border-radius: var(--radius); padding: 28px; width: 380px; max-width: 90vw; box-shadow: 0 20px 40px rgba(0,0,0,.15); }
.confirm-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
.confirm-box p  { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
.confirm-box .cta { display: flex; gap: 8px; justify-content: flex-end; }
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <a href="payments.php" class="back-btn">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back
      </a>
      <div class="divider"></div>
      <div>
        <h1>Payment Details</h1>
        <p><?= htmlspecialchars($tx_id) ?></p>
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

    <?php if ($success): ?>
      <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error"><i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Page header -->
    <div class="page-header">
      <div class="page-header-left">
        <span class="tx-id-badge"><?= htmlspecialchars($tx_id) ?></span>
        <?= statusBadge($p['status']) ?>
      </div>
      <?php if ($p['session_id']): ?>
        <a href="booking_view.php?id=<?= (int)$p['session_id'] ?>" class="btn btn-outline">
          <i class="fa-solid fa-calendar-check"></i> View Booking
        </a>
      <?php endif; ?>
    </div>

    <div class="detail-grid">

      <!-- Amount & Payment Info -->
      <div class="card">
        <div class="card-header">
          <div class="card-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa-solid fa-credit-card"></i></div>
          <div><h2>Payment Info</h2><p>Transaction and fee breakdown</p></div>
        </div>
        <div class="card-body">
          <div class="amount-hero">
            <div class="label">Amount Charged</div>
            <div class="value">Rs <?= number_format((float)$p['amount'], 2) ?></div>
            <div class="sub">@ Rs <?= number_format((float)($p['rate_per_hour'] ?? 0), 2) ?>/hr</div>
          </div>
          <div class="info-row">
            <span class="info-label">Status</span>
            <?= statusBadge($p['status']) ?>
          </div>
          <div class="info-row">
            <span class="info-label">Method</span>
            <span class="info-value"><?= htmlspecialchars(ucfirst($p['method'] ?? '—')) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Transaction ID</span>
            <span class="info-value mono"><?= htmlspecialchars($tx_id) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Paid at</span>
            <span class="info-value"><?= fmtDatetime($p['paid_at']) ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Created</span>
            <span class="info-value"><?= fmtDatetime($p['created_at']) ?></span>
          </div>
        </div>
      </div>

      <!-- Linked Session -->
      <div class="card">
        <div class="card-header">
          <div class="card-icon" style="background:#eff4ff;color:#2563eb;"><i class="fa-solid fa-clock-rotate-left"></i></div>
          <div><h2>Linked Session</h2><p>Parking session details</p></div>
        </div>
        <div class="card-body">
          <?php if ($p['session_id']): ?>
            <div class="info-row">
              <span class="info-label">Booking</span>
              <a href="booking_view.php?id=<?= (int)$p['session_id'] ?>" style="font-size:13px;font-weight:700;color:var(--accent);text-decoration:none;">
                BK-<?= str_pad($p['session_id'], 5, '0', STR_PAD_LEFT) ?>
              </a>
            </div>
            <div class="info-row">
              <span class="info-label">Session Status</span>
              <?= sessionBadge($p['session_status']) ?>
            </div>
            <div class="info-row">
              <span class="info-label">Slot</span>
              <span class="info-value"><?= htmlspecialchars($p['slot_code'] ?? '—') ?> — Zone <?= htmlspecialchars($p['row_label'] ?? '—') ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Plate</span>
              <span class="info-value mono"><?= htmlspecialchars($p['reg_plate'] ?? $p['plate_number'] ?? '—') ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Vehicle</span>
              <span class="info-value"><?= htmlspecialchars(trim(($p['make'] ?? '') . ' ' . ($p['model'] ?? ''))) ?: '—' ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Entry</span>
              <span class="info-value"><?= fmtDatetime($p['entry_time']) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Exit</span>
              <span class="info-value"><?= fmtDatetime($p['exit_time']) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Duration</span>
              <span class="info-value"><?= duration($p['duration_mins']) ?></span>
            </div>
          <?php else: ?>
            <div style="text-align:center;padding:28px 0;color:var(--muted);">
              <i class="fa-solid fa-link-slash" style="font-size:24px;margin-bottom:8px;opacity:.4;display:block;"></i>
              No linked session
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- User Info -->
      <div class="card">
        <div class="card-header">
          <div class="card-icon" style="background:#ede9fe;color:#7c3aed;"><i class="fa-solid fa-user"></i></div>
          <div><h2>User</h2><p>Account information</p></div>
        </div>
        <div class="card-body">
          <div class="user-block">
            <div class="user-avatar"><?= htmlspecialchars($uinit) ?></div>
            <div>
              <div class="uname"><?= htmlspecialchars($uname) ?></div>
              <div class="uemail"><?= htmlspecialchars($p['email'] ?? 'No email') ?></div>
            </div>
          </div>
          <div class="info-row">
            <span class="info-label">User ID</span>
            <span class="info-value mono"><?= $p['user_id'] ? '#' . $p['user_id'] : 'Guest' ?></span>
          </div>
          <div class="info-row">
            <span class="info-label">Role</span>
            <span class="info-value"><?= htmlspecialchars(ucfirst($p['user_role'] ?? 'guest')) ?></span>
          </div>
          <?php if ($p['user_id']): ?>
          <div class="info-row">
            <span class="info-label"></span>
            <a href="users.php?search=<?= urlencode($p['email'] ?? '') ?>" class="btn btn-outline" style="padding:5px 12px;font-size:12px;">
              View profile
            </a>
          </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Quick Edit -->
      <div class="card">
        <div class="card-header">
          <div class="card-icon" style="background:#fff7ed;color:#ea580c;"><i class="fa-solid fa-pen-to-square"></i></div>
          <div><h2>Edit Record</h2><p>Correct amount, rate or method</p></div>
        </div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="update"/>
            <div style="display:flex;flex-direction:column;gap:12px;">
              <div>
                <label style="font-size:12px;color:var(--muted);font-weight:500;display:block;margin-bottom:4px;">Amount (Rs)</label>
                <input type="number" name="amount" min="0" step="0.50"
                       value="<?= number_format((float)$p['amount'], 2, '.', '') ?>"
                       style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:var(--font);background:var(--bg);outline:none;"/>
              </div>
              <div>
                <label style="font-size:12px;color:var(--muted);font-weight:500;display:block;margin-bottom:4px;">Rate/hr (Rs)</label>
                <input type="number" name="rate_per_hour" min="0" step="0.50"
                       value="<?= number_format((float)($p['rate_per_hour'] ?? 20), 2, '.', '') ?>"
                       style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:var(--font);background:var(--bg);outline:none;"/>
              </div>
              <div>
                <label style="font-size:12px;color:var(--muted);font-weight:500;display:block;margin-bottom:4px;">Payment Method</label>
                <select name="method" style="width:100%;padding:8px 12px;border:1px solid var(--border);border-radius:8px;font-size:13px;font-family:var(--font);background:var(--bg);outline:none;">
                  <?php foreach (['cash','card','esewa','khalti'] as $m): ?>
                    <option value="<?= $m ?>" <?= ($p['method'] ?? '') === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-outline" style="align-self:flex-start;">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
              </button>
            </div>
          </form>
        </div>
      </div>

    </div><!-- /.detail-grid -->

    <!-- Status Actions -->
    <div>
      <div class="section-title">Status Actions</div>
      <div style="display:flex;flex-direction:column;gap:10px;">

        <!-- Mark Paid -->
        <div class="action-card <?= $is_paid ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:var(--green-bg);color:var(--green);"><i class="fa-solid fa-circle-check"></i></div>
          <div class="action-body">
            <h3>Mark as Paid</h3>
            <p>Record this payment as successfully collected and set the paid timestamp to now.</p>
            <form method="POST">
              <input type="hidden" name="action" value="mark_paid"/>
              <input type="hidden" name="amount" value="<?= htmlspecialchars($p['amount']) ?>"/>
              <div class="field-row">
                <label>Method</label>
                <select name="method">
                  <?php foreach (['cash','card','esewa','khalti'] as $m): ?>
                    <option value="<?= $m ?>" <?= ($p['method'] ?? 'cash') === $m ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button type="submit" class="btn btn-green"><i class="fa-solid fa-circle-check"></i> Mark Paid</button>
            </form>
          </div>
        </div>

        <!-- Refund -->
        <div class="action-card <?= !$is_paid ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:var(--purple-bg);color:var(--purple);"><i class="fa-solid fa-rotate-left"></i></div>
          <div class="action-body">
            <h3>Issue Refund</h3>
            <p>Mark this payment as refunded. Use this after returning the amount to the customer.</p>
            <form method="POST">
              <input type="hidden" name="action" value="refund"/>
              <button type="submit" class="btn btn-purple"><i class="fa-solid fa-rotate-left"></i> Mark Refunded</button>
            </form>
          </div>
        </div>

        <!-- Mark Failed -->
        <div class="action-card <?= $is_failed ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:var(--red-bg);color:var(--red);"><i class="fa-solid fa-circle-xmark"></i></div>
          <div class="action-body">
            <h3>Mark as Failed</h3>
            <p>Flag this payment as failed — e.g. a bounced card or declined transaction.</p>
            <form method="POST">
              <input type="hidden" name="action" value="mark_failed"/>
              <button type="submit" class="btn btn-red"><i class="fa-solid fa-circle-xmark"></i> Mark Failed</button>
            </form>
          </div>
        </div>

        <!-- Reset to Pending -->
        <div class="action-card <?= $is_pending ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:var(--amber-bg);color:var(--amber);"><i class="fa-solid fa-clock"></i></div>
          <div class="action-body">
            <h3>Reset to Pending</h3>
            <p>Clear the payment status and revert it back to pending. Useful to retry failed payments.</p>
            <form method="POST">
              <input type="hidden" name="action" value="mark_pending"/>
              <button type="submit" class="btn btn-amber"><i class="fa-solid fa-clock"></i> Reset to Pending</button>
            </form>
          </div>
        </div>

      </div>
    </div>

    <!-- Danger Zone -->
    <div>
      <div class="section-title" style="color:var(--red);">Danger Zone</div>
      <div class="action-card">
        <div class="action-icon" style="background:var(--red-bg);color:var(--red);"><i class="fa-solid fa-trash"></i></div>
        <div class="action-body">
          <h3>Delete Payment Record</h3>
          <p>Permanently remove this payment from the database. The linked booking session will remain but will have no payment record.</p>
          <button type="button" class="btn btn-danger" onclick="document.getElementById('confirmDelete').classList.add('open')">
            <i class="fa-solid fa-trash"></i> Delete Permanently
          </button>
        </div>
      </div>
    </div>

  </div><!-- /.content -->
</div><!-- /.main -->

<!-- Confirm delete modal -->
<div class="confirm-overlay" id="confirmDelete">
  <div class="confirm-box">
    <h3>Delete <?= htmlspecialchars($tx_id) ?>?</h3>
    <p>This will permanently delete the payment record. The linked booking (BK-<?= str_pad($p['session_id'] ?? 0, 5, '0', STR_PAD_LEFT) ?>) will remain but will show no payment. This cannot be undone.</p>
    <div class="cta">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('confirmDelete').classList.remove('open')">Cancel</button>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="delete"/>
        <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Yes, delete it</button>
      </form>
    </div>
  </div>
</div>

<script>
document.getElementById('confirmDelete').addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
});
</script>

</body>
</html>