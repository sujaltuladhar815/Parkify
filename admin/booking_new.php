<?php
// ============================================================
//  Parkify — New Booking (Manual Entry)
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'parkify_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

$errors  = [];
$success = false;
$new_id  = null;

// ── Handle POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Collect & sanitise inputs ---
    $user_mode    = $_POST['user_mode']    ?? 'existing'; // existing | guest
    $user_id      = (int)($_POST['user_id'] ?? 0);
    $guest_name   = trim($_POST['guest_name']   ?? '');
    $guest_email  = trim($_POST['guest_email']  ?? '');

    $vehicle_mode = $_POST['vehicle_mode'] ?? 'existing'; // existing | new
    $vehicle_id   = (int)($_POST['vehicle_id'] ?? 0);
    $plate        = strtoupper(trim($_POST['plate_number'] ?? ''));
    $v_make       = trim($_POST['v_make']  ?? '');
    $v_model      = trim($_POST['v_model'] ?? '');
    $v_color      = trim($_POST['v_color'] ?? '');

    $slot_id      = (int)($_POST['slot_id']    ?? 0);
    // datetime-local sends "YYYY-MM-DDTHH:MM" — convert T→space so MySQL parses correctly
    $entry_time   = trim($_POST['entry_time']  ?? '');
    if ($entry_time) $entry_time = date('Y-m-d H:i:s', strtotime($entry_time));
    $exit_time    = trim($_POST['exit_time']   ?? '');
    if ($exit_time)  $exit_time  = date('Y-m-d H:i:s', strtotime($exit_time));
    $status       = trim($_POST['status']      ?? 'upcoming');

    $pay_amount   = trim($_POST['pay_amount']  ?? '');
    $pay_method   = trim($_POST['pay_method']  ?? 'cash');
    $pay_status   = trim($_POST['pay_status']  ?? 'pending');

    // --- Validate ---
    if (!$entry_time) $errors[] = 'Entry time is required.';
    if (!$slot_id)    $errors[] = 'Please select a parking slot.';

    if ($user_mode === 'guest') {
        if (!$guest_name) $errors[] = 'Guest name is required.';
    } else {
        if (!$user_id) $errors[] = 'Please select a user.';
    }

    if ($vehicle_mode === 'new') {
        if (!$plate) $errors[] = 'Plate number is required for a new vehicle.';
    } else {
        if (!$vehicle_id) $errors[] = 'Please select a vehicle.';
    }

    if ($exit_time && $entry_time && strtotime($exit_time) <= strtotime($entry_time)) {
        $errors[] = 'Exit time must be after entry time.';
    }

    if (empty($errors)) {

        // --- Resolve user_id for guest ---
        $final_user_id = ($user_mode === 'existing') ? $user_id : null;

        // --- Create new vehicle if needed ---
        if ($vehicle_mode === 'new') {
            // Check plate uniqueness
            $chk = $conn->prepare("SELECT id FROM vehicles WHERE plate_number = ?");
            $chk->bind_param('s', $plate);
            $chk->execute();
            $chk->store_result();
            if ($chk->num_rows > 0) {
                $chk->bind_result($vehicle_id); // reuse existing
                $chk->fetch();
            } else {
                $uid_for_v = $final_user_id ?? 0;
                // If guest, we need a placeholder user_id — use 0 only if FK allows NULL
                // vehicles.user_id is NOT NULL per schema, so create a guest user if needed
                if (!$final_user_id) {
                    // Insert a minimal guest user
                    $gu_email = $guest_email ?: ('guest_' . time() . '@parkify.local');
                    $gu_name  = $guest_name  ?: 'Guest';
                    $gu_stmt  = $conn->prepare("INSERT INTO users (full_name, email, role) VALUES (?, ?, 'guest')");
                    $gu_stmt->bind_param('ss', $gu_name, $gu_email);
                    $gu_stmt->execute();
                    $final_user_id = $conn->insert_id;
                    $gu_stmt->close();
                }
                $ins_v = $conn->prepare("INSERT INTO vehicles (user_id, plate_number, make, model, color) VALUES (?, ?, ?, ?, ?)");
                $ins_v->bind_param('issss', $final_user_id, $plate, $v_make, $v_model, $v_color);
                $ins_v->execute();
                $vehicle_id = $conn->insert_id;
                $ins_v->close();
            }
            $chk->close();
        }

        // Fetch plate_number from vehicle if using existing vehicle
        if ($vehicle_mode === 'existing') {
            $vrow = $conn->query("SELECT plate_number FROM vehicles WHERE id = $vehicle_id")->fetch_assoc();
            $plate = $vrow['plate_number'] ?? $plate;
        }

        // --- Calculate duration ---
        $duration_mins = null;
        if ($exit_time) {
            $duration_mins = (int)round((strtotime($exit_time) - strtotime($entry_time)) / 60);
        }

        // --- Insert parking_session ---
        $ins_s = $conn->prepare("
            INSERT INTO parking_sessions
                (user_id, vehicle_id, slot_id, plate_number, entry_time, exit_time, duration_mins, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ins_s->bind_param(
            'iiisssis',
            $final_user_id, $vehicle_id, $slot_id, $plate,
            $entry_time, $exit_time, $duration_mins, $status
        );
        $ins_s->execute();
        $session_id = $conn->insert_id;
        $ins_s->close();

        // --- Mark slot as occupied if active ---
        if ($status === 'active') {
            $conn->query("UPDATE parking_slots SET status='occupied' WHERE id=$slot_id");
        }

        // --- Insert payment row if amount provided ---
        if ($pay_amount !== '' && is_numeric($pay_amount)) {
            $rate      = 20.00;
            $paid_at   = ($pay_status === 'paid') ? date('Y-m-d H:i:s') : null;
            $ins_p = $conn->prepare("
                INSERT INTO payments
                    (session_id, user_id, amount, rate_per_hour, method, status, paid_at)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $ins_p->bind_param('iiddsss', $session_id, $final_user_id, $pay_amount, $rate, $pay_method, $pay_status, $paid_at);
            $ins_p->execute();
            $ins_p->close();
        }

        $new_id  = $session_id;
        $success = true;
    }
}

// ── Fetch dropdown data ──────────────────────────────────────
$users   = $conn->query("SELECT id, full_name, email FROM users WHERE role != 'guest' ORDER BY full_name")->fetch_all(MYSQLI_ASSOC);
$slots   = $conn->query("SELECT id, slot_code, row_label, status FROM parking_slots ORDER BY row_label, slot_code")->fetch_all(MYSQLI_ASSOC);
$vehicles_all = $conn->query("SELECT v.id, v.plate_number, v.make, v.model, u.full_name, v.user_id FROM vehicles v LEFT JOIN users u ON u.id = v.user_id ORDER BY v.plate_number")->fetch_all(MYSQLI_ASSOC);

// Status options
$statuses = ['upcoming', 'active', 'completed', 'cancelled', 'no-show'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>New Booking — Parkify</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="icon" href="../images/fabiconlogo.png" type="image/png">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600;9..40,700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f5f6fa;--card:#ffffff;--border:#e8eaf0;
    --text:#1a1d2e;--text-muted:#8589a0;--text-light:#b0b4c8;
    --accent:#2563eb;--accent-light:#eff4ff;--accent-hover:#1d4ed8;
    --green:#16a34a;--green-bg:#f0fdf4;
    --red:#dc2626;--red-bg:#fef2f2;
    --amber:#d97706;--amber-bg:#fffbeb;
    --slate:#64748b;--slate-bg:#f8fafc;
    --sidebar-w:220px;--radius:10px;--radius-sm:6px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.07);
    --font:'DM Sans',sans-serif;--mono:'DM Mono',monospace;
}
html,body{height:100%}
body{font-family:var(--font);font-size:14px;background:var(--bg);color:var(--text);display:flex;-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}

.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh}

