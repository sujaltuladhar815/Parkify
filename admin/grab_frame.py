import cv2
import sys
import os

# ── CONFIG ───────────────────────────
CAMERA_INDEX = 1
SAVE_PATH    = "frame.jpg"
# ─────────────────────────────────────

cap = cv2.VideoCapture(CAMERA_INDEX)
if not cap.isOpened():
    print(f"Error: cannot open camera {CAMERA_INDEX}")
    sys.exit(1)

print("Live preview — press SPACE to capture, Q to quit\n")

while True:
    ret, frame = cap.read()
    if not ret:
        print("Error: failed to read frame")
        break

    # Show instructions on the frame
    display = frame.copy()
    cv2.putText(display, "SPACE = capture frame   Q = quit",
                (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.8, (0, 220, 0), 2)

    cv2.imshow("grab_frame — position plate then press SPACE", display)

    key = cv2.waitKey(1) & 0xFF
    if key == ord(' '):
        cv2.imwrite(SAVE_PATH, frame)
        saved = os.path.abspath(SAVE_PATH)
        print(f"Frame saved to: {saved}")
        print(f"Now run:  python test.py {SAVE_PATH}")
        # Flash confirmation on screen
        confirm = frame.copy()
        cv2.putText(confirm, "SAVED!", (10, 30),
                    cv2.FONT_HERSHEY_SIMPLEX, 1.2, (0, 220, 0), 3)
        cv2.imshow("grab_frame — position plate then press SPACE", confirm)
        cv2.waitKey(1000)
        break
    elif key == ord('q'):
        print("Quit — no frame saved.")
        break

cap.release()
cv2.destroyAllWindows()