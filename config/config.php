<?php
/**
 * Central config. Edit these for your environment.
 * Keep this file OUTSIDE the web-served root in production if possible,
 * or restrict via .htaccess / nginx location block.
 */

// --- Database ---
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'camera_portal');
define('DB_USER', 'cctv');
define('DB_PASS', 'YourPassword');

// --- Camera credential encryption ---
// Generate once with: php -r "echo bin2hex(random_bytes(32));"
// Store the result here. Losing this key means losing access to saved camera passwords.
define('ENC_KEY_HEX', 'XXXXXXinsert Key');

// --- go2rtc ---
// Host:port where go2rtc's HTTP/WebRTC API is reachable from the BROWSER
// (not from the server — this is what the viewer's <iframe> will hit directly).
define('GO2RTC_PUBLIC_URL', 'http://yourip');

// Path go2rtc's config file lives at (the sync script writes here)
define('GO2RTC_CONFIG_PATH', '/etc/go2rtc/go2rtc.yaml');

// Must match worker/config.py's RECORDING_DIR — used by admin/recordings.php
// to list/serve the files the Python worker's recorder.py writes.
define('RECORDING_DIR', '/var/camera-portal/recordings');
// The nginx `internal;` location that maps to RECORDING_DIR — see README.
define('RECORDING_URL_PREFIX', '/recordings');

// --- Session / auth ---
define('SESSION_NAME', 'camportal_admin');

// The URL path this app is deployed under (no trailing slash). Used to build
// an absolute redirect to the login page from any depth (viewer/, admin/,
// etc.) — a relative "login.php" redirect breaks depending on which
// directory the visitor was in when their session expired.
define('APP_BASE_PATH', '/cctv');

date_default_timezone_set('Asia/Kolkata');
