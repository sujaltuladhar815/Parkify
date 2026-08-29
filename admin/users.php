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
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name      = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
        $email     = $conn->real_escape_string(trim($_POST['email']     ?? ''));
        $role      = $conn->real_escape_string($_POST['role']           ?? 'user');
        $pass_hash = password_hash($_POST['password'] ?? 'Parkify@123', PASSWORD_DEFAULT);
        if ($name && $email)
            $conn->query("INSERT IGNORE INTO users (full_name, email, password_hash, role)
                          VALUES ('$name','$email','$pass_hash','$role')");

    } elseif ($action === 'edit_user') {
        $id   = (int)$_POST['user_id'];
        $name = $conn->real_escape_string(trim($_POST['full_name'] ?? ''));
        $role = $conn->real_escape_string($_POST['role'] ?? 'user');
        if ($id && $name)
            $conn->query("UPDATE users SET full_name='$name', role='$role' WHERE id=$id");

    } elseif ($action === 'delete_user') {
        $id = (int)$_POST['user_id'];
        if ($id) $conn->query("DELETE FROM users WHERE id=$id");
    }

    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ── Export CSV ────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $exp = $conn->query("SELECT full_name, email, role, created_at FROM users ORDER BY created_at DESC");
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="users_' . date('Y-m-d') . '.csv"');
    echo "Name,Email,Role,Joined\n";
    while ($r = $exp->fetch_assoc())
        echo '"' . addslashes($r['full_name']) . '","' . $r['email'] . '","' . $r['role'] . '","' . $r['created_at'] . '"' . "\n";
    $conn->close(); exit;
}

// ── Filters & Pagination ──────────────────────────────────────
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? 'all';
$role_filter   = $_GET['role']   ?? 'all';
$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = 8;
$offset        = ($page - 1) * $per_page;

// ── WHERE clause ──────────────────────────────────────────────
$wheres = [];
if ($search !== '')
    $wheres[] = "(full_name LIKE '%" . $conn->real_escape_string($search) . "%'
                  OR email  LIKE '%" . $conn->real_escape_string($search) . "%')";
if ($status_filter === 'active')
    $wheres[] = "created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
elseif ($status_filter === 'deactive')
    $wheres[] = "created_at < DATE_SUB(NOW(), INTERVAL 6 MONTH)";
if ($role_filter !== 'all')
    $wheres[] = "role = '" . $conn->real_escape_string($role_filter) . "'";
$where_sql = $wheres ? 'WHERE ' . implode(' AND ', $wheres) : '';

// ── Stats ─────────────────────────────────────────────────────
$total_users   = (int)$conn->query("SELECT COUNT(*) c FROM users")->fetch_assoc()['c'];
$active_users  = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)")->fetch_assoc()['c'];
$new_this_week = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['c'];
$blocked       = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='blocked'")->fetch_assoc()['c'];

// ── Distinct roles for dropdown ───────────────────────────────
$roles_q = $conn->query("SELECT DISTINCT role FROM users ORDER BY role");
$roles = [];
while ($r = $roles_q->fetch_assoc()) $roles[] = $r['role'];

// ── Paginated rows ────────────────────────────────────────────
$total_filtered = (int)$conn->query("SELECT COUNT(*) c FROM users $where_sql")->fetch_assoc()['c'];
$total_pages    = max(1, (int)ceil($total_filtered / $per_page));
$users_res      = $conn->query("SELECT * FROM users $where_sql ORDER BY created_at DESC LIMIT $per_page OFFSET $offset");

// ── Edit modal pre-fill ───────────────────────────────────────
$edit_user = null;
if (!empty($_GET['edit'])) {
    $eid = (int)$_GET['edit'];
    $edit_user = $conn->query("SELECT * FROM users WHERE id=$eid")->fetch_assoc();
}

$conn->close();

