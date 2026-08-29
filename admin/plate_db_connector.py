"""
Parkify — License Plate Detection + Database Connector
=======================================================
Flow:
  1. YOLO detects a plate in the image / camera frame
  2. EasyOCR extracts the plate text
  3. MySQL is queried to find the matching vehicle
  4. A parking_session is opened (entry) or closed (exit)
  5. Every scan is logged in plate_scan_logs

Requirements:
    pip install opencv-python easyocr ultralytics mysql-connector-python
"""

import cv2
import numpy as np
import easyocr
import re
import sys
from datetime import datetime
from ultralytics import YOLO
import mysql.connector
from mysql.connector import Error


# ── CONFIG ────────────────────────────────────────────────────
DB_CONFIG = {
    "host":     "localhost",
    "user":     "root",       # ← change me
    "password": "",           # ← change me
    "database": "parkify_db",
}

MODEL_PATH    = r"C:\xampp\htdocs\Parkify\admin\runs\detect\train\weights\best.pt"
CONFIDENCE        = 0.5   # YOLO detection threshold
MIN_DET_CONF      = 0.65  # minimum detection confidence before attempting OCR
BEST_FRAME_BUFFER = 5     # collect this many high-conf frames, pick the sharpest
RATE_PER_HOUR = 20.00        # matches your payments.rate_per_hour default

# ── OCR QUALITY GATES ─────────────────────────────────────────
# Minimum average OCR confidence to trust a read (0.0 – 1.0).
# Reads below this are logged but never registered or admitted.
MIN_OCR_CONFIDENCE = 0.60

# Accepted plate length range (characters, after cleaning).
# Adjust to match your country's plate format.
MIN_PLATE_LEN = 4
MAX_PLATE_LEN = 10

# Minimum ratio of digits in the plate text.
# e.g. 0.2 means at least 20 % of characters must be digits.
# Prevents pure-letter garbage like "ABCDEF" being registered.
MIN_DIGIT_RATIO = 0.20



# ═══════════════════════════════════════════════════════════════
#  DATABASE HELPERS
# ═══════════════════════════════════════════════════════════════

def get_connection():
    """Return a fresh MySQL connection."""
    return mysql.connector.connect(**DB_CONFIG)


def find_vehicle(cursor, plate: str):
    """
    Look up a vehicle by plate number.
    Returns the vehicle row dict or None.
    """
    cursor.execute(
        "SELECT id, user_id, plate_number, make, model, color "
        "FROM vehicles WHERE plate_number = %s LIMIT 1",
        (plate,)
    )
    return cursor.fetchone()


def find_available_slot(cursor):
    """Return the first available parking slot, or None."""
    cursor.execute(
        "SELECT id, slot_code FROM parking_slots "
        "WHERE status = 'available' LIMIT 1"
    )
    return cursor.fetchone()


def get_active_session(cursor, vehicle_id: int):
    """Return an active parking session for this vehicle, or None."""
    cursor.execute(
        "SELECT id, slot_id, entry_time "
        "FROM parking_sessions "
        "WHERE vehicle_id = %s AND status = 'active' LIMIT 1",
        (vehicle_id,)
    )
    return cursor.fetchone()


def open_session(conn, cursor, vehicle, slot):
    """
    Mark slot as occupied, create a parking_sessions row.
    Returns the new session id.
    """
    # Mark slot occupied
    cursor.execute(
        "UPDATE parking_slots SET status = 'occupied' WHERE id = %s",
        (slot["id"],)
    )

    # Create session
    cursor.execute(
        """
        INSERT INTO parking_sessions
            (user_id, vehicle_id, slot_id, plate_number, entry_time, status)
        VALUES (%s, %s, %s, %s, %s, 'active')
        """,
        (
            vehicle["user_id"],
            vehicle["id"],
            slot["id"],
            vehicle["plate_number"],
            datetime.now(),
        )
    )
    conn.commit()
    return cursor.lastrowid


