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
$total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) AS r FROM payments WHERE status IN ('success','paid')")->fetch_assoc()['r'];

$pending_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) AS r FROM payments WHERE status='pending'")->fetch_assoc()['r'];

$success_count = $conn->query("SELECT COUNT(*) AS c FROM payments WHERE status IN ('success','paid')")->fetch_assoc()['c'];
$cur_month_rev = $conn->query("SELECT COALESCE(SUM(amount), 0) AS r FROM payments WHERE status IN ('success','paid') AND MONTH(paid_at) = MONTH(CURRENT_DATE()) AND YEAR(paid_at) = YEAR(CURRENT_DATE())")->fetch_assoc()['r'];
$last_month_rev = $conn->query("SELECT COALESCE(SUM(amount), 0) AS r FROM payments WHERE status IN ('success','paid') AND MONTH(paid_at) = MONTH(CURRENT_DATE() - INTERVAL 1 MONTH) AND YEAR(paid_at) = YEAR(CURRENT_DATE() - INTERVAL 1 MONTH)")->fetch_assoc()['r'];
$rev_growth = $last_month_rev > 0 ? round((($cur_month_rev - $last_month_rev) / $last_month_rev) * 100, 1) : 0;

$monthly_revenue = array_fill(1, 12, 0);
$chart_q = $conn->query("SELECT MONTH(paid_at) AS m, SUM(amount) AS total FROM payments WHERE status IN ('success','paid') AND YEAR(paid_at) = YEAR(CURRENT_DATE()) GROUP BY MONTH(paid_at)");
while ($row = $chart_q->fetch_assoc()) {
    $monthly_revenue[(int)$row['m']] = (float)$row['total'];
}
$chart_values = array_values($monthly_revenue);
$chart_labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

$methods_data = [];
$methods_labels = [];
$methods_q = $conn->query("SELECT method, COUNT(*) as cnt, SUM(amount) as total FROM payments WHERE status IN ('success','paid') GROUP BY method");

while ($row = $methods_q->fetch_assoc()) {
    $methods_labels[] = ucfirst($row['method']);
    $methods_data[] = (float)$row['total'];
}
if (empty($methods_data)) {
    $methods_labels = ['Cash', 'Credit Card', 'Online'];
    $methods_data = [0, 0, 0];
}

// ── Table Filtering & Search ──────────────────────────────────
$search  = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
$filter  = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : 'all';

$where_clauses = [];
if ($filter === 'success')  $where_clauses[] = "p.status IN ('success','paid')";
if ($filter === 'pending')  $where_clauses[] = "p.status = 'pending'";

if (!empty($search)) {
    $where_clauses[] = "(p.transaction_id LIKE '%$search%' OR u.full_name LIKE '%$search%' OR ps.plate_number LIKE '%$search%')";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// Fetch Transactions
$transactions = $conn->query("
    SELECT 
        p.*, 
        u.full_name, 
        u.avatar_url,
        ps.id AS booking_ref,
        ps.plate_number
    FROM payments p
    LEFT JOIN users u ON p.user_id = u.id
    LEFT JOIN parking_sessions ps ON p.session_id = ps.id
    $where_sql
    ORDER BY p.created_at DESC
");

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Payments</title>
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
  --font:       'Inter', sans-serif;
}

body {
  font-family: var(--font);
  background: var(--bg);
  color: var(--text);
  display: flex;
  min-height: 100vh;
}

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

.admin-pill { display: flex; align-items: center; gap: 10px; }
.admin-avatar {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #7c3aed);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: #fff;
}
.admin-info .name { font-size: 13px; font-weight: 600; }
.admin-info .role { font-size: 11px; color: var(--muted); }

/* ── Content ─────────────────────────────────────────── */
.content { padding: 24px 28px; display: flex; flex-direction: column; gap: 20px; }

/* ── KPI Grid ─────────────────────────────────────────── */
.stats-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
}
.stat-card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 24px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.stat-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  color: var(--muted);
  font-size: 13px;
  font-weight: 500;
}
.stat-icon {
  width: 32px; height: 32px;
  border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
}
.stat-icon.blue  { background: var(--blue-light); color: var(--blue); }
.stat-icon.orange   { background: var(--orange-bg); color: var(--orange); }
.stat-icon.green { background: var(--green-bg); color: var(--green); }