// ── Helpers ───────────────────────────────────────────────────
function initials(string $name): string {
    $parts = array_filter(explode(' ', trim($name)));
    $s = '';
    foreach ($parts as $p) $s .= strtoupper($p[0] ?? '');
    return substr($s, 0, 2) ?: 'U';
}
function avatarBg(int $id): string {
    $palette = ['#4f46e5','#0891b2','#059669','#d97706','#dc2626','#7c3aed','#db2777'];
    return $palette[$id % count($palette)];
}
function isActive(string $created): bool {
    return strtotime($created) >= strtotime('-6 months');
}
// Build URL preserving current filters
function url(array $override = []): string {
    $params = array_merge([
        'search' => $_GET['search'] ?? '',
        'status' => $_GET['status'] ?? 'all',
        'role'   => $_GET['role']   ?? 'all',
        'page'   => $_GET['page']   ?? 1,
    ], $override);
    return '?' . http_build_query(array_filter($params, fn($v) => $v !== '' && $v !== 'all' && $v !== 1));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Parkify Admin — Users</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet"/>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet"/>
    <link rel="icon" href="../fabiconlogo.png" type="image/png" />

<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --blue:     #2563eb;
  --blue-d:   #1d4ed8;
  --blue-lt:  #eff6ff;
  --blue-mid: #dbeafe;
  --sidebar:  #ffffff;
  --text:     #111827;
  --muted:    #6b7280;
  --border:   #e5e7eb;
  --bg:       #f9fafb;
  --surface:  #ffffff;
  --green:    #10b981; --green-bg: #d1fae5;
  --red:      #ef4444; --red-bg:   #fee2e2;
  --orange:   #f59e0b; --orange-bg:#fef3c7;
  --purple:   #7c3aed; --purple-bg:#ede9fe;
  --cyan:     #0891b2; --cyan-bg:  #cffafe;
  --font: 'Inter', sans-serif;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: var(--font); background: var(--bg); color: var(--text); display: flex; min-height: 100vh; font-size: 14px; }

/* ─── Main ───────────────────────────────────── */
.main { margin-left: 200px; flex: 1; display: flex; flex-direction: column; }

/* ─── Topbar ─────────────────────────────────── */
.topbar {
  background: var(--surface); border-bottom: 1px solid var(--border);
  padding: 0 28px; height: 60px;
  display: flex; align-items: center; justify-content: space-between;
  position: sticky; top: 0; z-index: 50;
}
.topbar-title h1 { font-size: 18px; font-weight: 700; }
.topbar-title p  { font-size: 12px; color: var(--muted); margin-top: 1px; }
.topbar-right { display: flex; align-items: center; gap: 16px; }
.admin-pill { display: flex; align-items: center; gap: 10px; }
.admin-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: linear-gradient(135deg, #2563eb, #7c3aed);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.admin-info .name { font-size: 13px; font-weight: 600; text-align: right; }
.admin-info .role { font-size: 11px; color: var(--muted); text-align: left; }

/* ─── Content ────────────────────────────────── */
.content { padding: 24px 28px; display: flex; flex-direction: column; gap: 18px; }

/* ─── Stats strip ────────────────────────────── */
.stats-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; }
.stat-card {
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px 18px;
  display: flex; align-items: center; justify-content: space-between;
}
.stat-val { font-size: 26px; font-weight: 700; line-height: 1; color: var(--text); }
.stat-lbl { font-size: 11.5px; color: var(--muted); margin-top: 3px; }
.stat-ic {
  width: 40px; height: 40px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center; font-size: 17px;
}
.ic-blue   { background: var(--blue-mid); color: var(--blue); }
.ic-green  { background: var(--green-bg); color: var(--green); }
.ic-purple { background: var(--purple-bg);color: var(--purple);}
.ic-red    { background: var(--red-bg);   color: var(--red); }

/* ─── Main card ──────────────────────────────── */
.card { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }

/* ─── Filter bar ─────────────────────────────── */
.filter-bar {
  padding: 14px 18px; display: flex; align-items: center;
  gap: 12px; flex-wrap: wrap; border-bottom: 1px solid var(--border);
}
.filter-group { display: flex; flex-direction: column; gap: 4px; }
.filter-label { font-size: 11px; color: var(--muted); font-weight: 500; }

