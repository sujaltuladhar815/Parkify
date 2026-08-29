<?php
// ============================================================
//  Parkify — Live Camera Frame Server
//  Called by dashboard.php every ~200 ms via <img> refresh.
//  Returns the latest JPEG frame written by plate_db_connector.py
// ============================================================

$frame = __DIR__ . '/latest_frame.jpg';

if (!file_exists($frame)) {
    // Return a 1×1 transparent placeholder so the <img> doesn't break
    header('Content-Type: image/gif');
    header('Cache-Control: no-store');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

header('Content-Type: image/jpeg');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Content-Length: ' . filesize($frame));
readfile($frame);