.stat-value { font-size: 28px; font-weight: 700; color: var(--text); }
.stat-trend { font-size: 12px; display: flex; align-items: center; gap: 4px; font-weight: 500; }
.stat-trend.up { color: var(--green); }
.stat-trend.down { color: var(--red); }

/* ── Charts Row ───────────────────────────────────────── */
.charts-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 20px;
}
.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  padding: 24px;
}
.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}
.card-title { font-size: 15px; font-weight: 600; }
.card-select {
  padding: 6px 12px;
  border: 1px solid var(--border);
  border-radius: 6px;
  font-size: 12px;
  background: var(--surface);
  color: var(--muted);
  outline: none;
}
.chart-wrap { height: 260px; position: relative; }

/* Donut Layout */
.donut-layout { display: flex; flex-direction: column; align-items: center; gap: 20px; }
.donut-container { width: 150px; height: 150px; position: relative; }
.legend-list { width: 100%; display: flex; flex-direction: column; gap: 8px; }
.legend-item {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: var(--muted);
}
.legend-label-group { display: flex; align-items: center; gap: 8px; }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; }
.legend-val { font-weight: 600; color: var(--text); }

/* ── Transactions Controls ────────────────────────────── */
.table-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.table-header-row {
  padding: 20px 24px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid var(--border);
}
.search-form { display: flex; gap: 12px; align-items: center; }
.search-box {
  display: flex; align-items: center; gap: 8px;
  background: var(--bg); border: 1px solid var(--border);
  border-radius: 8px; padding: 8px 14px; width: 260px;
}
.search-box input { border: none; background: transparent; font-size: 13px; outline: none; width: 100%; }
.search-box i { color: var(--muted); }

.filter-group { display: flex; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; padding: 2px; }
.filter-btn {
  padding: 6px 16px; font-size: 12px; font-weight: 500;
  color: var(--muted); border: none; background: transparent;
  cursor: pointer; border-radius: 6px; text-decoration: none;
}
.filter-btn.active { background: #fff; color: var(--text); box-shadow: 0 1px 3px rgba(0,0,0,0.1); }

/* ── Data Table ───────────────────────────────────────── */
table { width: 100%; border-collapse: collapse; text-align: left; }
thead th {
  background: #fafafa; padding: 12px 24px;
  font-size: 11px; font-weight: 600; color: var(--muted);
  text-transform: uppercase; letter-spacing: 0.5px;
  border-bottom: 1px solid var(--border);
}
tbody td { padding: 16px 24px; font-size: 13px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: #fafafa; }

.tx-id { font-weight: 600; color: var(--text); }
.tx-date { font-size: 11px; color: var(--muted); margin-top: 2px; }
.user-cell { display: flex; align-items: center; gap: 10px; }
.user-avatar-circle {
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--blue-mid); color: var(--blue);
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 600;
}
.user-plate { font-size: 11px; color: var(--muted); margin-top: 1px; }
.booking-ref-link { color: var(--blue); font-weight: 500; text-decoration: none; }
.booking-ref-link:hover { text-decoration: underline; }

.badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: capitalize; }
.badge-success { background: var(--green-bg); color: var(--green); }
.badge-paid    { background: var(--green-bg); color: var(--green); }
.badge-pending { background: var(--orange-bg); color: var(--orange); }

.action-btn { color: var(--muted); cursor: pointer; background: none; border: none; font-size: 14px; }
.action-btn:hover { color: var(--text); }
</style>
</head>
<body>

<?php
$current_page = 'payments';
if (file_exists('sidebar.php')) {
    include 'sidebar.php';
} else {
    echo '<div style="width:220px; background:#1e3a8a; position:fixed; top:0; bottom:0;"></div>';
}
?>

