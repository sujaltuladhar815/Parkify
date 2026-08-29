<?php
// ============================================================
//  Parkify — My Vehicles (vehicles.php)
//  Location: Parkify/user/vehicles.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Auth Guard ──────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated.']);
        exit;
    }
    header('Location: ../login/login.php');
    exit;
}

// ── DB Connection ───────────────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'parkify_db');
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'DB error: ' . $conn->connect_error]);
        exit;
    }
    die('<p style="font-family:sans-serif;padding:40px;color:red">❌ Database connection failed: ' . $conn->connect_error . '</p>');
}

$userId = (int) $_SESSION['user_id'];

// ── Patch vehicles table with any columns database.php omitted ──
// CREATE TABLE IF NOT EXISTS is a no-op when the table already exists;
// ALTER TABLE ADD COLUMN IF NOT EXISTS safely adds missing columns.
$conn->query("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS year       SMALLINT   NULL");
$conn->query("ALTER TABLE vehicles ADD COLUMN IF NOT EXISTS is_default TINYINT(1) NOT NULL DEFAULT 0");

// ============================================================
//  AJAX ENDPOINTS
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── ADD VEHICLE ───────────────────────────────────────
    if ($action === 'add') {
        $input = json_decode(file_get_contents('php://input'), true);
        $plate = strtoupper(trim($input['plate_number'] ?? ''));
        $make  = trim($input['make']  ?? '');
        $model = trim($input['model'] ?? '');
        $color = trim($input['color'] ?? '');
        $year  = isset($input['year']) && $input['year'] !== '' ? (int)$input['year'] : null;

        // Validation
        if ($plate === '') {
            echo json_encode(['success' => false, 'message' => 'Plate number is required.']);
            exit;
        }
        if (strlen($plate) < 3 || strlen($plate) > 20) {
            echo json_encode(['success' => false, 'message' => 'Plate number must be 3–20 characters.']);
            exit;
        }
        if ($year !== null && ($year < 1970 || $year > (int)date('Y') + 1)) {
            echo json_encode(['success' => false, 'message' => 'Please enter a valid year.']);
            exit;
        }

        // Check duplicate plate for this user
        $chk = $conn->prepare("SELECT id FROM vehicles WHERE user_id = ? AND plate_number = ?");
        $chk->bind_param('is', $userId, $plate);
        $chk->execute();
        if ($chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => "Vehicle $plate is already registered to your account."]);
            exit;
        }

        // If this is the first vehicle, make it default
        $countRes = $conn->prepare("SELECT COUNT(*) AS cnt FROM vehicles WHERE user_id = ?");
        $countRes->bind_param('i', $userId);
        $countRes->execute();
        $isFirst = (int)$countRes->get_result()->fetch_assoc()['cnt'] === 0 ? 1 : 0;

        $stmt = $conn->prepare("
            INSERT INTO vehicles (user_id, plate_number, make, model, color, year, is_default)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param('issssii', $userId, $plate, $make, $model, $color, $year, $isFirst);

        if ($stmt->execute()) {
            $newId = $conn->insert_id;
            echo json_encode([
                'success' => true,
                'message' => "Vehicle $plate added successfully!",
                'vehicle' => [
                    'id'           => $newId,
                    'plate_number' => $plate,
                    'make'         => $make,
                    'model'        => $model,
                    'color'        => $color,
                    'year'         => $year,
                    'is_default'   => $isFirst,
                ],
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add vehicle. Please try again.']);
        }
        exit;
    }

    // ── SET DEFAULT ───────────────────────────────────────
    if ($action === 'set_default') {
        $input = json_decode(file_get_contents('php://input'), true);
        $vid   = (int)($input['vehicle_id'] ?? 0);

        // Make sure it belongs to this user
        $chk = $conn->prepare("SELECT id FROM vehicles WHERE id = ? AND user_id = ?");
        $chk->bind_param('ii', $vid, $userId);
        $chk->execute();
        if (!$chk->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
            exit;
        }

        $conn->begin_transaction();
        $conn->query("UPDATE vehicles SET is_default = 0 WHERE user_id = $userId");
        $s = $conn->prepare("UPDATE vehicles SET is_default = 1 WHERE id = ? AND user_id = ?");
        $s->bind_param('ii', $vid, $userId);
        $s->execute();
        $conn->commit();

        echo json_encode(['success' => true, 'message' => 'Default vehicle updated.']);
        exit;
    }

    // ── DELETE VEHICLE ────────────────────────────────────
    if ($action === 'delete') {
        $input = json_decode(file_get_contents('php://input'), true);
        $vid   = (int)($input['vehicle_id'] ?? 0);

        // Verify ownership
        $chk = $conn->prepare("SELECT id, plate_number, is_default FROM vehicles WHERE id = ? AND user_id = ?");
        $chk->bind_param('ii', $vid, $userId);
        $chk->execute();
        $veh = $chk->get_result()->fetch_assoc();
        if (!$veh) {
            echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
            exit;
        }

        // Block delete if vehicle is in an active parking session
        $activeCheck = $conn->prepare("SELECT id FROM parking_sessions WHERE vehicle_id = ? AND status = 'active' LIMIT 1");
        $activeCheck->bind_param('i', $vid);
        $activeCheck->execute();
        if ($activeCheck->get_result()->fetch_assoc()) {
            echo json_encode(['success' => false, 'message' => 'Cannot delete — this vehicle is currently parked.']);
            exit;
        }

        $del = $conn->prepare("DELETE FROM vehicles WHERE id = ? AND user_id = ?");
        $del->bind_param('ii', $vid, $userId);
        if ($del->execute()) {
            // If we deleted the default, promote the next vehicle
            if ($veh['is_default']) {
                $conn->query("UPDATE vehicles SET is_default = 1 WHERE user_id = $userId ORDER BY created_at ASC LIMIT 1");
            }
            echo json_encode(['success' => true, 'message' => "Vehicle {$veh['plate_number']} removed."]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Delete failed.']);
        }
        exit;
    }

    // ── LOGOUT ────────────────────────────────────────────
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true, 'redirect' => '../login/login.php']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ============================================================
//  INITIAL PAGE LOAD
// ============================================================
$userName = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userInit = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1));