def close_session(conn, cursor, session, vehicle):
    """
    Compute duration, mark session completed, free the slot,
    create a pending payment row.
    Returns (duration_mins, amount).
    """
    now = datetime.now()
    entry = session["entry_time"]
    duration_mins = max(1, int((now - entry).total_seconds() / 60))
    amount = round((duration_mins / 60) * RATE_PER_HOUR, 2)

    # Update session
    cursor.execute(
        """
        UPDATE parking_sessions
        SET exit_time = %s, duration_mins = %s, status = 'completed'
        WHERE id = %s
        """,
        (now, duration_mins, session["id"])
    )

    # Free the slot
    cursor.execute(
        "UPDATE parking_slots SET status = 'available' WHERE id = %s",
        (session["slot_id"],)
    )

    # Create payment record
    cursor.execute(
        """
        INSERT INTO payments
            (session_id, user_id, amount, rate_per_hour, method, status)
        VALUES (%s, %s, %s, %s, 'cash', 'pending')
        """,
        (session["id"], vehicle["user_id"], amount, RATE_PER_HOUR)
    )

    conn.commit()
    return duration_mins, amount


def log_scan(conn, cursor, raw: str, cleaned: str, confidence: float,
             vehicle_id, action: str):
    """Insert a row into plate_scan_logs."""
    cursor.execute(
        """
        INSERT INTO plate_scan_logs
            (raw_text, cleaned_text, confidence, matched_vehicle_id, action)
        VALUES (%s, %s, %s, %s, %s)
        """,
        (raw[:60], cleaned[:30], confidence, vehicle_id, action)
    )
    conn.commit()


# ═══════════════════════════════════════════════════════════════
#  QUALITY GATES
# ═══════════════════════════════════════════════════════════════

def check_confidence(confidence: float) -> tuple[bool, str]:
    """Fail if OCR confidence is below MIN_OCR_CONFIDENCE."""
    if confidence < MIN_OCR_CONFIDENCE:
        return False, (
            f"OCR confidence {confidence:.2f} is below "
            f"threshold {MIN_OCR_CONFIDENCE:.2f} — skipped"
        )
    return True, ""


def check_plate_format(plate: str) -> tuple[bool, str]:
    """
    Fail if the plate text looks like garbage:
      - Too short or too long
      - Not enough digits (catches pure-letter noise like 'ABCDEF')
      - Contains only one unique character repeated (e.g. '111111')
    """
    if not (MIN_PLATE_LEN <= len(plate) <= MAX_PLATE_LEN):
        return False, (
            f"Plate '{plate}' length {len(plate)} outside "
            f"[{MIN_PLATE_LEN}, {MAX_PLATE_LEN}] — skipped"
        )

    digit_ratio = sum(c.isdigit() for c in plate) / len(plate)
    if digit_ratio < MIN_DIGIT_RATIO:
        return False, (
            f"Plate '{plate}' has too few digits "
            f"({digit_ratio:.0%}) — skipped"
        )

    if len(set(plate)) == 1:
        return False, f"Plate '{plate}' is all same character — skipped"

    return True, ""


def is_valid_read(plate: str, confidence: float) -> tuple[bool, str]:
    """
    Run all quality gates in order.
    Returns (True, '') if all pass, or (False, reason) on first failure.
    """
    ok, reason = check_confidence(confidence)
    if not ok:
        return False, reason

    ok, reason = check_plate_format(plate)
    if not ok:
        return False, reason

    return True, ""


def get_or_create_vehicle(conn, cursor, plate: str) -> dict:
    """
    If the plate isn't registered, auto-create it under the guest user.
    Returns the vehicle row dict.
    """
    cursor.execute(
        "SELECT id FROM users WHERE email = 'guest@parkify.local' LIMIT 1"
    )
    guest = cursor.fetchone()
    if not guest:
        raise Exception(
            "Guest user not found. Run this SQL first:\n"
            "  INSERT INTO users (full_name, email, role) "
            "VALUES ('Guest', 'guest@parkify.local', 'guest');"
        )

    cursor.execute(
        "INSERT INTO vehicles (user_id, plate_number) VALUES (%s, %s)",
        (guest["id"], plate)
    )
    conn.commit()
    new_id = cursor.lastrowid

    cursor.execute(
        "SELECT id, user_id, plate_number, make, model, color "
        "FROM vehicles WHERE id = %s",
        (new_id,)
    )
    return cursor.fetchone()