<div class="main">
  <header class="topbar">
    <div class="topbar-title">
      <h1>Payments &amp; Revenue</h1>
      <p>Monitor transactions and financial performance</p>
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
  </header>

  <div class="content">
    
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-header">
          <span>Total Revenue</span>
          <div class="stat-icon green"><i class="fa-solid fa-arrow-trend-up"></i></div>
        </div>
        <div class="stat-value">Rs. <?= number_format($total_revenue, 2) ?></div>
        <div class="stat-trend <?= $rev_growth >= 0 ? 'up' : 'down' ?>">
          <i class="fa-solid <?= $rev_growth >= 0 ? 'fa-caret-up' : 'fa-caret-down' ?>"></i>
          <?= abs($rev_growth) ?>% <span style="color:var(--muted); font-weight:400;">from last month</span>
        </div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <span>Pending Payments</span>
          <div class="stat-icon orange"><i class="fa-solid fa-clock-solid fa-clock"></i></div>
        </div>
        <div class="stat-value">Rs. <?= number_format($pending_revenue, 2) ?></div>
        <div style="font-size: 12px; color: var(--muted);">Awaiting manual/digital checkout</div>
      </div>

      <div class="stat-card">
        <div class="stat-header">
          <span>Successful Transactions</span>
          <div class="stat-icon blue"><i class="fa-solid fa-receipt"></i></div>
        </div>
        <div class="stat-value"><?= number_format($success_count) ?></div>
        <div style="font-size: 12px; color: var(--muted);">Total paid sessions processed</div>
      </div>
    </div>

    <div class="charts-grid">
      <div class="card">
        <div class="card-head">
          <div class="card-title">Monthly Revenue Trends</div>
          <select class="card-select">
            <option>This Year</option>
          </select>
        </div>
        <div class="chart-wrap">
          <canvas id="monthlyTrendChart"></canvas>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <div class="card-title">Payment Methods</div>
        </div>
        <div class="donut-layout">
          <div class="donut-container">
            <canvas id="paymentMethodsChart"></canvas>
          </div>
          <div class="legend-list">
            <?php 
            $colors = ['#2563eb', '#475569', '#cbd5e1'];
            foreach($methods_labels as $index => $label): 
              $amt = $methods_data[$index];
              $pct = $total_revenue > 0 ? round(($amt / $total_revenue) * 100) : 0;
            ?>
            <div class="legend-item">
              <div class="legend-label-group">
                <div class="legend-dot" style="background: <?= $colors[$index % count($colors)] ?>;"></div>
                <span><?= htmlspecialchars($label) ?></span>
              </div>
              <span class="legend-val"><?= $pct ?>%</span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>

    <div class="table-card">
      <div class="table-header-row">
        <div class="card-title">Recent Transactions</div>
        
        <form method="GET" action="payments.php" class="search-form">
          <div class="filter-group">
            <a href="payments.php?status=all&search=<?= urlencode($search) ?>" class="filter-btn <?= $filter === 'all' ? 'active' : '' ?>">All</a>
            <a href="payments.php?status=success&search=<?= urlencode($search) ?>" class="filter-btn <?= $filter === 'success' ? 'active' : '' ?>">Success</a>
            <a href="payments.php?status=pending&search=<?= urlencode($search) ?>" class="filter-btn <?= $filter === 'pending' ? 'active' : '' ?>">Pending</a>
          </div>
          <div class="search-box">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="search" placeholder="Search ID, name, plate..." value="<?= htmlspecialchars($search) ?>"/>
            <input type="hidden" name="status" value="<?= htmlspecialchars($filter) ?>"/>
          </div>
        </form>
      </div>

      <div style="overflow-x: auto;">
        <table>
          <thead>
            <tr>
              <th>Transaction ID / Date</th>
              <th>User / Vehicle</th>
              <th>Booking Ref</th>
              <th>Method</th>
              <th>Amount</th>
              <th>Status</th>
              <th style="text-align:right;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($row = $transactions->fetch_assoc()): ?>
            <?php
              $initials = '';
              foreach (explode(' ', trim($row['full_name'] ?? 'Guest')) as $w) {
                  $initials .= strtoupper($w[0] ?? '');
              }
              $initials = substr($initials, 0, 2);
              $tx_date = $row['paid_at'] ? date('M d, Y • H:i', strtotime($row['paid_at'])) : date('M d, Y • H:i', strtotime($row['created_at']));
            ?>
            <tr>
              <td>
                <div class="tx-id">#<?= htmlspecialchars($row['transaction_id'] ?? 'TRX-' . $row['id']) ?></div>
                <div class="tx-date"><?= $tx_date ?></div>
              </td>
              <td>
                <div class="user-cell">
                  <div class="user-avatar-circle"><?= $initials ?: 'G' ?></div>
                  <div>
                    <div style="font-weight: 500;"><?= htmlspecialchars($row['full_name'] ?? 'Guest User') ?></div>
                    <div class="user-plate"><?= htmlspecialchars($row['plate_number'] ?? '—') ?></div>
                  </div>
                </div>
              </td>
              <td>
                <a href="bookings.php?id=<?= $row['session_id'] ?>" class="booking-ref-link">
                  BK-<?= str_pad($row['booking_ref'] ?? $row['session_id'], 4, '0', STR_PAD_LEFT) ?>
                </a>
              </td>
              <td style="text-transform: capitalize; color: var(--muted);">
                <?= htmlspecialchars($row['method']) ?>
              </td>
              <td style="font-weight: 600;">
                Rs. <?= number_format($row['amount'], 2) ?>
              </td>
              <td>
                <span class="badge badge-<?= $row['status'] ?>">
                  <?= htmlspecialchars($row['status']) ?>
                </span>
              </td>
              <td style="text-align:right;">
                <button class="action-btn" title="View Details"> <a href="payment_view.php?id=<?= $row['transaction_id'] ?>"><i class="fa-regular fa-file-lines"></i></a></button>
              </td>
            </tr>
            <?php endwhile; ?>
            <?php if ($transactions->num_rows === 0): ?>
            <tr>
              <td colspan="7" style="text-align:center; color:var(--muted); padding: 40px;">No transaction history match found.</td>
            </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</div>

