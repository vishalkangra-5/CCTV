<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

require_admin();

$camera = $_GET['camera'] ?? '';
$date   = $_GET['date'] ?? '';
$file   = $_GET['file'] ?? '';
$mode   = ($_GET['mode'] ?? 'download') === 'play' ? 'play' : 'download';

// Strict validation — this builds a filesystem path from GET params, so no
// "..", no slashes, nothing but the exact charsets each part is allowed to be.
if (!preg_match('/^[A-Za-z0-9_\-]+$/', $camera)
    || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
    || !preg_match('/^[A-Za-z0-9_\-]+\.mp4$/', $file)) {
    http_response_code(400);
    exit('Bad request');
}

$relative = "/$camera/$date/$file";
$absolute = RECORDING_DIR . $relative;
if (!is_file($absolute)) {
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: video/mp4');
header('Content-Disposition: ' . ($mode === 'play' ? 'inline' : 'attachment') . '; filename="' . $file . '"');

// Nginx must have a matching `internal;` location for RECORDING_URL_PREFIX
// pointing at RECORDING_DIR — see README "Serving recordings" section. This
// keeps every recording behind require_login() above: nginx will only ever
// serve these bytes when PHP tells it to via this header, never on a direct
// request to the URL.
header('X-Accel-Redirect: ' . RECORDING_URL_PREFIX . $relative);
