<?php
// ============================================================
//  Parkify — Database Setup
//  Run once: php database.php
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');   // ← change me
define('DB_PASS', '');       // ← change me
define('DB_NAME', 'parkify_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS);
if ($conn->connect_error) {
    die("❌  Connection failed: " . $conn->connect_error . "\n");
}

$conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$conn->select_db(DB_NAME);
echo "✅  Database `" . DB_NAME . "` ready\n\n";

function run(mysqli $db, string $name, string $sql): void {
    if ($db->query($sql)) {
        echo "✅  Table `$name` ready\n";
    } else {
        echo "❌  Error on `$name`: " . $db->error . "\n";
    }
}

// ── TABLE 1: users ───────────────────────────────────────────
run($conn, 'users', "
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    full_name     VARCHAR(120) NOT NULL,
    email         VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) DEFAULT NULL,
    google_id     VARCHAR(100) DEFAULT NULL UNIQUE,
    avatar_url    VARCHAR(500) DEFAULT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'user',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
");

// ── TABLE 2: vehicles ────────────────────────────────────────
run($conn, 'vehicles', "
CREATE TABLE IF NOT EXISTS vehicles (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT         NOT NULL,
    plate_number VARCHAR(30) NOT NULL UNIQUE,
    make         VARCHAR(60) DEFAULT NULL,
    model        VARCHAR(60) DEFAULT NULL,
    color        VARCHAR(40) DEFAULT NULL,
    created_at   TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
");

// ── TABLE 3: parking_slots ───────────────────────────────────
run($conn, 'parking_slots', "
CREATE TABLE IF NOT EXISTS parking_slots (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    slot_code   VARCHAR(10) NOT NULL UNIQUE,
    row_label   VARCHAR(5)  NOT NULL,
    slot_number INT         NOT NULL,
    status      VARCHAR(20) NOT NULL DEFAULT 'available'
) ENGINE=InnoDB;
");

// Seed slots A1–D4
$slots = [];
foreach (['A', 'B', 'C', 'D'] as $row) {
    for ($n = 1; $n <= 4; $n++) {
        $slots[] = "('$row$n', '$row', $n, 'available')";
    }
}
$conn->query("INSERT IGNORE INTO parking_slots (slot_code, row_label, slot_number, status) VALUES " . implode(',', $slots));
echo "   ↳  Seeded slots A1–D4\n";

// ── TABLE 4: parking_sessions ────────────────────────────────
run($conn, 'parking_sessions', "
CREATE TABLE IF NOT EXISTS parking_sessions (
    id            INT         AUTO_INCREMENT PRIMARY KEY,
    user_id       INT         DEFAULT NULL,
    vehicle_id    INT         NOT NULL,
    slot_id       INT         NOT NULL,
    plate_number  VARCHAR(30) NOT NULL,
    entry_time    DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    exit_time     DATETIME    DEFAULT NULL,
    duration_mins INT         DEFAULT NULL,
    status        VARCHAR(20) NOT NULL DEFAULT 'active',
    FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE SET NULL,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id)      ON DELETE CASCADE,
    FOREIGN KEY (slot_id)    REFERENCES parking_slots(id) ON DELETE CASCADE
) ENGINE=InnoDB;
");

// ── TABLE 5: payments ────────────────────────────────────────
run($conn, 'payments', "
CREATE TABLE IF NOT EXISTS payments (
    id             INT           AUTO_INCREMENT PRIMARY KEY,
    session_id     INT           NOT NULL,
    user_id        INT           DEFAULT NULL,
    amount         DECIMAL(10,2) NOT NULL,
    rate_per_hour  DECIMAL(6,2)  NOT NULL DEFAULT 20.00,
    method         VARCHAR(30)   NOT NULL DEFAULT 'cash',
    status         VARCHAR(20)   NOT NULL DEFAULT 'pending',
    transaction_id VARCHAR(120)  DEFAULT NULL,
    paid_at        DATETIME      DEFAULT NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES parking_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)            ON DELETE SET NULL
) ENGINE=InnoDB;
");

// ── TABLE 6: plans ───────────────────────────────────────────
run($conn, 'plans', "
CREATE TABLE IF NOT EXISTS plans (
    id          INT           AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60)   NOT NULL UNIQUE,
    label       VARCHAR(80)   NOT NULL,
    price       DECIMAL(10,2) NOT NULL,
    billing     VARCHAR(20)   NOT NULL DEFAULT 'day',
    max_hours   INT           DEFAULT NULL,
    description TEXT          DEFAULT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
");

$conn->query("
INSERT IGNORE INTO plans (name, label, price, billing, max_hours, description) VALUES
('basic',   'Basic Plan',   50.00,   'day',   2,    'Up to 2 hours. Standard support, online payment.'),
('premium', 'Premium Plan', 100.00,  'day',   12,   'Up to 12 hours. Priority slot, digital receipt.'),
('monthly', 'Monthly Plan', 1500.00, 'month', NULL, 'Unlimited parking, full reports, priority support.')
");
echo "   ↳  Seeded 3 plans\n";

// ── TABLE 7: user_plans ──────────────────────────────────────
run($conn, 'user_plans', "
CREATE TABLE IF NOT EXISTS user_plans (
    id         INT         AUTO_INCREMENT PRIMARY KEY,
    user_id    INT         NOT NULL,
    plan_id    INT         NOT NULL,
    start_date DATE        NOT NULL,
    end_date   DATE        NOT NULL,
    status     VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)  ON DELETE CASCADE,
    FOREIGN KEY (plan_id) REFERENCES plans(id)  ON DELETE CASCADE
) ENGINE=InnoDB;
");

// ── TABLE 8: plate_scan_logs ─────────────────────────────────
run($conn, 'plate_scan_logs', "
CREATE TABLE IF NOT EXISTS plate_scan_logs (
    id                 INT         AUTO_INCREMENT PRIMARY KEY,
    raw_text           VARCHAR(60) DEFAULT NULL,
    cleaned_text       VARCHAR(30) DEFAULT NULL,
    confidence         FLOAT       DEFAULT NULL,
    matched_vehicle_id INT         DEFAULT NULL,
    action             VARCHAR(20) NOT NULL DEFAULT 'unknown',
    scanned_at         TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (matched_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB;
");

echo "\n🎉  All 8 tables created in `" . DB_NAME . "`\n";
$conn->close();