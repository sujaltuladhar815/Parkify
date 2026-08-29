<?php
// ============================================================
//  Parkify — Landing Page (index.php)
//  Location: Parkify/user/index.php
// ============================================================

// ── DB Connection ────────────────────────────────────────────
$conn = new mysqli('localhost', 'root', '', 'parkify_db');
$conn->set_charset('utf8mb4');

// Safe defaults in case DB is unavailable
$totalSlots   = 16;
$occupiedSlots = 0;
$occPercent   = 0;
$totalUsers   = 0;
$totalSessions = 0;
$totalRevenue  = 0;
$uptime        = 99.9;

if (!$conn->connect_error) {

    // Live slot occupancy
    $r = $conn->query("SELECT COUNT(*) AS total, SUM(status != 'available') AS occupied FROM parking_slots");
    if ($r && $row = $r->fetch_assoc()) {
        $totalSlots    = max(1, (int)$row['total']);
        $occupiedSlots = (int)$row['occupied'];
        $occPercent    = round(($occupiedSlots / $totalSlots) * 100);
    }

    // Total registered drivers
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM users WHERE role = 'user'");
    if ($r && $row = $r->fetch_assoc()) $totalUsers = (int)$row['cnt'];

    // Total sessions ever
    $r = $conn->query("SELECT COUNT(*) AS cnt FROM parking_sessions");
    if ($r && $row = $r->fetch_assoc()) $totalSessions = (int)$row['cnt'];

    // Total revenue (NPR)
    $r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS rev FROM payments WHERE status IN ('success','paid')");
    if ($r && $row = $r->fetch_assoc()) $totalRevenue = (float)$row['rev'];

    $conn->close();
}

// Format revenue label: show in Lakhs if >= 100000
$revenueTarget = $totalRevenue;
$revenueSuffix = '';
$revenuePrefix = 'Rs. ';
if ($totalRevenue >= 100000) {
    $revenueTarget = round($totalRevenue / 100000, 1);
    $revenueSuffix = ' Lakh+';
} elseif ($totalRevenue >= 1000) {
    $revenueTarget = round($totalRevenue / 1000, 1);
    $revenueSuffix = 'K+';
} else {
    $revenueSuffix = '+';
}

// Occupancy bar width (capped 0-100)
$barWidth = min(100, max(0, $occPercent));

