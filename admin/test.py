import cv2
import numpy as np
import easyocr
import re
import sys
import os
from ultralytics import YOLO

# ── CONFIG ────────────────────────────────────────────────────
MODEL_PATH = r"C:\xampp\htdocs\Parkify\admin\runs\detect\train\weights\best.pt"
CONFIDENCE = 0.5
# ─────────────────────────────────────────────────────────────

print("Loading EasyOCR...")
READER = easyocr.Reader(['en'], gpu=False, verbose=False)
print("EasyOCR ready\n")


def upscale(img: np.ndarray, min_h: int = 80) -> np.ndarray:
    h, w = img.shape[:2]
    if h < min_h:
        scale = min_h / h
        img = cv2.resize(img, (int(w * scale), int(h * scale)),
                         interpolation=cv2.INTER_LANCZOS4)
    return img


def clean(text: str) -> str:
    return re.sub(r"[^A-Z0-9\-]", "", text.upper().strip())


def ocr_plate(crop: np.ndarray):
    """Returns (plate_text, confidence)."""
    crop = upscale(crop, min_h=80)
    results = READER.readtext(
        crop,
        allowlist="ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-",
        detail=1,
    )
    hits = [(r[0][0][0], clean(r[1]), r[2])
            for r in results if r[2] > 0.1 and clean(r[1])]
    hits.sort(key=lambda x: x[0])  # left → right

    text = "".join(h[1] for h in hits)
    conf = (sum(h[2] for h in hits) / len(hits)) if hits else 0.0
    return text, conf


def draw(image, x1, y1, x2, y2, plate_text, det_conf, ocr_conf):
    color = (0, 220, 0) if plate_text else (0, 165, 255)
    cv2.rectangle(image, (x1, y1), (x2, y2), color, 2)

    label = plate_text if plate_text else "unreadable"
    display = f"{label}  [det:{det_conf:.2f} ocr:{ocr_conf:.2f}]"

    (tw, th), _ = cv2.getTextSize(display, cv2.FONT_HERSHEY_SIMPLEX, 0.75, 2)
    bg_y1 = max(y1 - th - 12, 0)
    cv2.rectangle(image, (x1, bg_y1), (x1 + tw + 8, y1), color, -1)
    cv2.putText(image, display, (x1 + 4, y1 - 5),
                cv2.FONT_HERSHEY_SIMPLEX, 0.75, (0, 0, 0), 2)


def run(image_path: str):
    if not os.path.exists(image_path):
        print(f"Error: file not found — {image_path}")
        sys.exit(1)

    print(f"Loading model from: {MODEL_PATH}")
    model = YOLO(MODEL_PATH)

    image = cv2.imread(image_path)
    if image is None:
        print(f"Error: could not read image — {image_path}")
        sys.exit(1)

    print(f"Running detection on: {image_path}\n")
    detections = model(image, conf=CONFIDENCE, verbose=False)
    boxes = detections[0].boxes

    if not boxes or len(boxes) == 0:
        print("No plates detected.")
    else:
        print(f"{len(boxes)} plate(s) detected:\n")
        for i, box in enumerate(boxes):
            x1, y1, x2, y2 = map(int, box.xyxy[0])
            det_conf = float(box.conf[0])
            pad = 6
            crop = image[max(0, y1 - pad):y2 + pad, max(0, x1 - pad):x2 + pad]
            text, ocr_conf = ocr_plate(crop) if crop.size > 0 else ("", 0.0)

            print(f"  Plate {i + 1}: {text or '(unreadable)'}")
            print(f"           det confidence : {det_conf:.2f}")
            print(f"           ocr confidence : {ocr_conf:.2f}\n")

            draw(image, x1, y1, x2, y2, text, det_conf, ocr_conf)

    # Save annotated image next to the input file
    base, ext = os.path.splitext(image_path)
    out_path = base + "_result" + ext
    cv2.imwrite(out_path, image)
    print(f"Saved result to: {out_path}")

    cv2.imshow("Plate Detection", image)
    print("Press any key to close...")
    cv2.waitKey(0)
    cv2.destroyAllWindows()


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage:  python test.py <image_path>")
        print("Example: python test.py car.jpg")
        sys.exit(1)

    run(sys.argv[1])