/* Topbar */
.topbar{height:60px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:12px}
.back-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:13px;font-weight:500;color:var(--text-muted);background:transparent;cursor:pointer;transition:all .15s}
.back-btn:hover{background:var(--bg);color:var(--text)}
.topbar-title h1{font-size:18px;font-weight:700;letter-spacing:-.4px}
.topbar-title p{font-size:12px;color:var(--text-muted);margin-top:1px}
.admin-pill{display:flex;align-items:center;gap:10px}
.admin-avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#2563eb,#7c3aed);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:700;color:#fff}
.admin-info .name{font-size:13px;font-weight:600}
.admin-info .role{font-size:11px;color:var(--text-muted)}

/* Content */
.content{padding:28px;flex:1;max-width:900px;width:100%}

/* Alert */
.alert{padding:14px 18px;border-radius:var(--radius-sm);margin-bottom:22px;font-size:13.5px;display:flex;align-items:flex-start;gap:10px}
.alert svg{width:16px;height:16px;flex-shrink:0;margin-top:1px}
.alert-error{background:var(--red-bg);border:1px solid #fecaca;color:var(--red)}
.alert-success{background:var(--green-bg);border:1px solid #bbf7d0;color:var(--green)}
.alert ul{margin:.4em 0 0 1.1em}
.alert ul li{margin-top:.2em}

/* Card */
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-sm);margin-bottom:20px;overflow:hidden;animation:fadeUp .35s ease both}
.card-header{padding:16px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px}
.card-header h2{font-size:14.5px;font-weight:700}
.card-header p{font-size:12px;color:var(--text-muted);margin-top:2px}
.card-icon{width:34px;height:34px;border-radius:8px;display:grid;place-items:center;flex-shrink:0}
.card-icon svg,.card-icon i{width:16px;height:16px;font-size:15px}
.ci-blue{background:var(--accent-light);color:var(--accent)}
.ci-green{background:var(--green-bg);color:var(--green)}
.ci-amber{background:var(--amber-bg);color:var(--amber)}
.card-body{padding:20px 22px}