.search-wrap {
  display: flex; align-items: center; gap: 7px;
  border: 1px solid var(--border); border-radius: 8px;
  padding: 7px 11px; background: var(--bg); min-width: 200px;
}
.search-wrap input { border: none; background: transparent; font-size: 13px; color: var(--text); outline: none; width: 100%; font-family: var(--font); }
.search-wrap i { color: var(--muted); font-size: 12px; }

.tab-group {
  display: flex; background: var(--bg);
  border: 1px solid var(--border); border-radius: 8px; overflow: hidden;
}
.tab {
  padding: 7px 14px; font-size: 12.5px; font-weight: 500;
  color: var(--muted); cursor: pointer; border: none;
  background: transparent; transition: all 0.15s;
  text-decoration: none; display: inline-block;
}
.tab.active { background: var(--blue); color: #fff; }
.tab:hover:not(.active) { color: var(--text); }

.role-select {
  padding: 7px 11px; border: 1px solid var(--border); border-radius: 8px;
  font-size: 13px; color: var(--text); background: var(--bg);
  outline: none; cursor: pointer; font-family: var(--font);
}

.spacer { flex: 1; }
.btn {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 8px 14px; border-radius: 8px; font-size: 13px;
  font-weight: 500; cursor: pointer; border: none;
  text-decoration: none; transition: all 0.15s; white-space: nowrap;
  font-family: var(--font);
}
.btn-outline { background: var(--surface); color: var(--text); border: 1px solid var(--border); }
.btn-outline:hover { background: var(--bg); }
.btn-primary { background: var(--blue); color: #fff; }
.btn-primary:hover { background: var(--blue-d); }

/* ─── Table ──────────────────────────────────── */
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th {
  padding: 10px 16px; font-size: 11px; font-weight: 600;
  color: var(--muted); text-align: left; text-transform: uppercase;
  letter-spacing: 0.6px; border-bottom: 1px solid var(--border);
  background: var(--bg);
}
tbody td {
  padding: 13px 16px; font-size: 13.5px;
  border-bottom: 1px solid var(--border); vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: #fafbff; }

.user-cell { display: flex; align-items: center; gap: 10px; }
.user-av {
  width: 32px; height: 32px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 11px; font-weight: 700; color: #fff; flex-shrink: 0;
  background-size: cover; background-position: center;
}
.user-name  { font-size: 13.5px; font-weight: 600; color: var(--text); }

.badge {
  display: inline-block; padding: 3px 11px;
  border-radius: 20px; font-size: 12px; font-weight: 600;
}
.badge-active   { background: var(--green-bg); color: #065f46; }
.badge-deactive { background: var(--red-bg);   color: #991b1b; }

.role-text { font-size: 13.5px; color: var(--text); }
.email-text { font-size: 13px; color: var(--muted); }

.act-btns { display: flex; align-items: center; gap: 6px; }
.act-btn {
  width: 28px; height: 28px; border-radius: 6px;
  display: flex; align-items: center; justify-content: center;
  border: 1px solid var(--border); background: var(--surface);
  color: var(--muted); cursor: pointer; font-size: 12px;
  text-decoration: none; transition: all 0.15s;
}
.act-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-lt); }
.act-btn.del:hover { border-color: var(--red); color: var(--red); background: var(--red-bg); }

/* ─── Pagination ─────────────────────────────── */
.pagination {
  padding: 12px 18px; display: flex; align-items: center;
  justify-content: flex-end; gap: 5px; border-top: 1px solid var(--border);
}
.pg {
  min-width: 28px; height: 28px; border-radius: 6px;
  border: 1px solid var(--border); background: var(--surface);
  color: var(--muted); font-size: 12px; font-weight: 500;
  display: inline-flex; align-items: center; justify-content: center;
  cursor: pointer; text-decoration: none; transition: all 0.15s; padding: 0 6px;
}
.pg:hover  { border-color: var(--blue); color: var(--blue); }
.pg.active { background: var(--blue); color: #fff; border-color: var(--blue); }
.pg.disabled { opacity: 0.35; pointer-events: none; }

/* ─── Empty state ────────────────────────────── */
.empty td { text-align: center; padding: 52px; color: var(--muted); }

/* ─── Modal ──────────────────────────────────── */
.overlay {
  display: none; position: fixed; inset: 0;
  background: rgba(17,24,39,0.35); z-index: 300;
  align-items: center; justify-content: center;
}
.overlay.open { display: flex; }
.modal {
  background: var(--surface); border-radius: 12px;
  padding: 26px 28px; width: 420px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.15);
}
.modal-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.modal-head h3 { font-size: 16px; font-weight: 700; }
.modal-close {
  width: 28px; height: 28px; border-radius: 6px;
  border: 1px solid var(--border); background: var(--bg);
  display: flex; align-items: center; justify-content: center;
  cursor: pointer; font-size: 13px; color: var(--muted);
}
.modal-close:hover { color: var(--text); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.fg { margin-bottom: 14px; }
.fg label { display: block; font-size: 12px; font-weight: 500; color: var(--muted); margin-bottom: 5px; }
.fg input, .fg select {
  width: 100%; padding: 9px 12px; border: 1px solid var(--border);
  border-radius: 8px; font-size: 13px; color: var(--text);
  outline: none; transition: border 0.15s; font-family: var(--font);
  background: var(--surface);
}
.fg input:focus, .fg select:focus { border-color: var(--blue); }
.fg input:disabled { opacity: 0.55; cursor: not-allowed; background: var(--bg); }
.modal-footer { display: flex; gap: 10px; margin-top: 6px; }
.modal-footer .btn { flex: 1; justify-content: center; }
</style>
</head>
<body>

<!-- ── Sidebar ─────────────────────────────────────────────── -->
<?php
$current_page = 'users';
include 'sidebar.php';
?>

<!-- ── Main ───────────────────────────────────────────────── -->
<div class="main">

  <!-- Topbar -->
  <header class="topbar">
    <div class="topbar-title">
      <h1>Users List</h1>
      <p>Manage platform users and access controls</p>
    </div>
    <div class="topbar-right">
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

  <div class="content">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div>
          <div class="stat-val"><?= number_format($total_users) ?></div>
          <div class="stat-lbl">Total Users</div>
        </div>
        <div class="stat-ic ic-blue"><i class="fa-solid fa-users"></i></div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-val"><?= number_format($active_users) ?></div>
          <div class="stat-lbl">Active Users</div>
        </div>
        <div class="stat-ic ic-green"><i class="fa-solid fa-user-check"></i></div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-val"><?= number_format($new_this_week) ?></div>
          <div class="stat-lbl">New This Week</div>
        </div>
        <div class="stat-ic ic-purple"><i class="fa-solid fa-user-plus"></i></div>
      </div>
      <div class="stat-card">
        <div>
          <div class="stat-val"><?= number_format($blocked) ?></div>
          <div class="stat-lbl">Blocked Accounts</div>
        </div>
        <div class="stat-ic ic-red"><i class="fa-solid fa-user-slash"></i></div>
      </div>
    </div>

    <!-- Table card -->
    <div class="card">

      <!-- Filter bar -->
      <form method="GET" id="fForm">
        <div class="filter-bar">

          <!-- Search -->
          <div class="filter-group">
            <span class="filter-label">Search</span>
            <div class="search-wrap">
              <i class="fa-solid fa-magnifying-glass"></i>
              <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
                     placeholder="Search by name or email" oninput="debouncedSubmit()"/>
            </div>
          </div>

          <!-- Status tabs -->
          <div class="filter-group">
            <span class="filter-label">Status</span>
            <div class="tab-group">
              <?php foreach (['all'=>'All','active'=>'Active','deactive'=>'Deactive'] as $v=>$l): ?>
              <button type="submit" name="status" value="<?= $v ?>"
                class="tab <?= $status_filter===$v?'active':'' ?>"><?= $l ?></button>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Role -->
          <div class="filter-group">
            <span class="filter-label">Role</span>
            <select name="role" class="role-select" onchange="document.getElementById('fForm').submit()">
              <option value="all" <?= $role_filter==='all'?'selected':'' ?>>All</option>
              <?php foreach ($roles as $r): ?>
              <option value="<?= $r ?>" <?= $role_filter===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <input type="hidden" name="page" value="1"/>
          <div class="spacer"></div>

          <!-- Actions -->
          <a href="?export=1&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&role=<?= $role_filter ?>"
             class="btn btn-outline">
            <i class="fa-solid fa-file-excel" style="color:#10b981"></i> Export Excel
          </a>
          <button type="button" class="btn btn-primary"
                  onclick="document.getElementById('addOverlay').classList.add('open')">
            <i class="fa-solid fa-plus"></i> Add User
          </button>
        </div>
      </form>

      <!-- Table -->
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Name</th>
              <th>Email</th>
              <th>Status</th>
              <th>Role</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
          <?php
          $count = 0;
          while ($u = $users_res->fetch_assoc()):
            $count++;
            $active = isActive($u['created_at']);
            $ini    = initials($u['full_name'] ?? 'User');
            $bg     = avatarBg((int)$u['id']);
          ?>
            <tr>
              <!-- Name -->
              <td>
                <div class="user-cell">
                  <?php if (!empty($u['avatar_url'])): ?>
                    <div class="user-av" style="background-image:url('<?= htmlspecialchars($u['avatar_url']) ?>')"></div>
                  <?php else: ?>
                    <div class="user-av" style="background:<?= $bg ?>"><?= $ini ?></div>
                  <?php endif; ?>
                  <span class="user-name"><?= htmlspecialchars($u['full_name'] ?? '—') ?></span>
                </div>
              </td>
              <!-- Email -->
              <td class="email-text"><?= htmlspecialchars($u['email']) ?></td>
              <!-- Status -->
              <td>
                <span class="badge <?= $active ? 'badge-active' : 'badge-deactive' ?>">
                  <?= $active ? 'Active' : 'Deactive' ?>
                </span>
              </td>
              <!-- Role -->
              <td class="role-text"><?= ucfirst(htmlspecialchars($u['role'])) ?></td>
              <!-- Actions -->
              <td>
                <div class="act-btns">
                  <!-- Edit -->
                  <a href="?edit=<?= $u['id'] ?>&search=<?= urlencode($search) ?>&status=<?= $status_filter ?>&role=<?= $role_filter ?>&page=<?= $page ?>"
                     class="act-btn" title="Edit">
                    <i class="fa-solid fa-pen"></i>
                  </a>
                  <!-- Delete -->
                  <form method="POST" onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($u['full_name'] ?? '')) ?>?')" style="margin:0">
                    <input type="hidden" name="action"  value="delete_user"/>
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>"/>
                    <button type="submit" class="act-btn del" title="Delete">
                      <i class="fa-solid fa-rotate-left"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endwhile; ?>
          <?php if ($count === 0): ?>
            <tr class="empty">
              <td colspan="5">
                <i class="fa-solid fa-users-slash" style="font-size:30px;display:block;margin-bottom:10px;color:var(--border)"></i>
                No users match your filters.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination">
        <!-- Prev -->
        <a href="<?= url(['page' => max(1,$page-1)]) ?>"
           class="pg <?= $page <= 1 ? 'disabled' : '' ?>">
          <i class="fa-solid fa-chevron-left" style="font-size:10px"></i>
        </a>

        <?php
        // Show first few pages, ellipsis, last
        $range = 3;
        $pages_to_show = [];
        for ($p = max(1,$page-1); $p <= min($total_pages,$page+1); $p++) $pages_to_show[] = $p;
        if (!in_array(1,$pages_to_show)) array_unshift($pages_to_show, 1);
        if (!in_array($total_pages,$pages_to_show)) $pages_to_show[] = $total_pages;
        sort($pages_to_show);

        $prev = null;
        foreach ($pages_to_show as $p):
          if ($prev !== null && $p - $prev > 1) echo '<span class="pg" style="border:none;color:var(--muted)">…</span>';
          $prev = $p;
        ?>
        <a href="<?= url(['page'=>$p]) ?>"
           class="pg <?= $p===$page?'active':'' ?>">
          <?= str_pad($p, 2, '0', STR_PAD_LEFT) ?>
        </a>
        <?php endforeach; ?>

        <!-- Next -->
        <a href="<?= url(['page' => min($total_pages,$page+1)]) ?>"
           class="pg <?= $page >= $total_pages ? 'disabled' : '' ?>">
          <i class="fa-solid fa-chevron-right" style="font-size:10px"></i>
        </a>
      </div>
      <?php endif; ?>

    </div><!-- /card -->
  </div><!-- /content -->
</div><!-- /main -->

<!-- ── Add User Modal ──────────────────────────────────────── -->
<div class="overlay" id="addOverlay">
  <div class="modal">
    <div class="modal-head">
      <h3><i class="fa-solid fa-user-plus" style="color:var(--blue);margin-right:8px"></i>Add New User</h3>
      <div class="modal-close" onclick="document.getElementById('addOverlay').classList.remove('open')">
        <i class="fa-solid fa-xmark"></i>
      </div>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="add_user"/>
      <div class="form-row">
        <div class="fg">
          <label>Full Name</label>
          <input type="text" name="full_name" placeholder="John Doe" required/>
        </div>
        <div class="fg">
          <label>Role</label>
          <select name="role">
            <option value="user">User</option>
            <option value="admin">Admin</option>
          </select>
        </div>
      </div>
      <div class="fg">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="john@example.com" required/>
      </div>
      <div class="fg">
        <label>Password</label>
        <input type="password" name="password" placeholder="Min. 8 characters"/>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline"
                onclick="document.getElementById('addOverlay').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary">Add User</button>
      </div>
    </form>
  </div>
</div>

<!-- ── Edit User Modal ─────────────────────────────────────── -->
<?php if ($edit_user): ?>
<div class="overlay open" id="editOverlay">
  <div class="modal">
    <div class="modal-head">
      <h3><i class="fa-solid fa-pen" style="color:var(--blue);margin-right:8px"></i>Edit User</h3>
      <a href="<?= url() ?>" class="modal-close"><i class="fa-solid fa-xmark"></i></a>
    </div>
    <form method="POST">
      <input type="hidden" name="action"  value="edit_user"/>
      <input type="hidden" name="user_id" value="<?= (int)$edit_user['id'] ?>"/>
      <div class="form-row">
        <div class="fg">
          <label>Full Name</label>
          <input type="text" name="full_name"
                 value="<?= htmlspecialchars($edit_user['full_name']) ?>" required/>
        </div>
        <div class="fg">
          <label>Role</label>
          <select name="role">
            <?php foreach (['user','admin','blocked'] as $r): ?>
            <option value="<?= $r ?>" <?= $edit_user['role']===$r?'selected':'' ?>><?= ucfirst($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fg">
        <label>Email (read-only)</label>
        <input type="email" value="<?= htmlspecialchars($edit_user['email']) ?>" disabled/>
      </div>
      <div class="fg">
        <label>Joined</label>
        <input type="text" value="<?= date('M d, Y', strtotime($edit_user['created_at'])) ?>" disabled/>
      </div>
      <div class="modal-footer">
        <a href="<?= url() ?>" class="btn btn-outline">Cancel</a>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// Close modals on overlay click
document.querySelectorAll('.overlay').forEach(o => {
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('open'); });
});

// Debounced search
let t;
function debouncedSubmit() {
  clearTimeout(t);
  t = setTimeout(() => document.getElementById('fForm').submit(), 450);
}
</script>
</body>
</html>