# ═══════════════════════════════════════════════════════════════
#  CORE BUSINESS LOGIC
# ═══════════════════════════════════════════════════════════════

def handle_plate(raw_text: str, cleaned_text: str, ocr_confidence: float) -> dict:
    """
    Given a detected plate string, run quality gates then the DB workflow:
      - Low confidence or bad format  → rejected, logged, no DB write
      - Plate not in DB               → auto-registered under Guest, then ENTRY
      - Plate in DB, no active session → ENTRY
      - Plate in DB, active session    → EXIT + payment created
    Returns a result dict with status details.
    """
    result = {
        "plate":      cleaned_text,
        "action":     "unknown",
        "message":    "",
        "vehicle":    None,
        "session_id": None,
    }

    if not cleaned_text:
        result["message"] = "Empty plate text — skipped"
        return result

    # ── QUALITY GATES ────────────────────────────────────────────
    valid, reason = is_valid_read(cleaned_text, ocr_confidence)
    if not valid:
        result["action"]  = "rejected"
        result["message"] = reason
        print(f"  🚫  Rejected: {reason}")

        # Still log the bad read so admins can review it
        try:
            conn   = get_connection()
            cursor = conn.cursor(dictionary=True)
            log_scan(conn, cursor, raw_text, cleaned_text,
                     ocr_confidence, None, "rejected")
        except Error:
            pass
        finally:
            if 'cursor' in locals(): cursor.close()
            if 'conn'   in locals() and conn.is_connected(): conn.close()

        return result

    # ── DATABASE WORKFLOW ────────────────────────────────────────
    try:
        conn   = get_connection()
        cursor = conn.cursor(dictionary=True)

        vehicle = find_vehicle(cursor, cleaned_text)

        if vehicle is None:
            # ── AUTO-REGISTER under Guest ────────────────────────
            print(f"  ⚠️   Plate '{cleaned_text}' not found — "
                  f"auto-registering as guest vehicle...")
            vehicle = get_or_create_vehicle(conn, cursor, cleaned_text)
            log_scan(conn, cursor, raw_text, cleaned_text,
                     ocr_confidence, vehicle["id"], "registered")

        result["vehicle"] = vehicle
        session = get_active_session(cursor, vehicle["id"])

        if session is None:
            # ── ENTRY ────────────────────────────────────────────
            slot = find_available_slot(cursor)
            if slot is None:
                result["action"]  = "no_slot"
                result["message"] = "No available parking slots"
                log_scan(conn, cursor, raw_text, cleaned_text,
                         ocr_confidence, vehicle["id"], "no_slot")
            else:
                session_id = open_session(conn, cursor, vehicle, slot)
                result["action"]     = "entry"
                result["session_id"] = session_id
                result["message"]    = (
                    f"ENTRY — {vehicle['plate_number']} "
                    f"assigned to slot {slot['slot_code']} "
                    f"(session #{session_id})"
                )
                log_scan(conn, cursor, raw_text, cleaned_text,
                         ocr_confidence, vehicle["id"], "entry")
        else:
            # ── EXIT ─────────────────────────────────────────────
            duration, amount = close_session(conn, cursor, session, vehicle)
            result["action"]     = "exit"
            result["session_id"] = session["id"]
            result["message"]    = (
                f"EXIT  — {vehicle['plate_number']} | "
                f"{duration} min | ₱{amount:.2f} due"
            )
            log_scan(conn, cursor, raw_text, cleaned_text,
                     ocr_confidence, vehicle["id"], "exit")

    except Error as e:
        result["action"]  = "db_error"
        result["message"] = f"Database error: {e}"
    finally:
        if 'cursor' in locals(): cursor.close()
        if 'conn'   in locals() and conn.is_connected(): conn.close()

    return result


# ═══════════════════════════════════════════════════════════════
#  IMAGE PROCESSING (from new.py)
# ═══════════════════════════════════════════════════════════════