<script>
// ── Line Chart Definition (Monthly Trends) ───────────────────
const trendCtx = document.getElementById('monthlyTrendChart').getContext('2d');
const lineGradient = trendCtx.createLinearGradient(0, 0, 0, 240);
lineGradient.addColorStop(0, 'rgba(37, 99, 235, 0.2)');
lineGradient.addColorStop(1, 'rgba(37, 99, 235, 0)');

new Chart(trendCtx, {
  type: 'line',
  data: {
    labels: <?= json_encode($chart_labels) ?>,
    datasets: [{
      data: <?= json_encode($chart_values) ?>,
      borderColor: '#2563eb',
      backgroundColor: lineGradient,
      borderWidth: 3,
      fill: true,
      tension: 0.38,
      pointRadius: 0,
      pointHoverRadius: 5
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: false } },
    scales: {
      x: { grid: { display: false }, ticks: { font: { size: 11 }, color: '#9ca3af' } },
      y: { 
        grid: { color: '#f3f4f6', drawBorder: false }, 
        ticks: { font: { size: 11 }, color: '#9ca3af', callback: v => 'Rs.' + v.toLocaleString() } 
      }
    }
  }
});

// ── Donut Chart Definition (Payment Channels) ───────────────
const donutCtx = document.getElementById('paymentMethodsChart').getContext('2d');
new Chart(donutCtx, {
  type: 'doughnut',
  data: {
    labels: <?= json_encode($methods_labels) ?>,
    datasets: [{
      data: <?= json_encode($methods_data) ?>,
      backgroundColor: ['#2563eb', '#475569', '#cbd5e1'],
      borderWidth: 0
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '75%',
    plugins: { legend: { display: false } }
  }
});
</script>
</body>
</html>