/* Tab toggle */
.toggle-group{display:inline-flex;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius-sm);padding:3px;gap:3px;margin-bottom:16px}
.toggle-btn{padding:6px 18px;border-radius:4px;font-family:var(--font);font-size:12.5px;font-weight:600;cursor:pointer;border:none;background:transparent;color:var(--text-muted);transition:all .15s}
.toggle-btn.active{background:var(--card);color:var(--accent);box-shadow:0 1px 3px rgba(0,0,0,.1)}

/* Form grid */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-grid.single{grid-template-columns:1fr}
.form-grid.triple{grid-template-columns:1fr 1fr 1fr}

.field{display:flex;flex-direction:column;gap:5px}
.field.span2{grid-column:span 2}
label{font-size:12px;font-weight:600;color:var(--text-muted);letter-spacing:.03em;text-transform:uppercase}
.required-star{color:var(--red);margin-left:2px}
input[type=text],input[type=email],input[type=number],input[type=datetime-local],select,textarea{
    padding:9px 12px;border:1px solid var(--border);border-radius:var(--radius-sm);
    font-family:var(--font);font-size:13.5px;color:var(--text);background:#fff;outline:none;
    transition:border-color .15s;width:100%
}
input:focus,select:focus,textarea:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,99,235,.08)}
input::placeholder,textarea::placeholder{color:var(--text-light)}
select option[disabled]{color:var(--text-muted)}
.field-note{font-size:11.5px;color:var(--text-muted);margin-top:2px}
.slot-badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:600;margin-left:6px}
.slot-available{background:var(--green-bg);color:var(--green)}
.slot-occupied{background:var(--red-bg);color:var(--red)}

/* Divider */
.section-divider{border:none;border-top:1px solid var(--border);margin:18px 0}