def upscale(img: np.ndarray, min_h: int = 120) -> np.ndarray:
    h, w = img.shape[:2]
    if h < min_h:
        scale = min_h / h
        img = cv2.resize(img, (int(w * scale), int(h * scale)),
                         interpolation=cv2.INTER_LANCZOS4)
    return img


def preprocess_plate(crop: np.ndarray) -> np.ndarray:
    """
    Upscale, sharpen, and boost contrast so EasyOCR gets the
    clearest possible image regardless of camera distance.
    """
    # 1. Upscale to a fixed height so characters are large enough
    crop = upscale(crop, min_h=120)

    # 2. Convert to grayscale
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)

    # 3. CLAHE -- boosts local contrast (helps faded / uneven plates)
    clahe   = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(4, 4))
    gray    = clahe.apply(gray)

    # 4. Sharpening kernel
    kernel  = np.array([[ 0, -1,  0],
                         [-1,  5, -1],
                         [ 0, -1,  0]], dtype=np.float32)
    gray    = cv2.filter2D(gray, -1, kernel)

    # 5. Back to BGR so EasyOCR is happy
    return cv2.cvtColor(gray, cv2.COLOR_GRAY2BGR)


def sharpness_score(crop: np.ndarray) -> float:
    """Laplacian variance -- higher means sharper frame."""
    gray = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY) if len(crop.shape) == 3 else crop
    return float(cv2.Laplacian(gray, cv2.CV_64F).var())


def clean_ocr(text: str) -> str:
    return re.sub(r"[^A-Z0-9\-]", "", text.upper().strip())


def ocr_plate(crop: np.ndarray) -> tuple[str, str, float]:
    """
    Preprocess then OCR.
    Returns (raw_combined, cleaned, avg_confidence).
    """
    crop = preprocess_plate(crop)
    results = READER.readtext(
        crop,
        allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
        detail=1,
    )
    hits = [(r[0][0][0], clean_ocr(r[1]), r[2])
            for r in results if r[2] > 0.1 and clean_ocr(r[1])]
    hits.sort(key=lambda x: x[0])

    raw     = " ".join(h[1] for h in hits)
    cleaned = "".join(h[1] for h in hits)
    conf    = (sum(h[2] for h in hits) / len(hits)) if hits else 0.0
    return raw, cleaned, conf


def annotate(image, x1, y1, x2, y2, result: dict, det_conf: float):
    """Draw bounding box + plate text + action label onto the frame."""
    ACTION_COLORS = {
        "entry":      (0, 200, 0),    # green
        "exit":       (0, 100, 255),  # orange
        "registered": (255, 200, 0),  # cyan  (new guest vehicle)
        "rejected":   (80, 80, 80),   # dark grey (low confidence / bad format)
        "unknown":    (0, 165, 255),  # amber
        "no_slot":    (0, 0, 220),    # red
        "db_error":   (128, 0, 128),  # purple
    }
    color = ACTION_COLORS.get(result["action"], (200, 200, 200))

    cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)

    label = f"{result['plate'] or 'unreadable'}  [{det_conf:.2f}]"
    tag   = result["action"].upper()

    for i, line in enumerate([label, tag]):
        (tw, th), _ = cv2.getTextSize(line, cv2.FONT_HERSHEY_SIMPLEX, 0.7, 2)
        by = y1 - (i + 1) * (th + 8)
        by = max(by, 0)
        cv2.rectangle(image, (x1, by), (x1 + tw + 8, by + th + 6), color, -1)
        cv2.putText(image, line, (x1 + 4, by + th + 2),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.7, (0, 0, 0), 2)


# ═══════════════════════════════════════════════════════════════
#  ENTRY POINTS
# ═══════════════════════════════════════════════════════════════

