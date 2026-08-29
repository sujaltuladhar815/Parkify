<?php
// ============================================================
//  Parkify — Subscriptions (subscriptions.php)
//  Location: Parkify/user/subscriptions.php
// ============================================================

// ── Stripe Config (Test Mode) ────────────────────────────────
// Replace with your keys from https://dashboard.stripe.com/test/apikeys
// Test cards: 4242 4242 4242 4242  |  exp: any future  |  CVC: any 3 digits
$stripeSecretKey = getenv('STRIPE_SECRET_KEY');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_51Te4mtPn2TE0KpLMD1qXQyAcrXxCGmhTL0Yv5pXslpJWV0EPcHhUKNjv2EwmTurXRMuahVtCLsc6QN8bZoalvI9i00qtTL8oFo');
// NOTE: Stripe does not support NPR. Amounts are sent in USD cents.
// The UI always displays NPR. For production, use eSewa/Khalti.
define('NPR_TO_USD_RATE', 133.0); // approximate — update as needed
define('STRIPE_MIN_CENTS', 50);   // Stripe minimum is $0.50

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Auth Guard ──────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    if (isset($_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated.', 'redirect' => '../login/login.php']);
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

// ── Ensure subscriptions table exists ──────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS subscriptions (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        user_id        INT NOT NULL,
        plan           ENUM('basic','premium','monthly') NOT NULL,
        amount         DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(30) NOT NULL DEFAULT 'esewa',
        status         ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
        start_date     DATE NOT NULL,
        end_date       DATE NOT NULL,
        transaction_id VARCHAR(60) UNIQUE NOT NULL,
        created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status, end_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// ── Ensure payments.session_id allows NULL (for subscription payments) ──
// Subscription payments have no parking session, so session_id must be nullable.
@$conn->query("ALTER TABLE payments MODIFY COLUMN session_id INT NULL DEFAULT NULL");

// ── Shared plan definitions ─────────────────────────────────
$PLANS = [
    'basic'   => ['amount' => 50.00,   'days' => 1,  'label' => 'Basic'],
    'premium' => ['amount' => 100.00,  'days' => 1,  'label' => 'Premium'],
    'monthly' => ['amount' => 1500.00, 'days' => 30, 'label' => 'Monthly'],
];

// ============================================================
//  AJAX API ENDPOINTS
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── GET CURRENT SUBSCRIPTION ──────────────────────────
    if ($action === 'get_subscription') {
        $stmt = $conn->prepare("
            SELECT id, plan, amount, payment_method, status, start_date, end_date, transaction_id
            FROM   subscriptions
            WHERE  user_id = ? AND status = 'active' AND end_date >= CURDATE()
            ORDER  BY created_at DESC LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $sub = $stmt->get_result()->fetch_assoc();

        if (!$sub) {
            echo json_encode(['success' => true, 'subscription' => null]);
            exit;
        }

        $daysLeft = (int) ceil((strtotime($sub['end_date']) - time()) / 86400);
        echo json_encode([
            'success'      => true,
            'subscription' => [
                'id'             => $sub['id'],
                'plan'           => $sub['plan'],
                'amount'         => 'Rs. ' . number_format($sub['amount'], 0),
                'payment_method' => $sub['payment_method'],
                'status'         => $sub['status'],
                'start_date'     => date('M d, Y', strtotime($sub['start_date'])),
                'end_date'       => date('M d, Y', strtotime($sub['end_date'])),
                'days_left'      => $daysLeft,
                'transaction_id' => $sub['transaction_id'],
            ]
        ]);
        exit;
    }

    // ── STRIPE: CREATE PAYMENT INTENT FOR SUBSCRIPTION ────
    // Called when user selects Stripe and a plan. Returns client_secret
    // for the frontend Stripe.js to confirm the card charge.
    if ($action === 'stripe_create_intent_sub') {
        $input = json_decode(file_get_contents('php://input'), true);
        $plan  = isset($input['plan']) ? trim($input['plan']) : '';

        if (!array_key_exists($plan, $PLANS)) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan selected.']);
            exit;
        }

        // Check for an already-active subscription
        $chk = $conn->prepare("SELECT id, plan FROM subscriptions WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE() LIMIT 1");
        $chk->bind_param('i', $userId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        if ($existing) {
            echo json_encode(['success' => false, 'message' => 'You already have an active ' . ucfirst($existing['plan']) . ' subscription.']);
            exit;
        }

        $amountNPR   = $PLANS[$plan]['amount'];
        $amountUSD   = $amountNPR / NPR_TO_USD_RATE;
        $amountCents = max(STRIPE_MIN_CENTS, (int)round($amountUSD * 100));

        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . STRIPE_SECRET_KEY,
                'Content-Type: application/x-www-form-urlencoded',
                'Stripe-Version: 2024-04-10',
            ],
            CURLOPT_POSTFIELDS => http_build_query([
                'amount'                             => $amountCents,
                'currency'                           => 'usd',
                'automatic_payment_methods[enabled]' => 'true',
                'metadata[user_id]'                  => $userId,
                'metadata[plan]'                     => $plan,
                'metadata[amount_npr]'               => $amountNPR,
                'description'                        => 'Parkify ' . $PLANS[$plan]['label'] . ' subscription',
            ]),
        ]);
        $stripeResponse = json_decode(curl_exec($ch), true);
        $httpCode       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || empty($stripeResponse['client_secret'])) {
            $errMsg = $stripeResponse['error']['message'] ?? 'Stripe API error.';
            echo json_encode(['success' => false, 'message' => $errMsg]);
            exit;
        }

        echo json_encode([
            'success'           => true,
            'client_secret'     => $stripeResponse['client_secret'],
            'payment_intent_id' => $stripeResponse['id'],
            'publishable_key'   => STRIPE_PUBLISHABLE_KEY,
            'amount_npr'        => $amountNPR,
            'amount_display'    => 'Rs. ' . number_format($amountNPR, 0),
            'amount_cents'      => $amountCents,
            'plan'              => $plan,
        ]);
        exit;
    }

    // ── STRIPE: CONFIRM SUBSCRIPTION ─────────────────────
    // Called after Stripe.js confirms the card charge.
    // Verifies the PaymentIntent with Stripe, then activates the subscription
    // and records a row in payments for admin revenue tracking.
    if ($action === 'stripe_checkout_sub') {
        $input           = json_decode(file_get_contents('php://input'), true);
        $plan            = isset($input['plan'])              ? trim($input['plan'])              : '';
        $paymentIntentId = isset($input['payment_intent_id']) ? trim($input['payment_intent_id']) : '';

        if (!array_key_exists($plan, $PLANS) || !$paymentIntentId) {
            echo json_encode(['success' => false, 'message' => 'Missing payment data.']);
            exit;
        }

        // Check for existing active subscription (guard against double-submit)
        $chk = $conn->prepare("SELECT id, plan FROM subscriptions WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE() LIMIT 1");
        $chk->bind_param('i', $userId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        if ($existing) {
            echo json_encode(['success' => false, 'message' => 'You already have an active ' . ucfirst($existing['plan']) . ' subscription.']);
            exit;
        }

        // Verify PaymentIntent status with Stripe
        $ch = curl_init('https://api.stripe.com/v1/payment_intents/' . urlencode($paymentIntentId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . STRIPE_SECRET_KEY,
                'Stripe-Version: 2024-04-10',
            ],
        ]);
        $pi       = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || ($pi['status'] ?? '') !== 'succeeded') {
            $status = $pi['status'] ?? 'unknown';
            echo json_encode(['success' => false, 'message' => "Payment not confirmed by Stripe (status: $status)."]);
            exit;
        }

        // Stripe confirmed — activate subscription
        $amount    = $PLANS[$plan]['amount'];
        $days      = $PLANS[$plan]['days'];
        $startDate = date('Y-m-d');
        $endDate   = date('Y-m-d', strtotime("+{$days} days"));
        $txId      = 'STR-' . strtoupper(substr($paymentIntentId, 3, 12));

        $stmt = $conn->prepare("
            INSERT INTO subscriptions (user_id, plan, amount, payment_method, status, start_date, end_date, transaction_id)
            VALUES (?, ?, ?, 'stripe', 'active', ?, ?, ?)
        ");
        $stmt->bind_param('idssss', $userId, $plan, $amount, $startDate, $endDate, $txId);

        if (!$stmt->execute()) {
            echo json_encode(['success' => false, 'message' => 'Failed to activate subscription.']);
            exit;
        }

        // Record in payments table so admin dashboard counts this as revenue
        $pmt = $conn->prepare("
            INSERT INTO payments (session_id, user_id, amount, rate_per_hour, method, status, transaction_id, paid_at)
            VALUES (NULL, ?, ?, 0.00, 'stripe', 'paid', ?, NOW())
        ");
        $pmt->bind_param('ids', $userId, $amount, $txId);
        $pmt->execute(); // best-effort; non-fatal

        echo json_encode([
            'success'        => true,
            'message'        => ucfirst($plan) . ' plan activated via Stripe!',
            'transaction_id' => $txId,
            'plan'           => $plan,
            'end_date'       => date('M d, Y', strtotime($endDate)),
            'amount'         => 'Rs. ' . number_format($amount, 0),
        ]);
        exit;
    }

    // ── SUBSCRIBE (eSewa / Khalti — simulated) ────────────
    // For non-Stripe gateways. Activates subscription immediately and
    // records a row in payments for admin revenue tracking.
    if ($action === 'subscribe') {
        $input  = json_decode(file_get_contents('php://input'), true);
        $plan   = isset($input['plan'])   ? trim($input['plan'])   : '';
        $method = isset($input['method']) ? trim($input['method']) : 'esewa';

        if (!array_key_exists($plan, $PLANS)) {
            echo json_encode(['success' => false, 'message' => 'Invalid plan selected.']);
            exit;
        }

        // Check for an already-active subscription
        $chk = $conn->prepare("SELECT id, plan FROM subscriptions WHERE user_id = ? AND status = 'active' AND end_date >= CURDATE() LIMIT 1");
        $chk->bind_param('i', $userId);
        $chk->execute();
        $existing = $chk->get_result()->fetch_assoc();
        if ($existing) {
            echo json_encode(['success' => false, 'message' => 'You already have an active ' . ucfirst($existing['plan']) . ' subscription.']);
            exit;
        }

        $amount    = $PLANS[$plan]['amount'];
        $days      = $PLANS[$plan]['days'];
        $startDate = date('Y-m-d');
        $endDate   = date('Y-m-d', strtotime("+{$days} days"));
        $txId      = 'SUB-' . strtoupper(bin2hex(random_bytes(6)));

        $stmt = $conn->prepare("
            INSERT INTO subscriptions (user_id, plan, amount, payment_method, status, start_date, end_date, transaction_id)
            VALUES (?, ?, ?, ?, 'active', ?, ?, ?)
        ");
        $stmt->bind_param('isdssss', $userId, $plan, $amount, $method, $startDate, $endDate, $txId);

        if ($stmt->execute()) {
            // Record in payments table so admin dashboard counts this as revenue
            $pmt = $conn->prepare("
                INSERT INTO payments (session_id, user_id, amount, rate_per_hour, method, status, transaction_id, paid_at)
                VALUES (NULL, ?, ?, 0.00, ?, 'paid', ?, NOW())
            ");
            $pmt->bind_param('idss', $userId, $amount, $method, $txId);
            $pmt->execute(); // best-effort; non-fatal

            echo json_encode([
                'success'        => true,
                'message'        => ucfirst($plan) . ' plan activated successfully!',
                'transaction_id' => $txId,
                'plan'           => $plan,
                'end_date'       => date('M d, Y', strtotime($endDate)),
                'amount'         => 'Rs. ' . number_format($amount, 0),
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Subscription failed. Please try again.']);
        }
        exit;
    }

    // ── CANCEL SUBSCRIPTION ───────────────────────────────
    if ($action === 'cancel') {
        $stmt = $conn->prepare("UPDATE subscriptions SET status = 'cancelled' WHERE user_id = ? AND status = 'active'");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        echo json_encode(['success' => true, 'message' => 'Subscription cancelled.']);
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
$userName  = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$userInit  = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1));

// Fetch active subscription
$stmt = $conn->prepare("
    SELECT id, plan, amount, payment_method, status, start_date, end_date, transaction_id, created_at
    FROM   subscriptions
    WHERE  user_id = ? AND status = 'active' AND end_date >= CURDATE()
    ORDER  BY created_at DESC LIMIT 1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$activeSub = $stmt->get_result()->fetch_assoc();

// Fetch subscription history (last 5)
$histStmt = $conn->prepare("
    SELECT plan, amount, payment_method, status, start_date, end_date, transaction_id, created_at
    FROM   subscriptions WHERE user_id = ?
    ORDER  BY created_at DESC LIMIT 5
");
$histStmt->bind_param('i', $userId);
$histStmt->execute();
$histResult = $histStmt->get_result();
$history = [];
while ($h = $histResult->fetch_assoc()) { $history[] = $h; }

$hasSub      = (bool) $activeSub;
$subPlan     = $hasSub ? $activeSub['plan']     : null;
$subAmount   = $hasSub ? $activeSub['amount']   : 0;
$subStart    = $hasSub ? date('M d, Y', strtotime($activeSub['start_date'])) : '—';
$subEnd      = $hasSub ? date('M d, Y', strtotime($activeSub['end_date']))   : '—';
$daysLeft    = $hasSub ? max(0, (int) ceil((strtotime($activeSub['end_date']) - time()) / 86400)) : 0;
$subMethod   = $hasSub ? $activeSub['payment_method'] : '—';
$subTxId     = $hasSub ? $activeSub['transaction_id'] : '—';

$conn->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Parkify — Subscriptions</title>
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

    /* ── MAIN LAYOUT ── */
    main {
      flex: 1; padding: 28px 32px; max-width: 1200px; margin: 0 auto; width: 100%;
      display: flex; flex-direction: column; gap: 24px;
      animation: fadeUp .5s ease both;
    }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

    /* ── PAGE HEADER ── */
    .page-header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .page-header h1 { font-family: "Space Grotesk", sans-serif; font-size: 24px; font-weight: 700; }
    .page-header p  { color: var(--text-muted); font-size: 14px; margin-top: 3px; }

    /* ── ACTIVE SUBSCRIPTION BANNER ── */
    .active-banner {
      background: linear-gradient(135deg, #0f1f3d 0%, #1e3a6e 100%);
      border-radius: var(--radius); padding: 28px 32px; color: #fff;
      display: flex; align-items: center; justify-content: space-between; gap: 20px; flex-wrap: wrap;
    }
    .banner-left { display: flex; align-items: center; gap: 20px; }
    .banner-icon {
      width: 60px; height: 60px; border-radius: 16px;
      display: flex; align-items: center; justify-content: center; font-size: 26px; flex-shrink: 0;
    }
    .banner-icon.basic   { background: rgba(245,158,11,.2); }
    .banner-icon.premium { background: rgba(37,99,235,.25); }
    .banner-icon.monthly { background: rgba(124,58,237,.2); }
    .banner-title { font-family: "Space Grotesk", sans-serif; font-size: 20px; font-weight: 700; }
    .banner-sub   { font-size: 13px; opacity: .75; margin-top: 3px; }
    .banner-meta  { display: flex; gap: 28px; flex-wrap: wrap; }
    .meta-item    { text-align: center; }
    .meta-label   { font-size: 11px; opacity: .6; text-transform: uppercase; letter-spacing: .5px; }
    .meta-value   { font-family: "Space Grotesk", sans-serif; font-size: 16px; font-weight: 700; margin-top: 3px; }
    .days-pill    {
      display: inline-flex; align-items: center; gap: 6px;
      background: rgba(34,197,94,.2); color: #86efac;
      padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;
    }
    .cancel-btn {
      background: rgba(239,68,68,.15); color: #fca5a5; border: 1px solid rgba(239,68,68,.3);
      padding: 9px 18px; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600;
      font-family: "DM Sans", sans-serif; transition: all .2s; white-space: nowrap;
    }
    .cancel-btn:hover { background: rgba(239,68,68,.28); }

    /* ── NO SUBSCRIPTION ── */
    .no-sub-notice {
      background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
      padding: 28px 32px; display: flex; align-items: center; gap: 18px;
    }
    .no-sub-icon { font-size: 36px; color: #cbd5e1; }
    .no-sub-text h3 { font-family: "Space Grotesk", sans-serif; font-size: 16px; font-weight: 600; }
    .no-sub-text p  { color: var(--text-muted); font-size: 14px; margin-top: 4px; }

    /* ── SECTION TITLE ── */
    .section-head { font-family: "Space Grotesk", sans-serif; font-size: 17px; font-weight: 700; margin-bottom: 4px; }
    .section-desc { color: var(--text-muted); font-size: 13px; }

    /* ── PLAN CARDS ── */
    .plans-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    @media (max-width: 860px) { .plans-grid { grid-template-columns: 1fr; } }

    .plan-card {
      background: var(--card); border: 2px solid var(--border); border-radius: var(--radius);
      padding: 28px 24px; position: relative; transition: all .25s; cursor: default;
    }
    .plan-card:hover { box-shadow: var(--shadow-lg); transform: translateY(-3px); }
    .plan-card.featured { border-color: var(--blue); box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
    .plan-card.disabled { opacity: .55; pointer-events: none; }

    .popular-badge {
      position: absolute; top: -12px; left: 50%; transform: translateX(-50%);
      background: linear-gradient(90deg, var(--blue), var(--blue-light));
      color: #fff; font-size: 11px; font-weight: 700; padding: 4px 14px;
      border-radius: 20px; white-space: nowrap; letter-spacing: .4px;
    }
    .plan-icon { width: 48px; height: 48px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 20px; margin-bottom: 16px; }
    .icon-basic   { background: var(--amber-bg);  color: var(--amber); }
    .icon-premium { background: #eff6ff;            color: var(--blue); }
    .icon-monthly { background: var(--purple-bg);  color: var(--purple); }

    .plan-name { font-family: "Space Grotesk", sans-serif; font-size: 18px; font-weight: 700; }
    .plan-hint { color: var(--text-muted); font-size: 13px; margin-top: 3px; margin-bottom: 18px; }
    .plan-price { display: flex; align-items: baseline; gap: 3px; margin-bottom: 6px; }
    .price-cur  { font-size: 15px; font-weight: 600; color: var(--text-muted); margin-top: 4px; }
    .price-num  { font-family: "Space Grotesk", sans-serif; font-size: 34px; font-weight: 800; }
    .price-freq { font-size: 14px; color: var(--text-muted); }
    .plan-divider { height: 1px; background: var(--border); margin: 18px 0; }
    .plan-features { list-style: none; display: flex; flex-direction: column; gap: 10px; margin-bottom: 24px; }
    .plan-features li { display: flex; align-items: center; gap: 10px; font-size: 13.5px; }
    .feat-ok   { color: var(--green); } .feat-no { color: #f43f5e; }
    .feat-no-text { color: var(--text-muted); }

    .subscribe-btn {
      width: 100%; padding: 14px; border: none; border-radius: 12px; cursor: pointer;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600;
      display: flex; align-items: center; justify-content: center; gap: 8px; transition: all .25s;
    }
    .btn-solid {
      background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff;
      box-shadow: 0 4px 16px rgba(37,99,235,.3);
    }
    .btn-solid:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(37,99,235,.4); }
    .btn-outline-plan {
      background: #fff; color: var(--navy); border: 2px solid var(--border);
    }
    .btn-outline-plan:hover { border-color: var(--blue); color: var(--blue); background: #f0f7ff; }
    .subscribe-btn:disabled { opacity: .5; cursor: not-allowed; transform: none !important; }

    /* ── HISTORY TABLE ── */
    .history-card { background: var(--card); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; }
    .history-header { padding: 20px 24px; border-bottom: 1px solid var(--border); }
    .history-table { width: 100%; border-collapse: collapse; }
    .history-table th { background: #f8fafc; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; color: var(--text-muted); padding: 12px 16px; text-align: left; }
    .history-table td { padding: 13px 16px; font-size: 13.5px; border-top: 1px solid #f1f5f9; }
    .history-table tr:hover td { background: #fafbff; }
    .plan-badge {
      display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px;
      border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
    }
    .badge-basic   { background: var(--amber-bg);  color: #92400e; }
    .badge-premium { background: #eff6ff;            color: #1d4ed8; }
    .badge-monthly { background: var(--purple-bg);  color: #5b21b6; }
    .status-badge  { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .s-active    { background: var(--green-bg);  color: #15803d; }
    .s-expired   { background: #f1f5f9;           color: var(--text-muted); }
    .s-cancelled { background: var(--red-bg);     color: #b91c1c; }
    .tx-code { font-family: "Space Grotesk", sans-serif; font-size: 12px; color: var(--text-muted); }
    .empty-history { text-align: center; padding: 40px; color: var(--text-muted); font-size: 14px; }
    .empty-history i { font-size: 32px; color: #cbd5e1; display: block; margin-bottom: 10px; }

    /* ── PLAN SELECTION MODAL ── */
    .modal-overlay {
      position: fixed; inset: 0; background: rgba(15,31,61,.55); backdrop-filter: blur(4px);
      z-index: 500; display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .25s;
    }
    .modal-overlay.active { opacity: 1; pointer-events: all; }
    .modal {
      background: #fff; border-radius: var(--radius); width: 100%; max-width: 480px;
      box-shadow: 0 24px 80px rgba(0,0,0,.2); padding: 32px;
      transform: translateY(20px); transition: transform .3s ease;
    }
    .modal-overlay.active .modal { transform: translateY(0); }
    .modal-close {
      position: absolute; top: 16px; right: 18px; background: none; border: none;
      font-size: 22px; color: var(--text-muted); cursor: pointer; line-height: 1;
    }
    .modal-header { position: relative; margin-bottom: 20px; }
    .modal-icon { width: 52px; height: 52px; border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 22px; margin-bottom: 12px; }
    .modal-title { font-family: "Space Grotesk", sans-serif; font-size: 20px; font-weight: 700; }
    .modal-sub   { font-size: 13px; color: var(--text-muted); margin-top: 3px; }
    .modal-price-row { display: flex; align-items: center; justify-content: space-between; background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 20px; }
    .modal-price-label { font-size: 13px; color: var(--text-muted); }
    .modal-price-val   { font-family: "Space Grotesk", sans-serif; font-size: 22px; font-weight: 800; color: var(--blue); }

    .pay-methods-label { font-family: "Space Grotesk", sans-serif; font-size: 13px; font-weight: 600; margin-bottom: 10px; color: var(--text); }
    .pay-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
    .pay-opt {
      border: 2px solid var(--border); border-radius: var(--radius-sm);
      padding: 12px 10px; background: #fff; cursor: pointer;
      display: flex; align-items: center; gap: 8px; transition: all .18s;
      font-size: 13px; font-weight: 600; font-family: "DM Sans", sans-serif; color: var(--text);
    }
    .pay-opt:hover { border-color: var(--blue); background: #f0f7ff; color: var(--blue); }
    .pay-opt.active { border-color: var(--blue); background: #eff6ff; color: var(--blue); }
    .pay-icon { width: 30px; height: 30px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; flex-shrink: 0; }
    .pi-stripe { background: #ede9fe; color: #6772e5; }
    .pi-esewa  { background: #dcfce7; color: #16a34a; }
    .pi-khalti { background: var(--purple-bg); color: var(--purple); }

    .confirm-btn {
      width: 100%; padding: 15px; border: none; border-radius: 12px; cursor: pointer;
      background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      box-shadow: 0 4px 16px rgba(37,99,235,.3); transition: all .25s;
    }
    .confirm-btn:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(37,99,235,.45); }
    .confirm-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; }
    .modal-secure { text-align: center; font-size: 12px; color: var(--text-muted); margin-top: 12px; display: flex; align-items: center; justify-content: center; gap: 5px; }

    /* ── STRIPE PAYMENT MODAL ── */
    .stripe-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(15,31,61,.55); backdrop-filter: blur(4px);
      z-index: 600; align-items: center; justify-content: center;
    }
    .stripe-overlay.open { display: flex; }
    .stripe-modal {
      background: #fff; border-radius: 20px; width: 460px; max-width: calc(100vw - 32px);
      box-shadow: 0 24px 80px rgba(15,31,61,.22); overflow: hidden;
      animation: modalIn .28s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes modalIn { from { opacity:0; transform:translateY(24px) scale(.97); } to { opacity:1; transform:none; } }
    .smodal-header {
      background: linear-gradient(135deg, var(--navy) 0%, #1e3a6e 100%);
      padding: 22px 28px; display: flex; align-items: center; justify-content: space-between;
    }
    .smodal-header-left { display: flex; align-items: center; gap: 12px; }
    .smodal-logo {
      width: 42px; height: 42px; background: rgba(255,255,255,.12); border-radius: 12px;
      display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff;
    }
    .smodal-title    { color: #fff; font-family: "Space Grotesk", sans-serif; font-size: 17px; font-weight: 700; }
    .smodal-subtitle { color: rgba(255,255,255,.65); font-size: 12px; margin-top: 2px; }
    .smodal-close {
      width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,.1); border: none;
      color: rgba(255,255,255,.8); font-size: 16px; cursor: pointer;
      display: flex; align-items: center; justify-content: center; transition: background .2s;
    }
    .smodal-close:hover { background: rgba(255,255,255,.2); }
    .smodal-body { padding: 24px 28px; }
    .smodal-amount-row {
      display: flex; align-items: center; justify-content: space-between;
      background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border: 1px solid #bae6fd;
      border-radius: 12px; padding: 14px 18px; margin-bottom: 22px;
    }
    .smodal-amount-label { font-size: 13px; color: #0369a1; font-weight: 500; }
    .smodal-amount-sub   { font-size: 11px; color: #0284c7; margin-top: 2px; }
    .smodal-amount-value { font-family: "Space Grotesk",sans-serif; font-size: 24px; font-weight: 800; color: var(--navy); }
    .smodal-amount-usd   { font-size: 11px; color: var(--text-muted); text-align: right; margin-top: 2px; }

    .stripe-field-label {
      font-size: 13px; font-weight: 600; color: var(--text); margin-bottom: 8px;
      display: flex; align-items: center; gap: 6px;
    }
    .stripe-field-label i { color: #6772e5; font-size: 12px; }
    #stripe-card-element {
      border: 1.5px solid var(--border); border-radius: 10px; padding: 13px 14px;
      background: #fafbfc; transition: border-color .2s, box-shadow .2s;
    }
    #stripe-card-element.focused {
      border-color: #6772e5; box-shadow: 0 0 0 3px rgba(103,114,229,.15);
    }
    #stripe-card-errors {
      color: var(--red); font-size: 12px; margin-top: 8px; min-height: 18px;
      display: flex; align-items: center; gap: 6px;
    }
    #stripe-card-errors:not(:empty)::before { content: '\f06a'; font-family: "Font Awesome 6 Free"; font-weight: 900; }
    .stripe-test-hint {
      background: #fef9c3; border: 1px solid #fde047; border-radius: 10px;
      padding: 10px 14px; margin: 14px 0 0; font-size: 12px; color: #854d0e;
      display: flex; align-items: flex-start; gap: 8px; line-height: 1.5;
    }
    .stripe-test-hint i { color: #ca8a04; margin-top: 1px; flex-shrink: 0; }
    .stripe-test-hint code {
      background: rgba(0,0,0,.06); border-radius: 4px; padding: 1px 5px;
      font-family: monospace; font-size: 12px;
    }
    .smodal-footer { padding: 0 28px 24px; display: flex; flex-direction: column; gap: 10px; }
    .stripe-pay-btn {
      width: 100%; background: linear-gradient(135deg, #6772e5 0%, #8b93f0 100%);
      color: #fff; border: none; border-radius: 12px; padding: 15px;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 700;
      cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;
      transition: all .25s; box-shadow: 0 4px 16px rgba(103,114,229,.4); letter-spacing: .3px;
    }
    .stripe-pay-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(103,114,229,.55); }
    .stripe-pay-btn:disabled { opacity: .65; cursor: not-allowed; transform: none; }
    .stripe-pay-btn.success { background: linear-gradient(135deg, var(--green), #16a34a); box-shadow: 0 4px 16px rgba(34,197,94,.4); }
    .smodal-cancel-btn {
      width: 100%; background: none; border: 1.5px solid var(--border); border-radius: 12px;
      padding: 12px; font-size: 14px; color: var(--text-muted); cursor: pointer;
      font-family: "DM Sans", sans-serif; transition: all .2s;
    }
    .smodal-cancel-btn:hover { border-color: var(--text-muted); color: var(--text); }
    .stripe-badge {
      display: flex; align-items: center; justify-content: center; gap: 6px;
      font-size: 11px; color: #9ca3af; margin-top: 4px;
    }
    .stripe-badge i { font-size: 10px; color: #6772e5; }

    /* ── SUCCESS OVERLAY ── */
    .success-overlay {
      position: fixed; inset: 0; background: rgba(15,31,61,.6); backdrop-filter: blur(6px);
      z-index: 700; display: flex; align-items: center; justify-content: center; padding: 20px;
      opacity: 0; pointer-events: none; transition: opacity .3s;
    }
    .success-overlay.active { opacity: 1; pointer-events: all; }
    .success-card {
      background: #fff; border-radius: var(--radius); max-width: 400px; width: 100%;
      padding: 40px 32px; text-align: center;
      transform: scale(.85); transition: transform .35s ease;
    }
    .success-overlay.active .success-card { transform: scale(1); }
    .success-check { font-size: 54px; color: var(--green); margin-bottom: 16px; }
    .success-title { font-family: "Space Grotesk", sans-serif; font-size: 22px; font-weight: 800; margin-bottom: 6px; }
    .success-msg   { font-size: 14px; color: var(--text-muted); margin-bottom: 24px; }
    .success-detail { background: #f8fafc; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 16px; text-align: left; margin-bottom: 24px; }
    .sd-row { display: flex; justify-content: space-between; padding: 5px 0; font-size: 13px; }
    .sd-key { color: var(--text-muted); }
    .sd-val { font-weight: 600; font-family: "Space Grotesk", sans-serif; font-size: 13px; }
    .success-done-btn {
      width: 100%; padding: 14px; border: none; border-radius: 12px; cursor: pointer;
      background: linear-gradient(135deg, var(--blue), var(--blue-light)); color: #fff;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600; transition: all .25s;
    }
    .success-done-btn:hover { transform: translateY(-2px); }

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

<!-- ── NAV ──────────────────────────────────────────────────── -->
<?php include 'nav.php'; ?>

<!-- ── MAIN ──────────────────────────────────────────────────── -->
<main>

  <!-- Page Header -->
  <div class="page-header">
    <div>
      <h1><i class="fa-solid fa-crown" style="color:var(--amber);margin-right:8px;font-size:20px"></i>Subscriptions</h1>
      <p>Manage your parking plan and billing</p>
    </div>
  </div>

  <!-- Active Subscription Banner / No-Sub Notice -->
  <?php if ($hasSub): ?>
  <div class="active-banner" id="active-banner">
    <div class="banner-left">
      <div class="banner-icon <?= $subPlan ?>">
        <?php
          $icons = ['basic' => '⚡', 'premium' => '🚀', 'monthly' => '💎'];
          echo $icons[$subPlan] ?? '🎫';
        ?>
      </div>
      <div>
        <div class="banner-title"><?= ucfirst($subPlan) ?> Plan — Active</div>
        <div class="banner-sub">via <?= ucfirst($subMethod) ?> &nbsp;·&nbsp; Tx: <?= htmlspecialchars($subTxId) ?></div>
        <div class="days-pill" style="margin-top:8px">
          <i class="fa-solid fa-circle" style="font-size:7px"></i>
          <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> remaining
        </div>
      </div>
    </div>
    <div class="banner-meta">
      <div class="meta-item">
        <div class="meta-label">Amount Paid</div>
        <div class="meta-value">Rs. <?= number_format($subAmount, 0) ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Start Date</div>
        <div class="meta-value"><?= $subStart ?></div>
      </div>
      <div class="meta-item">
        <div class="meta-label">Expires</div>
        <div class="meta-value"><?= $subEnd ?></div>
      </div>
    </div>
    <button class="cancel-btn" onclick="cancelSubscription()">
      <i class="fa-solid fa-xmark"></i> Cancel Plan
    </button>
  </div>
  <?php else: ?>
  <div class="no-sub-notice" id="no-sub-notice">
    <div class="no-sub-icon"><i class="fa-solid fa-circle-info"></i></div>
    <div class="no-sub-text">
      <h3>No active subscription</h3>
      <p>Choose a plan below to enjoy priority slots, extended parking hours, and more.</p>
    </div>
  </div>
  <?php endif; ?>

  <!-- Plans -->
  <div>
    <div class="section-head">Available Plans</div>
    <div class="section-desc" style="margin-bottom:20px">Pick what fits your parking lifestyle</div>
    <div class="plans-grid">

      <!-- Basic -->
      <div class="plan-card <?= ($hasSub && $subPlan === 'basic') ? 'disabled' : '' ?>">
        <div class="plan-icon icon-basic"><i class="fa-solid fa-bolt"></i></div>
        <div class="plan-name">Basic</div>
        <div class="plan-hint">For occasional parkers</div>
        <div class="plan-price">
          <span class="price-cur">Rs.</span>
          <span class="price-num">50</span>
          <span class="price-freq">/day</span>
        </div>
        <div class="plan-divider"></div>
        <ul class="plan-features">
          <li><i class="fa-solid fa-check feat-ok"></i> Up to 2 hours parking</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Standard support</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Online payment</li>
          <li><i class="fa-solid fa-xmark feat-no"></i> <span class="feat-no-text">Priority slot access</span></li>
          <li><i class="fa-solid fa-xmark feat-no"></i> <span class="feat-no-text">Monthly reports</span></li>
        </ul>
        <button class="subscribe-btn btn-outline-plan" onclick="openModal('basic')"
          <?= $hasSub ? 'disabled' : '' ?>>
          <?= $hasSub ? '<i class="fa-solid fa-lock"></i> Plan Active' : 'Get Started' ?>
        </button>
      </div>

      <!-- Premium -->
      <div class="plan-card featured <?= ($hasSub && $subPlan === 'premium') ? 'disabled' : '' ?>">
        <div class="popular-badge">⭐ Most Popular</div>
        <div class="plan-icon icon-premium"><i class="fa-solid fa-rocket"></i></div>
        <div class="plan-name">Premium</div>
        <div class="plan-hint">For frequent parkers</div>
        <div class="plan-price">
          <span class="price-cur">Rs.</span>
          <span class="price-num">100</span>
          <span class="price-freq">/day</span>
        </div>
        <div class="plan-divider"></div>
        <ul class="plan-features">
          <li><i class="fa-solid fa-check feat-ok"></i> Up to 12 hours parking</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Priority support</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Online payment</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Digital receipt</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Priority slot access</li>
        </ul>
        <button class="subscribe-btn btn-solid" onclick="openModal('premium')"
          <?= $hasSub ? 'disabled' : '' ?>>
          <?= $hasSub ? '<i class="fa-solid fa-lock"></i> Plan Active' : 'Get Started <i class="fa-solid fa-arrow-right fa-xs"></i>' ?>
        </button>
      </div>

      <!-- Monthly -->
      <div class="plan-card <?= ($hasSub && $subPlan === 'monthly') ? 'disabled' : '' ?>">
        <div class="plan-icon icon-monthly"><i class="fa-solid fa-gem"></i></div>
        <div class="plan-name">Monthly</div>
        <div class="plan-hint">Best value for regulars</div>
        <div class="plan-price">
          <span class="price-cur">Rs.</span>
          <span class="price-num">1,500</span>
          <span class="price-freq">/mo</span>
        </div>
        <div class="plan-divider"></div>
        <ul class="plan-features">
          <li><i class="fa-solid fa-check feat-ok"></i> Unlimited parking</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Priority support</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Online payment</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Digital receipt</li>
          <li><i class="fa-solid fa-check feat-ok"></i> Monthly reports</li>
        </ul>
        <button class="subscribe-btn btn-outline-plan" onclick="openModal('monthly')"
          <?= $hasSub ? 'disabled' : '' ?>>
          <?= $hasSub ? '<i class="fa-solid fa-lock"></i> Plan Active' : 'Get Started' ?>
        </button>
      </div>

    </div>
  </div>

  <!-- Subscription History -->
  <div class="history-card">
    <div class="history-header">
      <div class="section-head" style="margin-bottom:0">Subscription History</div>
    </div>
    <table class="history-table">
      <thead>
        <tr>
          <th>Plan</th>
          <th>Amount</th>
          <th>Payment Method</th>
          <th>Start</th>
          <th>End</th>
          <th>Status</th>
          <th>Transaction ID</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
        <tr><td colspan="7" class="empty-history">
          <i class="fa-solid fa-clock-rotate-left"></i>
          No subscription history yet
        </td></tr>
        <?php else: foreach ($history as $h): ?>
        <tr>
          <td>
            <span class="plan-badge badge-<?= $h['plan'] ?>">
              <?= ucfirst($h['plan']) ?>
            </span>
          </td>
          <td>Rs. <?= number_format($h['amount'], 0) ?></td>
          <td><?= ucfirst($h['payment_method']) ?></td>
          <td><?= date('M d, Y', strtotime($h['start_date'])) ?></td>
          <td><?= date('M d, Y', strtotime($h['end_date'])) ?></td>
          <td>
            <span class="status-badge s-<?= $h['status'] ?>">
              <i class="fa-solid fa-circle" style="font-size:7px"></i>
              <?= ucfirst($h['status']) ?>
            </span>
          </td>
          <td><span class="tx-code"><?= htmlspecialchars($h['transaction_id']) ?></span></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

</main>

<!-- FOOTER -->
<footer>
  <span><i class="fa-solid fa-lock"></i> Your subscription data is secure and encrypted.</span>
  <span><i class="fa-solid fa-copyright"></i> <?= date('Y') ?> Parkify System. All rights reserved.</span>
</footer>

<!-- TOAST -->
<div id="toast"><span id="toast-msg"></span></div>

<!-- ── PLAN SELECTION MODAL ── -->
<div class="modal-overlay" id="modal-overlay" onclick="handleOverlayClick(event)">
  <div class="modal" id="modal">
    <div class="modal-header">
      <button class="modal-close" onclick="closeModal()">&times;</button>
      <div class="modal-icon" id="modal-icon"></div>
      <div class="modal-title" id="modal-title"></div>
      <div class="modal-sub"   id="modal-sub"></div>
    </div>

    <div class="modal-price-row">
      <div>
        <div class="modal-price-label">Amount due</div>
        <div class="modal-price-val" id="modal-price"></div>
      </div>
      <div style="text-align:right">
        <div class="modal-price-label">Duration</div>
        <div style="font-family:'Space Grotesk',sans-serif;font-size:14px;font-weight:700" id="modal-duration"></div>
      </div>
    </div>

    <div class="pay-methods-label">Choose Payment Method</div>
    <div class="pay-grid">
      <button class="pay-opt" onclick="selectPayment(this,'stripe')">
        <div class="pay-icon pi-stripe"><i class="fa-brands fa-stripe-s"></i></div> Stripe
      </button>
      <button class="pay-opt" onclick="selectPayment(this,'esewa')">
        <div class="pay-icon pi-esewa"><i class="fa-solid fa-mobile-screen-button"></i></div> eSewa
      </button>
      <button class="pay-opt" onclick="selectPayment(this,'khalti')">
        <div class="pay-icon pi-khalti"><i class="fa-solid fa-wallet"></i></div> Khalti
      </button>
    </div>

    <button class="confirm-btn" id="confirm-btn" onclick="confirmSubscription()">
      <i class="fa-solid fa-lock"></i> Confirm &amp; Pay
    </button>
    <div class="modal-secure">
      <i class="fa-solid fa-shield-halved" style="color:var(--green)"></i>
      256-bit encrypted &nbsp;·&nbsp; Instant digital receipt
    </div>
  </div>
</div>

<!-- ── STRIPE PAYMENT MODAL ── -->
<div class="stripe-overlay" id="stripe-modal-overlay">
  <div class="stripe-modal" role="dialog" aria-modal="true" aria-label="Stripe Payment">

    <!-- Header -->
    <div class="smodal-header">
      <div class="smodal-header-left">
        <div class="smodal-logo"><i class="fa-brands fa-stripe-s"></i></div>
        <div>
          <div class="smodal-title">Pay with Card</div>
          <div class="smodal-subtitle">Secured by Stripe · Test Mode</div>
        </div>
      </div>
      <button class="smodal-close" id="stripe-close-btn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- Body -->
    <div class="smodal-body">
      <!-- Amount summary -->
      <div class="smodal-amount-row">
        <div>
          <div class="smodal-amount-label"><i class="fa-solid fa-crown" style="margin-right:4px;color:#0369a1"></i> Subscription Fee</div>
          <div class="smodal-amount-sub" id="stripe-plan-label">Loading…</div>
        </div>
        <div style="text-align:right">
          <div class="smodal-amount-value" id="stripe-amount-npr">Rs. —</div>
          <div class="smodal-amount-usd" id="stripe-amount-usd"></div>
        </div>
      </div>

      <!-- Stripe Card Element -->
      <div class="stripe-field-label"><i class="fa-brands fa-cc-visa"></i> Card Details</div>
      <div id="stripe-card-element"></div>
      <div id="stripe-card-errors" role="alert"></div>

      <!-- Test hint -->
      <div class="stripe-test-hint">
        <i class="fa-solid fa-flask"></i>
        <div>
          <strong>Test Mode:</strong> Use card <code>4242 4242 4242 4242</code>, any future date, any 3-digit CVC, any ZIP.
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div class="smodal-footer">
      <button class="stripe-pay-btn" id="stripe-submit-btn" disabled>
        <i class="fa-brands fa-stripe-s"></i>
        <span id="stripe-submit-label">Pay Now</span>
      </button>
      <button class="smodal-cancel-btn" id="stripe-cancel-btn">Cancel</button>
      <div class="stripe-badge"><i class="fa-solid fa-lock"></i> End-to-end encrypted · Powered by Stripe</div>
    </div>

  </div>
</div>

<!-- ── SUCCESS OVERLAY ── -->
<div class="success-overlay" id="success-overlay">
  <div class="success-card">
    <div class="success-check"><i class="fa-solid fa-circle-check"></i></div>
    <div class="success-title">Subscription Activated!</div>
    <div class="success-msg" id="success-msg">Your plan is now active and ready to use.</div>
    <div class="success-detail" id="success-detail"></div>
    <button class="success-done-btn" onclick="closeSuccess()">
      <i class="fa-solid fa-arrow-right"></i> Continue
    </button>
  </div>
</div>

<!-- Stripe.js (loaded async, initialized when modal opens) -->
<script src="https://js.stripe.com/v3/" async></script>

<script>
  const PLANS = {
    basic:   { label:'Basic',   price:'Rs. 50',    priceNum:50,   duration:'1 Day',   icon:'⚡', iconClass:'icon-basic',   color:'#f59e0b' },
    premium: { label:'Premium', price:'Rs. 100',   priceNum:100,  duration:'1 Day',   icon:'🚀', iconClass:'icon-premium', color:'#2563eb' },
    monthly: { label:'Monthly', price:'Rs. 1,500', priceNum:1500, duration:'30 Days', icon:'💎', iconClass:'icon-monthly', color:'#7c3aed' },
  };

  let currentPlan    = null;
  let selectedMethod = null;

  // Stripe instances (lazy-initialized when Stripe modal opens)
  let stripeInstance = null;
  let stripeElements = null;
  let stripeCard     = null;
  let currentPI      = null; // { client_secret, payment_intent_id, amount_npr, amount_cents, plan }

  // ══════════════════════════════════════════════════════════
  //  PLAN SELECTION MODAL
  // ══════════════════════════════════════════════════════════
  function openModal(plan) {
    currentPlan    = plan;
    selectedMethod = null;
    const p = PLANS[plan];

    document.getElementById('modal-icon').className = 'modal-icon ' + p.iconClass;
    document.getElementById('modal-icon').textContent = p.icon;
    document.getElementById('modal-title').textContent = p.label + ' Plan';
    document.getElementById('modal-sub').textContent   = 'Select a payment method to continue.';
    document.getElementById('modal-price').textContent = p.price;
    document.getElementById('modal-duration').textContent = p.duration;

    document.querySelectorAll('.pay-opt').forEach(b => b.classList.remove('active'));
    document.getElementById('confirm-btn').disabled = true;
    document.getElementById('modal-overlay').classList.add('active');
    document.body.style.overflow = 'hidden';
  }

  function closeModal() {
    document.getElementById('modal-overlay').classList.remove('active');
    document.body.style.overflow = '';
  }

  function handleOverlayClick(e) {
    if (e.target === document.getElementById('modal-overlay')) closeModal();
  }

  function selectPayment(btn, method) {
    document.querySelectorAll('.pay-opt').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    selectedMethod = method;
    document.getElementById('confirm-btn').disabled = false;
  }

  // ══════════════════════════════════════════════════════════
  //  PAYMENT DISPATCHER
  // ══════════════════════════════════════════════════════════
  async function confirmSubscription() {
    if (!selectedMethod || !currentPlan) return;

    if (selectedMethod === 'stripe') {
      // Stripe: close plan modal → open Stripe card modal
      closeModal();
      await openStripeModal(currentPlan);
    } else {
      // eSewa / Khalti: simulated — call subscribe directly
      await subscribeSimulated(currentPlan, selectedMethod);
    }
  }

  // ── Simulated payment (eSewa / Khalti) ─────────────────────
  async function subscribeSimulated(plan, method) {
    const btn = document.getElementById('confirm-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

    try {
      const res  = await fetch('subscriptions.php?action=subscribe', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ plan, method }),
      });
      const data = await res.json();

      if (data.success) {
        closeModal();
        showSuccess(data, method);
      } else {
        showToast('error', data.message || 'Subscription failed. Try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-lock"></i> Confirm &amp; Pay';
      }
    } catch (e) {
      showToast('error', 'Network error. Please try again.');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-lock"></i> Confirm &amp; Pay';
    }
  }

  // ══════════════════════════════════════════════════════════
  //  STRIPE MODAL
  // ══════════════════════════════════════════════════════════
  function initStripeElements() {
    if (stripeCard) return true; // already initialized

    const pubKey = '<?= STRIPE_PUBLISHABLE_KEY ?>';
    if (!window.Stripe || pubKey.includes('REPLACE')) {
      showToast('error', 'Stripe not configured. Add your publishable key.');
      return false;
    }

    stripeInstance = Stripe(pubKey);
    stripeElements = stripeInstance.elements({
      fonts: [{ cssSrc: 'https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500&display=swap' }],
    });

    stripeCard = stripeElements.create('card', {
      style: {
        base: {
          fontFamily: '"DM Sans", sans-serif', fontSize: '15px',
          color: '#1e293b', fontWeight: '500',
          '::placeholder': { color: '#94a3b8' },
        },
        invalid: { color: '#ef4444' },
      },
      hidePostalCode: false,
    });

    stripeCard.mount('#stripe-card-element');

    stripeCard.on('focus', () => document.getElementById('stripe-card-element').classList.add('focused'));
    stripeCard.on('blur',  () => document.getElementById('stripe-card-element').classList.remove('focused'));
    stripeCard.on('change', e => {
      document.getElementById('stripe-card-errors').textContent = e.error ? e.error.message : '';
    });

    return true;
  }

  // Open Stripe modal: request a PaymentIntent from server, then mount card
  async function openStripeModal(plan) {
    const overlay   = document.getElementById('stripe-modal-overlay');
    const submitBtn = document.getElementById('stripe-submit-btn');

    overlay.classList.add('open');
    submitBtn.disabled = true;
    document.getElementById('stripe-submit-label').textContent = 'Preparing…';
    document.getElementById('stripe-plan-label').textContent   = 'Calculating…';
    document.getElementById('stripe-amount-npr').textContent   = 'Rs. —';
    document.getElementById('stripe-amount-usd').textContent   = '';

    try {
      const res  = await fetch('subscriptions.php?action=stripe_create_intent_sub', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ plan }),
      });
      const data = await res.json();

      if (!data.success) {
        closeStripeModal();
        showToast('error', data.message || 'Could not initialize payment.');
        return;
      }

      currentPI = data;
      const p = PLANS[plan];

      document.getElementById('stripe-plan-label').textContent = p.label + ' Plan — ' + p.duration;
      document.getElementById('stripe-amount-npr').textContent = data.amount_display;
      document.getElementById('stripe-amount-usd').textContent = `~$${(data.amount_cents / 100).toFixed(2)} USD charged to card`;

      if (!initStripeElements()) {
        closeStripeModal();
        return;
      }

      document.getElementById('stripe-submit-label').textContent = 'Pay Now';
      submitBtn.disabled = false;

    } catch (e) {
      closeStripeModal();
      showToast('error', 'Network error initializing payment.');
    }
  }

  function closeStripeModal() {
    document.getElementById('stripe-modal-overlay').classList.remove('open');
  }

  // Submit Stripe payment
  async function submitStripePayment() {
    if (!stripeInstance || !stripeCard || !currentPI) return;

    const submitBtn = document.getElementById('stripe-submit-btn');
    const errEl     = document.getElementById('stripe-card-errors');

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Processing…</span>';
    errEl.textContent   = '';

    try {
      // 1. Confirm card payment with Stripe.js
      const { paymentIntent, error } = await stripeInstance.confirmCardPayment(
        currentPI.client_secret,
        { payment_method: { card: stripeCard } }
      );

      if (error) {
        errEl.textContent = error.message;
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-brands fa-stripe-s"></i> <span id="stripe-submit-label">Pay Now</span>';
        return;
      }

      if (paymentIntent.status !== 'succeeded') {
        errEl.textContent = `Payment status: ${paymentIntent.status}. Please try again.`;
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-brands fa-stripe-s"></i> <span id="stripe-submit-label">Pay Now</span>';
        return;
      }

      // 2. Stripe confirmed → tell server to activate subscription & record payment
      const cfRes  = await fetch('subscriptions.php?action=stripe_checkout_sub', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          plan:              currentPI.plan,
          payment_intent_id: currentPI.payment_intent_id,
        }),
      });
      const cfData = await cfRes.json();

      if (cfData.success) {
        // Animate success in Stripe modal, then show success overlay
        submitBtn.classList.add('success');
        submitBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> <span>Paid — ' + cfData.amount + '</span>';

        setTimeout(() => {
          closeStripeModal();
          showSuccess(cfData, 'stripe');
        }, 1200);

      } else {
        errEl.textContent = cfData.message || 'Server error confirming payment.';
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fa-brands fa-stripe-s"></i> <span id="stripe-submit-label">Pay Now</span>';
      }

    } catch (e) {
      errEl.textContent = 'Network error. Please try again.';
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa-brands fa-stripe-s"></i> <span id="stripe-submit-label">Pay Now</span>';
    }
  }

  // ══════════════════════════════════════════════════════════
  //  SUCCESS OVERLAY
  // ══════════════════════════════════════════════════════════
  function showSuccess(data, method) {
    const p = PLANS[data.plan];
    const methodLabel = method === 'stripe' ? 'Stripe (Card)' : method.charAt(0).toUpperCase() + method.slice(1);
    document.getElementById('success-msg').textContent = p.label + ' plan is now active until ' + data.end_date + '.';
    document.getElementById('success-detail').innerHTML = `
      <div class="sd-row"><span class="sd-key">Plan</span><span class="sd-val">${p.label}</span></div>
      <div class="sd-row"><span class="sd-key">Amount Paid</span><span class="sd-val">${data.amount}</span></div>
      <div class="sd-row"><span class="sd-key">Payment Method</span><span class="sd-val">${methodLabel}</span></div>
      <div class="sd-row"><span class="sd-key">Expires</span><span class="sd-val">${data.end_date}</span></div>
      <div class="sd-row"><span class="sd-key">Transaction ID</span><span class="sd-val" style="font-size:11px">${data.transaction_id}</span></div>
    `;
    document.getElementById('success-overlay').classList.add('active');
  }

  function closeSuccess() {
    document.getElementById('success-overlay').classList.remove('active');
    location.reload();
  }

  // ── Cancel subscription ───────────────────────────────────
  async function cancelSubscription() {
    if (!confirm('Are you sure you want to cancel your current subscription?')) return;
    try {
      const res  = await fetch('subscriptions.php?action=cancel', { method: 'POST' });
      const data = await res.json();
      if (data.success) {
        showToast('success', 'Subscription cancelled successfully.');
        setTimeout(() => location.reload(), 1200);
      } else {
        showToast('error', data.message || 'Cancellation failed.');
      }
    } catch (e) {
      showToast('error', 'Network error.');
    }
  }

  // ── Logout ────────────────────────────────────────────────
  async function handleLogout() {
    await fetch('subscriptions.php?action=logout');
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

  // ── Boot ──────────────────────────────────────────────────
  window.addEventListener('DOMContentLoaded', () => {
    document.getElementById('stripe-close-btn').addEventListener('click', closeStripeModal);
    document.getElementById('stripe-cancel-btn').addEventListener('click', closeStripeModal);
    document.getElementById('stripe-modal-overlay').addEventListener('click', e => {
      if (e.target === e.currentTarget) closeStripeModal();
    });
    document.getElementById('stripe-submit-btn').addEventListener('click', submitStripePayment);

    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeModal(); closeStripeModal(); }
    });
  });
</script>
</body>
</html>