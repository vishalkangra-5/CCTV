<?php
require_once __DIR__ . '/../config/config.php';

function init_cctv_session() {
    if (php_sapi_name() === 'cli') return;
    if (session_status() === PHP_SESSION_NONE) {
        $sName = defined('SESSION_NAME') ? SESSION_NAME : 'camportal_admin';
        session_name($sName);
        session_set_cookie_params([
            "lifetime" => 86400,
            "path"     => "/",
            "domain"   => "",
            "secure"   => false,
            "httponly" => true,
            "samesite" => "Lax"
        ]);
        session_start();
    }
}

function h($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

function login_url() {
    $base = defined('APP_BASE_PATH') ? APP_BASE_PATH : '';
    return $base . '/admin/login.php';
}

function is_logged_in() {
    init_cctv_session();
    return !empty($_SESSION['admin_id']) || !empty($_SESSION['user_id']) || !empty($_SESSION['admin']);
}

/** Full admin_users row for the logged-in user, or null. Cached per-request. */
function current_user() {
    static $user = false; // false = not looked up yet; null = looked up, no session
    if ($user !== false) return $user;
    init_cctv_session();
    $id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
    if (!$id) return $user = null;
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    return $user = ($stmt->fetch() ?: null);
}

function is_admin() {
    $u = current_user();
    if (!$u) return false;
    // A missing/null role (migration 003 not run yet) is treated as admin —
    // matches every account's actual access before roles existed, so nobody
    // gets locked out just because the migration hasn't been applied yet.
    return empty($u['role']) || $u['role'] === 'admin';
}

/** Camera IDs this user may see, or null meaning "all" (admins, or anyone
 * with no role column / a NULL role from before this feature existed). */
function allowed_camera_ids() {
    $u = current_user();
    if (!$u || empty($u['role']) || $u['role'] === 'admin') return null;
    $stmt = db()->prepare('SELECT camera_id FROM camera_permissions WHERE user_id = ?');
    $stmt->execute([$u['id']]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Writes go2rtc.yaml from the current camera list and restarts the service.
 * Returns ['stream_count'=>int, 'write_ok'=>bool, 'restart_ok'=>bool,
 * 'restart_log'=>string, 'yaml'=>string]. Shared by go2rtc_sync.php (the
 * manual "Regenerate" button) and camera_form.php (auto-run after every save). */
function sync_go2rtc_config(): array {
    $cams = db()->query('SELECT * FROM cameras WHERE enabled = 1')->fetchAll(PDO::FETCH_ASSOC);

    $lines = ["streams:"];
    foreach ($cams as $c) {
        $safeName = str_replace('"', '\"', $c['name']);
        $mainUrl  = camera_rtsp_url($c);
        $subUrl   = camera_rtsp_url_sub($c);
        $safeMain = str_replace('"', '\"', $mainUrl);

        if ($subUrl !== null) {
            $safeSub = str_replace('"', '\"', $subUrl);
            $lines[] = '  "' . $safeName . '":';
            $lines[] = '    - "' . $safeMain . '"';
            $lines[] = '    - "' . $safeSub . '"';
            $lines[] = '  "' . $safeName . '_low": "' . $safeSub . '"';
        } else {
            $lines[] = '  "' . $safeName . '": "' . $safeMain . '"';
        }
    }

    $lines[] = "api:";
    $lines[] = '  listen: ":1984"';
    $lines[] = "webrtc:";
    $lines[] = '  listen: ":8555"';
    $lines[] = '  candidates:';
    $lines[] = '    - 103.210.32.115:8555';
    $lines[] = '    - stun:stun.l.google.com:19302';

    $yaml = implode("\n", $lines) . "\n";
    $cfg = defined('GO2RTC_CONFIG_PATH') ? GO2RTC_CONFIG_PATH : '/etc/go2rtc/go2rtc.yaml';
    $writeOk = @file_put_contents($cfg, $yaml);

    $restartOk = false;
    $restartLog = '';
    if ($writeOk !== false) {
        exec('sudo /usr/bin/systemctl restart go2rtc 2>&1', $out, $code);
        $restartOk = ($code === 0);
        $restartLog = implode("\n", $out);
    }

    return [
        'stream_count' => count($cams),
        'write_ok'     => ($writeOk !== false),
        'restart_ok'   => $restartOk,
        'restart_log'  => $restartLog,
        'yaml'         => $yaml,
    ];
}
function require_login() {
    if (php_sapi_name() === 'cli') return true;
    init_cctv_session();
    if (!is_logged_in()) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . login_url() . '?next=' . $next);
        exit;
    }
}

/** Admin-panel pages (camera edit, users, recordings, go2rtc sync) — viewer-role
 * accounts can log in, but can't reach any of this, only the read-only viewer. */
function require_admin() {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('Admin access required.');
    }
}

/** For JSON endpoints (the mobile app, the viewer's fetch calls): a 401 JSON
 * body instead of an HTML redirect, since a redirect is useless to a fetch()
 * caller or the Flutter app. */
function require_login_api() {
    if (php_sapi_name() === 'cli') return true;
    init_cctv_session();
    if (!is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Not logged in']);
        exit;
    }
}

function _key_bytes() {
    if (defined('ENC_KEY_HEX')) {
        return hex2bin(ENC_KEY_HEX);
    }
    return hash('sha256', 'vdt_cctv', true);
}

function encrypt_secret($plaintext) {
    if ($plaintext === '' || $plaintext === null) return '';
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', _key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    return $iv . $tag . $ciphertext;
}

function decrypt_secret($data) {
    if (empty($data)) return '';
    if (strlen($data) < 28) return '';
    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $ciphertext = substr($data, 28);
    $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', _key_bytes(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($plain === false) {
        error_log('decrypt_secret: failed to decrypt a stored camera password (ENC_KEY_HEX mismatch or corrupt data)');
        return '';
    }
    return $plain;
}

function camera_rtsp_url($cam, $pathOverride = null) {
    if ($pathOverride === null && !empty($cam['rtsp_url'])) return $cam['rtsp_url'];
    $user = $cam['username'] ?? '';
    $pass = '';
    if (!empty($cam['password_enc'])) {
        $pass = decrypt_secret($cam['password_enc']);
    } elseif (!empty($cam['password'])) {
        $pass = $cam['password'];
    }
    $ip   = $cam['ip'] ?? '127.0.0.1';
    $port = $cam['port'] ?? 554;
    $rawPath = $pathOverride ?? ($cam['rtsp_path'] ?? ($cam['path'] ?? ''));
    $path = '/' . ltrim($rawPath, '/');
    $auth = '';
    if ($user !== '' || $pass !== '') {
        $userEnc = rawurlencode($user);
        $passEnc = rawurlencode($pass);
        $auth = $userEnc . ':' . $passEnc . '@';
    }
    return "rtsp://{$auth}{$ip}:{$port}{$path}";
}

/** The lower-resolution/compat sub-stream URL, or null if the camera has none configured. */
function camera_rtsp_url_sub($cam) {
    if (empty($cam['sub_rtsp_path'])) return null;
    return camera_rtsp_url($cam, $cam['sub_rtsp_path']);
}
