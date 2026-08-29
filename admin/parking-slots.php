<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
// ── DB Connection ─────────────────────────────────────────────
$host = 'localhost';
$user = 'root';
$pass = '';
$db   = 'parkify_db';
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action']  ?? '';
    $slot_id = (int)($_POST['slot_id'] ?? 0);

    if ($action === 'set_maintenance' && $slot_id) {
        $conn->query("UPDATE parking_slots SET status='maintenance' WHERE id=$slot_id");
    } elseif ($action === 'release' && $slot_id) {
        $conn->query("UPDATE parking_slots SET status='available' WHERE id=$slot_id");
    } elseif ($action === 'add_slot') {
        $code = $conn->real_escape_string(strtoupper(trim($_POST['slot_code'] ?? '')));
        $row  = $conn->real_escape_string(strtoupper(substr($code, 0, 1)));
        $num  = (int)filter_var($code, FILTER_SANITIZE_NUMBER_INT);
        if ($code) {
            $conn->query("INSERT IGNORE INTO parking_slots (slot_code, row_label, slot_number, status)
                          VALUES ('$code','$row',$num,'available')");
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ── Filters ───────────────────────────────────────────────────
$zone_filter   = $_GET['zone']   ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$selected_id   = (int)($_GET['slot'] ?? 0);

// ── Stats ─────────────────────────────────────────────────────
$stats = [];
foreach (['available','occupied','reserved','maintenance'] as $s) {
    $stats[$s] = $conn->query("SELECT COUNT(*) AS c FROM parking_slots WHERE status='$s'")->fetch_assoc()['c'];
}

// ── All zones ─────────────────────────────────────────────────
$zone_rows = $conn->query("SELECT DISTINCT row_label FROM parking_slots ORDER BY row_label");
$zones = [];
while ($z = $zone_rows->fetch_assoc()) $zones[] = $z['row_label'];

// ── Slot grid query ───────────────────────────────────────────
$where = [];
if ($zone_filter !== 'all')   $where[] = "row_label = '" . $conn->real_escape_string($zone_filter) . "'";
if ($status_filter !== 'all') $where[] = "status = '"    . $conn->real_escape_string($status_filter) . "'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$slots_res = $conn->query("SELECT * FROM parking_slots $where_sql ORDER BY row_label, slot_number");
$all_slots = [];
while ($s = $slots_res->fetch_assoc()) $all_slots[] = $s;

// Group by row
$by_row = [];
foreach ($all_slots as $s) $by_row[$s['row_label']][] = $s;

// ── Selected slot detail ──────────────────────────────────────
$selected = null;
$booking  = null;
if ($selected_id) {
    $selected = $conn->query("SELECT * FROM parking_slots WHERE id=$selected_id")->fetch_assoc();
    if ($selected) {
        $booking = $conn->query("
            SELECT ps.*, u.full_name, u.avatar_url, v.plate_number, v.make, v.model
            FROM parking_sessions ps
            LEFT JOIN users u ON ps.user_id = u.id
            LEFT JOIN vehicles v ON ps.vehicle_id = v.id
            WHERE ps.slot_id = $selected_id AND ps.status = 'active'
            ORDER BY ps.entry_time DESC LIMIT 1
        ")->fetch_assoc();
    }
}
// Default: pick first slot if none selected
if (!$selected && !empty($all_slots)) {
    $selected_id = $all_slots[0]['id'];
    $selected    = $all_slots[0];
    $booking = $conn->query("
        SELECT ps.*, u.full_name, u.avatar_url, v.plate_number, v.make, v.model
        FROM parking_sessions ps
        LEFT JOIN users u ON ps.user_id = u.id
        LEFT JOIN vehicles v ON ps.vehicle_id = v.id
        WHERE ps.slot_id = $selected_id AND ps.status = 'active'
        ORDER BY ps.entry_time DESC LIMIT 1
    ")->fetch_assoc();
}

$conn->close();

// ── Helpers ───────────────────────────────────────────────────
function statusColor(string $s): string {
    return match($s) {
        'available'   => 'green',
        'occupied'    => 'red',
        'reserved'    => 'orange',
        'maintenance' => 'gray',
        default       => 'blue',
    };
}
function statusIcon(string $s): string {
    return match($s) {
        'available'   => 'fa-check',
        'occupied'    => 'fa-car',
        'reserved'    => 'fa-bookmark',
        'maintenance' => 'fa-wrench',
        default       => 'fa-circle',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Parking Slots</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
    <link rel="icon" href="../images/fabiconlogo.png" type="image/png" />

<style>
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
  --green:     #10b981;  --green-bg:  #ecfdf5;  --green-bd: #a7f3d0;
  --red:       #ef4444;  --red-bg:    #fef2f2;  --red-bd:   #fecaca;
  --orange:    #f59e0b;  --orange-bg: #fffbeb;  --orange-bd:#fde68a;
  --gray:      #6b7280;  --gray-bg:   #f9fafb;  --gray-bd:  #e5e7eb;
  --font: 'Inter', sans-serif;
}
body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }

/* ── Main ── */
.main { margin-left: 220px; flex: 1; display: flex; flex-direction: column; }

/* ── Topbar ── */
.topbar {
  background: var(--surface); border-bottom: 1px solid var(--border);
  padding: 0 28px; height: 60px; display: flex; align-items: center;
  justify-content: space-between; position: sticky; top: 0; z-index: 50;
}
.topbar-title h1 { font-size: 18px; font-weight: 700; }
.topbar-title p  { font-size: 12px; color: var(--muted); }
.topbar-right    { display: flex; align-items: center; gap: 16px; }
.search-box { display: flex; align-items: center; gap: 8px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 7px 12px; width: 220px; }
.search-box input { border: none; background: transparent; font-size: 13px; color: var(--text); outline: none; width: 100%; }
.search-box i { color: var(--muted); font-size: 13px; }
.admin-pill  { display: flex; align-items: center; gap: 10px; }
.admin-avatar { width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg,#2563eb,#7c3aed); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; color: #fff; }
.admin-info .name { font-size: 13px; font-weight: 600; }
.admin-info .role { font-size: 11px; color: var(--muted); }

/* ── Body layout: grid + detail panel ── */
.body-wrap { display: flex; flex: 1; overflow: hidden; }
.slot-grid-area { flex: 1; overflow-y: auto; padding: 24px 24px 24px 28px; display: flex; flex-direction: column; gap: 20px; }
.detail-panel { width: 280px; min-height: calc(100vh - 60px); background: var(--surface); border-left: 1px solid var(--border); padding: 0; display: flex; flex-direction: column; overflow-y: auto; position: sticky; top: 60px; }

/* ── Stats strip ── */
.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }
.stat-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 16px 18px; display: flex; align-items: center; gap: 14px; }
.stat-icon-wrap { width: 42px; height: 42px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
.green-ic  { background: var(--green-bg);  color: var(--green); }
.red-ic    { background: var(--red-bg);    color: var(--red); }
.orange-ic { background: var(--orange-bg); color: var(--orange); }
.gray-ic   { background: var(--gray-bg);   color: var(--gray); }
.stat-info .val   { font-size: 26px; font-weight: 700; line-height: 1; }
.stat-info .lbl   { font-size: 12px; color: var(--muted); margin-top: 2px; }

/* ── Toolbar ── */
.toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.select-box { padding: 7px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 13px; color: var(--text); background: var(--surface); cursor: pointer; outline: none; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: all 0.15s; }
.btn-primary { background: var(--blue); color: #fff; }
.btn-primary:hover { background: var(--blue-d); }
.btn-outline { background: var(--surface); color: var(--text); border: 1px solid var(--border); }
.btn-outline:hover { background: var(--bg); }
.btn-danger  { background: var(--red); color: #fff; }
.btn-danger:hover { background: #dc2626; }
.toolbar-spacer { flex: 1; }

/* ── Zone section ── */
.zone-section { display: flex; flex-direction: column; gap: 14px; }
.zone-header { display: flex; align-items: center; justify-content: space-between; }
.zone-title  { font-size: 15px; font-weight: 600; }
.view-toggle { display: flex; background: var(--bg); border: 1px solid var(--border); border-radius: 7px; overflow: hidden; }
.view-btn { padding: 5px 13px; font-size: 12px; font-weight: 500; color: var(--muted); cursor: pointer; border: none; background: transparent; transition: all 0.15s; }
.view-btn.active { background: var(--blue); color: #fff; }

/* ── Slot grid ── */
.slot-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(72px, 1fr)); gap: 10px; }
.slot-card {
  border-radius: 10px; padding: 10px 8px 8px; cursor: pointer;
  border: 1.5px solid transparent; display: flex; flex-direction: column;
  align-items: center; gap: 5px; transition: all 0.15s; text-decoration: none;
  position: relative;
}
.slot-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.slot-card.selected { box-shadow: 0 0 0 2.5px var(--blue); }

.slot-card.green  { background: var(--green-bg);  border-color: var(--green-bd); }
.slot-card.red    { background: var(--red-bg);     border-color: var(--red-bd);   }
.slot-card.orange { background: var(--orange-bg);  border-color: var(--orange-bd);}
.slot-card.gray   { background: var(--gray-bg);    border-color: var(--gray-bd);  }

.slot-icon { font-size: 18px; }
.slot-card.green  .slot-icon { color: var(--green);  }
.slot-card.red    .slot-icon { color: var(--red);    }
.slot-card.orange .slot-icon { color: var(--orange); }
.slot-card.gray   .slot-icon { color: var(--gray);   }

.slot-code { font-size: 11px; font-weight: 600; color: var(--text); }
.slot-status-dot { width: 7px; height: 7px; border-radius: 50%; position: absolute; top: 7px; right: 7px; }
.slot-card.green  .slot-status-dot { background: var(--green);  }
.slot-card.red    .slot-status-dot { background: var(--red);    }
.slot-card.orange .slot-status-dot { background: var(--orange); }
.slot-card.gray   .slot-status-dot { background: var(--gray);   }

/* ── Detail panel ── */
.dp-head {
  padding: 18px 20px 14px; border-bottom: 1px solid var(--border);
}
.dp-slot-code { font-size: 18px; font-weight: 700; }
.dp-zone      { font-size: 12px; color: var(--muted); margin-top: 2px; }
.dp-badge {
  display: inline-block; padding: 3px 10px; border-radius: 20px;
  font-size: 11px; font-weight: 600; margin-top: 8px;
}
.dp-badge.green  { background: var(--green-bg);  color: var(--green);  }
.dp-badge.red    { background: var(--red-bg);     color: var(--red);    }
.dp-badge.orange { background: var(--orange-bg);  color: var(--orange); }
.dp-badge.gray   { background: var(--gray-bg);    color: var(--gray);   }

.dp-section { padding: 16px 20px; border-bottom: 1px solid var(--border); }
.dp-section-title { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-bottom: 12px; }

.booking-user { display: flex; align-items: center; gap: 10px; margin-bottom: 12px; }
.booking-av   { width: 36px; height: 36px; border-radius: 50%; background: var(--blue-mid); color: var(--blue); display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 700; flex-shrink: 0; }
.booking-name  { font-size: 13px; font-weight: 600; }
.booking-plate { font-size: 11px; color: var(--muted); }

.time-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.time-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--muted); margin-bottom: 3px; }
.time-val { font-size: 13px; font-weight: 600; }

.detail-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.detail-row:last-child { margin-bottom: 0; }
.detail-key { font-size: 12.5px; color: var(--muted); }
.detail-val { font-size: 12.5px; font-weight: 500; }

.maint-textarea {
  width: 100%; min-height: 80px; resize: vertical;
  border: 1px solid var(--border); border-radius: 8px;
  padding: 9px 12px; font-size: 12.5px; font-family: var(--font);
  color: var(--text); outline: none; transition: border 0.15s;
}
.maint-textarea:focus { border-color: var(--blue); }

.dp-actions { padding: 16px 20px; display: flex; flex-direction: column; gap: 8px; margin-top: auto; }
.btn-block { display: block; width: 100%; text-align: center; padding: 10px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: all 0.15s; }
.btn-block-primary { background: var(--blue); color: #fff; }
.btn-block-primary:hover { background: var(--blue-d); }
.btn-block-outline { background: var(--surface); color: var(--text); border: 1px solid var(--border); }
.btn-block-outline:hover { background: var(--bg); }
.btn-block-danger  { background: var(--red-bg); color: var(--red); border: 1px solid var(--red-bd); }
.btn-block-danger:hover  { background: var(--red); color: #fff; }

/* ── Add Slot Modal ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 200; align-items: center; justify-content: center; }
.modal-overlay.open { display: flex; }
.modal { background: var(--surface); border-radius: 14px; padding: 24px; width: 340px; }
.modal h3 { font-size: 16px; font-weight: 700; margin-bottom: 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 12px; font-weight: 500; margin-bottom: 6px; color: var(--muted); }
.form-group input, .form-group select {
  width: 100%; padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 13px; color: var(--text); outline: none;
  transition: border 0.15s;
}
.form-group input:focus, .form-group select:focus { border-color: var(--blue); }
.modal-actions { display: flex; gap: 8px; margin-top: 20px; }
.modal-actions .btn { flex: 1; justify-content: center; }
</style>
</head>
<body>

<?php
$current_page = 'parking-slots';
include 'sidebar.php';
?>

<!-- ── Main ── -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <h1>Slot Management</h1>
      <p>Monitor and manage parking zone availability</p>
    </div>
    <div class="topbar-right">
      <div class="search-box">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="slotSearch" placeholder="Search slots, zones…"/>
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

  <div class="body-wrap">

    <!-- ── Slot Grid Area ── -->
    <div class="slot-grid-area">
      <div class="stats-row">
        <div class="stat-card">
          <div class="stat-icon-wrap green-ic"><i class="fa-solid fa-check"></i></div>
          <div class="stat-info">
            <div class="val"><?= $stats['available'] ?></div>
            <div class="lbl">Available</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon-wrap red-ic"><i class="fa-solid fa-car"></i></div>
          <div class="stat-info">
            <div class="val"><?= $stats['occupied'] ?></div>
            <div class="lbl">Occupied</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon-wrap orange-ic"><i class="fa-solid fa-bookmark"></i></div>
          <div class="stat-info">
            <div class="val"><?= $stats['reserved'] ?></div>
            <div class="lbl">Reserved</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon-wrap gray-ic"><i class="fa-solid fa-wrench"></i></div>
          <div class="stat-info">
            <div class="val"><?= $stats['maintenance'] ?></div>
            <div class="lbl">Maintenance</div>
          </div>
        </div>
      </div>

      <!-- Toolbar -->
      <div class="toolbar">
        <form method="GET" style="display:contents">
          <select name="zone" class="select-box" onchange="this.form.submit()">
            <option value="all" <?= $zone_filter==='all'?'selected':'' ?>>All Zones</option>
            <?php foreach ($zones as $z): ?>
            <option value="<?= $z ?>" <?= $zone_filter===$z?'selected':'' ?>>Zone <?= $z ?></option>
            <?php endforeach; ?>
          </select>
          <select name="status" class="select-box" onchange="this.form.submit()">
            <option value="all"         <?= $status_filter==='all'?'selected':'' ?>>All Status</option>
            <option value="available"   <?= $status_filter==='available'?'selected':'' ?>>Available</option>
            <option value="occupied"    <?= $status_filter==='occupied'?'selected':'' ?>>Occupied</option>
            <option value="reserved"    <?= $status_filter==='reserved'?'selected':'' ?>>Reserved</option>
            <option value="maintenance" <?= $status_filter==='maintenance'?'selected':'' ?>>Maintenance</option>
          </select>
          <?php if ($selected_id): ?>
          <input type="hidden" name="slot" value="<?= $selected_id ?>"/>
          <?php endif; ?>
        </form>
        <div class="toolbar-spacer"></div>
        <button class="btn btn-outline" onclick="document.getElementById('addModal').classList.add('open')">
          <i class="fa-solid fa-plus"></i> Add Slot
        </button>
        <div style="position:relative">
          <button class="btn btn-primary" onclick="toggleBulk()">
            <i class="fa-solid fa-bolt"></i> Bulk Actions <i class="fa-solid fa-chevron-down" style="font-size:10px"></i>
          </button>
          <div id="bulkMenu" style="display:none;position:absolute;right:0;top:calc(100% + 6px);background:var(--surface);border:1px solid var(--border);border-radius:8px;min-width:160px;overflow:hidden;z-index:10;box-shadow:0 4px 16px rgba(0,0,0,0.1)">
            <a href="#" style="display:block;padding:10px 14px;font-size:13px;color:var(--text);text-decoration:none" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"><i class="fa-solid fa-wrench" style="width:16px;color:var(--orange)"></i> Set Maintenance</a>
            <a href="#" style="display:block;padding:10px 14px;font-size:13px;color:var(--red);text-decoration:none" onmouseover="this.style.background='var(--bg)'" onmouseout="this.style.background='transparent'"><i class="fa-solid fa-rotate-left" style="width:16px"></i> Release All</a>
          </div>
        </div>
      </div>

      <!-- Zone grids -->
      <?php if (empty($all_slots)): ?>
        <div style="text-align:center;padding:48px;color:var(--muted)">No slots match your filters.</div>
      <?php else: ?>
      <?php foreach ($by_row as $rowLabel => $rowSlots): ?>
      <div class="zone-section">
        <div class="zone-header">
          <div class="zone-title">Zone <?= $rowLabel ?> Layout</div>
          <div class="view-toggle">
            <button class="view-btn active">Grid View</button>
            <button class="view-btn">List View</button>
          </div>
        </div>
        <div class="slot-grid" id="grid-<?= $rowLabel ?>">
          <?php foreach ($rowSlots as $slot):
            $color = statusColor($slot['status']);
            $icon  = statusIcon($slot['status']);
            $isSelected = $slot['id'] == $selected_id;
          ?>
          <a href="?zone=<?= $zone_filter ?>&status=<?= $status_filter ?>&slot=<?= $slot['id'] ?>"
             class="slot-card <?= $color ?> <?= $isSelected ? 'selected' : '' ?>">
            <div class="slot-status-dot"></div>
            <i class="fa-solid <?= $icon ?> slot-icon"></i>
            <div class="slot-code"><?= htmlspecialchars($slot['slot_code']) ?></div>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>

    </div><!-- /slot-grid-area -->

    <!-- ── Detail Panel ── -->
    <aside class="detail-panel">
      <?php if ($selected): ?>
      <?php
        $color = statusColor($selected['status']);
        $statusLabel = ucfirst($selected['status']);
      ?>

      <!-- Header -->
      <div class="dp-head">
        <div style="display:flex;align-items:flex-start;justify-content:space-between">
          <div>
            <div class="dp-slot-code">Slot <?= htmlspecialchars($selected['slot_code']) ?></div>
            <div class="dp-zone">Zone <?= $selected['row_label'] ?> (North)</div>
          </div>
          <span class="dp-badge <?= $color ?>"><?= $statusLabel ?></span>
        </div>
      </div>

      <!-- Current Booking -->
      <div class="dp-section">
        <div class="dp-section-title">Current Booking</div>
        <?php if ($booking): ?>
        <?php
          $initials = '';
          foreach (explode(' ', trim($booking['full_name'] ?? 'Guest')) as $w)
              $initials .= strtoupper($w[0] ?? '');
          $initials = substr($initials, 0, 2) ?: 'GU';
        ?>
        <div class="booking-user">
          <div class="booking-av"><?= $initials ?></div>
          <div>
            <div class="booking-name"><?= htmlspecialchars($booking['full_name'] ?? 'Guest') ?></div>
            <div class="booking-plate"><?= htmlspecialchars(($booking['make'] ?? '') . ' · ' . $booking['plate_number']) ?></div>
          </div>
        </div>
        <div class="time-row">
          <div>
            <div class="time-lbl">Entry</div>
            <div class="time-val"><?= date('h:i A', strtotime($booking['entry_time'])) ?></div>
          </div>
          <div>
            <div class="time-lbl">Est. Exit</div>
            <div class="time-val"><?= $booking['exit_time'] ? date('h:i A', strtotime($booking['exit_time'])) : '—' ?></div>
          </div>
        </div>
        <?php else: ?>
        <div style="color:var(--muted);font-size:13px;padding:8px 0">
          <?= $selected['status'] === 'available' ? 'No active booking.' : ucfirst($selected['status']) . ' — no session.' ?>
        </div>
        <?php endif; ?>
      </div>

      <!-- Slot Details -->
      <div class="dp-section">
        <div class="dp-section-title">Slot Details</div>
        <div class="detail-row">
          <span class="detail-key">Type</span>
          <span class="detail-val">Standard Car</span>
        </div>
        <div class="detail-row">
          <span class="detail-key">Rate</span>
          <span class="detail-val">Rs. 20 / hr</span>
        </div>
        <div class="detail-row">
          <span class="detail-key">Zone</span>
          <span class="detail-val">Zone <?= $selected['row_label'] ?></span>
        </div>
        <div class="detail-row">
          <span class="detail-key">Features</span>
          <span class="detail-val">Covered</span>
        </div>
      </div>

      <!-- Maintenance -->
      <div class="dp-section">
        <div class="dp-section-title">Maintenance</div>
        <textarea class="maint-textarea" placeholder="Add maintenance notes or issue reports…"></textarea>
      </div>

      <!-- Actions -->
      <div class="dp-actions">
        <button class="btn-block btn-block-primary">
          <i class="fa-solid fa-pen"></i> Edit Slot Details
        </button>
        <form method="POST" style="margin:0">
          <input type="hidden" name="slot_id" value="<?= $selected['id'] ?>"/>
          <input type="hidden" name="action"  value="set_maintenance"/>
          <button type="submit" class="btn-block btn-block-outline">
            <i class="fa-solid fa-wrench"></i> Mark Maintenance
          </button>
        </form>
        <form method="POST" style="margin:0">
          <input type="hidden" name="slot_id" value="<?= $selected['id'] ?>"/>
          <input type="hidden" name="action"  value="release"/>
          <button type="submit" class="btn-block btn-block-danger">
            <i class="fa-solid fa-lock-open"></i> Release Slot
          </button>
        </form>
      </div>

      <?php else: ?>
      <div style="padding:24px;color:var(--muted);font-size:13px;text-align:center">Select a slot to view details.</div>
      <?php endif; ?>
    </aside>

  </div><!-- /body-wrap -->
</div><!-- /main -->

<!-- ── Add Slot Modal ── -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <h3>Add New Slot</h3>
    <form method="POST">
      <input type="hidden" name="action" value="add_slot"/>
      <div class="form-group">
        <label>Slot Code (e.g. E1)</label>
        <input type="text" name="slot_code" placeholder="E1" required/>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-outline" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add Slot</button>
      </div>
    </form>
  </div>
</div>

<script>
// Close modal on overlay click
document.getElementById('addModal').addEventListener('click', function(e) {
  if (e.target === this) this.classList.remove('open');
});

// Bulk menu toggle
function toggleBulk() {
  const m = document.getElementById('bulkMenu');
  m.style.display = m.style.display === 'none' ? 'block' : 'none';
}
document.addEventListener('click', e => {
  if (!e.target.closest('[onclick="toggleBulk()"]') && !e.target.closest('#bulkMenu'))
    document.getElementById('bulkMenu').style.display = 'none';
});

// View toggle (cosmetic)
document.querySelectorAll('.view-toggle').forEach(vt => {
  vt.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      vt.querySelectorAll('.view-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
    });
  });
});

// Live slot search filter
document.getElementById('slotSearch').addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.slot-card').forEach(card => {
    const code = card.querySelector('.slot-code')?.textContent.toLowerCase() ?? '';
    card.style.display = code.includes(q) ? '' : 'none';
  });
});
</script>
</body>
</html>