def run_on_image(image_path: str):
    """Detect plates in a static image, update DB, show result."""
    model = YOLO(MODEL_PATH)
    image = cv2.imread(image_path)
    if image is None:
        print(f"Could not load image: {image_path}")
        return

    print(f"🔍  Detecting plates in: {image_path}")
    detections = model(image, conf=CONFIDENCE, verbose=False)
    boxes = detections[0].boxes

    if not boxes or len(boxes) == 0:
        print("⚠️   No plates detected.")
    else:
        print(f"✅  {len(boxes)} plate(s) found\n")
        for i, box in enumerate(boxes):
            x1, y1, x2, y2 = map(int, box.xyxy[0])
            det_conf = float(box.conf[0])
            pad  = 6
            crop = image[max(0, y1-pad):y2+pad, max(0, x1-pad):x2+pad]

            raw, cleaned, ocr_conf = ocr_plate(crop) if crop.size > 0 else ("", "", 0.0)

            print(f"  [{i+1}] OCR: '{cleaned}'  det_conf={det_conf:.2f}  ocr_conf={ocr_conf:.2f}")

            db_result = handle_plate(raw, cleaned, ocr_conf)
            print(f"       → {db_result['message']}\n")

            annotate(image, x1, y1, x2, y2, db_result, det_conf)

    cv2.imshow("Parkify — Plate Detection", image)
    cv2.waitKey(0)
    cv2.destroyAllWindows()


def run_on_camera(camera_index: int = 0):
    """
    Live camera loop. 
    Each detected plate triggers a DB lookup on every frame where 
    a new plate text is seen (debounced: same plate won't re-trigger 
    within 5 seconds to avoid duplicate sessions).
    """
    from collections import defaultdict
    import time

    model      = YOLO(MODEL_PATH)
    cap        = cv2.VideoCapture(camera_index)
    last_seen  = defaultdict(float)   # plate → last trigger timestamp
    DEBOUNCE_S = 5.0

    print("📷  Camera started. Press 'q' to quit.\n")

    while True:
        ret, frame = cap.read()
        if not ret:
            break

        detections = model(frame, conf=CONFIDENCE, verbose=False)
        boxes      = detections[0].boxes

        if boxes:
            for box in boxes:
                x1, y1, x2, y2 = map(int, box.xyxy[0])
                det_conf = float(box.conf[0])
                pad  = 6
                crop = frame[max(0, y1-pad):y2+pad, max(0, x1-pad):x2+pad]

                raw, cleaned, ocr_conf = ocr_plate(crop) if crop.size > 0 else ("", "", 0.0)
                if not cleaned:
                    continue

                now = time.time()
                if now - last_seen[cleaned] > DEBOUNCE_S:
                    last_seen[cleaned] = now
                    db_result = handle_plate(raw, cleaned, ocr_conf)
                    print(f"[{datetime.now():%H:%M:%S}] {db_result['message']}")
                else:
                    # Reuse last known action for annotation colour
                    db_result = {"plate": cleaned, "action": "entry", "message": ""}

                annotate(frame, x1, y1, x2, y2, db_result, det_conf)

        cv2.imshow("Parkify — Live Camera", frame)
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break

    cap.release()
    cv2.destroyAllWindows()