/* Footer actions */
.form-footer{display:flex;align-items:center;gap:10px;justify-content:flex-end;padding-top:6px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 20px;border-radius:var(--radius-sm);font-family:var(--font);font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .15s;white-space:nowrap}
.btn svg,.btn i{width:15px;height:15px;font-size:14px}
.btn-outline{background:transparent;border:1px solid var(--border);color:var(--text)}
.btn-outline:hover{background:var(--bg);border-color:#c5c8d8}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent-hover)}
.btn-success{background:var(--green);color:#fff}
.btn-success:hover{background:#15803d}

/* Success state */
.success-box{text-align:center;padding:40px 20px}
.success-box .check-ring{width:68px;height:68px;border-radius:50%;background:var(--green-bg);display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px}
.success-box .check-ring svg{width:32px;height:32px;color:var(--green)}
.success-box h2{font-size:20px;font-weight:700;margin-bottom:8px}
.success-box p{font-size:13.5px;color:var(--text-muted);margin-bottom:24px}
.success-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* Hidden sections */
.hidden{display:none}
</style>
</head>
<body>

<?php
$current_page = 'bookings';
include 'sidebar.php';
?>

<div class="main">

    <!-- Topbar -->
    <header class="topbar">
        <div class="topbar-left">
            <a href="bookings.php" class="back-btn">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg>
                Back
            </a>
            <div class="topbar-title">
                <h1>New Booking</h1>
                <p>Manually create a parking reservation</p>
            </div>
        </div>
        <div class="admin-pill">
            <?php
            $admin_name = $_SESSION['user_name'] ?? 'Admin';
            $admin_role = $_SESSION['role']      ?? 'admin';
            $words      = explode(' ', trim($admin_name));
            $initials   = strtoupper(substr($words[0],0,1).(isset($words[1])?substr($words[1],0,1):''));
            ?>
            <div class="admin-avatar"><?= htmlspecialchars($initials) ?></div>
            <div class="admin-info">
                <div class="name"><?= htmlspecialchars($admin_name) ?></div>
                <div class="role"><?= htmlspecialchars(ucfirst($admin_role)) ?></div>
            </div>
        </div>
    </header>

    <div class="content">

        <?php if ($success): ?>
        <!-- ── Success ── -->
        <div class="card">
            <div class="success-box">
                <div class="check-ring">
                    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <h2>Booking Created!</h2>
                <p>Booking <strong>#BK-<?= str_pad($new_id, 5, '0', STR_PAD_LEFT) ?></strong> has been successfully added.</p>
                <div class="success-actions">
                    <a href="booking_view.php?id=<?= $new_id ?>" class="btn btn-primary">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        View Booking
                    </a>
                    <a href="booking_new.php" class="btn btn-outline">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                        Add Another
                    </a>
                    <a href="bookings.php" class="btn btn-outline">All Bookings</a>
                </div>
            </div>
        </div>

        <?php else: ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <div>
                <strong>Please fix the following:</strong>
                <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="bookingForm">

            <!-- ── Section 1: User ── -->
            <div class="card" style="animation-delay:.05s">
                <div class="card-header">
                    <div class="card-icon ci-blue">
                        <i class="fa-solid fa-user"></i>
                    </div>
                    <div>
                        <h2>User Details</h2>
                        <p>Select an existing user or enter guest information</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="toggle-group">
                        <button type="button" class="toggle-btn active" onclick="switchMode('user','existing')">Existing User</button>
                        <button type="button" class="toggle-btn"        onclick="switchMode('user','guest')">Walk-in Guest</button>
                    </div>
                    <input type="hidden" name="user_mode" id="user_mode" value="existing">

                    <!-- Existing user -->
                    <div id="user_existing_section" class="form-grid single">
                        <div class="field">
                            <label>User <span class="required-star">*</span></label>
                            <select name="user_id" id="user_id_select" onchange="filterVehicles()">
                                <option value="">— Select user —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"
                                    <?= ((int)($_POST['user_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['full_name']) ?> — <?= htmlspecialchars($u['email']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Guest -->
                    <div id="user_guest_section" class="form-grid hidden">
                        <div class="field">
                            <label>Full Name <span class="required-star">*</span></label>
                            <input type="text" name="guest_name" placeholder="e.g. Ram Sharma" value="<?= htmlspecialchars($_POST['guest_name'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Email <span class="field-note">(optional)</span></label>
                            <input type="email" name="guest_email" placeholder="guest@email.com" value="<?= htmlspecialchars($_POST['guest_email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 2: Vehicle ── -->
            <div class="card" style="animation-delay:.10s">
                <div class="card-header">
                    <div class="card-icon ci-blue">
                        <i class="fa-solid fa-car"></i>
                    </div>
                    <div>
                        <h2>Vehicle</h2>
                        <p>Pick an existing vehicle or register a new one</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="toggle-group">
                        <button type="button" class="toggle-btn active" onclick="switchMode('vehicle','existing')">Existing Vehicle</button>
                        <button type="button" class="toggle-btn"        onclick="switchMode('vehicle','new')">New Vehicle</button>
                    </div>
                    <input type="hidden" name="vehicle_mode" id="vehicle_mode" value="existing">

                    <!-- Existing vehicle -->
                    <div id="vehicle_existing_section" class="form-grid single">
                        <div class="field">
                            <label>Vehicle <span class="required-star">*</span></label>
                            <select name="vehicle_id" id="vehicle_select">
                                <option value="">— Select vehicle —</option>
                                <?php foreach ($vehicles_all as $v): ?>
                                <option value="<?= $v['id'] ?>" data-user="<?= $v['user_id'] ?>"
                                    <?= ((int)($_POST['vehicle_id'] ?? 0) === (int)$v['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['plate_number']) ?>
                                    <?php if ($v['make'] || $v['model']): ?>
                                        — <?= htmlspecialchars(trim($v['make'].' '.$v['model'])) ?>
                                    <?php endif; ?>
                                    (<?= htmlspecialchars($v['full_name'] ?? 'no user') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="field-note">Selecting a user above will filter vehicles.</span>
                        </div>
                    </div>

                    <!-- New vehicle -->
                    <div id="vehicle_new_section" class="hidden">
                        <div class="form-grid">
                            <div class="field">
                                <label>Plate Number <span class="required-star">*</span></label>
                                <input type="text" name="plate_number" placeholder="e.g. BA 1 KHA 1234" style="text-transform:uppercase" value="<?= htmlspecialchars($_POST['plate_number'] ?? '') ?>">
                            </div>
                            <div class="field">
                                <label>Color</label>
                                <input type="text" name="v_color" placeholder="e.g. White" value="<?= htmlspecialchars($_POST['v_color'] ?? '') ?>">
                            </div>
                            <div class="field">
                                <label>Make</label>
                                <input type="text" name="v_make" placeholder="e.g. Toyota" value="<?= htmlspecialchars($_POST['v_make'] ?? '') ?>">
                            </div>
                            <div class="field">
                                <label>Model</label>
                                <input type="text" name="v_model" placeholder="e.g. Corolla" value="<?= htmlspecialchars($_POST['v_model'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 3: Slot & Time ── -->
            <div class="card" style="animation-delay:.15s">
                <div class="card-header">
                    <div class="card-icon ci-amber">
                        <i class="fa-solid fa-location-dot"></i>
                    </div>
                    <div>
                        <h2>Slot &amp; Schedule</h2>
                        <p>Assign a parking slot and set the time window</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-grid">
                        <div class="field">
                            <label>Parking Slot <span class="required-star">*</span></label>
                            <select name="slot_id">
                                <option value="">— Choose slot —</option>
                                <?php foreach ($slots as $sl): ?>
                                <option value="<?= $sl['id'] ?>" <?= ((int)($_POST['slot_id'] ?? 0) === (int)$sl['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sl['slot_code']) ?>
                                    (<?= ucfirst($sl['status']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Status <span class="required-star">*</span></label>
                            <select name="status">
                                <?php foreach ($statuses as $s): ?>
                                <option value="<?= $s ?>" <?= (($_POST['status'] ?? 'upcoming') === $s) ? 'selected' : '' ?>>
                                    <?= ucfirst($s) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Entry Time <span class="required-star">*</span></label>
                            <input type="datetime-local" name="entry_time" value="<?= htmlspecialchars($_POST['entry_time'] ?? date('Y-m-d\TH:i')) ?>">
                        </div>
                        <div class="field">
                            <label>Exit Time <span class="field-note">(optional)</span></label>
                            <input type="datetime-local" name="exit_time" value="<?= htmlspecialchars($_POST['exit_time'] ?? '') ?>">
                            <span class="field-note">Leave blank for open-ended / active sessions.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Section 4: Payment ── -->
            <div class="card" style="animation-delay:.20s">
                <div class="card-header">
                    <div class="card-icon ci-green">
                        <i class="fa-solid fa-credit-card"></i>
                    </div>
                    <div>
                        <h2>Payment <span style="font-weight:400;font-size:12px;color:var(--text-muted)">(optional)</span></h2>
                        <p>Leave amount blank to skip creating a payment record</p>
                    </div>
                </div>
                <div class="card-body">
                    <div class="form-grid triple">
                        <div class="field">
                            <label>Amount (Rs)</label>
                            <input type="number" name="pay_amount" min="0" step="0.01" placeholder="e.g. 100.00" value="<?= htmlspecialchars($_POST['pay_amount'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label>Method</label>
                            <select name="pay_method">
                                <?php foreach (['cash','card','esewa','khalti','bank'] as $m): ?>
                                <option value="<?= $m ?>" <?= (($_POST['pay_method'] ?? 'cash') === $m) ? 'selected' : '' ?>><?= ucfirst($m) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label>Payment Status</label>
                            <select name="pay_status">
                                <?php foreach (['pending','paid','failed','refunded'] as $ps): ?>
                                <option value="<?= $ps ?>" <?= (($_POST['pay_status'] ?? 'pending') === $ps) ? 'selected' : '' ?>><?= ucfirst($ps) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Footer -->
            <div class="form-footer">
                <a href="bookings.php" class="btn btn-outline">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i>
                    Create Booking
                </button>
            </div>

        </form>
        <?php endif; ?>

    </div><!-- /content -->
</div><!-- /main -->

<script>
// ── Toggle user/vehicle modes ────────────────────────────────
function switchMode(type, mode) {
    const prefix = type + '_';
    document.getElementById(prefix + 'mode').value = mode;

    const sections  = document.querySelectorAll(`[id^="${prefix}"][id$="_section"]`);
    const btns      = document.querySelectorAll(`[onclick^="switchMode('${type}'"]`);

    sections.forEach(s => {
        s.classList.toggle('hidden', !s.id.includes(mode));
    });
    btns.forEach((b, i) => {
        b.classList.toggle('active', (i === 0 && mode === 'existing') || (i === 1 && mode !== 'existing'));
    });
}

// ── Filter vehicles by selected user ────────────────────────
function filterVehicles() {
    const uid     = document.getElementById('user_id_select').value;
    const sel     = document.getElementById('vehicle_select');
    const options = sel.querySelectorAll('option[data-user]');

    options.forEach(opt => {
        const show = !uid || opt.dataset.user === uid;
        opt.style.display = show ? '' : 'none';
    });
    // Reset selection if current pick is now hidden
    const chosen = sel.options[sel.selectedIndex];
    if (chosen && chosen.style.display === 'none') sel.value = '';
}

// ── Restore toggle states on validation failure ──────────────
(function () {
    const um = document.getElementById('user_mode')?.value;
    const vm = document.getElementById('vehicle_mode')?.value;
    if (um === 'guest')    switchMode('user',    'guest');
    if (vm === 'new')      switchMode('vehicle', 'new');
})();
</script>

</body>
</html>
<?php $conn->close(); ?>