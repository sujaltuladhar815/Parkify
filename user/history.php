<?php
// ============================================================
//  Parkify — Parking History (history.php)
//  Location: Parkify/user/history.php
// ============================================================

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Auth Guard ──────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    header('Location: ../login/login.php');
    exit;
}

// ── DB Connection ───────────────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'parkify_db');
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
    die('<p style="font-family:sans-serif;padding:40px;color:red">❌ Database connection failed: ' . $conn->connect_error . '</p>');
}

$userId   = (int) $_SESSION['user_id'];
$userName = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userInit = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1));

// ── Filters ─────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? 'all';
$filterMonth  = $_GET['month']  ?? '';
$search       = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 10;
$offset       = ($page - 1) * $perPage;

// ── Build WHERE clause ──────────────────────────────────────
$where   = ['ps.user_id = ?'];
$params  = [$userId];
$types   = 'i';

if ($filterStatus === 'active')    { $where[] = "ps.status = 'active'";    }
elseif ($filterStatus === 'completed') { $where[] = "ps.status = 'completed'"; }

if ($filterMonth !== '') {
    $where[]  = "DATE_FORMAT(ps.entry_time, '%Y-%m') = ?";
    $params[] = $filterMonth;
    $types   .= 's';
}

if ($search !== '') {
    $where[]  = "(ps.plate_number LIKE ? OR sl.slot_code LIKE ?)";
    $like     = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

// ── Count total rows ────────────────────────────────────────
$countSQL = "
    SELECT COUNT(*) AS total
    FROM   parking_sessions ps
    LEFT JOIN parking_slots sl ON sl.id = ps.slot_id
    $whereSQL
";
$cStmt = $conn->prepare($countSQL);
$cStmt->bind_param($types, ...$params);
$cStmt->execute();
$totalRows = (int) $cStmt->get_result()->fetch_assoc()['total'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);

// ── Fetch paginated history ─────────────────────────────────
$dataSQL = "
    SELECT
        ps.id            AS session_id,
        COALESCE(NULLIF(ps.plate_number,''), v.plate_number) AS plate_number,
        ps.entry_time,
        ps.exit_time,
        ps.duration_mins,
        ps.status        AS session_status,
        sl.slot_code,
        v.make, v.model, v.color,
        py.amount,
        py.rate_per_hour,
        py.method,
        py.status        AS payment_status,
        py.transaction_id,
        py.paid_at
    FROM   parking_sessions ps
    LEFT JOIN parking_slots sl ON sl.id  = ps.slot_id
    LEFT JOIN vehicles      v  ON v.id   = ps.vehicle_id
    LEFT JOIN payments      py ON py.session_id = ps.id
    $whereSQL
    ORDER  BY ps.entry_time DESC
    LIMIT  ? OFFSET ?
";
$params[] = $perPage;
$params[] = $offset;
$types   .= 'ii';

$dStmt = $conn->prepare($dataSQL);
$dStmt->bind_param($types, ...$params);
$dStmt->execute();
$rows = $dStmt->get_result()->fetch_all(MYSQLI_ASSOC);

$stats = $conn->query("
    SELECT
        COUNT(*)                             AS total_sessions,
        SUM(ps.status = 'completed')         AS completed,
        SUM(ps.status = 'active')            AS active,
        COALESCE(SUM(ps.duration_mins), 0)   AS total_mins,
        COALESCE(SUM(py.amount), 0)          AS total_spent
    FROM parking_sessions ps
    LEFT JOIN payments py ON py.session_id = ps.id
    WHERE ps.user_id = $userId
")->fetch_assoc();

$totalHours = floor((int)$stats['total_mins'] / 60);
$totalMins  = (int)$stats['total_mins'] % 60;

$monthsRes = $conn->query("
    SELECT DISTINCT DATE_FORMAT(entry_time, '%Y-%m') AS ym,
                    DATE_FORMAT(entry_time, '%b %Y')  AS label
    FROM parking_sessions
    WHERE user_id = $userId
    ORDER BY ym DESC
    LIMIT 24
");
$months = $monthsRes->fetch_all(MYSQLI_ASSOC);

$conn->close();

// ── Helpers ─────────────────────────────────────────────────
function fmtDur(int $mins): string {
    if ($mins <= 0) return '—';
    return sprintf('%dh %02dm', floor($mins / 60), $mins % 60);
}
function statusBadge(string $s): string {
    return match($s) {
        'active'    => '<span class="badge badge-active"><i class="fa-solid fa-circle fa-2xs"></i> Active</span>',
        'completed' => '<span class="badge badge-done"><i class="fa-solid fa-circle-check fa-2xs"></i> Completed</span>',
        default     => '<span class="badge badge-muted">' . htmlspecialchars($s) . '</span>',
    };
}
function payBadge(?string $s): string {
    if (!$s) return '<span class="badge badge-muted">—</span>';
    return match($s) {
        'paid'    => '<span class="badge badge-paid"><i class="fa-solid fa-check fa-2xs"></i> Paid</span>',
        'pending' => '<span class="badge badge-pending">Pending</span>',
        default   => '<span class="badge badge-muted">' . htmlspecialchars($s) . '</span>',
    };
}

// Build current query string (for pagination links)
function qstr(array $overrides = []): string {
    $base = ['status' => $_GET['status'] ?? 'all', 'month' => $_GET['month'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1];
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '' && $v !== 'all');
    return $merged ? '?' . http_build_query($merged) : '?';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Parkify — History</title>
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
    main { flex: 1; padding: 28px 32px; max-width: 1400px; margin: 0 auto; width: 100%; animation: fadeUp .5s ease both; }
    @keyframes fadeUp { from { opacity:0; transform:translateY(16px); } to { opacity:1; transform:translateY(0); } }

    /* ── PAGE HEADER ── */
    .page-header { display: flex; align-items: flex-end; justify-content: space-between; margin-bottom: 24px; }
    .page-header h1 { font-family: "Space Grotesk", sans-serif; font-size: 26px; font-weight: 700; }
    .page-header p  { color: var(--text-muted); font-size: 14px; margin-top: 3px; }
    .header-right   { display: flex; align-items: center; gap: 10px; }

    /* ── STAT CARDS ── */
    .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
    .stat-card {
      background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm);
      padding: 18px 20px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow);
      transition: transform .2s, box-shadow .2s;
    }
    .stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
    .stat-icon { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .icon-blue   { background: #eff6ff; } .icon-blue   i { color: var(--blue); }
    .icon-green  { background: var(--green-bg); } .icon-green  i { color: var(--green); }
    .icon-amber  { background: var(--amber-bg); } .icon-amber  i { color: var(--amber); }
    .icon-purple { background: #f3e8ff; } .icon-purple i { color: #a855f7; }
    .stat-label { font-size: 11px; font-weight: 500; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; }
    .stat-value { font-family: "Space Grotesk", sans-serif; font-size: 20px; font-weight: 700; margin-top: 2px; }

    /* ── FILTERS BAR ── */
    .filters-bar {
      background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm);
      padding: 14px 20px; display: flex; align-items: center; gap: 12px; margin-bottom: 20px;
      box-shadow: var(--shadow); flex-wrap: wrap;
    }
    .filter-label { font-size: 13px; font-weight: 600; color: var(--text-muted); white-space: nowrap; }
    .filter-group { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .filter-btn {
      padding: 6px 14px; border-radius: 8px; border: 1.5px solid var(--border); background: #fff;
      font-family: "DM Sans", sans-serif; font-size: 13px; font-weight: 500; color: var(--text-muted);
      cursor: pointer; text-decoration: none; transition: all .2s;
    }
    .filter-btn:hover { border-color: var(--blue); color: var(--blue); background: #f0f7ff; }
    .filter-btn.active { border-color: var(--blue); background: #eff6ff; color: var(--blue); font-weight: 600; }
    .filter-divider { width: 1px; height: 24px; background: var(--border); }
    .filter-select {
      padding: 6px 12px; border-radius: 8px; border: 1.5px solid var(--border); background: #fff;
      font-family: "DM Sans", sans-serif; font-size: 13px; color: var(--text);
      cursor: pointer; outline: none; transition: border-color .2s;
    }
    .filter-select:focus { border-color: var(--blue); }
    .search-wrap { margin-left: auto; position: relative; }
    .search-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 13px; pointer-events: none; }
    .search-input {
      padding: 7px 14px 7px 34px; border-radius: 8px; border: 1.5px solid var(--border); background: #fff;
      font-family: "DM Sans", sans-serif; font-size: 13px; outline: none; width: 220px; transition: border-color .2s;
    }
    .search-input:focus { border-color: var(--blue); }

    /* ── TABLE CARD ── */
    .table-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden; }
    .table-head-bar { padding: 18px 24px; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--border); }
    .table-head-bar h2 { font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600; }
    .result-count { font-size: 12px; color: var(--text-muted); }

    table { width: 100%; border-collapse: collapse; }
    thead tr { background: #f8fafc; }
    th {
      padding: 12px 16px; text-align: left; font-size: 11px; font-weight: 600;
      color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px;
      border-bottom: 1px solid var(--border); white-space: nowrap;
    }
    td { padding: 14px 16px; font-size: 13px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tbody tr { transition: background .15s; }
    tbody tr:hover { background: #f8fafc; }

    .plate-cell {
      display: inline-flex; align-items: center; gap: 8px;
      background: #fff; border: 1.5px solid #1e293b; border-radius: 6px;
      padding: 4px 12px; font-family: "Space Grotesk", sans-serif; font-size: 13px;
      font-weight: 700; letter-spacing: 1.5px;
    }
    .plate-np { background: var(--navy); color: #fff; font-size: 9px; font-weight: 700; padding: 2px 5px; border-radius: 3px; letter-spacing: 0; }
    .slot-pill {
      display: inline-flex; align-items: center; justify-content: center;
      background: #eff6ff; color: var(--blue); border: 1px solid #bfdbfe;
      border-radius: 6px; padding: 3px 10px; font-family: "Space Grotesk", sans-serif;
      font-size: 12px; font-weight: 700; letter-spacing: .5px;
    }
    .vehicle-cell { color: var(--text-muted); font-size: 12px; }
    .vehicle-cell strong { color: var(--text); font-size: 13px; display: block; }
    .amount-cell { font-family: "Space Grotesk", sans-serif; font-weight: 700; color: var(--navy); font-size: 14px; }
    .amount-cell.zero { color: var(--text-muted); font-weight: 500; }
    .tx-cell { font-size: 11px; color: var(--text-muted); font-family: monospace; }

    /* Badges */
    .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .badge-active  { background: #dcfce7; color: #16a34a; }
    .badge-done    { background: #eff6ff; color: var(--blue); }
    .badge-paid    { background: #dcfce7; color: #15803d; }
    .badge-pending { background: var(--amber-bg); color: #92400e; }
    .badge-muted   { background: #f1f5f9; color: var(--text-muted); }

    /* ── EMPTY STATE ── */
    .empty-state { padding: 64px 24px; text-align: center; }
    .empty-icon { width: 72px; height: 72px; background: #f1f5f9; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 28px; color: var(--text-muted); margin: 0 auto 18px; }
    .empty-state h3 { font-family: "Space Grotesk", sans-serif; font-size: 18px; font-weight: 600; margin-bottom: 6px; }
    .empty-state p  { color: var(--text-muted); font-size: 14px; }

    /* ── PAGINATION ── */
    .pagination-bar { display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-top: 1px solid var(--border); }
    .pagination-info { font-size: 13px; color: var(--text-muted); }
    .page-links { display: flex; align-items: center; gap: 4px; }
    .page-link {
      width: 34px; height: 34px; border-radius: 8px; border: 1.5px solid var(--border); background: #fff;
      display: flex; align-items: center; justify-content: center;
      font-size: 13px; font-weight: 500; color: var(--text); text-decoration: none; transition: all .2s;
    }
    .page-link:hover { border-color: var(--blue); color: var(--blue); background: #f0f7ff; }
    .page-link.active { border-color: var(--blue); background: var(--blue); color: #fff; font-weight: 700; }
    .page-link.disabled { opacity: .4; pointer-events: none; }

    /* ── METHOD ICON ── */
    .method-icon { display: inline-flex; align-items: center; gap: 5px; font-size: 12px; color: var(--text-muted); }
    .method-icon i { font-size: 13px; }
    .method-esewa  i { color: #16a34a; }
    .method-khalti i { color: #7c3aed; }
    .method-card   i { color: var(--blue); }
    .method-cash   i { color: var(--amber); }

    /* Footer */
    footer { background: #fff; border-top: 1px solid var(--border); padding: 12px 32px; display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted); }
    footer span { display: flex; align-items: center; gap: 6px; }
    footer i { color: var(--blue); }

    /* Toast */
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

    @media (max-width: 900px) {
      main { padding: 20px 16px; }
      .stats-row { grid-template-columns: 1fr 1fr; }
      nav { padding: 0 16px; }
      .nav-links { display: none; }
      table { display: block; overflow-x: auto; }
      .filters-bar { flex-wrap: wrap; }
      .search-wrap { margin-left: 0; width: 100%; }
      .search-input { width: 100%; }
    }
  </style>
</head>
<body>

<!-- ── NAV ─────────────────────────────────────────────────── -->
<?php include 'nav.php'; ?>

<!-- ── MAIN ─────────────────────────────────────────────────── -->
<main>

  <!-- Page header -->
  <div class="page-header">
    <div>
      <h1><i class="fa-solid fa-clock-rotate-left" style="color:var(--blue);margin-right:8px;font-size:22px;"></i>Parking History</h1>
      <p>All your past and current parking sessions in one place.</p>
    </div>
  </div>

  <!-- Stat cards -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-icon icon-blue"><i class="fa-solid fa-layer-group"></i></div>
      <div>
        <div class="stat-label">Total Sessions</div>
        <div class="stat-value"><?= number_format((int)$stats['total_sessions']) ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
      <div>
        <div class="stat-label">Completed</div>
        <div class="stat-value"><?= number_format((int)$stats['completed']) ?></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon icon-amber"><i class="fa-solid fa-clock"></i></div>
      <div>
        <div class="stat-label">Total Time Parked</div>
        <div class="stat-value"><?= $totalHours ?>h <?= $totalMins ?>m</div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-icon icon-purple"><i class="fa-solid fa-wallet"></i></div>
      <div>
        <div class="stat-label">Total Spent</div>
        <div class="stat-value">Rs. <?= number_format((float)$stats['total_spent'], 0) ?></div>
      </div>
    </div>
  </div>

  <!-- Filters bar -->
  <form method="GET" action="history.php" id="filter-form">
    <div class="filters-bar">
      <span class="filter-label">Status:</span>
      <div class="filter-group">
        <?php
        $statuses = ['all' => 'All', 'active' => 'Active', 'completed' => 'Completed'];
        foreach ($statuses as $val => $label):
            $q   = array_filter(['status' => $val === 'all' ? null : $val, 'month' => $filterMonth ?: null, 'q' => $search ?: null], fn($v) => $v !== null);
            $href = $q ? '?' . http_build_query($q) : '?';
        ?>
          <a href="<?= htmlspecialchars($href) ?>" class="filter-btn <?= $filterStatus === $val ? 'active' : '' ?>"><?= $label ?></a>
        <?php endforeach; ?>
      </div>

      <div class="filter-divider"></div>

      <span class="filter-label">Month:</span>
      <select name="month" class="filter-select" onchange="this.form.submit()">
        <option value="">All Time</option>
        <?php foreach ($months as $m): ?>
          <option value="<?= htmlspecialchars($m['ym']) ?>" <?= $filterMonth === $m['ym'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($m['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>" />

      <div class="search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input
          type="text"
          name="q"
          class="search-input"
          placeholder="Search plate or slot…"
          value="<?= htmlspecialchars($search) ?>"
          onchange="this.form.submit()"
        />
      </div>
    </div>
  </form>

  <!-- Table card -->
  <div class="table-card">
    <div class="table-head-bar">
      <h2><i class="fa-solid fa-table-list" style="color:var(--blue);margin-right:6px;"></i>Sessions</h2>
      <span class="result-count"><?= number_format($totalRows) ?> result<?= $totalRows !== 1 ? 's' : '' ?></span>
    </div>

    <?php if (empty($rows)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fa-solid fa-car-on"></i></div>
        <h3>No sessions found</h3>
        <p>Try adjusting your filters, or your parking history will appear here once you park.</p>
      </div>
    <?php else: ?>

    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Plate</th>
          <th>Slot</th>
          <th>Vehicle</th>
          <th>Entry</th>
          <th>Exit</th>
          <th>Duration</th>
          <th>Status</th>
          <th>Amount</th>
          <th>Payment</th>
          <th>Method</th>
          <th>Transaction ID</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i => $r):
          $entryFmt = $r['entry_time'] ? date('M d, Y · h:i A', strtotime($r['entry_time'])) : '—';
          $exitFmt  = $r['exit_time']  ? date('M d, Y · h:i A', strtotime($r['exit_time']))  : '—';
          $vehicle  = trim(($r['make'] ?? '') . ' ' . ($r['model'] ?? ''));
          $color    = $r['color'] ?? '';
          $rowNum   = $offset + $i + 1;

          $methodMap = [
            'esewa'  => ['fa-mobile-screen-button', 'method-esewa',  'eSewa'],
            'khalti' => ['fa-wallet',               'method-khalti', 'Khalti'],
            'card'   => ['fa-credit-card',           'method-card',   'Card'],
            'cash'   => ['fa-money-bill-wave',        'method-cash',   'Cash'],
          ];
          $mkey = strtolower($r['method'] ?? '');
          [$mIcon, $mClass, $mLabel] = $methodMap[$mkey] ?? ['fa-circle-question', 'method-cash', ucfirst($mkey ?: '—')];
        ?>
        <tr>
          <td style="color:var(--text-muted);font-size:12px;"><?= $rowNum ?></td>

          <td>
            <div class="plate-cell">
              <span class="plate-np">NP</span>
              <?= htmlspecialchars($r['plate_number'] ?? '—') ?>
            </div>
          </td>

          <td>
            <?php if ($r['slot_code']): ?>
              <span class="slot-pill"><?= htmlspecialchars($r['slot_code']) ?></span>
            <?php else: echo '—'; endif; ?>
          </td>

          <td>
            <div class="vehicle-cell">
              <strong><?= $vehicle ? htmlspecialchars($vehicle) : '—' ?></strong>
              <?= $color ? htmlspecialchars($color) : '' ?>
            </div>
          </td>

          <td style="white-space:nowrap;"><?= $entryFmt ?></td>

          <td style="white-space:nowrap;color:var(--text-muted);">
            <?php if ($r['session_status'] === 'active'): ?>
              <span style="color:var(--green);font-weight:600;font-size:12px;"><i class="fa-solid fa-circle fa-2xs"></i> In progress</span>
            <?php else: ?>
              <?= $exitFmt ?>
            <?php endif; ?>
          </td>

          <td style="white-space:nowrap;">
            <?php if ($r['session_status'] === 'active'):
              $liveMins = max(0, (int)((time() - strtotime($r['entry_time'])) / 60));
              echo '<span style="color:var(--amber);font-weight:600;">' . fmtDur($liveMins) . ' ⏱</span>';
            else:
              echo fmtDur((int)$r['duration_mins']);
            endif; ?>
          </td>

          <td><?= statusBadge($r['session_status']) ?></td>

          <td>
            <?php if ($r['amount']): ?>
              <span class="amount-cell">Rs. <?= number_format((float)$r['amount'], 0) ?></span>
            <?php else: ?>
              <span class="amount-cell zero">—</span>
            <?php endif; ?>
          </td>

          <td><?= payBadge($r['payment_status']) ?></td>

          <td>
            <?php if ($r['method']): ?>
              <span class="method-icon <?= $mClass ?>">
                <i class="fa-solid <?= $mIcon ?>"></i> <?= $mLabel ?>
              </span>
            <?php else: echo '<span class="badge badge-muted">—</span>'; endif; ?>
          </td>

          <td>
            <span class="tx-cell"><?= $r['transaction_id'] ? htmlspecialchars($r['transaction_id']) : '—' ?></span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination-bar">
      <span class="pagination-info">
        Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?> of <?= number_format($totalRows) ?>
      </span>
      <div class="page-links">
        <a href="<?= qstr(['page' => $page - 1]) ?>" class="page-link <?= $page <= 1 ? 'disabled' : '' ?>">
          <i class="fa-solid fa-chevron-left fa-xs"></i>
        </a>
        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        if ($start > 1): ?><a href="<?= qstr(['page' => 1]) ?>" class="page-link">1</a><?php if ($start > 2) echo '<span style="padding:0 4px;color:var(--text-muted);">…</span>'; endif;
        for ($p = $start; $p <= $end; $p++): ?>
          <a href="<?= qstr(['page' => $p]) ?>" class="page-link <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
        <?php endfor;
        if ($end < $totalPages): if ($end < $totalPages - 1) echo '<span style="padding:0 4px;color:var(--text-muted);">…</span>'; ?><a href="<?= qstr(['page' => $totalPages]) ?>" class="page-link"><?= $totalPages ?></a><?php endif; ?>
        <a href="<?= qstr(['page' => $page + 1]) ?>" class="page-link <?= $page >= $totalPages ? 'disabled' : '' ?>">
          <i class="fa-solid fa-chevron-right fa-xs"></i>
        </a>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>

</main>

<!-- FOOTER -->
<footer>
  <span><i class="fa-solid fa-lock"></i> Your parking data is secure and updated in real-time.</span>
  <span><i class="fa-solid fa-copyright"></i> <?= date('Y') ?> Parkify System. All rights reserved.</span>
</footer>

<!-- TOAST -->
<div id="toast"><span id="toast-msg"></span></div>

<script>
  async function handleLogout() {
    localStorage.removeItem('parkify_user');
    localStorage.removeItem('parkify_token');
    await fetch('home.php?action=logout');
    window.location.href = '../login/login.php';
  }
  function showToast(type, msg) {
    const t = document.getElementById('toast');
    document.getElementById('toast-msg').textContent = msg;
    t.className = '';
    void t.offsetWidth;
    t.classList.add('show', type);
    setTimeout(() => t.classList.remove('show'), 4000);
  }
</script>
</body>
</html>