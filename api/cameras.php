<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

// Viewer now requires login (see viewer/index.php + api/login.php) — matches
// the web viewer and the Flutter app, which both hit this same endpoint.
require_login_api();

header('Content-Type: application/json');

$allowed = allowed_camera_ids(); // null = admin, sees everything

$sql = 'SELECT id, name, label, sub_rtsp_path FROM cameras WHERE enabled = 1';
$params = [];
if ($allowed !== null) {
    if (empty($allowed)) {
        // Viewer account with no cameras assigned yet — show nothing, not everything.
        echo json_encode(['cameras' => []]);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($allowed), '?'));
    $sql .= " AND id IN ($placeholders)";
    $params = $allowed;
}
$sql .= ' ORDER BY sort_order, id';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cameras = array_map(function ($c) {
    $mode = 'webrtc,mse,mp4,mjpeg';
    $streamUrl = GO2RTC_PUBLIC_URL . '/stream.html?src=' . rawurlencode($c['name']) . '&mode=' . $mode;
    // If this camera has a sub-stream configured, grid tiles get a dedicated
    // low-res/low-bitrate URL (go2rtc_sync.php emits it as "<name>_low") —
    // much cheaper to decode when several tiles are playing at once. No
    // sub-stream configured -> same URL as fullscreen, nothing changes.
    $thumbUrl = !empty($c['sub_rtsp_path'])
        ? GO2RTC_PUBLIC_URL . '/stream.html?src=' . rawurlencode($c['name'] . '_low') . '&mode=' . $mode
        : $streamUrl;
    return [
        'id'    => $c['id'],
        'name'  => $c['name'],
        'label' => $c['label'] !== '' ? $c['label'] : $c['name'],
        // go2rtc's built-in player page, embedded directly — no custom WebRTC JS needed.
        // mode is a fallback chain: go2rtc tries webrtc first, then falls back to
        // mse/mp4/mjpeg if the browser/device can't negotiate a WebRTC-compatible
        // codec (e.g. most DVRs' main stream is H.265, which WebRTC doesn't support
        // in any browser — this is the "codecs not matched: video:H265" error).
        'stream_url' => $streamUrl,
        // Use this one for grid/thumbnail tiles; use stream_url for a single
        // fullscreen camera.
        'thumb_url' => $thumbUrl,
    ];
}, $rows);

echo json_encode(['cameras' => $cameras]);