// Colour for occupancy: green < 60, amber 60-85, red > 85
$occColor = $occPercent < 60 ? '#10b981' : ($occPercent < 85 ? '#f59e0b' : '#ef4444');
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Parkify — Park Smarter, Live Better</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800;900&family=Inter:wght@400;500;600&display=swap"
      rel="stylesheet"
    />
    <link
      rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"
    />
    <link rel="icon" href="../images/fabiconlogo.png" type="image/png" />
    <link rel="stylesheet" href="style.css" />
    <style>
      /* ── Occupancy bar ── */
      .occ-bar-track {
        height: 6px;
        background: #e2e8f0;
        border-radius: 99px;
        margin-top: 6px;
        overflow: hidden;
      }
      .occ-bar-fill {
        height: 100%;
        border-radius: 99px;
        transition: width 1s ease;
        background: <?= $occColor ?>;
        width: <?= $barWidth ?>%;
      }
      .occ-live-dot {
        width: 7px; height: 7px;
        border-radius: 50%;
        background: <?= $occColor ?>;
        display: inline-block;
        margin-right: 4px;
        animation: pulse 1.6s infinite;
      }
      /* ── Stat icon (replaces emoji) ── */
      .stat-icon-fa {
        width: 52px; height: 52px;
        border-radius: 14px;
        display: flex; align-items: center; justify-content: center;
        font-size: 22px;
        margin: 0 auto 12px;
      }
      /* Car-icon in hero card */
      .car-icon-wrap {
        font-size: 56px;
        color: var(--blue-mid, #2563eb);
        line-height: 1;
        margin-bottom: 4px;
      }
    </style>
  </head>
  <body>

    <!-- ── NAV ── -->
    <nav id="navbar">
      <div class="nav-inner">
        <a class="logo" href="#hero">
          <img src="../images/initialLogo.png" alt="Parkify" width="150px" />
        </a>
        <ul class="nav-links">
          <li><a href="#features">Features</a></li>
          <li><a href="#how">How It Works</a></li>
          <li><a href="#pricing">Pricing</a></li>
          <li><a href="#reviews">Reviews</a></li>
          <li><a href="#contact-strip">Contact</a></li>
        </ul>
        <div class="nav-actions">
          <a href="login-aith-google.html"><button class="btn-nav-ghost">Log In</button></a>
          <button class="btn-nav-solid">Get Started &rarr;</button>
        </div>
        <div class="hamburger" id="hamburger">
          <span></span><span></span><span></span>
        </div>
      </div>
    </nav>

    <!-- Mobile drawer -->
    <div class="nav-drawer" id="drawer">
      <a href="#features"    onclick="closeDrawer()">Features</a>
      <a href="#how"         onclick="closeDrawer()">How It Works</a>
      <a href="#pricing"     onclick="closeDrawer()">Pricing</a>
      <a href="#reviews"     onclick="closeDrawer()">Reviews</a>
      <a href="#contact-strip" onclick="closeDrawer()">Contact</a>
    </div>

    <!-- ── HERO ── -->
    <section id="hero">
      <div class="blob blob-a"></div>
      <div class="blob blob-b"></div>
      <div class="hero-wrap">

        <!-- Left copy -->
        <div>
          <div class="hero-eyebrow">
            <span class="pill"><span class="dot"></span> AI-Powered Parking</span>
          </div>
          <h1 class="hero-h1">
            Find. Park.<br /><span class="grad">Pay Easier.</span>
          </h1>
          <p class="hero-desc">
            Automatic plate recognition, real-time slot allocation, and cashless
            payment — all wrapped in one seamless smart parking system.
          </p>
          <div class="hero-btns">
            <a href="#booking" class="btn btn-primary">
              <i class="fa-solid fa-car"></i> Book a Slot
            </a>
            <a href="#features" class="btn btn-ghost">
              Explore Features <i class="fa-solid fa-arrow-right fa-xs"></i>
            </a>
          </div>
          <div class="hero-proof">
            <div class="avatars">
              <span style="background:#dbeafe;color:#1d4ed8">S</span>
              <span style="background:#dcfce7;color:#15803d">A</span>
              <span style="background:#fef9c3;color:#a16207">R</span>
              <span style="background:#fce7f3;color:#9d174d">K</span>
              <span style="background:#ede9fe;color:#6d28d9">+</span>
            </div>
            <div>
              <div class="stars">★★★★★</div>
              <div class="proof-text">
                Trusted by <strong>5,000+</strong> drivers &middot;
                <strong style="color:var(--amber)">4.9 / 5</strong>
              </div>
            </div>
          </div>
        </div>

        <!-- Right visual -->
        <div class="hero-right">

          <!-- Main scan card — FA icon instead of emoji -->
          <div class="hero-main-card">
            <div class="car-icon-wrap">
              <i class="fa-solid fa-car-side"></i>
            </div>
            <div class="scan-line"></div>
            <div class="live-tag">
              <div class="live-dot"></div>
              Scanning plate…
            </div>
          </div>

          <!-- Plate detected -->
          <div class="plate-float">
            <div class="plate-top">
              <i class="fa-solid fa-camera fa-xs"></i> &nbsp;Plate Detected
            </div>
            <div class="plate-num">BA 12 AB 1234</div>
            <div class="plate-bottom">
              <div class="plate-ok"><span></span> Entry Successful</div>
              <div class="plate-slot">Slot A-3</div>
            </div>
          </div>

          <!-- Live Occupancy — real DB data -->
          <div class="occ-float">
            <div class="occ-label">
              <span class="occ-live-dot"></span>Live Occupancy
            </div>
            <div class="occ-val" style="color:<?= $occColor ?>">
              <?= $occPercent ?>%
            </div>
            <div class="occ-sub"><?= $occupiedSlots ?> / <?= $totalSlots ?> slots</div>
            <div class="occ-bar-track">
              <div class="occ-bar-fill"></div>
            </div>
          </div>

          <!-- Booking mini -->
          <div class="mini-booking">
            <div class="mb-row">
              <div class="mb-icon" style="background:#eff6ff">
                <i class="fa-solid fa-clock" style="color:var(--blue-mid)"></i>
              </div>
              <div>
                <div class="mb-label">Duration</div>
                <div class="mb-value">2h 30m</div>
              </div>
            </div>
            <div class="mb-row" style="margin-bottom:0">
              <div class="mb-icon" style="background:#ecfdf5">
                <i class="fa-solid fa-tag" style="color:var(--green)"></i>
              </div>
              <div>
                <div class="mb-label">Total</div>
                <div class="mb-value">Rs. 125</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── BRANDS / TRUST ── -->
    <section id="brands">
      <div class="container">
        <div class="brands-inner">
          <span class="brand-label">Integrated with</span>
          <div class="brand-chip"><i class="fa-brands fa-google-pay"></i> Google Pay</div>
          <div class="brand-chip"><i class="fa-solid fa-mobile-screen"></i> eSewa</div>
          <div class="brand-chip"><i class="fa-solid fa-wallet"></i> Khalti</div>
          <div class="brand-chip"><i class="fa-solid fa-credit-card"></i> Visa / Mastercard</div>
          <div class="brand-chip"><i class="fa-solid fa-university"></i> Connect IPS</div>
        </div>
      </div>
    </section>

    <!-- ── FEATURES ── -->
    <section class="section section-alt" id="features">
      <div class="container">
        <div style="text-align:center" class="reveal">
          <span class="pill"><span class="dot"></span> Why Parkify</span>
          <h2 class="sec-title">Everything for smart parking</h2>
          <div class="rule"></div>
        </div>
        <div class="feat-grid">
          <div class="feat-card reveal" style="transition-delay:.04s">
            <div class="feat-icon-wrap" style="background:#eff6ff">
              <i class="fa-solid fa-camera fa-lg" style="color:#2563eb"></i>
            </div>
            <h3>Auto Plate Recognition</h3>
            <p>AI-powered camera system detects and verifies number plates in under a second.</p>
          </div>
          <div class="feat-card reveal" style="transition-delay:.1s">
            <div class="feat-icon-wrap" style="background:#f0fdf4">
              <i class="fa-solid fa-map-location-dot fa-lg" style="color:#10b981"></i>
            </div>
            <h3>Smart Slot Allocation</h3>
            <p>Nearest available spot is instantly assigned and guided via digital signage.</p>
          </div>
          <div class="feat-card reveal" style="transition-delay:.16s">
            <div class="feat-icon-wrap" style="background:#faf5ff">
              <i class="fa-solid fa-shield-halved fa-lg" style="color:#7c3aed"></i>
            </div>
            <h3>Secure Payments</h3>
            <p>Multiple payment gateways with 256-bit encryption and instant digital receipts.</p>
          </div>
          <div class="feat-card reveal" style="transition-delay:.22s">
            <div class="feat-icon-wrap" style="background:#fffbeb">
              <i class="fa-solid fa-chart-line fa-lg" style="color:#f59e0b"></i>
            </div>
            <h3>Live Dashboard</h3>
            <p>Real-time occupancy, revenue analytics, and peak-hour insights at a glance.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ── HOW IT WORKS ── -->
    <section class="section" id="how">
      <div class="container">
        <div style="text-align:center" class="reveal">
          <span class="pill"><span class="dot"></span> How It Works</span>
          <h2 class="sec-title">Parked in four simple steps</h2>
          <div class="rule"></div>
        </div>
        <div class="how-grid">
          <div class="step-card reveal" style="transition-delay:.04s">
            <div class="step-circle" style="background:#eff6ff;color:#2563eb;border-color:#bfdbfe">01</div>
            <h3>Vehicle Arrives</h3>
            <p>Camera reads the license plate automatically as you drive in.</p>
          </div>
          <div class="step-card reveal" style="transition-delay:.12s">
            <div class="step-circle" style="background:#f0fdf4;color:#10b981;border-color:#bbf7d0">02</div>
            <h3>Slot Assigned</h3>
            <p>System selects the optimal nearest slot in milliseconds.</p>
          </div>
          <div class="step-card reveal" style="transition-delay:.2s">
            <div class="step-circle" style="background:#fffbeb;color:#f59e0b;border-color:#fde68a">03</div>
            <h3>Park &amp; Relax</h3>
            <p>Follow the LED guide and enjoy your completely worry-free session.</p>
          </div>
          <div class="step-card reveal" style="transition-delay:.28s">
            <div class="step-circle" style="background:#faf5ff;color:#7c3aed;border-color:#ddd6fe">04</div>
            <h3>Pay &amp; Go</h3>
            <p>Tap to pay at kiosk or phone — receipt arrives instantly.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- ── STATS — real DB data ── -->
    <section id="stats">
      <div class="container">
        <div class="stats-grid">

          <!-- Happy Drivers -->
          <div class="stat-card reveal" style="transition-delay:0s">
            <div class="stat-icon-fa" style="background:#eff6ff;color:#2563eb">
              <i class="fa-solid fa-users"></i>
            </div>
            <div class="stat-number"
                 data-target="<?= max($totalUsers, 1) ?>"
                 data-suffix="+"><?= max($totalUsers, 1) ?>+</div>
            <div class="stat-desc">Registered Drivers</div>
          </div>

          <!-- Vehicles Parked -->
          <div class="stat-card reveal" style="transition-delay:.1s">
            <div class="stat-icon-fa" style="background:#ecfdf5;color:#10b981">
              <i class="fa-solid fa-car"></i>
            </div>
            <div class="stat-number"
                 data-target="<?= max($totalSessions, 1) ?>"
                 data-suffix="+"><?= max($totalSessions, 1) ?>+</div>
            <div class="stat-desc">Vehicles Parked</div>
          </div>

          <!-- Revenue Generated -->
          <div class="stat-card reveal" style="transition-delay:.2s">
            <div class="stat-icon-fa" style="background:#fffbeb;color:#f59e0b">
              <i class="fa-solid fa-sack-dollar"></i>
            </div>
            <div class="stat-number"
                 data-target="<?= $revenueTarget ?>"
                 data-suffix="<?= htmlspecialchars($revenueSuffix) ?>"
                 data-prefix="<?= htmlspecialchars($revenuePrefix) ?>"
                 <?= (floor($revenueTarget) != $revenueTarget) ? 'data-float="true"' : '' ?>
            ><?= $revenuePrefix . $revenueTarget . $revenueSuffix ?></div>
            <div class="stat-desc">Revenue Generated</div>
          </div>

          <!-- System Uptime -->
          <div class="stat-card reveal" style="transition-delay:.3s">
            <div class="stat-icon-fa" style="background:#faf5ff;color:#7c3aed">
              <i class="fa-solid fa-server"></i>
            </div>
            <div class="stat-number"
                 data-target="<?= $uptime ?>"
                 data-suffix="%"
                 data-float="true"><?= $uptime ?>%</div>
            <div class="stat-desc">System Uptime</div>
          </div>

        </div>
      </div>
    </section>

    <!-- ── CTA BAND ── -->
    <section id="cta-band">
      <div class="container">
        <div class="cta-inner reveal">
          <div>
            <h2>Ready to park smarter?</h2>
            <p>
              Join thousands of drivers who trust Parkify daily for smooth,
              secure, stress-free parking — every single time.
            </p>
          </div>
          <div class="cta-btns">
            <a href="#" class="btn btn-primary"><i class="fa-solid fa-car"></i> Book Now</a>
            <a href="#" class="btn btn-outline">View Dashboard &rarr;</a>
          </div>
        </div>
      </div>
    </section>

    <!-- ── PRICING ── -->
    <section class="section section-alt" id="pricing">
      <div class="container">
        <div style="text-align:center" class="reveal">
          <span class="pill"><span class="dot"></span> Pricing</span>
          <h2 class="sec-title">Affordable plans for everyone</h2>
          <p class="sec-sub" style="margin:0 auto 0">No hidden fees, no surprises. Pick what fits your lifestyle.</p>
          <div class="rule"></div>
        </div>
        <div class="plans-row">

          <!-- Basic -->
          <div class="plan-card reveal" style="transition-delay:.04s">
            <div class="plan-tier">Basic</div>
            <div class="plan-hint">For occasional parkers</div>
            <div class="plan-amt">
              <span class="plan-cur">Rs.</span>
              <span class="plan-num">50</span>
              <span class="plan-freq">/day</span>
            </div>
            <div class="plan-line"></div>
            <ul class="plan-list">
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Up to 2 hours parking</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Standard support</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Online payment</li>
              <li><div class="chk chk-x"><svg viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="#f43f5e" stroke-width="1.6" stroke-linecap="round"/></svg></div><span style="color:var(--text4)">Priority slot</span></li>
              <li><div class="chk chk-x"><svg viewBox="0 0 10 10" fill="none"><path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="#f43f5e" stroke-width="1.6" stroke-linecap="round"/></svg></div><span style="color:var(--text4)">Monthly reports</span></li>
            </ul>
            <button class="btn btn-outline" style="width:100%;justify-content:center;padding:13px" onclick="openPkgModal('basic')">Get Started</button>
          </div>

          <!-- Premium -->
          <div class="plan-card plan-hot reveal" style="transition-delay:.12s">
            <div class="hot-badge">Most Popular</div>
            <div class="plan-tier">Premium</div>
            <div class="plan-hint">For frequent parkers</div>
            <div class="plan-amt">
              <span class="plan-cur">Rs.</span>
              <span class="plan-num">100</span>
              <span class="plan-freq">/day</span>
            </div>
            <div class="plan-line"></div>
            <ul class="plan-list">
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Up to 12 hours</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Priority support</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Online payment</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Digital receipt</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Priority slot access</li>
            </ul>
            <button class="btn btn-primary" style="width:100%;justify-content:center;padding:13px" onclick="openPkgModal('premium')">Get Started &rarr;</button>
          </div>

          <!-- Monthly -->
          <div class="plan-card reveal" style="transition-delay:.2s">
            <div class="plan-tier">Monthly</div>
            <div class="plan-hint">Best value for regulars</div>
            <div class="plan-amt">
              <span class="plan-cur">Rs.</span>
              <span class="plan-num">1,500</span>
              <span class="plan-freq">/mo</span>
            </div>
            <div class="plan-line"></div>
            <ul class="plan-list">
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Unlimited parking</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Priority support</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Online payment</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Digital receipt</li>
              <li><div class="chk"><svg viewBox="0 0 10 10" fill="none"><path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></div>Monthly reports</li>
            </ul>
            <button class="btn btn-outline" style="width:100%;justify-content:center;padding:13px" onclick="openPkgModal('monthly')">Get Started</button>
          </div>

        </div>
      </div>
    </section>

    <!-- ── REVIEWS ── -->
    <section class="section" id="reviews">
      <div class="container">
        <div style="text-align:center" class="reveal">
          <span class="pill"><span class="dot"></span> Testimonials</span>
          <h2 class="sec-title">Loved by our users</h2>
          <div class="rule"></div>
        </div>
        <div class="revs-grid">
          <div class="rev-card reveal" style="transition-delay:.04s">
            <div class="rev-stars">★★★★★</div>
            <p class="rev-body">"Parkify completely changed how I handle parking. The plate recognition is lightning-fast and the checkout process is genuinely effortless."</p>
            <div class="reviewer">
              <div class="rev-avatar" style="background:#dbeafe;color:#1d4ed8">SK</div>
              <div>
                <div class="rev-name">Sujan K.</div>
                <div class="rev-role">Regular User · Kathmandu</div>
              </div>
            </div>
          </div>
          <div class="rev-card reveal" style="transition-delay:.12s">
            <div class="rev-stars">★★★★★</div>
            <p class="rev-body">"Super smooth and incredibly reliable. No more searching for cash or queuing at the booth. It just works perfectly every single time."</p>
            <div class="reviewer">
              <div class="rev-avatar" style="background:#dcfce7;color:#15803d">AT</div>
              <div>
                <div class="rev-name">Anita Thapa</div>
                <div class="rev-role">Premium User · Lalitpur</div>
              </div>
            </div>
          </div>
          <div class="rev-card reveal" style="transition-delay:.2s">
            <div class="rev-stars">★★★★★</div>
            <p class="rev-body">"The admin dashboard is seriously impressive. We run a 400-slot facility and Parkify makes every day feel effortless to manage."</p>
            <div class="reviewer">
              <div class="rev-avatar" style="background:#fef9c3;color:#a16207">RP</div>
              <div>
                <div class="rev-name">Ramesh P.</div>
                <div class="rev-role">Parking Manager · Bhaktapur</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── CONTACT ── -->
    <section id="contact-strip" class="section section-alt">
      <div class="container">
        <div style="text-align:center" class="reveal">
          <span class="pill"><span class="dot"></span> Get In Touch</span>
          <h2 class="sec-title">We're here to help</h2>
          <div class="rule"></div>
        </div>
        <div class="contact-grid">
          <div class="contact-card reveal" style="transition-delay:.04s">
            <div class="cc-icon" style="background:#eff6ff">
              <i class="fa-solid fa-location-dot fa-lg" style="color:#2563eb"></i>
            </div>
            <div>
              <div class="cc-title">Our Location</div>
              <div class="cc-val">Kathmandu Metropolitan City<br>Bagmati Province, Nepal</div>
            </div>
          </div>
          <div class="contact-card reveal" style="transition-delay:.1s">
            <div class="cc-icon" style="background:#f0fdf4">
              <i class="fa-solid fa-phone fa-lg" style="color:#10b981"></i>
            </div>
            <div>
              <div class="cc-title">Phone &amp; Email</div>
              <div class="cc-val">+977 9800-000-000</div>
              <div class="cc-val">support@parkify.com.np</div>
              <div class="cc-hours"><i class="fa-regular fa-clock fa-xs"></i> Mon – Sun · 6 AM – 10 PM</div>
            </div>
          </div>
          <div class="contact-card reveal" style="transition-delay:.16s">
            <div class="cc-icon" style="background:#fffbeb">
              <i class="fa-solid fa-headset fa-lg" style="color:#f59e0b"></i>
            </div>
            <div>
              <div class="cc-title">Live Support</div>
              <div class="cc-val">Chat with our team via WhatsApp or the in-app support portal.</div>
              <div class="cc-hours" style="margin-top:8px">
                <span style="display:inline-flex;align-items:center;gap:5px;background:#ecfdf5;color:#10b981;border-radius:999px;padding:3px 10px;font-size:11px;font-weight:700">
                  <span style="width:6px;height:6px;border-radius:50%;background:#10b981;display:inline-block"></span>
                  Online Now
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- ── FOOTER ── -->
    <footer id="footer">
      <div class="container">
        <div class="foot-grid">
          <div>
            <div class="foot-logo"><img src="../images/initialLogo.png" alt="Parkify" width="150px" /></div>
            <p class="foot-desc">Next-generation parking management — simple, secure, and smart for drivers and operators everywhere.</p>
            <div class="socials">
              <a class="social-btn" href="#"><i class="fab fa-facebook-f fa-xs"></i></a>
              <a class="social-btn" href="#"><i class="fab fa-x-twitter fa-xs"></i></a>
              <a class="social-btn" href="#"><i class="fab fa-linkedin-in fa-xs"></i></a>
              <a class="social-btn" href="#"><i class="fab fa-instagram fa-xs"></i></a>
            </div>
          </div>
          <div class="foot-col">
            <h4>Quick Links</h4>
            <a href="#hero">Home</a>
            <a href="#features">Features</a>
            <a href="#how">How It Works</a>
            <a href="#pricing">Pricing</a>
            <a href="#reviews">Reviews</a>
          </div>
          <div class="foot-col">
            <h4>Support</h4>
            <span>Help Center</span>
            <span>Terms &amp; Conditions</span>
            <span>Privacy Policy</span>
            <span>Refund Policy</span>
            <span>FAQ</span>
          </div>
          <div class="foot-col">
            <h4>Contact</h4>
            <span><i class="fa-solid fa-location-dot fa-xs" style="color:var(--blue-mid);margin-right:5px"></i>Kathmandu, Nepal</span>
            <span><i class="fa-solid fa-phone fa-xs" style="color:var(--blue-mid);margin-right:5px"></i>+977 9800000000</span>
            <span><i class="fa-solid fa-envelope fa-xs" style="color:var(--blue-mid);margin-right:5px"></i>support@parkify.com.np</span>
            <span><i class="fa-regular fa-clock fa-xs" style="color:var(--blue-mid);margin-right:5px"></i>Mon–Sun, 6AM–10PM</span>
          </div>
          <div>
            <div class="foot-col"><h4>Newsletter</h4></div>
            <p class="nl-desc">Stay updated with the latest features, offers, and smart parking tips.</p>
            <div class="nl-form">
              <input class="nl-input" type="email" placeholder="you@example.com" />
              <button class="btn btn-primary" style="justify-content:center;font-size:13.5px;padding:11px 20px"
                      onclick="showToast('Subscribed! Welcome to Parkify.')">Subscribe &rarr;</button>
            </div>
          </div>
        </div>
        <div class="foot-bottom">
          <p>&copy; <?= date('Y') ?> Parkify. All rights reserved.</p>
          <div class="foot-links">
            <a href="#">Privacy</a>
            <a href="#">Terms</a>
            <a href="#">Cookies</a>
          </div>
        </div>
      </div>
    </footer>

    <!-- Toast -->
    <div id="toast">
      <i class="fa-solid fa-circle-check"></i> <span id="toast-msg">Done!</span>
    </div>

    <!-- ── PACKAGE MODAL ── -->
    <div id="pkg-overlay" onclick="closePkgModal(event)">
      <div class="pkg-modal" id="pkg-modal">
        <button class="pkg-close" onclick="closePkgModal(null,true)">&times;</button>
        <div class="pkg-badge" id="pkg-badge"></div>
        <h2 class="pkg-title" id="pkg-title"></h2>
        <p class="pkg-hint" id="pkg-hint"></p>
        <div class="pkg-price-row">
          <span class="plan-cur">Rs.</span>
          <span class="pkg-price-num" id="pkg-price"></span>
          <span class="plan-freq" id="pkg-freq"></span>
        </div>
        <p class="pkg-desc" id="pkg-desc"></p>
        <ul class="pkg-features" id="pkg-features"></ul>
        <div class="pkg-divider"></div>
        <p class="pkg-pay-label">Choose Payment Method</p>
        <div class="pkg-pay-grid">
          <button class="pkg-pay-btn" onclick="selectPayment(this)"><i class="fa-solid fa-mobile-screen"></i> eSewa</button>
          <button class="pkg-pay-btn" onclick="selectPayment(this)"><i class="fa-solid fa-wallet"></i> Khalti</button>
          <button class="pkg-pay-btn" onclick="selectPayment(this)"><i class="fa-brands fa-google-pay"></i> Google Pay</button>
          <button class="pkg-pay-btn" onclick="selectPayment(this)"><i class="fa-solid fa-credit-card"></i> Card</button>
        </div>
        <button class="btn btn-primary pkg-buy-btn" id="pkg-buy-btn" onclick="handlePurchase()">
          <i class="fa-solid fa-lock"></i> Confirm Purchase
        </button>
        <p class="pkg-secure">
          <i class="fa-solid fa-shield-halved"></i> 256-bit encrypted &nbsp;·&nbsp; Instant digital receipt
        </p>
      </div>
    </div>

    <script>
      /* Nav scroll */
      const nav = document.getElementById('navbar');
      window.addEventListener('scroll', () => nav.classList.toggle('scrolled', scrollY > 24));

      /* Hamburger */
      const hb = document.getElementById('hamburger');
      const dr = document.getElementById('drawer');
      hb.addEventListener('click', () => { hb.classList.toggle('open'); dr.classList.toggle('open'); });
      function closeDrawer() { hb.classList.remove('open'); dr.classList.remove('open'); }

      /* Smooth scroll */
      document.querySelectorAll('a[href^="#"]').forEach(a => {
        a.addEventListener('click', e => {
          const t = document.querySelector(a.getAttribute('href'));
          if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
        });
      });

      /* Scroll reveal */
      const obs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); obs.unobserve(e.target); } });
      }, { threshold: 0.1 });
      document.querySelectorAll('.reveal').forEach(el => obs.observe(el));

      /* Count-up (reads data-target / data-prefix / data-suffix / data-float from element) */
      function countUp(el) {
        const target = parseFloat(el.dataset.target);
        const suffix = el.dataset.suffix || '';
        const prefix = el.dataset.prefix || '';
        const isFloat = el.dataset.float === 'true';
        const dur = 1800;
        let start = null;
        (function step(ts) {
          if (!start) start = ts;
          const p = Math.min((ts - start) / dur, 1);
          const ease = 1 - Math.pow(1 - p, 3);
          const cur = ease * target;
          el.textContent = prefix + (isFloat ? cur.toFixed(1) : Math.floor(cur).toLocaleString()) + suffix;
          if (p < 1) requestAnimationFrame(step);
          else el.textContent = prefix + (isFloat ? target.toFixed(1) : target.toLocaleString()) + suffix;
        })(performance.now());
      }
      const cntObs = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { countUp(e.target); cntObs.unobserve(e.target); } });
      }, { threshold: 0.3 });
      document.querySelectorAll('.stat-number[data-target]').forEach(el => cntObs.observe(el));

      /* Toast */
      function showToast(msg) {
        const t = document.getElementById('toast');
        document.getElementById('toast-msg').textContent = msg;
        t.classList.add('show');
        setTimeout(() => t.classList.remove('show'), 3500);
      }

      /* Button ripple */
      document.querySelectorAll('.btn, .btn-nav-solid, .btn-nav-ghost').forEach(btn => {
        btn.addEventListener('click', function(e) {
          const r = document.createElement('span');
          const rect = this.getBoundingClientRect();
          r.style.cssText = `position:absolute;border-radius:50%;background:rgba(255,255,255,.3);
            width:5px;height:5px;pointer-events:none;
            left:${e.clientX-rect.left-2.5}px;top:${e.clientY-rect.top-2.5}px;
            transform:scale(0);animation:rpl .55s ease forwards;`;
          this.style.position = 'relative';
          this.style.overflow = 'hidden';
          this.appendChild(r);
          setTimeout(() => r.remove(), 600);
        });
      });
      const rs = document.createElement('style');
      rs.textContent = '@keyframes rpl{to{transform:scale(55);opacity:0}}';
      document.head.appendChild(rs);

      /* Package Modal */
      const packages = {
        basic: {
          badge:'basic', label:'Basic', hint:'For occasional parkers',
          price:'50', freq:'/day',
          desc:'Perfect if you park a couple of times a week. Get reliable slot allocation, cashless payment, and standard support — all at an unbeatable entry price.',
          features:[
            {text:'Up to 2 hours parking',ok:true},{text:'Standard support',ok:true},
            {text:'Online payment',ok:true},{text:'Priority slot',ok:false},{text:'Monthly reports',ok:false}
          ]
        },
        premium: {
          badge:'premium', label:'Premium', hint:'For frequent parkers',
          price:'100', freq:'/day',
          desc:'Our most popular plan. Stay parked all day, get priority slot allocation, digital receipts instantly, and jump the queue with priority support.',
          features:[
            {text:'Up to 12 hours parking',ok:true},{text:'Priority support',ok:true},
            {text:'Online payment',ok:true},{text:'Digital receipt',ok:true},{text:'Priority slot access',ok:true}
          ]
        },
        monthly: {
          badge:'monthly', label:'Monthly', hint:'Best value for regulars',
          price:'1,500', freq:'/mo',
          desc:'The smartest investment for daily commuters. Unlimited parking all month long, full analytics, and premium-tier support — at a fraction of the daily cost.',
          features:[
            {text:'Unlimited parking',ok:true},{text:'Priority support',ok:true},
            {text:'Online payment',ok:true},{text:'Digital receipt',ok:true},{text:'Monthly reports',ok:true}
          ]
        }
      };

      let selectedPayment = null;

      function openPkgModal(planKey) {
        const p = packages[planKey];
        document.getElementById('pkg-badge').className = 'pkg-badge ' + p.badge;
        document.getElementById('pkg-badge').textContent = p.label;
        document.getElementById('pkg-title').textContent = p.label + ' Plan';
        document.getElementById('pkg-hint').textContent = p.hint;
        document.getElementById('pkg-price').textContent = p.price;
        document.getElementById('pkg-freq').textContent = p.freq;
        document.getElementById('pkg-desc').textContent = p.desc;
        const ul = document.getElementById('pkg-features');
        ul.innerHTML = p.features.map(f => `
          <li class="${f.ok ? '' : 'no'}">
            <div class="chk"><svg viewBox="0 0 10 10" fill="none">
              ${f.ok
                ? `<path d="M1.5 5.5l2.5 2.5 4.5-5" stroke="#10b981" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>`
                : `<path d="M2.5 2.5l5 5M7.5 2.5l-5 5" stroke="#f43f5e" stroke-width="1.6" stroke-linecap="round"/>`}
            </svg></div>${f.text}
          </li>`).join('');
        selectedPayment = null;
        document.querySelectorAll('.pkg-pay-btn').forEach(b => b.classList.remove('selected'));
        document.getElementById('pkg-overlay').classList.add('active');
        document.body.style.overflow = 'hidden';
      }

      function closePkgModal(e, force) {
        if (force || (e && e.target === document.getElementById('pkg-overlay'))) {
          document.getElementById('pkg-overlay').classList.remove('active');
          document.body.style.overflow = '';
        }
      }

      function selectPayment(btn) {
        document.querySelectorAll('.pkg-pay-btn').forEach(b => b.classList.remove('selected'));
        btn.classList.add('selected');
        selectedPayment = btn.textContent.trim();
      }

      function handlePurchase() {
        if (!selectedPayment) { showToast('Please select a payment method first.'); return; }
        closePkgModal(null, true);
        showToast('Purchase initiated via ' + selectedPayment + '! Redirecting…');
      }

      document.addEventListener('keydown', e => { if (e.key === 'Escape') closePkgModal(null, true); });
    </script>
  </body>
</html>