// Fetch all user vehicles with active session flag
$stmt = $conn->prepare("
    SELECT
        v.id, v.plate_number, v.make, v.model, v.color, v.year,
        v.is_default, v.created_at,
        (SELECT COUNT(*) FROM parking_sessions ps
         WHERE ps.vehicle_id = v.id AND ps.status = 'active') AS is_parked
    FROM vehicles v
    WHERE v.user_id = ?
    ORDER BY v.is_default DESC, v.created_at ASC
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result   = $stmt->get_result();
$vehicles = [];
while ($row = $result->fetch_assoc()) { $vehicles[] = $row; }

$totalVehicles = count($vehicles);
$conn->close();

// Color → CSS class/hex mapping for swatches
$colorMap = [
    'White'  => '#ffffff', 'Black'  => '#1e293b', 'Silver' => '#94a3b8',
    'Gray'   => '#64748b', 'Red'    => '#ef4444', 'Blue'   => '#3b82f6',
    'Green'  => '#22c55e', 'Yellow' => '#eab308', 'Orange' => '#f97316',
    'Brown'  => '#92400e', 'Maroon' => '#7f1d1d', 'Gold'   => '#d97706',
    'Purple' => '#7c3aed', 'Beige'  => '#d4b483', 'Other'  => '#cbd5e1',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Parkify — My Vehicles</title>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" />
  <link rel="icon" href="../images/fabiconlogo.png" type="image/png" />

  <style>
    :root {
      --navy: #0f1f3d; --navy-light: #162847;
      --blue: #2563eb; --blue-light: #3b82f6;
      --green: #22c55e; --green-bg: #dcfce7;
      --red: #ef4444;   --red-bg: #fee2e2;
      --amber: #f59e0b; --amber-bg: #fef3c7;
      --purple: #7c3aed; --purple-bg: #f3e8ff;
      --bg: #f0f4f8;    --card: #ffffff;
      --text: #1e293b;  --text-muted: #64748b;
      --border: #e2e8f0;
      --shadow: 0 4px 24px rgba(15,31,61,.07);
      --shadow-lg: 0 8px 40px rgba(15,31,61,.12);
      --radius: 16px; --radius-sm: 10px;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: "DM Sans", sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; flex-direction: column; }

    /* ── MAIN ── */
    main {
      flex: 1; padding: 28px 32px; max-width: 1100px; margin: 0 auto; width: 100%;
      display: flex; flex-direction: column; gap: 24px;
      animation: fadeUp .45s ease both;
    }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: translateY(0); } }

    /* ── PAGE HEADER ── */
    .page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .page-header h1 { font-family: "Space Grotesk", sans-serif; font-size: 24px; font-weight: 700; display: flex; align-items: center; gap: 10px; }
    .page-header p  { color: var(--text-muted); font-size: 14px; margin-top: 3px; }
    .add-btn {
      display: flex; align-items: center; gap: 8px;
      background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff;
      border: none; padding: 11px 20px; border-radius: 12px; cursor: pointer;
      font-family: "Space Grotesk", sans-serif; font-size: 14px; font-weight: 600;
      box-shadow: 0 4px 14px rgba(37,99,235,.3); transition: all .25s;
    }
    .add-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(37,99,235,.4); }

    /* ── STAT BAR ── */
    .stat-bar {
      display: flex; gap: 16px; flex-wrap: wrap;
    }
    .stat-card {
      background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm);
      padding: 16px 22px; display: flex; align-items: center; gap: 14px; flex: 1; min-width: 160px;
    }
    .stat-icon { width: 42px; height: 42px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .si-blue   { background: #eff6ff; color: var(--blue); }
    .si-green  { background: var(--green-bg); color: #15803d; }
    .si-amber  { background: var(--amber-bg); color: var(--amber); }
    .stat-info .stat-num   { font-family: "Space Grotesk", sans-serif; font-size: 22px; font-weight: 800; line-height: 1; }
    .stat-info .stat-label { font-size: 12px; color: var(--text-muted); margin-top: 2px; }

    /* ── EMPTY STATE ── */
    .empty-state {
      background: var(--card); border: 2px dashed var(--border); border-radius: var(--radius);
      padding: 60px 32px; text-align: center;
    }
    .empty-icon { font-size: 52px; color: #cbd5e1; margin-bottom: 16px; }
    .empty-title { font-family: "Space Grotesk", sans-serif; font-size: 20px; font-weight: 700; margin-bottom: 8px; }
    .empty-desc  { color: var(--text-muted); font-size: 14px; margin-bottom: 24px; }
    .empty-add-btn {
      display: inline-flex; align-items: center; gap: 8px;
      background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff;
      border: none; padding: 13px 24px; border-radius: 12px; cursor: pointer;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600;
      box-shadow: 0 4px 14px rgba(37,99,235,.3); transition: all .25s;
    }
    .empty-add-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(37,99,235,.4); }

    /* ── VEHICLES GRID ── */
    .vehicles-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; }

    /* ── VEHICLE CARD ── */
    .vehicle-card {
      background: var(--card); border: 2px solid var(--border); border-radius: var(--radius);
      overflow: hidden; transition: all .25s; position: relative;
    }
    .vehicle-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-3px); }
    .vehicle-card.default-card { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
    .vehicle-card.parked-card  { border-color: var(--green); box-shadow: 0 0 0 3px rgba(34,197,94,.12); }

    /* Card top strip */
    .card-strip {
      height: 6px;
      background: linear-gradient(90deg, var(--blue), var(--blue-light));
    }
    .vehicle-card.parked-card  .card-strip { background: linear-gradient(90deg, var(--green), #16a34a); }
    .vehicle-card.default-card .card-strip { background: linear-gradient(90deg, var(--blue), var(--blue-light)); }

    .card-body { padding: 20px 22px; }

    /* Plate display */
    .plate-wrap { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
    .plate {
      display: inline-flex; align-items: center; gap: 0;
      border: 2.5px solid #1e293b; border-radius: 6px; overflow: hidden;
      font-family: "Space Grotesk", sans-serif; font-weight: 700;
      box-shadow: 0 2px 8px rgba(0,0,0,.12);
    }
    .plate-flag { background: var(--navy); color: #fff; font-size: 10px; font-weight: 700; padding: 5px 7px; letter-spacing: .3px; writing-mode: vertical-rl; text-orientation: mixed; border-right: 2px solid #1e293b; }
    .plate-num  { background: #fff; color: #1e293b; font-size: 17px; padding: 6px 12px; letter-spacing: 2px; }

    .plate-badges { display: flex; flex-direction: column; gap: 5px; }
    .default-badge {
      display: inline-flex; align-items: center; gap: 4px;
      background: #eff6ff; color: var(--blue); border: 1px solid #bfdbfe;
      padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 700;
    }
    .parked-badge {
      display: inline-flex; align-items: center; gap: 4px;
      background: var(--green-bg); color: #15803d; border: 1px solid #bbf7d0;
      padding: 3px 9px; border-radius: 20px; font-size: 11px; font-weight: 700;
    }
    .parked-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--green); animation: pulse 1.5s infinite; }
    @keyframes pulse { 0%,100% { opacity:1; } 50% { opacity:.4; } }

    /* Vehicle info grid */
    .vehicle-info { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 16px; }
    .info-item {}
    .info-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; font-weight: 600; margin-bottom: 2px; }
    .info-val   { font-size: 14px; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: 6px; }
    .info-val.muted { color: var(--text-muted); font-weight: 400; font-style: italic; }
    .color-swatch { width: 14px; height: 14px; border-radius: 50%; border: 1.5px solid rgba(0,0,0,.12); flex-shrink: 0; }

    /* Card actions */
    .card-actions {
      display: flex; gap: 8px; padding: 14px 22px;
      border-top: 1px solid var(--border); background: #fafbff;
    }
    .action-btn {
      flex: 1; padding: 9px 12px; border-radius: 9px; border: 1.5px solid var(--border);
      background: #fff; font-family: "DM Sans", sans-serif; font-size: 13px; font-weight: 600;
      cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px;
      color: var(--text); transition: all .18s;
    }
    .action-btn:hover { border-color: var(--blue); color: var(--blue); background: #f0f7ff; }
    .action-btn.danger:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }
    .action-btn.active-default { background: #eff6ff; border-color: #bfdbfe; color: var(--blue); cursor: default; }

    /* ── ADD MODAL ── */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(15,31,61,.5); backdrop-filter: blur(4px);
      z-index: 500; display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    .modal-overlay.active { opacity: 1; pointer-events: all; }
    .modal {
      background: #fff; border-radius: var(--radius); width: 100%; max-width: 520px;
      box-shadow: 0 24px 80px rgba(0,0,0,.18);
      transform: translateY(20px); transition: transform .3s ease;
      max-height: 90vh; overflow-y: auto;
    }
    .modal-overlay.active .modal { transform: translateY(0); }
    .modal-head {
      padding: 24px 28px 0; display: flex; align-items: flex-start; justify-content: space-between;
    }
    .modal-head-left { display: flex; align-items: center; gap: 14px; }
    .modal-icon-wrap { width: 48px; height: 48px; border-radius: 14px; background: #eff6ff; display: flex; align-items: center; justify-content: center; font-size: 22px; color: var(--blue); flex-shrink: 0; }
    .modal-title { font-family: "Space Grotesk", sans-serif; font-size: 19px; font-weight: 700; }
    .modal-sub   { font-size: 13px; color: var(--text-muted); margin-top: 2px; }
    .modal-close { background: none; border: none; font-size: 20px; color: var(--text-muted); cursor: pointer; padding: 4px; line-height: 1; margin-top: -2px; }
    .modal-close:hover { color: var(--text); }

    .modal-body { padding: 22px 28px 8px; }

    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
    .form-group { margin-bottom: 16px; }
    .form-group.full { grid-column: 1 / -1; }
    .form-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text); }
    .form-label span.req { color: var(--red); margin-left: 2px; }
    .form-input, .form-select {
      width: 100%; padding: 11px 14px; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
      font-family: "DM Sans", sans-serif; font-size: 14px; color: var(--text); background: #fff;
      transition: border-color .18s, box-shadow .18s; outline: none;
      -webkit-appearance: none; appearance: none;
    }
    .form-input:focus, .form-select:focus {
      border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.12);
    }
    .form-input.error { border-color: var(--red); }
    .form-hint  { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; }
    .form-error { font-size: 12px; color: var(--red); margin-top: 5px; display: none; }
    .form-error.show { display: block; }

    /* Plate preview inside modal */
    .plate-preview-wrap { display: flex; align-items: center; gap: 12px; margin-top: 10px; }
    .plate-preview-label { font-size: 12px; color: var(--text-muted); }
    .plate-preview {
      display: inline-flex; align-items: center;
      border: 2.5px solid #1e293b; border-radius: 6px; overflow: hidden;
      font-family: "Space Grotesk", sans-serif; font-weight: 700;
      box-shadow: 0 2px 8px rgba(0,0,0,.1);
    }
    .plate-preview .pf { background: var(--navy); color: #fff; font-size: 9px; font-weight: 700; padding: 4px 6px; letter-spacing: .3px; writing-mode: vertical-rl; border-right: 2px solid #1e293b; }
    .plate-preview .pn { background: #fff; color: #1e293b; font-size: 15px; padding: 5px 10px; letter-spacing: 2px; min-width: 80px; }

    .modal-foot { padding: 16px 28px 24px; display: flex; gap: 10px; }
    .btn-cancel {
      flex: 1; padding: 13px; border: 1.5px solid var(--border); border-radius: 12px;
      background: #fff; font-family: "DM Sans", sans-serif; font-size: 14px; font-weight: 600;
      cursor: pointer; color: var(--text-muted); transition: all .18s;
    }
    .btn-cancel:hover { border-color: var(--text-muted); color: var(--text); }
    .btn-save {
      flex: 2; padding: 13px; border: none; border-radius: 12px; cursor: pointer;
      background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600;
      display: flex; align-items: center; justify-content: center; gap: 8px;
      box-shadow: 0 4px 14px rgba(37,99,235,.28); transition: all .25s;
    }
    .btn-save:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(37,99,235,.4); }
    .btn-save:disabled { opacity: .6; cursor: not-allowed; transform: none; }

    /* ── DELETE CONFIRM MODAL ── */
    .del-overlay {
      position: fixed; inset: 0; background: rgba(15,31,61,.5); backdrop-filter: blur(4px);
      z-index: 600; display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .2s;
    }
    .del-overlay.active { opacity: 1; pointer-events: all; }
    .del-modal {
      background: #fff; border-radius: var(--radius); max-width: 380px; width: 100%;
      padding: 32px; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.16);
      transform: scale(.93); transition: transform .25s ease;
    }
    .del-overlay.active .del-modal { transform: scale(1); }
    .del-icon  { font-size: 44px; color: var(--red); margin-bottom: 14px; }
    .del-title { font-family: "Space Grotesk", sans-serif; font-size: 19px; font-weight: 700; margin-bottom: 8px; }
    .del-msg   { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5; }
    .del-actions { display: flex; gap: 10px; }
    .del-cancel {
      flex: 1; padding: 12px; border: 1.5px solid var(--border); border-radius: 10px;
      background: #fff; font-family: "DM Sans", sans-serif; font-size: 14px; cursor: pointer;
      color: var(--text-muted); transition: all .18s;
    }
    .del-cancel:hover { border-color: var(--text-muted); color: var(--text); }
    .del-confirm {
      flex: 1; padding: 12px; border: none; border-radius: 10px; cursor: pointer;
      background: var(--red); color: #fff;
      font-family: "Space Grotesk", sans-serif; font-size: 14px; font-weight: 600;
      transition: background .18s;
    }
    .del-confirm:hover { background: #dc2626; }
    .del-confirm:disabled { opacity: .6; cursor: not-allowed; }

    /* ── TOAST ── */
    #toast {
      position: fixed; bottom: 28px; right: 28px; background: #1e293b; color: #fff;
      padding: 12px 20px; border-radius: 12px; font-size: 14px; font-weight: 500;
      display: flex; align-items: center; gap: 10px; z-index: 9999;
      transform: translateY(80px); opacity: 0; transition: all .35s ease;
      box-shadow: 0 8px 32px rgba(0,0,0,.18);
    }
    #toast.show { transform: translateY(0); opacity: 1; }
    #toast.success { background: #14532d; }
    #toast.error   { background: #7f1d1d; }

    footer { background: #fff; border-top: 1px solid var(--border); padding: 12px 32px; display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted); }
    footer i { color: var(--blue); }
  </style>
</head>
<body>

<?php include 'nav.php'; ?>

<main>

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fa-solid fa-car" style="color:var(--blue);font-size:22px"></i> My Vehicles</h1>
      <p>Manage your registered vehicles</p>
    </div>
    <?php if ($totalVehicles > 0): ?>
    <button class="add-btn" onclick="openAddModal()">
      <i class="fa-solid fa-plus"></i> Add Vehicle
    </button>
    <?php endif; ?>
  </div>

  <!-- Stat bar -->
  <?php if ($totalVehicles > 0):
    $parkedCount  = count(array_filter($vehicles, fn($v) => $v['is_parked'] > 0));
    $defaultVehicle = current(array_filter($vehicles, fn($v) => $v['is_default']));
  ?>
  <div class="stat-bar">
    <div class="stat-card">
      <div class="stat-icon si-blue"><i class="fa-solid fa-car-side"></i></div>
      <div class="stat-info">
        <div class="stat-num"><?= $totalVehicles ?></div>
        <div class="stat-label">Registered Vehicle<?= $totalVehicles !== 1 ? 's' : '' ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-green"><i class="fa-solid fa-circle-parking"></i></div>
      <div class="stat-info">
        <div class="stat-num"><?= $parkedCount ?></div>
        <div class="stat-label">Currently Parked</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon si-amber"><i class="fa-solid fa-star"></i></div>
      <div class="stat-info">
        <div class="stat-num" style="font-size:15px;margin-top:2px"><?= $defaultVehicle ? htmlspecialchars($defaultVehicle['plate_number']) : '—' ?></div>
        <div class="stat-label">Default Vehicle</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Empty state -->
  <?php if ($totalVehicles === 0): ?>
  <div class="empty-state">
    <div class="empty-icon"><i class="fa-solid fa-car-burst"></i></div>
    <div class="empty-title">No vehicles yet</div>
    <div class="empty-desc">Add your vehicle's plate number so Parkify can recognise it automatically when you park.</div>
    <button class="empty-add-btn" onclick="openAddModal()">
      <i class="fa-solid fa-plus"></i> Add Your First Vehicle
    </button>
  </div>

  <?php else: ?>

  <!-- Vehicles grid -->
  <div class="vehicles-grid" id="vehicles-grid">
    <?php foreach ($vehicles as $v):
      $isParked  = $v['is_parked'] > 0;
      $isDef     = (bool)$v['is_default'];
      $cardClass = $isParked ? 'parked-card' : ($isDef ? 'default-card' : '');
      $colorHex  = $colorMap[$v['color']] ?? '#cbd5e1';
      $displayColor = $v['color'] ?: null;
    ?>
    <div class="vehicle-card <?= $cardClass ?>" id="vcard-<?= $v['id'] ?>">
      <div class="card-strip"></div>
      <div class="card-body">

        <!-- Plate + badges -->
        <div class="plate-wrap">
          <div class="plate">
            <span class="plate-flag">NP</span>
            <span class="plate-num"><?= htmlspecialchars($v['plate_number']) ?></span>
          </div>
          <div class="plate-badges">
            <?php if ($isDef): ?>
            <span class="default-badge"><i class="fa-solid fa-star" style="font-size:9px"></i> Default</span>
            <?php endif; ?>
            <?php if ($isParked): ?>
            <span class="parked-badge"><span class="parked-dot"></span> Parked</span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Info grid -->
        <div class="vehicle-info">
          <div class="info-item">
            <div class="info-label">Make</div>
            <div class="info-val <?= $v['make'] ? '' : 'muted' ?>"><?= $v['make'] ? htmlspecialchars($v['make']) : 'Not set' ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Model</div>
            <div class="info-val <?= $v['model'] ? '' : 'muted' ?>"><?= $v['model'] ? htmlspecialchars($v['model']) : 'Not set' ?></div>
          </div>
          <div class="info-item">
            <div class="info-label">Color</div>
            <div class="info-val <?= $v['color'] ? '' : 'muted' ?>">
              <?php if ($v['color']): ?>
              <span class="color-swatch" style="background:<?= htmlspecialchars($colorHex) ?>;<?= $v['color']==='White'?'border-color:#cbd5e1':'' ?>"></span>
              <?= htmlspecialchars($v['color']) ?>
              <?php else: ?>
              Not set
              <?php endif; ?>
            </div>
          </div>
          <div class="info-item">
            <div class="info-label">Year</div>
            <div class="info-val <?= $v['year'] ? '' : 'muted' ?>"><?= $v['year'] ?: 'Not set' ?></div>
          </div>
        </div>

      </div>
      <div class="card-actions">
        <?php if (!$isDef): ?>
        <button class="action-btn" onclick="setDefault(<?= $v['id'] ?>, this)" title="Set as default">
          <i class="fa-regular fa-star"></i> Set Default
        </button>
        <?php else: ?>
        <button class="action-btn active-default" disabled title="This is your default vehicle">
          <i class="fa-solid fa-star" style="color:var(--amber)"></i> Default
        </button>
        <?php endif; ?>
        <button class="action-btn danger" onclick="openDeleteModal(<?= $v['id'] ?>, '<?= htmlspecialchars($v['plate_number'], ENT_QUOTES) ?>', <?= $isParked ? 'true' : 'false' ?>)" title="Remove vehicle">
          <i class="fa-solid fa-trash-can"></i> Remove
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <?php endif; ?>

</main>

<footer>
  <span><i class="fa-solid fa-shield-halved"></i> Your vehicle data is stored securely.</span>
  <span><i class="fa-solid fa-copyright"></i> <?= date('Y') ?> Parkify System. All rights reserved.</span>
</footer>

<!-- TOAST -->
<div id="toast"><span id="toast-msg"></span></div>

<!-- ── ADD VEHICLE MODAL ── -->
<div class="modal-overlay" id="add-overlay" onclick="handleOverlayClick(event,'add-overlay')">
  <div class="modal" id="add-modal">

    <div class="modal-head">
      <div class="modal-head-left">
        <div class="modal-icon-wrap"><i class="fa-solid fa-car"></i></div>
        <div>
          <div class="modal-title">Add Vehicle</div>
          <div class="modal-sub">Register a new vehicle to your account</div>
        </div>
      </div>
      <button class="modal-close" onclick="closeAddModal()">&times;</button>
    </div>

    <div class="modal-body">

      <!-- Plate number -->
      <div class="form-group">
        <label class="form-label" for="f-plate">Plate Number <span class="req">*</span></label>
        <input class="form-input" id="f-plate" type="text" placeholder="e.g. BA 1 PA 2345"
               maxlength="20" oninput="updatePlatePreview(this.value)" autocomplete="off" />
        <div class="form-error" id="err-plate"></div>
        <div class="plate-preview-wrap">
          <span class="plate-preview-label">Preview:</span>
          <div class="plate-preview">
            <span class="pf">NP</span>
            <span class="pn" id="plate-preview-text">&nbsp;&nbsp;&nbsp;&nbsp;</span>
          </div>
        </div>
      </div>

      <div class="form-row">
        <!-- Make -->
        <div class="form-group">
          <label class="form-label" for="f-make">Make</label>
          <input class="form-input" id="f-make" type="text" placeholder="e.g. Toyota" maxlength="60" />
        </div>
        <!-- Model -->
        <div class="form-group">
          <label class="form-label" for="f-model">Model</label>
          <input class="form-input" id="f-model" type="text" placeholder="e.g. Hilux" maxlength="60" />
        </div>
        <!-- Color -->
        <div class="form-group">
          <label class="form-label" for="f-color">Color</label>
          <select class="form-select" id="f-color">
            <option value="">Select color</option>
            <?php foreach (array_keys($colorMap) as $c): ?>
            <option value="<?= $c ?>"><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Year -->
        <div class="form-group">
          <label class="form-label" for="f-year">Year</label>
          <input class="form-input" id="f-year" type="number"
                 placeholder="e.g. <?= date('Y') ?>"
                 min="1970" max="<?= (int)date('Y') + 1 ?>" />
        </div>
      </div>

      <p class="form-hint" style="margin-top:-6px"><i class="fa-solid fa-circle-info" style="color:var(--blue);margin-right:4px"></i>Only the plate number is required. Other details help identify your vehicle.</p>

    </div>

    <div class="modal-foot">
      <button class="btn-cancel" onclick="closeAddModal()">Cancel</button>
      <button class="btn-save" id="save-btn" onclick="saveVehicle()">
        <i class="fa-solid fa-plus"></i> Add Vehicle
      </button>
    </div>
  </div>
</div>

<!-- ── DELETE CONFIRM MODAL ── -->
<div class="del-overlay" id="del-overlay">
  <div class="del-modal">
    <div class="del-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <div class="del-title">Remove Vehicle?</div>
    <div class="del-msg" id="del-msg">This will permanently remove the vehicle from your account.</div>
    <div class="del-actions">
      <button class="del-cancel" onclick="closeDeleteModal()">Keep It</button>
      <button class="del-confirm" id="del-confirm-btn" onclick="confirmDelete()">
        <i class="fa-solid fa-trash-can"></i> Remove
      </button>
    </div>
  </div>
</div>

<script>
  let deleteTargetId   = null;
  let deleteTargetPlate = null;

  // ── Overlay helpers ────────────────────────────────────────
  function handleOverlayClick(e, id) {
    if (e.target.id === id) { id === 'add-overlay' ? closeAddModal() : closeDeleteModal(); }
  }

  // ══════════════════════════════════════════════════════════
  //  ADD MODAL
  // ══════════════════════════════════════════════════════════
  function openAddModal() {
    document.getElementById('f-plate').value  = '';
    document.getElementById('f-make').value   = '';
    document.getElementById('f-model').value  = '';
    document.getElementById('f-color').value  = '';
    document.getElementById('f-year').value   = '';
    document.getElementById('plate-preview-text').textContent = '\u00a0\u00a0\u00a0\u00a0';
    document.getElementById('err-plate').textContent = '';
    document.getElementById('err-plate').classList.remove('show');
    document.getElementById('f-plate').classList.remove('error');
    document.getElementById('save-btn').disabled = false;
    document.getElementById('save-btn').innerHTML = '<i class="fa-solid fa-plus"></i> Add Vehicle';
    document.getElementById('add-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('f-plate').focus(), 300);
  }

  function closeAddModal() {
    document.getElementById('add-overlay').classList.remove('active');
    document.body.style.overflow = '';
  }

  function updatePlatePreview(val) {
    const cleaned = val.toUpperCase().trim() || '\u00a0\u00a0\u00a0\u00a0';
    document.getElementById('plate-preview-text').textContent = cleaned;
  }

  async function saveVehicle() {
    const plate = document.getElementById('f-plate').value.trim().toUpperCase();
    const make  = document.getElementById('f-make').value.trim();
    const model = document.getElementById('f-model').value.trim();
    const color = document.getElementById('f-color').value;
    const year  = document.getElementById('f-year').value;

    // Frontend validation
    const errEl = document.getElementById('err-plate');
    const inEl  = document.getElementById('f-plate');
    errEl.classList.remove('show');
    inEl.classList.remove('error');

    if (!plate) {
      errEl.textContent = 'Plate number is required.';
      errEl.classList.add('show');
      inEl.classList.add('error');
      inEl.focus();
      return;
    }
    if (plate.length < 3) {
      errEl.textContent = 'Plate number must be at least 3 characters.';
      errEl.classList.add('show');
      inEl.classList.add('error');
      return;
    }

    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…';

    try {
      const res  = await fetch('vehicles.php?action=add', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ plate_number: plate, make, model, color, year }),
      });
      const data = await res.json();

      if (data.success) {
        closeAddModal();
        showToast('success', data.message);
        setTimeout(() => location.reload(), 900);
      } else {
        errEl.textContent = data.message;
        errEl.classList.add('show');
        inEl.classList.add('error');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Vehicle';
      }
    } catch (e) {
      showToast('error', 'Network error. Please try again.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Vehicle';
    }
  }

  // ══════════════════════════════════════════════════════════
  //  SET DEFAULT
  // ══════════════════════════════════════════════════════════
  async function setDefault(vehicleId, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

    try {
      const res  = await fetch('vehicles.php?action=set_default', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ vehicle_id: vehicleId }),
      });
      const data = await res.json();
      if (data.success) {
        showToast('success', 'Default vehicle updated.');
        setTimeout(() => location.reload(), 800);
      } else {
        showToast('error', data.message || 'Failed to update default.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-regular fa-star"></i> Set Default';
      }
    } catch (e) {
      showToast('error', 'Network error.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-regular fa-star"></i> Set Default';
    }
  }

  // ══════════════════════════════════════════════════════════
  //  DELETE MODAL
  // ══════════════════════════════════════════════════════════
  function openDeleteModal(vehicleId, plate, isParked) {
    if (isParked) {
      showToast('error', `Cannot remove ${plate} — it is currently parked.`);
      return;
    }
    deleteTargetId    = vehicleId;
    deleteTargetPlate = plate;
    document.getElementById('del-msg').textContent = `Remove "${plate}" from your account? This action cannot be undone.`;
    document.getElementById('del-confirm-btn').disabled = false;
    document.getElementById('del-confirm-btn').innerHTML = '<i class="fa-solid fa-trash-can"></i> Remove';
    document.getElementById('del-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeDeleteModal() {
    document.getElementById('del-overlay').classList.remove('active');
    document.body.style.overflow = '';
    deleteTargetId    = null;
    deleteTargetPlate = null;
  }

  async function confirmDelete() {
    if (!deleteTargetId) return;
    const btn = document.getElementById('del-confirm-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Removing…';

    try {
      const res  = await fetch('vehicles.php?action=delete', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ vehicle_id: deleteTargetId }),
      });
      const data = await res.json();

      if (data.success) {
        closeDeleteModal();
        showToast('success', data.message);
        setTimeout(() => location.reload(), 900);
      } else {
        showToast('error', data.message || 'Delete failed.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Remove';
      }
    } catch (e) {
      showToast('error', 'Network error.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-trash-can"></i> Remove';
    }
  }

  // ── Logout ────────────────────────────────────────────────
  async function handleLogout() {
    await fetch('vehicles.php?action=logout');
    window.location.href = '../login/login.php';
  }

  // ── Toast ─────────────────────────────────────────────────
  function showToast(type, msg) {
    const t = document.getElementById('toast');
    document.getElementById('toast-msg').textContent = msg;
    t.className = '';
    void t.offsetWidth;
    t.classList.add('show', type);
    setTimeout(() => t.classList.remove('show'), 4000);
  }

  // ── Keyboard ──────────────────────────────────────────────
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeAddModal(); closeDeleteModal(); }
    if (e.key === 'Enter' && document.getElementById('add-overlay').classList.contains('active')) {
      saveVehicle();
    }
  });
</script>
</body>
</html>