def run_server_mode(camera_index: int = 0):
    """
    Headless server mode — no GUI windows, designed to be started/stopped
    by the PHP dashboard (camera_control.php).

    Each detected plate triggers a DB lookup with a 5-second debounce.
    Results are printed to stdout (captured in camera.log) and written
    to plate_scan_logs in the database.

    Stop gracefully with SIGTERM (kill <pid>) or SIGINT (Ctrl-C).
    """
    import signal
    import time
    from collections import defaultdict
    import os

    FRAME_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "latest_frame.jpg")
    PID_FILE   = os.path.join(os.path.dirname(os.path.abspath(__file__)), "camera.pid")

    # Write our own PID -- wmic returns the cmd.exe wrapper PID, not ours
    with open(PID_FILE, 'w') as _pf:
        _pf.write(str(os.getpid()))
    print(f"[SERVER] PID {os.getpid()} written to camera.pid", flush=True)

    stop_flag = [False]

    def _handle_signal(signum, frame):
        print(f"[SERVER] Signal {signum} received — shutting down...", flush=True)
        stop_flag[0] = True

    signal.signal(signal.SIGTERM, _handle_signal)
    signal.signal(signal.SIGINT,  _handle_signal)

    print(f"[SERVER] Parkify plate-detection server starting (camera {camera_index})...", flush=True)
    global READER
    READER = easyocr.Reader(['en'], gpu=False, verbose=False)
    print("[SERVER] EasyOCR ready.", flush=True)
    try:
        model = YOLO(MODEL_PATH)
    except Exception as e:
        print(f"[SERVER] ❌ Failed to load YOLO model: {e}", flush=True)
        sys.exit(1)

    cap = cv2.VideoCapture(camera_index)
    if not cap.isOpened():
        print(f"[SERVER] ❌ Cannot open camera index {camera_index}", flush=True)
        sys.exit(1)

    last_seen   = defaultdict(float)
    DEBOUNCE_S  = 5.0
    # best-frame buffer: track (det_conf, crop) for each box position
    # key = rough box centre, value = list of (det_conf, sharpness, crop)
    frame_buffer = defaultdict(list)

    print(f"[SERVER] ✅ Camera {camera_index} opened. Listening for plates...", flush=True)

    while not stop_flag[0]:
        ret, frame = cap.read()
        if not ret:
            time.sleep(0.05)
            continue

        detections = model(frame, conf=CONFIDENCE, verbose=False)
        boxes      = detections[0].boxes

        if boxes:
            for box in boxes:
                x1, y1, x2, y2 = map(int, box.xyxy[0])
                det_conf = float(box.conf[0])

                # Skip low-confidence detections entirely
                if det_conf < MIN_DET_CONF:
                    continue

                pad  = 6
                crop = frame[max(0, y1-pad):y2+pad, max(0, x1-pad):x2+pad]
                if crop.size == 0:
                    continue

                # Use box centre as the buffer key (rounded to nearest 20px)
                cx = ((x1 + x2) // 2 // 20) * 20
                cy = ((y1 + y2) // 2 // 20) * 20
                buf_key = (cx, cy)

                sharp = sharpness_score(crop)
                frame_buffer[buf_key].append((det_conf, sharp, crop))

                # Once we have enough frames, pick the sharpest and OCR it
                if len(frame_buffer[buf_key]) >= BEST_FRAME_BUFFER:
                    best_crop = max(frame_buffer[buf_key],
                                    key=lambda t: t[0] * 0.4 + t[1] * 0.6)[2]
                    frame_buffer[buf_key].clear()

                    raw, cleaned, ocr_conf = ocr_plate(best_crop)
                    if not cleaned:
                        continue

                    now = time.time()
                    if now - last_seen[cleaned] > DEBOUNCE_S:
                        last_seen[cleaned] = now
                        db_result = handle_plate(raw, cleaned, ocr_conf)
                        print(
                            f"[{datetime.now():%Y-%m-%d %H:%M:%S}] "
                            f"action={db_result['action']} "
                            f"plate={db_result['plate']} "
                            f"det={det_conf:.2f} ocr={ocr_conf:.2f} | "
                            f"{db_result['message']}",
                            flush=True
                        )

        cv2.imshow("Parkify — Live Camera", frame) if False else None  # headless
        # Save latest frame so frame.php can serve it as a live preview
        try:
            cv2.imwrite(FRAME_FILE, frame)
        except Exception:
            pass

    cap.release()
    # Clean up PID file and last frame on exit
    for _f in (PID_FILE, FRAME_FILE):
        try:
            if os.path.exists(_f): os.remove(_f)
        except Exception:
            pass
    print("[SERVER] Camera released. Goodbye.", flush=True)


# ── MAIN ──────────────────────────────────────────────────────
if __name__ == "__main__":
    if len(sys.argv) > 1:
        arg = sys.argv[1]
        if arg == "server":
            # Started by PHP dashboard: python plate_db_connector.py server [camera_index]
            cam_idx = int(sys.argv[2]) if len(sys.argv) > 2 else 0
            run_server_mode(cam_idx)
        elif arg.isdigit():
            run_on_camera(int(arg))       # python plate_db_connector.py 0
        else:
            run_on_image(arg)             # python plate_db_connector.py photo.jpg
    else:
        run_on_image("more.png")          # default: static image test