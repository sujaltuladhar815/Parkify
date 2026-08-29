<?php
// ============================================================
//  Parkify — Camera Control Endpoint  (Windows / XAMPP build)
//  Called via AJAX from dashboard.php
//
//  POST /camera_control.php  { action: "start" | "stop" | "status" }
//  Returns JSON
// ============================================================

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// ── Paths ─────────────────────────────────────────────────────
define('SCRIPT_DIR',    __DIR__);
define('PID_FILE',      SCRIPT_DIR . '\\camera.pid');
define('LOG_FILE',      SCRIPT_DIR . '\\camera.log');
define('PYTHON_SCRIPT', SCRIPT_DIR . '\\plate_db_connector.py');
define('CAMERA_INDEX',  0);   // ← change if you use a different camera

// ── OS detection ──────────────────────────────────────────────
define('IS_WINDOWS', strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

// ── Helpers ───────────────────────────────────────────────────

/**
 * Find the Python executable.
 * On Windows: checks the venv first, then falls back to system Python.
 */
function find_python(): string {
    // 1. Prefer the venv inside the project (most reliable on Windows + XAMPP)
    $venv_paths = [
        SCRIPT_DIR . '\\.venv\\Scripts\\python.exe',
        SCRIPT_DIR . '\\venv\\Scripts\\python.exe',
        dirname(SCRIPT_DIR) . '\\.venv\\Scripts\\python.exe',
        dirname(SCRIPT_DIR) . '\\venv\\Scripts\\python.exe', 
    ];
    foreach ($venv_paths as $p) {
        if (file_exists($p)) return $p;
    }

    // 2. System Python — use `where` on Windows, `which` on Linux/Mac
    if (IS_WINDOWS) {
        foreach (['python', 'python3'] as $cmd) {
            $out = shell_exec("where $cmd 2>NUL");
            if ($out) {
                // `where` may return multiple lines; take the first
                $line = strtok(trim($out), "\n");
                if ($line && file_exists(trim($line))) return trim($line);
            }
        }
    } else {
        foreach (['python3', 'python'] as $cmd) {
            $out = trim(shell_exec("which $cmd 2>/dev/null") ?: '');
            if ($out && file_exists($out)) return $out;
        }
    }

    return '';
}

/** Check whether a PID is still running. */
function pid_alive(int $pid): bool {
    if ($pid <= 0) return false;

    if (IS_WINDOWS) {
        $out = shell_exec("tasklist /FI \"PID eq $pid\" /NH 2>NUL");
        return $out && strpos($out, (string)$pid) !== false;
    }

    // Linux / Mac
    if (is_dir("/proc/$pid")) return true;
    if (function_exists('posix_kill')) return @posix_kill($pid, 0);
    return false;
}

/** Read the saved PID from disk, or return 0. */
function read_pid(): int {
    if (!file_exists(PID_FILE)) return 0;
    return (int)trim(file_get_contents(PID_FILE));
}

/** Kill a running process by PID. */
function kill_pid(int $pid): void {
    if (IS_WINDOWS) {
        shell_exec("taskkill /PID $pid /F /T 2>NUL");
    } else {
        if (function_exists('posix_kill')) {
            posix_kill($pid, 15);
            sleep(1);
            if (pid_alive($pid)) posix_kill($pid, 9);
        } else {
            shell_exec("kill -15 $pid 2>/dev/null; sleep 1; kill -9 $pid 2>/dev/null");
        }
    }
}

// ── Router ────────────────────────────────────────────────────

$raw    = file_get_contents('php://input');
$body   = json_decode($raw, true);
$action = $body['action'] ?? ($_POST['action'] ?? '');

switch ($action) {

    // ── START ──────────────────────────────────────────────────
    case 'start':
        $pid = read_pid();
        if ($pid && pid_alive($pid)) {
            echo json_encode([
                'ok'      => true,
                'status'  => 'already_running',
                'pid'     => $pid,
                'message' => "Camera is already running (PID $pid).",
            ]);
            break;
        }

        // Clear stale PID
        if (file_exists(PID_FILE)) unlink(PID_FILE);

        // Locate Python
        $python = find_python();
        if (!$python) {
            echo json_encode([
                'ok'      => false,
                'status'  => 'error',
                'message' => 'Python not found. Make sure your venv is inside the Parkify folder '
                           . '(venv\\Scripts\\python.exe) or Python is added to your system PATH.',
            ]);
            break;
        }

        if (!file_exists(PYTHON_SCRIPT)) {
            echo json_encode([
                'ok'      => false,
                'status'  => 'error',
                'message' => 'plate_db_connector.py not found in: ' . SCRIPT_DIR,
            ]);
            break;
        }

        // ── Launch (Windows vs Linux differ in backgrounding) ──
        if (IS_WINDOWS) {
            $logFile = LOG_FILE;
            $script  = PYTHON_SCRIPT;
            $camIdx  = (int)CAMERA_INDEX;

            // Launch Python detached via cmd /C start /B so it outlives PHP.
            // Python writes its own real PID to camera.pid on startup,
            // so we ignore wmic's ProcessId (which is the cmd.exe wrapper PID).
            $pyCmd = "\"$python\" \"$script\" server $camIdx >> \"$logFile\" 2>&1";
            $startCmd = "cmd /C start /B \"\" " . $pyCmd;
            pclose(popen($startCmd, "r"));

            // Wait up to 8 s for Python to write its own PID
            $new_pid = 0;
            for ($i = 0; $i < 16; $i++) {
                usleep(500000); // 0.5 s
                if (file_exists(PID_FILE)) {
                    $new_pid = (int)trim(file_get_contents(PID_FILE));
                    if ($new_pid > 0) break;
                }
            }

        } else {
            $cmd     = escapeshellarg($python)
                     . ' ' . escapeshellarg(PYTHON_SCRIPT)
                     . ' server ' . (int)CAMERA_INDEX
                     . ' >> ' . escapeshellarg(LOG_FILE)
                     . ' 2>&1 & echo $!';
            $new_pid = (int)shell_exec($cmd);
        }

        if ($new_pid <= 0) {
            echo json_encode([
                'ok'      => false,
                'status'  => 'error',
                'message' => 'Python started but did not write its PID in time. Check camera.log for errors.',
            ]);
            break;
        }

        // Python already wrote camera.pid itself; write again to be safe
        file_put_contents(PID_FILE, $new_pid);

        echo json_encode([
            'ok'      => true,
            'status'  => 'started',
            'pid'     => $new_pid,
            'python'  => $python,
            'message' => "Camera detection started (PID $new_pid).",
        ]);
        break;

    // ── STOP ───────────────────────────────────────────────────
    case 'stop':
        $pid = read_pid();

        // On Windows sentinel fallback — kill by script name
        if (IS_WINDOWS && $pid === 1) {
            shell_exec('taskkill /IM python.exe /F /T 2>NUL');
            if (file_exists(PID_FILE)) unlink(PID_FILE);
            echo json_encode(['ok' => true, 'status' => 'stopped', 'message' => 'Camera detection stopped.']);
            break;
        }

        if (!$pid || !pid_alive($pid)) {
            if (file_exists(PID_FILE)) unlink(PID_FILE);
            echo json_encode(['ok' => true, 'status' => 'not_running', 'message' => 'Camera was not running.']);
            break;
        }

        kill_pid($pid);
        if (file_exists(PID_FILE)) unlink(PID_FILE);

        echo json_encode([
            'ok'      => true,
            'status'  => 'stopped',
            'pid'     => $pid,
            'message' => "Camera detection stopped (was PID $pid).",
        ]);
        break;

    // ── STATUS ─────────────────────────────────────────────────
    case 'status':
    default:
        $pid     = read_pid();
        $running = $pid && pid_alive($pid);

        if ($pid && !$running && file_exists(PID_FILE)) unlink(PID_FILE);

        echo json_encode([
            'ok'     => true,
            'status' => $running ? 'running' : 'stopped',
            'pid'    => $running ? $pid : null,
        ]);
        break;
}