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

// ── Handle POST actions ────────────────────────────────────────
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── End session (complete) ─────────────────────────────────
    if ($action === 'end_session') {
        $rate      = (float)($_POST['rate_per_hour'] ?? 20.00);
        $method    = $conn->real_escape_string($_POST['pay_method'] ?? 'cash');

        // Fetch entry time
        $r = $conn->query("SELECT entry_time, slot_id FROM parking_sessions WHERE id=$id")->fetch_assoc();
        if ($r && $r['entry_time']) {
            $now      = date('Y-m-d H:i:s');
            $mins     = (int)round((strtotime($now) - strtotime($r['entry_time'])) / 60);
            $amount   = round(($mins / 60) * $rate, 2);
            $slot_id  = (int)$r['slot_id'];

            $conn->begin_transaction();
            try {
                $conn->query("UPDATE parking_sessions
                              SET exit_time=NOW(), duration_mins=$mins, status='completed'
                              WHERE id=$id");

                // Upsert payment
                $exists = $conn->query("SELECT id FROM payments WHERE session_id=$id")->fetch_assoc();
                if ($exists) {
                    $conn->query("UPDATE payments SET amount=$amount, status='success', method='$method', paid_at=NOW()
                                  WHERE session_id=$id");
                } else {
                    $uid = (int)($conn->query("SELECT user_id FROM parking_sessions WHERE id=$id")->fetch_assoc()['user_id'] ?? 0);
                    $conn->query("INSERT INTO payments (session_id, user_id, amount, rate_per_hour, method, status, paid_at)
                                  VALUES ($id, " . ($uid ?: 'NULL') . ", $amount, $rate, '$method', 'success', NOW())");
                }

                // Free the slot
                $conn->query("UPDATE parking_slots SET status='available' WHERE id=$slot_id");

                $conn->commit();
                $success = "Session ended. Duration: {$mins} min — Amount charged: Rs " . number_format($amount, 2);
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Failed to end session: ' . $e->getMessage();
            }
        } else {
            $error = 'Session not found or already missing entry time.';
        }
    }

    // ── Cancel session ─────────────────────────────────────────
    elseif ($action === 'cancel') {
        $r = $conn->query("SELECT slot_id, status FROM parking_sessions WHERE id=$id")->fetch_assoc();
        if ($r && $r['status'] !== 'cancelled') {
            $slot_id = (int)$r['slot_id'];
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE parking_sessions SET status='cancelled' WHERE id=$id");
                $conn->query("UPDATE parking_slots SET status='available' WHERE id=$slot_id");
                $conn->commit();
                $success = 'Booking cancelled and slot released.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Cancellation failed: ' . $e->getMessage();
            }
        } else {
            $error = 'Booking is already cancelled.';
        }
    }

    // ── Mark payment as paid ────────────────────────────────────
    elseif ($action === 'mark_paid') {
        $method = $conn->real_escape_string($_POST['pay_method'] ?? 'cash');
        $exists = $conn->query("SELECT id FROM payments WHERE session_id=$id")->fetch_assoc();
        if ($exists) {
            $conn->query("UPDATE payments SET status='success', method='$method', paid_at=NOW() WHERE session_id=$id");
        } else {
            $uid = (int)($conn->query("SELECT user_id FROM parking_sessions WHERE id=$id")->fetch_assoc()['user_id'] ?? 0);
            $conn->query("INSERT INTO payments (session_id, user_id, amount, method, status, paid_at)
                          VALUES ($id, " . ($uid ?: 'NULL') . ", 0, '$method', 'success', NOW())");
        }
        $success = 'Payment marked as paid.';
    }

    // ── Reopen (active) ────────────────────────────────────────
    elseif ($action === 'reopen') {
        $r = $conn->query("SELECT slot_id FROM parking_sessions WHERE id=$id")->fetch_assoc();
        if ($r) {
            $slot_id = (int)$r['slot_id'];
            $conn->begin_transaction();
            try {
                $conn->query("UPDATE parking_sessions SET status='active', exit_time=NULL, duration_mins=NULL WHERE id=$id");
                $conn->query("UPDATE parking_slots SET status='occupied' WHERE id=$slot_id");
                $conn->commit();
                $success = 'Session re-opened and marked active.';
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Could not reopen session: ' . $e->getMessage();
            }
        }
    }

    // ── Delete session ─────────────────────────────────────────
    elseif ($action === 'delete') {
        $r = $conn->query("SELECT slot_id, status FROM parking_sessions WHERE id=$id")->fetch_assoc();
        if ($r) {
            $slot_id = (int)$r['slot_id'];
            $conn->begin_transaction();
            try {
                $conn->query("DELETE FROM payments WHERE session_id=$id");
                $conn->query("DELETE FROM parking_sessions WHERE id=$id");
                if ($r['status'] === 'active') {
                    $conn->query("UPDATE parking_slots SET status='available' WHERE id=$slot_id");
                }
                $conn->commit();
                header('Location: bookings.php?deleted=1'); exit;
            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Delete failed: ' . $e->getMessage();
            }
        }
    }
}

// ── Re-fetch (so status reflects any changes) ──────────────────
$stmt = $conn->prepare("
    SELECT ps.id, ps.entry_time, ps.exit_time, ps.duration_mins, ps.status, ps.plate_number,
           u.full_name, u.email,
           v.make, v.model, v.plate_number AS reg_plate,
           sl.slot_code, sl.row_label,
           py.amount, py.rate_per_hour, py.method, py.status AS payment_status
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
if (!$b) { header('Location: bookings.php'); exit; }

// ── Helpers ────────────────────────────────────────────────────
function bookingId(int $id): string {
    return 'BK-' . str_pad($id, 5, '0', STR_PAD_LEFT);
}
function statusBadge(string $status): string {
    $map = [
        'active'    => ['Active',    '#dbeafe', '#2563eb'],
        'completed' => ['Completed', '#dcfce7', '#16a34a'],
        'cancelled' => ['Cancelled', '#fee2e2', '#dc2626'],
        'pending'   => ['Pending',   '#fef9c3', '#ca8a04'],
        'no-show'   => ['No-show',   '#f3e8ff', '#7c3aed'],
    ];
    [$label, $bg, $color] = $map[strtolower($status)] ?? [ucfirst($status), '#f3f4f6', '#374151'];
    return "<span style=\"display:inline-flex;align-items:center;gap:5px;padding:3px 10px;border-radius:99px;font-size:12px;font-weight:600;background:$bg;color:$color\">
        <span style=\"width:6px;height:6px;border-radius:50%;background:$color;\"></span>$label</span>";
}

$admin_name = $_SESSION['user_name'] ?? 'Admin';
$admin_role = $_SESSION['role']      ?? 'admin';
$words      = explode(' ', trim($admin_name));
$initials   = strtoupper(substr($words[0], 0, 1) . (isset($words[1]) ? substr($words[1], 0, 1) : ''));
$status     = strtolower($b['status'] ?? 'active');
$is_active  = $status === 'active';
$is_done    = in_array($status, ['completed', 'cancelled']);
$current_page = 'bookings';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Manage <?= bookingId($id) ?></title>
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
    --purple:     #7c3aed;  --purple-bg: #f3e8ff;
    --font: 'DM Sans', sans-serif;
    --sidebar: #1e3a8a;
    --radius: 12px;
}

body {
    font-family: var(--font); background: var(--bg);
    color: var(--text); display: flex; min-height: 100vh; font-size: 14px;
}

/* Sidebar */
.sidebar { width: 220px; min-height: 100vh; background: var(--sidebar); display: flex; flex-direction: column; position: fixed; top: 0; left: 0; z-index: 100; }
.sidebar-logo { height: 100px; padding: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid rgba(255,255,255,0.1); text-decoration: none; }
.sidebar-logo img { margin-top: 30px; margin-left: 10px; }
.sidebar-nav { padding: 12px; flex: 1; display: flex; flex-direction: column; gap: 2px; }
.nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; text-decoration: none; color: rgba(255,255,255,0.65); font-size: 13.5px; font-weight: 500; transition: all 0.15s; }
.nav-item i { width: 16px; font-size: 14px; flex-shrink: 0; }
.nav-item:hover  { background: rgba(255,255,255,0.1);  color: #fff; }
.nav-item.active { background: rgba(255,255,255,0.15); color: #fff; }
.sidebar-bottom { padding: 12px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; flex-direction: column; gap: 2px; }

/* Main */
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* Topbar */
.topbar { background: var(--surface); border-bottom: 1px solid var(--border); padding: 0 28px; height: 60px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50; }
.topbar-title { display: flex; align-items: center; gap: 10px; }
.back-btn { display: flex; align-items: center; gap: 6px; text-decoration: none; color: var(--muted); font-size: 13px; font-weight: 500; padding: 5px 10px; border-radius: 7px; border: 1px solid var(--border); transition: all .15s; }
.back-btn:hover { background: var(--bg); color: var(--text); }
.back-btn svg { width: 14px; height: 14px; }
.divider { width: 1px; height: 20px; background: var(--border); }
.topbar-title h1 { font-size: 18px; font-weight: 700; }
.topbar-title p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.admin-pill   { display: flex; align-items: center; gap: 10px; }
.admin-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, #2563eb, #7c3aed); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0; }
.admin-info .name { font-size: 13px; font-weight: 600; }
.admin-info .role { font-size: 11px; color: var(--muted); }

/* Content */
.content { padding: 28px; display: flex; flex-direction: column; gap: 20px; max-width: 860px; }

/* Alert */
.alert { padding: 12px 16px; border-radius: 8px; font-size: 13px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
.alert-success { background: var(--green-bg); color: var(--green); border: 1px solid var(--green-bd); }
.alert-error   { background: var(--red-bg);   color: var(--red);   border: 1px solid var(--red-bd); }
.alert i { font-size: 15px; flex-shrink: 0; }

/* Booking summary strip */
.summary-strip { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px 20px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
.strip-item { display: flex; flex-direction: column; gap: 2px; }
.strip-label { font-size: 11px; color: var(--muted); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
.strip-value { font-size: 14px; font-weight: 700; }
.strip-divider { width: 1px; height: 36px; background: var(--border); }

/* Action sections */
.section-title { font-size: 13px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 10px; }

/* Action cards */
.action-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 20px;
    display: flex; align-items: flex-start; gap: 16px;
}
.action-card.disabled { opacity: .5; pointer-events: none; }
.action-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.action-body { flex: 1; }
.action-body h3 { font-size: 14px; font-weight: 700; margin-bottom: 3px; }
.action-body p  { font-size: 12px; color: var(--muted); line-height: 1.5; margin-bottom: 12px; }
.action-fields { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.action-fields label { font-size: 12px; color: var(--muted); font-weight: 500; }
.action-fields input[type="number"],
.action-fields select {
    padding: 7px 10px; border: 1px solid var(--border); border-radius: 7px;
    font-size: 13px; font-family: var(--font); color: var(--text);
    background: var(--bg); outline: none;
}
.action-fields input[type="number"]:focus,
.action-fields select:focus { border-color: var(--accent); background: #fff; }

/* Buttons */
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 8px; border: none; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; transition: all .15s; font-family: var(--font); }
.btn svg { width: 14px; height: 14px; }
.btn-outline { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn-outline:hover { background: var(--bg); }
.btn-green  { background: var(--green-bg);  color: var(--green);  border: 1px solid var(--green-bd); }
.btn-green:hover  { background: #dcfce7; }
.btn-amber  { background: var(--amber-bg);  color: var(--amber);  border: 1px solid var(--amber-bd); }
.btn-amber:hover  { background: #fef3c7; }
.btn-red    { background: var(--red-bg);    color: var(--red);    border: 1px solid var(--red-bd); }
.btn-red:hover    { background: #fee2e2; }
.btn-blue   { background: var(--accent-lt); color: var(--accent); border: 1px solid #bfdbfe; }
.btn-blue:hover   { background: #dbeafe; }
.btn-danger { background: var(--red);       color: #fff; }
.btn-danger:hover { background: #b91c1c; }

/* Confirm overlay */
.confirm-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 200;
    align-items: center; justify-content: center;
}
.confirm-overlay.open { display: flex; }
.confirm-box {
    background: var(--surface); border-radius: var(--radius);
    padding: 28px; width: 380px; max-width: 90vw;
    box-shadow: 0 20px 40px rgba(0,0,0,.15);
}
.confirm-box h3 { font-size: 16px; font-weight: 700; margin-bottom: 8px; }
.confirm-box p  { font-size: 13px; color: var(--muted); margin-bottom: 20px; line-height: 1.5; }
.confirm-box .actions { display: flex; gap: 8px; justify-content: flex-end; }
</style>
</head>
<body>

<?php include 'sidebar.php'; ?>

<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <a href="booking_view.php?id=<?= $id ?>" class="back-btn">
        <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
        Back
      </a>
      <div class="divider"></div>
      <div>
        <h1>Manage Booking</h1>
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

    <!-- Alerts -->
    <?php if ($success): ?>
      <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
      </div>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fa-solid fa-circle-xmark"></i> <?= htmlspecialchars($error) ?>
      </div>
    <?php endif; ?>

    <!-- Summary strip -->
    <div class="summary-strip">
      <div class="strip-item">
        <span class="strip-label">Booking</span>
        <span class="strip-value"><?= bookingId($id) ?></span>
      </div>
      <div class="strip-divider"></div>
      <div class="strip-item">
        <span class="strip-label">Status</span>
        <span class="strip-value"><?= statusBadge($b['status']) ?></span>
      </div>
      <div class="strip-divider"></div>
      <div class="strip-item">
        <span class="strip-label">User</span>
        <span class="strip-value"><?= htmlspecialchars($b['full_name'] ?? 'Guest') ?></span>
      </div>
      <div class="strip-divider"></div>
      <div class="strip-item">
        <span class="strip-label">Slot</span>
        <span class="strip-value"><?= htmlspecialchars($b['slot_code'] ?? '—') ?> — Zone <?= htmlspecialchars($b['row_label'] ?? '—') ?></span>
      </div>
      <div class="strip-divider"></div>
      <div class="strip-item">
        <span class="strip-label">Plate</span>
        <span class="strip-value" style="font-family:monospace;letter-spacing:1px;"><?= htmlspecialchars($b['reg_plate'] ?? $b['plate_number'] ?? '—') ?></span>
      </div>
    </div>

    <!-- Actions -->
    <div>
      <div class="section-title">Session Actions</div>
      <div style="display:flex;flex-direction:column;gap:10px;">

        <!-- End Session -->
        <div class="action-card <?= !$is_active ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:#f0fdf4;color:#16a34a;">
            <i class="fa-solid fa-flag-checkered"></i>
          </div>
          <div class="action-body">
            <h3>End Session</h3>
            <p>Check the vehicle out now. Duration and fee will be calculated automatically from the entry time.</p>
            <form method="POST">
              <input type="hidden" name="action" value="end_session"/>
              <div class="action-fields">
                <label>Rate/hr (Rs)</label>
                <input type="number" name="rate_per_hour" min="0" step="0.50"
                       value="<?= number_format((float)($b['rate_per_hour'] ?? 20), 2, '.', '') ?>"/>
                <label>Payment method</label>
                <select name="pay_method">
                  <option value="cash">Cash</option>
                  <option value="card">Card</option>
                  <option value="esewa">eSewa</option>
                  <option value="khalti">Khalti</option>
                </select>
              </div>
              <button type="submit" class="btn btn-green">
                <i class="fa-solid fa-flag-checkered"></i> End &amp; Charge
              </button>
            </form>
          </div>
        </div>

        <!-- Mark Payment Paid -->
        <div class="action-card <?= ($b['payment_status'] === 'success' || $b['payment_status'] === 'paid') ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:#eff4ff;color:#2563eb;">
            <i class="fa-solid fa-money-bill-wave"></i>
          </div>
          <div class="action-body">
            <h3>Mark Payment as Paid</h3>
            <p>Record a manual payment for this booking without ending the session.</p>
            <form method="POST">
              <input type="hidden" name="action" value="mark_paid"/>
              <div class="action-fields">
                <label>Method</label>
                <select name="pay_method">
                  <option value="cash">Cash</option>
                  <option value="card">Card</option>
                  <option value="esewa">eSewa</option>
                  <option value="khalti">Khalti</option>
                </select>
              </div>
              <button type="submit" class="btn btn-blue">
                <i class="fa-solid fa-check"></i> Mark Paid
              </button>
            </form>
          </div>
        </div>

        <!-- Cancel Booking -->
        <div class="action-card <?= $is_done ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:#fffbeb;color:#d97706;">
            <i class="fa-solid fa-ban"></i>
          </div>
          <div class="action-body">
            <h3>Cancel Booking</h3>
            <p>Mark this session as cancelled and release the parking slot immediately.</p>
            <form method="POST">
              <input type="hidden" name="action" value="cancel"/>
              <button type="submit" class="btn btn-amber">
                <i class="fa-solid fa-ban"></i> Cancel Booking
              </button>
            </form>
          </div>
        </div>

        <!-- Reopen -->
        <div class="action-card <?= $is_active ? 'disabled' : '' ?>">
          <div class="action-icon" style="background:#f3e8ff;color:#7c3aed;">
            <i class="fa-solid fa-rotate-left"></i>
          </div>
          <div class="action-body">
            <h3>Re-open Session</h3>
            <p>Set this booking back to <strong>active</strong> and mark the slot as occupied. Use if a session was closed by mistake.</p>
            <form method="POST">
              <input type="hidden" name="action" value="reopen"/>
              <button type="submit" class="btn btn-outline">
                <i class="fa-solid fa-rotate-left"></i> Re-open
              </button>
            </form>
          </div>
        </div>

      </div>
    </div>

    <!-- Danger Zone -->
    <div>
      <div class="section-title" style="color:var(--red);">Danger Zone</div>
      <div class="action-card">
        <div class="action-icon" style="background:var(--red-bg);color:var(--red);">
          <i class="fa-solid fa-trash"></i>
        </div>
        <div class="action-body">
          <h3>Delete Booking</h3>
          <p>Permanently remove this session and its payment record. This cannot be undone.</p>
          <button type="button" class="btn btn-danger" onclick="document.getElementById('confirmDelete').classList.add('open')">
            <i class="fa-solid fa-trash"></i> Delete Permanently
          </button>
        </div>
      </div>
    </div>

  </div><!-- /.content -->
</div><!-- /.main -->

<!-- Delete confirmation modal -->
<div class="confirm-overlay" id="confirmDelete">
  <div class="confirm-box">
    <h3>Delete <?= bookingId($id) ?>?</h3>
    <p>This will permanently delete the session and all associated payment records. The parking slot will be freed. This action cannot be undone.</p>
    <div class="actions">
      <button type="button" class="btn btn-outline" onclick="document.getElementById('confirmDelete').classList.remove('open')">
        Cancel
      </button>
      <form method="POST" style="display:inline;">
        <input type="hidden" name="action" value="delete"/>
        <button type="submit" class="btn btn-danger">
          <i class="fa-solid fa-trash"></i> Yes, delete it
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// Close modal on backdrop click
document.getElementById('confirmDelete').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});
</script>

</body>
</html>
