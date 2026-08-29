<?php
// ============================================================
//  Parkify — Camera Status & Scan Feed
//  Called by AJAX polling in dashboard.php every 3 seconds.
//
//  GET /camera_status.php
//  Returns JSON: { running, pid, scans[], log_tail }
// ============================================================

header('Content-Type: application/json');
header('Cache-Control: no-store');

define('PID_FILE', __DIR__ . '/camera.pid');
define('LOG_FILE', __DIR__ . '/camera.log');

// ── DB ────────────────────────────────────────────────────────
$host = 'localhost';
$user = 'root';   // ← same as database.php
$pass = '';
$db   = 'parkify_db';

function pid_alive(int $pid): bool {
    if ($pid <= 0) return false;
    // Windows
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $out = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL");
        return $out && strpos($out, (string)$pid) !== false;
    }
    // Linux / Mac
    if (is_dir("/proc/$pid")) return true;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return false;
}

// ── Camera running? ───────────────────────────────────────────
$pid     = file_exists(PID_FILE) ? (int)trim(file_get_contents(PID_FILE)) : 0;
$running = pid_alive($pid);
if ($pid && !$running && file_exists(PID_FILE)) unlink(PID_FILE);

// ── Recent scans (last 15 rows) ───────────────────────────────
$scans = [];
try {
    $conn = new mysqli($host, $user, $pass, $db);
    if (!$conn->connect_error) {
        $rs = $conn->query("
            SELECT
                psl.id,
                psl.cleaned_text   AS plate,
                psl.confidence,
                psl.action,
                psl.scanned_at,
                v.make,
                v.model,
                v.color
            FROM plate_scan_logs psl
            LEFT JOIN vehicles v ON v.id = psl.matched_vehicle_id
            ORDER BY psl.scanned_at DESC
            LIMIT 15
        ");
        while ($row = $rs->fetch_assoc()) {
            $row['confidence'] = $row['confidence'] !== null
                ? round((float)$row['confidence'] * 100, 1) . '%'
                : '—';
            $scans[] = $row;
        }
        $conn->close();
    }
} catch (Exception $e) {
    // DB not available — return empty scans, don't crash
}

// ── Last 20 lines of the log file ─────────────────────────────
$log_tail = '';
if (file_exists(LOG_FILE)) {
    $lines    = file(LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $tail     = array_slice($lines, -20);
    $log_tail = implode("\n", $tail);
}

// ── Live slot counts (for real-time stat refresh) ─────────────
$slots = ['available' => 0, 'occupied' => 0, 'total' => 0];
try {
    $conn2 = new mysqli($host, $user, $pass, $db);
    if (!$conn2->connect_error) {
        $sr = $conn2->query("SELECT status, COUNT(*) AS c FROM parking_slots GROUP BY status");
        while ($r = $sr->fetch_assoc()) {
            $slots[$r['status']] = (int)$r['c'];
            $slots['total'] += (int)$r['c'];
        }
        $conn2->close();
    }
} catch (Exception $e) {}

echo json_encode([
    'ok'       => true,
    'running'  => $running,
    'pid'      => $running ? $pid : null,
    'scans'    => $scans,
    'log_tail' => $log_tail,
    'slots'    => $slots,
]);