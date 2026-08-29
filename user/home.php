<?php
$stripeSecretKey = getenv('STRIPE_SECRET_KEY');
define('STRIPE_PUBLISHABLE_KEY', 'pk_test_51Te4mtPn2TE0KpLMD1qXQyAcrXxCGmhTL0Yv5pXslpJWV0EPcHhUKNjv2EwmTurXRMuahVtCLsc6QN8bZoalvI9i00qtTL8oFo');
// NOTE: Stripe does not support NPR. Amounts are sent in USD cents for the
// PaymentIntent. The UI always displays NPR. For production, use eSewa/Khalti.
define('NPR_TO_USD_RATE', 133.0); // approximate — update as needed
define('STRIPE_MIN_CENTS', 50);   // Stripe minimum is $0.50

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// ── Auth Guard ──────────────────────────────────────────────
// If not logged in, kick back to login page
if (empty($_SESSION['user_id'])) {
    // Handle AJAX requests separately
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

// ── Active subscription check (reused in AJAX + page load) ──
function getActiveSub(mysqli $db, int $uid): ?array {
    // Check the subscriptions table (created by subscriptions.php)
    $tableCheck = $db->query("SHOW TABLES LIKE 'subscriptions'");
    if (!$tableCheck || $tableCheck->num_rows === 0) return null;

    $s = $db->prepare("
        SELECT plan FROM subscriptions
        WHERE  user_id = ? AND status = 'active' AND end_date >= CURDATE()
        ORDER  BY created_at DESC LIMIT 1
    ");
    $s->bind_param('i', $uid);
    $s->execute();
    $row = $s->get_result()->fetch_assoc();
    return $row ?: null;
}

// ── Parking fee — billed in 30-min blocks, minimum 1 hour ──
// Rules:
//   • First hour always charged in full (Rs. rate)
//   • Every additional fraction of 30 min = rate/2
//   • e.g. 1h15m → 3 half-blocks → 1.5× rate
//          1h35m → 4 half-blocks → 2× rate
function calcFee(float $durationMins, float $rate): float {
    $halfBlocks = (int) max(2, ceil($durationMins / 30));
    return round($halfBlocks * ($rate / 2), 2);
}

// ============================================================
//  AJAX API ENDPOINTS
// ============================================================
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // ── GET STATUS: active session + fee ─────────────────────
    if ($action === 'get_status') {
        $stmt = $conn->prepare("
            SELECT
                ps.id           AS session_id,
                COALESCE(NULLIF(ps.plate_number,''), v.plate_number) AS plate_number,
                ps.entry_time,
                ps.status       AS session_status,
                sl.slot_code,
                sl.id           AS slot_id,
                v.make,
                v.model,
                v.color,
                COALESCE(py.rate_per_hour, 20.00) AS rate_per_hour
            FROM   parking_sessions ps
            LEFT JOIN parking_slots    sl ON sl.id = ps.slot_id
            LEFT JOIN vehicles         v  ON v.id  = ps.vehicle_id
            LEFT JOIN payments         py ON py.session_id = ps.id
            WHERE  ps.user_id = ? AND ps.status = 'active'
            ORDER  BY ps.entry_time DESC
            LIMIT  1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();

        if (!$row) {
            echo json_encode(['success' => true, 'session' => null]);
            exit;
        }

        // Calculate duration in minutes from entry_time to now
        $entryTs      = strtotime($row['entry_time']);
        $nowTs        = time();
        $durationMins = max(0, (int)(($nowTs - $entryTs) / 60));
        $hours        = floor($durationMins / 60);
        $mins         = $durationMins % 60;
        $rate         = (float) $row['rate_per_hour'];

        // Subscribers park for free
        $activeSub   = getActiveSub($conn, $userId);
        $isSubscribed = $activeSub !== null;
        $totalFee    = $isSubscribed ? 0 : calcFee($durationMins, $rate);

        echo json_encode([
            'success' => true,
            'session' => [
                'session_id'    => $row['session_id'],
                'plate_number'  => $row['plate_number'],
                'slot_code'     => $row['slot_code'],
                'slot_id'       => $row['slot_id'],
                'entry_time'    => $row['entry_time'],
                'entry_ts'      => $entryTs,
                'duration_mins' => $durationMins,
                'duration_fmt'  => sprintf('%02dh %02dm', $hours, $mins),
                'make'          => $row['make'],
                'model'         => $row['model'],
                'color'         => $row['color'],
                'rate_per_hour' => $rate,
                'total_fee'     => $totalFee,
                'total_fee_fmt' => $isSubscribed ? 'FREE' : ('Rs. ' . number_format($totalFee, 0)),
                'is_subscribed' => $isSubscribed,
                'sub_plan'      => $isSubscribed ? $activeSub['plan'] : null,
            ]
        ]);
        exit;
    }

    // ── GET SLOTS: full slot map ──────────────────────────────
    if ($action === 'get_slots') {
        // Find user's current slot
        $mySlotId = null;
        $stmt = $conn->prepare("
            SELECT slot_id FROM parking_sessions
            WHERE user_id = ? AND status = 'active'
            ORDER BY entry_time DESC LIMIT 1
        ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc();
        if ($r) $mySlotId = (int)$r['slot_id'];

        $result = $conn->query("SELECT id, slot_code, row_label, slot_number, status FROM parking_slots ORDER BY row_label, slot_number");
        $slots = [];
        while ($s = $result->fetch_assoc()) {
            $slots[] = [
                'id'          => (int)$s['id'],
                'slot_code'   => $s['slot_code'],
                'row_label'   => $s['row_label'],
                'slot_number' => (int)$s['slot_number'],
                'status'      => $s['status'],
                'is_mine'     => ($mySlotId !== null && (int)$s['id'] === $mySlotId),
            ];
        }

        echo json_encode(['success' => true, 'slots' => $slots]);
        exit;
    }

    // ── LOGOUT ────────────────────────────────────────────────
    if ($action === 'logout') {
        session_destroy();
        echo json_encode(['success' => true, 'redirect' => '../login/login.php']);
        exit;
    }

    // ── STRIPE: CREATE PAYMENT INTENT ─────────────────────────
    // Called when user opens the Stripe payment modal.
    // Returns a client_secret the frontend uses to confirm the card.
    if ($action === 'stripe_create_intent') {
        $input     = json_decode(file_get_contents('php://input'), true);
        $sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;

        if (!$sessionId) {
            echo json_encode(['success' => false, 'message' => 'No active session.']);
            exit;
        }

        // Verify session belongs to this user and is active
        $stmt = $conn->prepare("SELECT id, entry_time FROM parking_sessions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) {
            echo json_encode(['success' => false, 'message' => 'Session not found or already closed.']);
            exit;
        }

        // Calculate fee in NPR
        $durationMins = max(1, (int)((time() - strtotime($sess['entry_time'])) / 60));
        $rate         = 20.00; // Rs./hr
        $amountNPR    = calcFee($durationMins, $rate);

        // Convert to USD cents for Stripe (Stripe doesn't support NPR)
        $amountUSD    = $amountNPR / NPR_TO_USD_RATE;
        $amountCents  = max(STRIPE_MIN_CENTS, (int)round($amountUSD * 100));

        // Call Stripe API to create a PaymentIntent
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
                'amount'                          => $amountCents,
                'currency'                        => 'usd',
                'automatic_payment_methods[enabled]' => 'true',
                'metadata[session_id]'            => $sessionId,
                'metadata[user_id]'               => $userId,
                'metadata[amount_npr]'            => $amountNPR,
                'description'                     => 'Parkify parking fee — ' . $durationMins . ' min',
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
        ]);
        exit;
    }

    // ── STRIPE: CONFIRM CHECKOUT (called after Stripe confirms card) ──
    // Verifies the PaymentIntent status with Stripe, then closes the session.
    if ($action === 'stripe_checkout') {
        $input           = json_decode(file_get_contents('php://input'), true);
        $sessionId       = isset($input['session_id'])       ? (int)$input['session_id']           : 0;
        $paymentIntentId = isset($input['payment_intent_id']) ? trim($input['payment_intent_id']) : '';

        if (!$sessionId || !$paymentIntentId) {
            echo json_encode(['success' => false, 'message' => 'Missing payment data.']);
            exit;
        }

        // Verify session
        $stmt = $conn->prepare("SELECT id, entry_time FROM parking_sessions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) {
            echo json_encode(['success' => false, 'message' => 'Session not found or already closed.']);
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

        // Stripe confirmed — close the session
        $exitTime     = date('Y-m-d H:i:s');
        $durationMins = max(1, (int)((strtotime($exitTime) - strtotime($sess['entry_time'])) / 60));
        $rate         = 20.00;
        $amountNPR    = calcFee($durationMins, $rate);
        $txId         = 'STR-' . strtoupper(substr($paymentIntentId, 3, 12)); // e.g. STR-PI_XXXXXX

        $stmt = $conn->prepare("UPDATE parking_sessions SET exit_time = ?, duration_mins = ?, status = 'completed' WHERE id = ?");
        $stmt->bind_param('sii', $exitTime, $durationMins, $sessionId);
        $stmt->execute();

        $stmt = $conn->prepare("INSERT INTO payments (session_id, user_id, amount, rate_per_hour, method, status, transaction_id, paid_at) VALUES (?, ?, ?, ?, 'stripe', 'paid', ?, NOW())");
        $stmt->bind_param('iidds', $sessionId, $userId, $amountNPR, $rate, $txId);
        $stmt->execute();

        // Free the slot
        $slotRow = $conn->query("SELECT slot_id FROM parking_sessions WHERE id = $sessionId")->fetch_assoc();
        if ($slotRow) {
            $conn->query("UPDATE parking_slots SET status = 'available' WHERE id = " . (int)$slotRow['slot_id']);
        }

        echo json_encode([
            'success'        => true,
            'message'        => 'Payment successful via Stripe!',
            'transaction_id' => $txId,
            'amount'         => 'Rs. ' . number_format($amountNPR, 0),
        ]);
        exit;
    }

    // ── PROCESS PAYMENT ───────────────────────────────────────
    if ($action === 'process_payment') {
        $input     = json_decode(file_get_contents('php://input'), true);
        $sessionId = isset($input['session_id']) ? (int)$input['session_id'] : 0;
        $method    = isset($input['method'])     ? trim($input['method'])     : 'cash';

        if (!$sessionId) {
            echo json_encode(['success' => false, 'message' => 'No active session.']);
            exit;
        }

        // Verify session belongs to this user
        $stmt = $conn->prepare("SELECT id, entry_time FROM parking_sessions WHERE id = ? AND user_id = ? AND status = 'active'");
        $stmt->bind_param('ii', $sessionId, $userId);
        $stmt->execute();
        $sess = $stmt->get_result()->fetch_assoc();
        if (!$sess) {
            echo json_encode(['success' => false, 'message' => 'Session not found or already closed.']);
            exit;
        }

        $exitTime     = date('Y-m-d H:i:s');
        $durationMins = max(1, (int)((strtotime($exitTime) - strtotime($sess['entry_time'])) / 60));

        // Subscribers pay nothing
        $activeSub    = getActiveSub($conn, $userId);
        $isSubscribed = $activeSub !== null;
        $rate         = $isSubscribed ? 0.00 : 20.00;
        $amount       = $isSubscribed ? 0.00 : calcFee($durationMins, $rate);
        if ($isSubscribed) { $method = 'subscription'; }
        $txId         = 'PK-' . strtoupper(bin2hex(random_bytes(5)));

        // Close the parking session
        $stmt = $conn->prepare("UPDATE parking_sessions SET exit_time = ?, duration_mins = ?, status = 'completed' WHERE id = ?");
        $stmt->bind_param('sii', $exitTime, $durationMins, $sessionId);
        $stmt->execute();

        // Record payment
        $stmt = $conn->prepare("INSERT INTO payments (session_id, user_id, amount, rate_per_hour, method, status, transaction_id, paid_at) VALUES (?, ?, ?, ?, ?, 'paid', ?, NOW())");
        $stmt->bind_param('iiddss', $sessionId, $userId, $amount, $rate, $method, $txId);
        $stmt->execute();

        // Free the parking slot
        $slotRow = $conn->query("SELECT slot_id FROM parking_sessions WHERE id = $sessionId")->fetch_assoc();
        if ($slotRow) {
            $conn->query("UPDATE parking_slots SET status = 'available' WHERE id = " . (int)$slotRow['slot_id']);
        }

        echo json_encode([
            'success'        => true,
            'message'        => $isSubscribed ? 'Checked out successfully! (Subscription)' : 'Payment recorded successfully!',
            'transaction_id' => $txId,
            'amount'         => $isSubscribed ? 'FREE' : ('Rs. ' . number_format($amount, 0)),
            'is_subscribed'  => $isSubscribed,
        ]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ============================================================
//  INITIAL PAGE LOAD — fetch data server-side for first render
// ============================================================

// Logged-in user info
$userName  = htmlspecialchars($_SESSION['user_name']  ?? 'User');
$userEmail = htmlspecialchars($_SESSION['user_email'] ?? '');
$userInit  = strtoupper(substr($_SESSION['user_name'] ?? 'U', 0, 1));

// Active session
$stmt = $conn->prepare("
    SELECT
        ps.id           AS session_id,
        COALESCE(NULLIF(ps.plate_number,''), v.plate_number) AS plate_number,
        ps.entry_time,
        sl.slot_code,
        sl.id           AS slot_id,
        v.make, v.model, v.color,
        COALESCE(py.rate_per_hour, 20.00) AS rate_per_hour
    FROM   parking_sessions ps
    LEFT JOIN parking_slots    sl ON sl.id = ps.slot_id
    LEFT JOIN vehicles         v  ON v.id  = ps.vehicle_id
    LEFT JOIN payments         py ON py.session_id = ps.id
    WHERE  ps.user_id = ? AND ps.status = 'active'
    ORDER  BY ps.entry_time DESC
    LIMIT  1
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$session = $stmt->get_result()->fetch_assoc();

$hasSession    = (bool) $session;
$plate         = $hasSession ? htmlspecialchars($session['plate_number']) : '—';
$slotCode      = $hasSession ? htmlspecialchars($session['slot_code'])    : '—';
$mySlotId      = $hasSession ? (int) $session['slot_id']                  : 0;
$entryTime     = $hasSession ? $session['entry_time']                     : null;
$entryTs       = $hasSession ? strtotime($entryTime)                      : 0;
$rate          = $hasSession ? (float) $session['rate_per_hour']          : 20.00;
$sessionId     = $hasSession ? (int)   $session['session_id']             : 0;

$durationMins  = $hasSession ? max(0, (int)((time() - $entryTs) / 60))   : 0;
$durFmt        = $hasSession ? sprintf('%02dh %02dm', floor($durationMins/60), $durationMins%60) : '—';

// Check active subscription — subscribers park free
$activeSub     = getActiveSub($conn, $userId);
$isSubscribed  = $activeSub !== null;
$subPlan       = $isSubscribed ? $activeSub['plan'] : null;
$subPlanLabel  = $subPlan ? ucfirst($subPlan) : '';

$totalFee      = ($hasSession && !$isSubscribed) ? calcFee($durationMins, $rate) : 0;
$entryFmt      = $hasSession ? date('h:i A', $entryTs)                   : '—';
$entryDateFmt  = $hasSession ? date('M d, Y', $entryTs)                  : '';

// All parking slots
$slotsResult = $conn->query("SELECT id, slot_code, row_label, slot_number, status FROM parking_slots ORDER BY row_label, slot_number");
$allSlots = [];
while ($s = $slotsResult->fetch_assoc()) { $allSlots[] = $s; }

$conn->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Parkify — Home</title>
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
    main {
      flex: 1; display: grid; grid-template-columns: 1fr 340px;
      gap: 24px; padding: 28px 32px; max-width: 1400px; margin: 0 auto; width: 100%;
      animation: fadeUp .5s ease both;
    }
    @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

    /* ── CARDS ── */
    .card { background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); }
    .left-panel { display: flex; flex-direction: column; gap: 20px; }

    /* Welcome */
    .welcome-section { padding: 24px 28px; }
    .welcome-section h1 { font-family: "Space Grotesk", sans-serif; font-size: 26px; font-weight: 700; }
    .welcome-section p { color: var(--text-muted); font-size: 14px; margin-top: 4px; }

    /* Entry card */
    .entry-card {
      border-radius: var(--radius-sm); border: 1px solid var(--border);
      padding: 20px; display: flex; align-items: center; gap: 24px;
      margin-top: 20px; background: linear-gradient(135deg, #f8faff 0%, #fff 100%);
    }
    .entry-visual { width: 80px; height: 80px; flex-shrink: 0; background: linear-gradient(135deg,#eff6ff,#dbeafe); border-radius: 16px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; border: 1.5px solid #bfdbfe; }
    .entry-visual .ev-cam { font-size: 22px; color: var(--blue); }
    .entry-visual .ev-car { font-size: 26px; color: var(--navy); }
    .entry-info { flex: 1; }
    .entry-info h3 { font-family: "Space Grotesk", sans-serif; font-size: 16px; font-weight: 600; }
    .entry-info p { color: var(--text-muted); font-size: 13px; margin: 3px 0 14px; }
    .plate-display {
      background: #fff; border: 2px solid #1e293b; border-radius: 8px;
      padding: 8px 18px; display: inline-flex; align-items: center; gap: 12px;
      font-family: "Space Grotesk", sans-serif; font-size: 18px; font-weight: 700;
      letter-spacing: 2px; box-shadow: 0 2px 8px rgba(0,0,0,.1);
    }
    .plate-country { background: var(--navy); color: #fff; font-size: 11px; font-weight: 600; padding: 4px 8px; border-radius: 5px; letter-spacing: 0; }
    .success-badge { display: inline-flex; align-items: center; gap: 6px; color: var(--green); font-size: 13px; font-weight: 500; margin-top: 10px; }
    .no-session-badge { display: inline-flex; align-items: center; gap: 6px; color: var(--text-muted); font-size: 13px; font-weight: 500; margin-top: 10px; }

    /* Status */
    .section-title { font-family: "Space Grotesk", sans-serif; font-size: 16px; font-weight: 600; margin-bottom: 14px; }
    .section-wrapper { padding: 22px 24px; }
    .status-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }
    .status-card {
      background: var(--card); border: 1px solid var(--border); border-radius: var(--radius-sm);
      padding: 14px 16px; display: flex; align-items: center; gap: 12px; box-shadow: var(--shadow);
      transition: transform .2s, box-shadow .2s;
    }
    .status-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
    .status-icon { width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 17px; flex-shrink: 0; }
    .icon-blue i  { color: var(--blue); }
    .icon-green i { color: var(--green); }
    .icon-amber i { color: var(--amber); }
    .icon-purple i{ color: #a855f7; }
    .icon-blue { background: #eff6ff; } .icon-green { background: var(--green-bg); }
    .icon-amber { background: var(--amber-bg); } .icon-purple { background: #f3e8ff; }
    .status-label { color: var(--text-muted); font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
    .status-value { font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 700; margin-top: 2px; }
    .status-sub { font-size: 11px; color: var(--text-muted); margin-top: 1px; }

    /* Fee */
    .fee-card { padding: 22px 24px; }
    .fee-row {
      background: linear-gradient(135deg, #f0f7ff, #f8fbff); border: 1px solid #dbeafe;
      border-radius: var(--radius-sm); padding: 16px 20px;
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;
    }
    .fee-item { text-align: center; }
    .fee-label { color: var(--text-muted); font-size: 12px; }
    .fee-value { font-family: "Space Grotesk", sans-serif; font-size: 16px; font-weight: 600; margin-top: 4px; }
    .fee-divider { width: 1px; height: 36px; background: #dbeafe; }
    .fee-total { font-size: 22px; color: var(--green); }
    .pay-btn {
      width: 100%; background: linear-gradient(135deg, var(--blue) 0%, var(--blue-light) 100%);
      color: #fff; border: none; border-radius: 12px; padding: 15px 24px;
      font-family: "Space Grotesk", sans-serif; font-size: 15px; font-weight: 600; cursor: pointer;
      display: flex; align-items: center; justify-content: center; gap: 10px;
      transition: all .25s; box-shadow: 0 4px 16px rgba(37,99,235,.35); letter-spacing: .3px;
    }
    .pay-btn:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(37,99,235,.45); }
    .pay-btn:disabled { opacity: .6; cursor: not-allowed; }

    /* ── RIGHT SIDEBAR ── */
    .right-sidebar { display: flex; flex-direction: column; gap: 20px; }
    .slot-map-card { padding: 22px; }
    .slot-legend { display: flex; gap: 14px; margin-bottom: 16px; flex-wrap: wrap; }
    .legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text-muted); }
    .legend-dot { width: 12px; height: 12px; border-radius: 3px; }
    .dot-avail { background: var(--green); } .dot-occ { background: var(--red); }
    .dot-mine { background: var(--amber); border: 2px solid #d97706; }

    .slot-grid { display: grid; grid-template-columns: auto repeat(4, 1fr); gap: 8px; align-items: center; }
    .slot-row-label { font-family: "Space Grotesk", sans-serif; font-size: 13px; font-weight: 600; color: var(--text-muted); text-align: center; }
    .slot {
      aspect-ratio: 1; border-radius: 8px; display: flex; align-items: center; justify-content: center;
      font-family: "Space Grotesk", sans-serif; font-size: 12px; font-weight: 600;
      cursor: pointer; transition: transform .15s, box-shadow .15s; position: relative;
    }
    .slot:hover { transform: scale(1.08); box-shadow: 0 4px 12px rgba(0,0,0,.15); }
    .slot-avail { background: var(--green-bg); color: #16a34a; border: 1.5px solid #bbf7d0; }
    .slot-occ   { background: var(--red-bg);   color: #dc2626; border: 1.5px solid #fecaca; }
    .slot-mine  { background: var(--amber-bg); color: #92400e; border: 2px solid var(--amber); box-shadow: 0 0 0 3px rgba(245,158,11,.2); }
    .mine-icon  { position: absolute; top: -8px; right: -8px; width: 18px; height: 18px; background: var(--amber); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 9px; color: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.2); }

    /* Summary */
    .summary-card { padding: 22px; }
    .summary-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .summary-row:last-of-type { border-bottom: none; }
    .summary-key { color: var(--text-muted); }
    .summary-val { font-weight: 600; font-family: "Space Grotesk", sans-serif; font-size: 13px; }
    .total-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 0 14px; border-top: 2px solid var(--border); margin-top: 4px; }
    .total-label { font-weight: 700; font-size: 15px; font-family: "Space Grotesk", sans-serif; }
    .total-amount { font-weight: 800; font-size: 20px; color: var(--green); font-family: "Space Grotesk", sans-serif; }
    .payment-methods-title { font-family: "Space Grotesk", sans-serif; font-size: 14px; font-weight: 600; margin-bottom: 12px; margin-top: 4px; }
    .payment-methods { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
    .pm-btn {
      border: 2px solid var(--border); border-radius: 10px; padding: 10px 6px; background: #fff; cursor: pointer;
      display: flex; flex-direction: column; align-items: center; gap: 6px;
      font-size: 12px; font-weight: 600; color: var(--text); transition: all .2s; font-family: "DM Sans", sans-serif;
    }
    .pm-btn:hover { border-color: var(--blue); background: #f0f7ff; color: var(--blue); }
    .pm-btn.active { border-color: var(--blue); background: #eff6ff; color: var(--blue); }
    .pm-icon { width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 15px; }
    .pm-icon.esewa  { background: #dcfce7; color: #16a34a; }
    .pm-icon.khalti { background: #ede9fe; color: #7c3aed; }
    .pm-icon.stripe { background: #eff6ff; color: #6772e5; }
    .pm-icon.card   { background: #dbeafe; color: #2563eb; }

    /* ── Stripe Payment Modal ── */
    .modal-overlay {
      display: none; position: fixed; inset: 0;
      background: rgba(15,31,61,.55); backdrop-filter: blur(4px);
      z-index: 1000; align-items: center; justify-content: center;
    }
    .modal-overlay.open { display: flex; }
    .stripe-modal {
      background: #fff; border-radius: 20px; width: 460px; max-width: calc(100vw - 32px);
      box-shadow: 0 24px 80px rgba(15,31,61,.22); overflow: hidden;
      animation: modalIn .28s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes modalIn { from { opacity:0; transform:translateY(24px) scale(.97); } to { opacity:1; transform:none; } }
    .modal-header {
      background: linear-gradient(135deg, var(--navy) 0%, #1e3a6e 100%);
      padding: 22px 28px; display: flex; align-items: center; justify-content: space-between;
    }
    .modal-header-left { display: flex; align-items: center; gap: 12px; }
    .modal-logo {
      width: 42px; height: 42px; background: rgba(255,255,255,.12); border-radius: 12px;
      display: flex; align-items: center; justify-content: center; font-size: 20px; color: #fff;
    }
    .modal-title { color: #fff; font-family: "Space Grotesk", sans-serif; font-size: 17px; font-weight: 700; }
    .modal-subtitle { color: rgba(255,255,255,.65); font-size: 12px; margin-top: 2px; }
    .modal-close {
      width: 32px; height: 32px; border-radius: 8px; background: rgba(255,255,255,.1); border: none;
      color: rgba(255,255,255,.8); font-size: 16px; cursor: pointer; display: flex;
      align-items: center; justify-content: center; transition: background .2s;
    }
    .modal-close:hover { background: rgba(255,255,255,.2); }
    .modal-body { padding: 24px 28px; }
    .modal-amount-row {
      display: flex; align-items: center; justify-content: space-between;
      background: linear-gradient(135deg, #f0f9ff, #e0f2fe); border: 1px solid #bae6fd;
      border-radius: 12px; padding: 14px 18px; margin-bottom: 22px;
    }
    .modal-amount-label { font-size: 13px; color: #0369a1; font-weight: 500; }
    .modal-amount-sub   { font-size: 11px; color: #0284c7; margin-top: 2px; }
    .modal-amount-value { font-family: "Space Grotesk",sans-serif; font-size: 24px; font-weight: 800; color: var(--navy); }
    .modal-amount-usd   { font-size: 11px; color: var(--text-muted); text-align: right; margin-top: 2px; }

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
    .modal-footer { padding: 0 28px 24px; display: flex; flex-direction: column; gap: 10px; }
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
    .modal-cancel-btn {
      width: 100%; background: none; border: 1.5px solid var(--border); border-radius: 12px;
      padding: 12px; font-size: 14px; color: var(--text-muted); cursor: pointer;
      font-family: "DM Sans", sans-serif; transition: all .2s;
    }
    .modal-cancel-btn:hover { border-color: var(--text-muted); color: var(--text); }
    .stripe-badge {
      display: flex; align-items: center; justify-content: center; gap: 6px;
      font-size: 11px; color: #9ca3af; margin-top: 4px;
    }
    .stripe-badge i { font-size: 10px; color: #6772e5; }

    /* Footer */
    footer { background: #fff; border-top: 1px solid var(--border); padding: 12px 32px; display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--text-muted); }
    footer span { display: flex; align-items: center; gap: 6px; }
    footer i { color: var(--blue); }

    /* Pulse */
    @keyframes pulse { 0%,100%{opacity:1}50%{opacity:.4} }
    .running-badge {
      display: inline-flex; align-items: center; gap: 5px; background: var(--green-bg); color: var(--green);
      font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 20px;
      animation: pulse 2s ease-in-out infinite; text-transform: uppercase; letter-spacing: .5px;
    }
    .running-badge i { font-size: 8px; }

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

    /* No-session state */
    .no-session-msg { text-align: center; padding: 32px 20px; color: var(--text-muted); }
    .no-session-msg .ns-icon { font-size: 40px; color: #cbd5e1; margin-bottom: 12px; }
    .no-session-msg p { font-size: 14px; }

    /* Subscription free-parking styles */
    .sub-free-banner {
      display: flex; align-items: center; gap: 14px;
      background: linear-gradient(135deg, #f0fdf4, #dcfce7); border: 1.5px solid #86efac;
      border-radius: var(--radius-sm); padding: 14px 18px; margin-bottom: 4px;
    }
    .sub-free-icon {
      width: 44px; height: 44px; flex-shrink: 0; background: var(--green); color: #fff;
      border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 20px;
    }
    .sub-free-text { display: flex; flex-direction: column; gap: 2px; }
    .sub-free-text strong { font-family: "Space Grotesk", sans-serif; font-size: 14px; color: #15803d; }
    .sub-free-text span { font-size: 12px; color: #166534; }
    .fee-free { color: var(--green) !important; font-size: 20px !important; font-weight: 800 !important; }
    .pay-btn-free {
      background: linear-gradient(135deg, var(--green) 0%, #16a34a 100%) !important;
      box-shadow: 0 4px 16px rgba(34,197,94,.35) !important;
    }
    .pay-btn-free:hover:not(:disabled) { box-shadow: 0 8px 24px rgba(34,197,94,.45) !important; }
    .sub-free-amount { color: var(--green) !important; }
    .sub-pill {
      display: inline-flex; align-items: center; gap: 6px;
      background: var(--green-bg); color: #15803d; font-size: 12px; font-weight: 600;
      padding: 5px 12px; border-radius: 20px; margin-bottom: 12px; margin-top: 4px;
      border: 1px solid #bbf7d0;
    }
    .sub-pill i { font-size: 11px; }
    .pm-disabled { opacity: .4; pointer-events: none; }

    /* Refresh indicator */
    .refresh-dot {
      width: 8px; height: 8px; border-radius: 50%; background: var(--green);
      display: inline-block; margin-left: 6px; animation: pulse 2s infinite;
    }
    .last-updated { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

    /* Avatar initial */
    #nav-avatar-initial {
      width: 36px; height: 36px; border-radius: 50%;
      background: linear-gradient(135deg, #2563eb, #22c55e); color: #fff;
      display: flex; align-items: center; justify-content: center;
      font-weight: 700; font-size: 15px;
    }
  </style>
</head>
<body>

<!-- ── NAV ──────────────────────────────────────────────────── -->
<?php include 'nav.php'; ?>

<!-- ── MAIN ──────────────────────────────────────────────────── -->
<main>
  <div class="left-panel">

    <div class="card welcome-section">
      <h1>Welcome, <?= explode(' ', $userName)[0] ?> <i class="fa-solid fa-hand-wave" style="color:var(--amber);font-size:22px;vertical-align:middle"></i></h1>
      <p>
        <?= $hasSession
          ? "Your vehicle is currently parked. Live data updates every 30 seconds."
          : "No active parking session found for your account." ?>
        <span class="refresh-dot"></span>
      </p>

      <div class="entry-card">
        <div class="entry-visual">
          <i class="fa-solid fa-camera ev-cam"></i>
          <i class="fa-solid fa-car-side ev-car"></i>
        </div>
        <div class="entry-info">
          <h3>Vehicle Entry (Plate Recognition)</h3>
          <p>Plate number linked to your account.</p>
          <div class="plate-display">
            <span class="plate-country">Nepal</span>
            <span id="plate-display-text"><?= $plate ?></span>
          </div>
          <?php if ($hasSession): ?>
            <div class="success-badge"><i class="fa-solid fa-circle-check"></i> Vehicle parked — slot <?= $slotCode ?></div>
          <?php else: ?>
            <div class="no-session-badge"><i class="fa-solid fa-circle-info"></i> No active session detected</div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Current Parking Status -->
    <div class="card section-wrapper">
      <div class="section-title">
        Current Parking Status
        <small class="last-updated" id="last-updated-label"></small>
      </div>

      <?php if ($hasSession): ?>
      <div class="status-grid">
        <div class="status-card">
          <div class="status-icon icon-blue"><i class="fa-solid fa-car"></i></div>
          <div>
            <div class="status-label">Vehicle Number</div>
            <div class="status-value" id="st-plate"><?= $plate ?></div>
          </div>
        </div>
        <div class="status-card">
          <div class="status-icon icon-green"><i class="fa-solid fa-square-parking"></i></div>
          <div>
            <div class="status-label">Parking Slot</div>
            <div class="status-value" id="st-slot"><?= $slotCode ?></div>
          </div>
        </div>
        <div class="status-card">
          <div class="status-icon icon-amber"><i class="fa-solid fa-clock"></i></div>
          <div>
            <div class="status-label">Entry Time</div>
            <div class="status-value" id="st-entry"><?= $entryFmt ?></div>
            <div class="status-sub" id="st-entry-date"><?= $entryDateFmt ?></div>
          </div>
        </div>
        <div class="status-card">
          <div class="status-icon icon-purple"><i class="fa-solid fa-stopwatch"></i></div>
          <div>
            <div class="status-label">Duration</div>
            <div class="status-value" id="st-duration"><?= $durFmt ?></div>
            <div class="running-badge"><i class="fa-solid fa-circle" style="font-size:6px"></i> Running</div>
          </div>
        </div>
      </div>
      <?php else: ?>
      <div class="no-session-msg">
        <div class="ns-icon"><i class="fa-solid fa-square-parking"></i></div>
        <p>You don't have an active parking session.<br>Visit the parking entrance to begin.</p>
      </div>
      <?php endif; ?>
    </div>

    <!-- Fee Card -->
    <div class="card fee-card">
      <div class="section-title">Parking Fee</div>
      <?php if ($hasSession): ?>

        <?php if ($isSubscribed): ?>
        <!-- ── SUBSCRIBED: free parking banner ── -->
        <div class="sub-free-banner">
          <div class="sub-free-icon"><i class="fa-solid fa-shield-check"></i></div>
          <div class="sub-free-text">
            <strong><?= $subPlanLabel ?> Plan Active</strong>
            <span>Parking is included in your subscription — no charge!</span>
          </div>
        </div>
        <div class="fee-row" style="margin-top:12px">
          <div class="fee-item">
            <div class="fee-label">Rate</div>
            <div class="fee-value" id="fee-rate" style="text-decoration:line-through;color:var(--text-muted)">Rs. <?= number_format($rate, 0) ?> / hour</div>
          </div>
          <div class="fee-divider"></div>
          <div class="fee-item">
            <div class="fee-label">Total Time</div>
            <div class="fee-value" id="fee-duration"><?= $durFmt ?></div>
          </div>
          <div class="fee-divider"></div>
          <div class="fee-item">
            <div class="fee-label">Total Amount</div>
            <div class="fee-value fee-free" id="fee-total">FREE</div>
          </div>
        </div>
        <button class="pay-btn pay-btn-free" id="pay-btn" onclick="handlePayment()">
          <i class="fa-solid fa-circle-check"></i> Check Out (Free) <i class="fa-solid fa-arrow-right"></i>
        </button>

        <?php else: ?>
        <!-- ── NOT SUBSCRIBED: normal fee display ── -->
        <div class="fee-row">
          <div class="fee-item">
            <div class="fee-label">Rate</div>
            <div class="fee-value" id="fee-rate">Rs. <?= number_format($rate, 0) ?> / hour</div>
          </div>
          <div class="fee-divider"></div>
          <div class="fee-item">
            <div class="fee-label">Total Time</div>
            <div class="fee-value" id="fee-duration"><?= $durFmt ?></div>
          </div>
          <div class="fee-divider"></div>
          <div class="fee-item">
            <div class="fee-label">Total Amount</div>
            <div class="fee-value fee-total" id="fee-total">Rs. <?= number_format($totalFee, 0) ?></div>
          </div>
        </div>
        <button class="pay-btn" id="pay-btn" onclick="handlePayment()">
          <i class="fa-solid fa-credit-card"></i> Proceed to Pay <i class="fa-solid fa-arrow-right"></i>
        </button>
        <?php endif; ?>

      <?php else: ?>
      <div class="no-session-msg" style="padding:16px 0 0">
        <p>No active session — fee will appear once your vehicle is parked.</p>
      </div>
      <?php endif; ?>
    </div>

  </div>

  <!-- RIGHT SIDEBAR -->
  <div class="right-sidebar">

    <!-- Slot Map -->
    <div class="card slot-map-card">
      <div class="section-title">Parking Slot Map</div>
      <div class="slot-legend">
        <span class="legend-item"><span class="legend-dot dot-avail"></span> Available</span>
        <span class="legend-item"><span class="legend-dot dot-occ"></span>   Occupied</span>
        <span class="legend-item"><span class="legend-dot dot-mine"></span>  Your Slot</span>
      </div>
      <div class="slot-grid" id="slot-grid">
        <?php
        $rows = [];
        foreach ($allSlots as $s) $rows[$s['row_label']][] = $s;
        foreach ($rows as $rowLabel => $rowSlots): ?>
          <div class="slot-row-label"><?= htmlspecialchars($rowLabel) ?></div>
          <?php foreach ($rowSlots as $s):
            $isOccupied = ($s['status'] === 'occupied');
            $isMine     = ((int)$s['id'] === $mySlotId);
            $cls = $isMine ? 'slot-mine' : ($isOccupied ? 'slot-occ' : 'slot-avail');
            $title = $isMine ? 'Your slot' : ($isOccupied ? 'Occupied' : 'Available');
          ?>
            <div class="slot <?= $cls ?>" id="slot-<?= $s['id'] ?>" title="<?= $title ?>">
              <?= htmlspecialchars($s['slot_code']) ?>
              <?php if ($isMine): ?><span class="mine-icon"><i class="fa-solid fa-car"></i></span><?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Payment Summary -->
    <div class="card summary-card">
      <div class="section-title">Payment Summary</div>
      <div class="summary-row"><span class="summary-key">Vehicle Number</span><span class="summary-val" id="sum-plate"><?= $plate ?></span></div>
      <div class="summary-row"><span class="summary-key">Parking Slot</span>  <span class="summary-val" id="sum-slot"><?= $slotCode ?></span></div>
      <div class="summary-row"><span class="summary-key">Entry Time</span>    <span class="summary-val" id="sum-entry"><?= $hasSession ? $entryFmt . ', ' . $entryDateFmt : '—' ?></span></div>
      <div class="summary-row"><span class="summary-key">Duration</span>      <span class="summary-val" id="sum-duration"><?= $durFmt ?></span></div>
      <div class="summary-row"><span class="summary-key">Rate</span>          <span class="summary-val" id="sum-rate">Rs. <?= number_format($rate, 0) ?> / hour</span></div>
      <div class="total-row">
        <span class="total-label">Total Amount</span>
        <?php if ($isSubscribed): ?>
          <span class="total-amount sub-free-amount" id="sum-total">FREE</span>
        <?php else: ?>
          <span class="total-amount" id="sum-total"><?= $hasSession ? 'Rs. ' . number_format($totalFee, 0) : 'Rs. 0' ?></span>
        <?php endif; ?>
      </div>

      <?php if ($isSubscribed): ?>
      <div class="sub-pill"><i class="fa-solid fa-shield-check"></i> <?= $subPlanLabel ?> Plan — Parking covered</div>
      <?php endif; ?>

      <div class="payment-methods-title">Choose Payment Method</div>
      <div class="payment-methods <?= $isSubscribed ? 'pm-disabled' : '' ?>">
        <button class="pm-btn active" onclick="selectPM(this,'stripe')">
          <div class="pm-icon stripe"><i class="fa-brands fa-stripe-s"></i></div>Stripe
        </button>
        <button class="pm-btn" onclick="selectPM(this,'esewa')">
          <div class="pm-icon esewa"><i class="fa-solid fa-mobile-screen-button"></i></div>eSewa
        </button>
        <button class="pm-btn" onclick="selectPM(this,'khalti')">
          <div class="pm-icon khalti"><i class="fa-solid fa-wallet"></i></div>Khalti
        </button>
      </div>
    </div>

  </div>
</main>

<!-- FOOTER -->
<footer>
  <span><i class="fa-solid fa-lock"></i> Your parking data is secure and updated in real-time.</span>
  <span><i class="fa-solid fa-copyright"></i> <?= date('Y') ?> Parkify System. All rights reserved.</span>
</footer>

<!-- ── STRIPE PAYMENT MODAL ────────────────────────────────── -->
<div class="modal-overlay" id="stripe-modal-overlay">
  <div class="stripe-modal" role="dialog" aria-modal="true" aria-label="Stripe Payment">

    <!-- Header -->
    <div class="modal-header">
      <div class="modal-header-left">
        <div class="modal-logo"><i class="fa-brands fa-stripe-s"></i></div>
        <div>
          <div class="modal-title">Pay with Card</div>
          <div class="modal-subtitle">Secured by Stripe · Test Mode</div>
        </div>
      </div>
      <button class="modal-close" id="modal-close-btn" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
    </div>

    <!-- Body -->
    <div class="modal-body">
      <!-- Amount summary -->
      <div class="modal-amount-row">
        <div>
          <div class="modal-amount-label"><i class="fa-solid fa-receipt" style="margin-right:4px;color:#0369a1"></i> Parking Fee</div>
          <div class="modal-amount-sub" id="modal-duration-label">Calculating…</div>
        </div>
        <div style="text-align:right">
          <div class="modal-amount-value" id="modal-npr-amount">Rs. —</div>
          <div class="modal-amount-usd" id="modal-usd-amount"></div>
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
    <div class="modal-footer">
      <button class="stripe-pay-btn" id="stripe-submit-btn">
        <i class="fa-brands fa-stripe-s"></i>
        <span id="stripe-submit-label">Pay Now</span>
      </button>
      <button class="modal-cancel-btn" id="modal-cancel-btn">Cancel</button>
      <div class="stripe-badge"><i class="fa-solid fa-lock"></i> End-to-end encrypted · Powered by Stripe</div>
    </div>

  </div>
</div>

<!-- TOAST -->
<div id="toast"><span id="toast-msg"></span></div>

<!-- Stripe.js (loaded async, initialized when modal opens) -->
<script src="https://js.stripe.com/v3/" async></script>

<script>
  // ── State injected from PHP ────────────────────────────────
  const STATE = {
    hasSession   : <?= json_encode($hasSession) ?>,
    sessionId    : <?= json_encode($sessionId) ?>,
    entryTs      : <?= json_encode($entryTs) ?>,
    rate         : <?= json_encode($rate) ?>,
    mySlotId     : <?= json_encode($mySlotId) ?>,
    isSubscribed : <?= json_encode($isSubscribed) ?>,
    subPlan      : <?= json_encode($subPlan) ?>,
  };

  let selectedPM     = 'stripe';   // default
  let paymentDone    = false;
  let liveTimerID    = null;
  let pollIntervalID = null;

  // ── Stripe instances (lazy-initialized when modal opens) ───
  let stripeInstance  = null;
  let stripeElements  = null;
  let stripeCard      = null;
  let currentPI       = null; // { client_secret, payment_intent_id, amount_npr, amount_cents }

  // ── Payment method selector ────────────────────────────────
  function selectPM(btn, method) {
    document.querySelectorAll('.pm-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    selectedPM = method;
  }

  // ── Live duration timer ────────────────────────────────────
  function startLiveTimer() {
    if (!STATE.hasSession || paymentDone) return;

    function tick() {
      const nowTs   = Math.floor(Date.now() / 1000);
      const durMins = Math.max(0, Math.floor((nowTs - STATE.entryTs) / 60));
      const h       = Math.floor(durMins / 60);
      const m       = durMins % 60;
      const durFmt  = String(h).padStart(2,'0') + 'h ' + String(m).padStart(2,'0') + 'm';

      setTxt('st-duration',  durFmt);
      setTxt('fee-duration', durFmt);
      setTxt('sum-duration', durFmt);

      if (STATE.isSubscribed) {
        setTxt('fee-total',  'FREE');
        setTxt('sum-total',  'FREE');
      } else {
        const fee    = Math.max(2, Math.ceil(durMins / 30)) * (STATE.rate / 2);
        const feeStr = 'Rs. ' + fee;
        setTxt('fee-total', feeStr);
        setTxt('sum-total', feeStr);
      }
    }
    tick();
    liveTimerID = setInterval(tick, 1000);
  }

  // ── Poll DB every 30 s ─────────────────────────────────────
  async function pollData() {
    try {
      const slotsRes  = await fetch('home.php?action=get_slots');
      const slotsData = await slotsRes.json();
      if (slotsData.success && slotsData.slots) renderSlots(slotsData.slots);

      const statRes  = await fetch('home.php?action=get_status');
      const statData = await statRes.json();
      if (statData.success && statData.session) {
        const s = statData.session;
        setTxt('st-plate',           s.plate_number);
        setTxt('st-slot',            s.slot_code);
        setTxt('plate-display-text', s.plate_number);
        setTxt('sum-plate',          s.plate_number);
        setTxt('sum-slot',           s.slot_code);
        STATE.entryTs      = s.entry_ts;
        STATE.rate         = s.rate_per_hour;
        STATE.isSubscribed = s.is_subscribed;
        STATE.subPlan      = s.sub_plan;
        setTxt('fee-rate',  STATE.isSubscribed ? '' : ('Rs. ' + Math.round(s.rate_per_hour) + ' / hour'));
        setTxt('sum-rate',  STATE.isSubscribed ? 'Subscription' : ('Rs. ' + Math.round(s.rate_per_hour) + ' / hour'));
      }

      const now = new Date();
      setTxt('last-updated-label', '— updated ' + now.toLocaleTimeString());
    } catch (e) {
      console.warn('Poll error:', e);
    }
  }

  // ── Render slot grid ───────────────────────────────────────
  function renderSlots(slots) {
    slots.forEach(s => {
      const el = document.getElementById('slot-' + s.id);
      if (!el) return;
      el.className = 'slot ' + (s.is_mine ? 'slot-mine' : (s.status === 'occupied' ? 'slot-occ' : 'slot-avail'));
      el.title     = s.is_mine ? 'Your slot' : (s.status === 'occupied' ? 'Occupied' : 'Available');
      const existing = el.querySelector('.mine-icon');
      if (s.is_mine && !existing) {
        const pin = document.createElement('span');
        pin.className = 'mine-icon';
        pin.innerHTML = '<i class="fa-solid fa-car"></i>';
        el.appendChild(pin);
      } else if (!s.is_mine && existing) {
        existing.remove();
      }
    });
  }

  // ══════════════════════════════════════════════════════════
  //  STRIPE MODAL
  // ══════════════════════════════════════════════════════════

  // Initialize Stripe Elements inside the modal (lazy — waits for Stripe.js)
  function initStripeElements() {
    if (stripeCard) return; // already initialized

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

    stripeCard.on('focus', () => {
      document.getElementById('stripe-card-element').classList.add('focused');
    });
    stripeCard.on('blur', () => {
      document.getElementById('stripe-card-element').classList.remove('focused');
    });
    stripeCard.on('change', e => {
      document.getElementById('stripe-card-errors').textContent = e.error ? e.error.message : '';
    });

    return true;
  }

  // Open modal: request a PaymentIntent from server, then mount card
  async function openStripeModal() {
    const overlay = document.getElementById('stripe-modal-overlay');
    const submitBtn = document.getElementById('stripe-submit-btn');
    const label     = document.getElementById('stripe-submit-label');

    overlay.classList.add('open');
    submitBtn.disabled = true;
    label.textContent  = 'Preparing…';

    // Show loading placeholders
    setTxt('modal-duration-label', 'Calculating duration…');
    setTxt('modal-npr-amount', 'Rs. —');
    setTxt('modal-usd-amount', '');

    try {
      const res  = await fetch('home.php?action=stripe_create_intent', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({ session_id: STATE.sessionId }),
      });
      const data = await res.json();

      if (!data.success) {
        closeStripeModal();
        showToast('error', data.message || 'Could not initialize payment.');
        return;
      }

      currentPI = data;

      // Update modal amounts
      const nowTs   = Math.floor(Date.now() / 1000);
      const durMins = Math.max(1, Math.floor((nowTs - STATE.entryTs) / 60));
      const h       = Math.floor(durMins / 60);
      const m       = durMins % 60;
      setTxt('modal-duration-label', `${String(h).padStart(2,'0')}h ${String(m).padStart(2,'0')}m parked`);
      setTxt('modal-npr-amount', data.amount_display);
      setTxt('modal-usd-amount', `~$${(data.amount_cents / 100).toFixed(2)} USD charged to card`);

      // Init Stripe Elements
      if (!initStripeElements()) {
        closeStripeModal(); return;
      }

      label.textContent  = 'Pay Now';
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
    const label     = document.getElementById('stripe-submit-label');
    const errEl     = document.getElementById('stripe-card-errors');

    submitBtn.disabled = true;
    label.textContent  = 'Processing…';
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Processing…</span>';
    errEl.textContent  = '';

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

      // 2. Payment confirmed by Stripe → tell server to close session
      const cfRes  = await fetch('home.php?action=stripe_checkout', {
        method : 'POST',
        headers: { 'Content-Type': 'application/json' },
        body   : JSON.stringify({
          session_id       : STATE.sessionId,
          payment_intent_id: currentPI.payment_intent_id,
        }),
      });
      const cfData = await cfRes.json();

      if (cfData.success) {
        paymentDone = true;
        clearInterval(liveTimerID);
        clearInterval(pollIntervalID);

        // Animate success in modal
        submitBtn.classList.add('success');
        submitBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> <span>Paid — ' + cfData.amount + '</span>';

        setTimeout(() => {
          closeStripeModal();
          // Update main pay button
          const mainBtn = document.getElementById('pay-btn');
          if (mainBtn) {
            mainBtn.disabled = true;
            mainBtn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Paid via Stripe — ' + cfData.amount;
          }
          showToast('success', '✓ Payment successful! Tx: ' + cfData.transaction_id);
          setTimeout(pollData, 800);
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
  //  MAIN PAYMENT HANDLER (dispatcher)
  // ══════════════════════════════════════════════════════════
  async function handlePayment() {
    if (!STATE.hasSession || paymentDone) return;

    // Subscribers: free checkout — no card needed
    if (STATE.isSubscribed) {
      const btn = document.getElementById('pay-btn');
      btn.disabled = true;
      btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking out…';

      try {
        const res  = await fetch('home.php?action=process_payment', {
          method : 'POST',
          headers: { 'Content-Type': 'application/json' },
          body   : JSON.stringify({ session_id: STATE.sessionId, method: 'subscription' }),
        });
        const data = await res.json();

        if (data.success) {
          paymentDone = true;
          clearInterval(liveTimerID);
          clearInterval(pollIntervalID);
          btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Checked Out — Free';
          showToast('success', 'Checked out! (Subscription — no charge)');
          setTimeout(pollData, 800);
        } else {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Check Out (Free) <i class="fa-solid fa-arrow-right"></i>';
          showToast('error', data.message || 'Checkout failed.');
        }
      } catch (e) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Check Out (Free) <i class="fa-solid fa-arrow-right"></i>';
        showToast('error', 'Network error. Please try again.');
      }
      return;
    }

    // Non-subscribers: route by selected payment method
    if (selectedPM === 'stripe') {
      openStripeModal();
    } else {
      // eSewa / Khalti — demo placeholder (not yet integrated)
      showToast('error', `${selectedPM.charAt(0).toUpperCase() + selectedPM.slice(1)} integration coming soon. Use Stripe for now.`);
    }
  }

  // ── Logout ─────────────────────────────────────────────────
  async function handleLogout() {
    localStorage.removeItem('parkify_user');
    localStorage.removeItem('parkify_token');
    await fetch('home.php?action=logout');
    window.location.href = '../login/login.php';
  }

  // ── Utility ───────────────────────────────────────────────
  function setTxt(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
  }

  function showToast(type, msg) {
    const t = document.getElementById('toast');
    document.getElementById('toast-msg').textContent = msg;
    t.className = '';
    void t.offsetWidth;
    t.classList.add('show', type);
    setTimeout(() => t.classList.remove('show'), 4500);
  }

  // ── Boot ──────────────────────────────────────────────────
  window.addEventListener('DOMContentLoaded', () => {
    startLiveTimer();
    pollData();
    pollIntervalID = setInterval(pollData, 30000);

    // Modal event listeners
    document.getElementById('modal-close-btn').addEventListener('click', closeStripeModal);
    document.getElementById('modal-cancel-btn').addEventListener('click', closeStripeModal);
    document.getElementById('stripe-modal-overlay').addEventListener('click', e => {
      if (e.target === e.currentTarget) closeStripeModal(); // click outside
    });
    document.getElementById('stripe-submit-btn').addEventListener('click', submitStripePayment);

    // ESC key closes modal
    document.addEventListener('keydown', e => {
      if (e.key === 'Escape') closeStripeModal();
    });
  });
</script>
</body>
</html>