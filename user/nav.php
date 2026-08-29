<?php

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$userName  = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$userInit  = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1));
$_currentPage = basename($_SERVER['PHP_SELF'], '.php');

function navLink(string $href, string $label, string $pageKey): string {
    global $_currentPage;
    $active = $_currentPage === $pageKey ? ' class="active"' : '';
    return "<li><a href=\"{$href}\"{$active}>{$label}</a></li>";
}
?>

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

  /* ── NAV ── */
  nav {
    background: #fff; padding: 0 32px; height: 60px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 1px 0 var(--border), 0 2px 12px rgba(15,31,61,.06);
  }
  .logo { display: flex; align-items: center; gap: 10px; text-decoration: none; }
  .logo-icon { width: 36px; height: 36px; background: var(--blue); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; color: #fff; }
  .logo-text strong { font-family: "Space Grotesk", sans-serif; font-size: 17px; font-weight: 700; display: block; line-height: 1; color: var(--navy); }
  .logo-text span { font-size: 11px; color: var(--text-muted); }
  .nav-links { display: flex; align-items: center; gap: 4px; list-style: none; }
  .nav-links a { color: var(--text-muted); text-decoration: none; padding: 6px 14px; border-radius: 8px; font-size: 14px; font-weight: 500; transition: all .2s; }
  .nav-links a:hover { color: var(--navy); background: var(--bg); }
  .nav-links a.active { color: var(--blue); background: #eff6ff; font-weight: 600; }
  .logout-btn {
    background: #fff; border: 1.5px solid var(--border); color: var(--text);
    padding: 7px 16px; border-radius: 8px; font-family: "DM Sans", sans-serif;
    font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 6px; transition: all .2s;
  }
  .logout-btn:hover { background: var(--bg); border-color: #cbd5e1; color: var(--navy); }

  /* ── AVATAR INITIAL ── */
  #nav-avatar-initial {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, var(--blue), var(--blue-light));
    color: #fff; font-family: "Space Grotesk", sans-serif;
    font-size: 14px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; box-shadow: 0 2px 8px rgba(37,99,235,.30);
    cursor: default; user-select: none;
  }

  @media (max-width: 768px) {
    nav { padding: 0 16px; }
    .nav-links { display: none; }
    .nav-user-info { display: none; }
  }
</style>

<nav>
  <a href="home.php" class="logo">
    <img src="../images/initialLogo.png" alt="" width="160px" style="margin-top:27px;">
  </a>

  <ul class="nav-links">
    <?= navLink('home.php',          'Home',          'home')          ?>
    <?= navLink('subscriptions.php', 'Subscriptions', 'subscriptions') ?>
    <?= navLink('vehicles.php',       'Vehicles',       'vehicles')       ?>
    <?= navLink('history.php',       'History',       'history')       ?>
  </ul>
  <div style="display:flex;align-items:center;gap:10px;">
    <div class="nav-user-info" style="text-align:right;">
      <div style="font-size:13px;font-weight:600;color:var(--navy);line-height:1.2;"><?= $userName ?></div>
      <div style="font-size:11px;color:var(--text-muted);"><?= $userEmail ?></div>
    </div>
    <div id="nav-avatar-initial" title="<?= $userName ?>"><?= $userInit ?></div>
    <button class="logout-btn" onclick="handleLogout()">
      <i class="fa-solid fa-right-from-bracket"></i> Logout
    </button>
  </div>
</nav>

<?php if (!defined('PARKIFY_NAV_LOADED')): define('PARKIFY_NAV_LOADED', true); ?>
<script>
  async function handleLogout() {
    const btn = document.querySelector('.logout-btn');
    if (btn) {
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Logging out…';
    }
    try {
      await fetch('home.php?action=logout');
    } catch (_) {}
    localStorage.removeItem('parkify_user');
    localStorage.removeItem('parkify_token');
    window.location.href = '../login/login.php';
  }
</script>
<?php endif; ?>