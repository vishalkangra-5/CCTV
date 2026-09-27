<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

$id  = isset($_GET['id']) ? (int)$_GET['id'] : null;
$cam = ['name'=>'','label'=>'','ip'=>'','port'=>554,'rtsp_path'=>'/','sub_rtsp_path'=>'','username'=>'',
        'detect_object'=>0,'detect_face'=>0,'recording_mode'=>'off','enabled'=>1,'sort_order'=>0];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM cameras WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if ($row) $cam = $row;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name      = trim($_POST['name']);
    $label     = trim($_POST['label']);
    $ip        = trim($_POST['ip']);
    $port      = (int)$_POST['port'];
    $path      = trim($_POST['rtsp_path']) ?: '/';
    $subPath   = trim($_POST['sub_rtsp_path'] ?? '');
    $subPath   = ($subPath === '') ? null : $subPath;
    $username  = trim($_POST['username']);
    $password  = $_POST['password'] ?? '';
    $detObj    = isset($_POST['detect_object']) ? 1 : 0;
    $detFace   = isset($_POST['detect_face']) ? 1 : 0;
    $recMode   = $_POST['recording_mode'] ?? 'off';
    if (!in_array($recMode, ['off','continuous','motion'], true)) $recMode = 'off';
    $enabled   = isset($_POST['enabled']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    if ($name === '' || $ip === '') {
        $error = 'Name and IP are required.';
    } elseif (!preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
        $error = 'Name may only contain letters, numbers, underscore and hyphen (no spaces, colons, etc).';
    } else {
        if ($id) {
            $sql = 'UPDATE cameras SET name=?,label=?,ip=?,port=?,rtsp_path=?,sub_rtsp_path=?,username=?,
                    detect_object=?,detect_face=?,recording_mode=?,enabled=?,sort_order=?';
            $params = [$name,$label,$ip,$port,$path,$subPath,$username,$detObj,$detFace,$recMode,$enabled,$sortOrder];
            if ($password !== '') {
                $sql .= ',password_enc=?';
                $params[] = encrypt_secret($password);
            }
            $sql .= ' WHERE id=?';
            $params[] = $id;
            db()->prepare($sql)->execute($params);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO cameras (name,label,ip,port,rtsp_path,sub_rtsp_path,username,password_enc,
                 detect_object,detect_face,recording_mode,enabled,sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([$name,$label,$ip,$port,$path,$subPath,$username,
                encrypt_secret($password),$detObj,$detFace,$recMode,$enabled,$sortOrder]);
        }

        // Auto-regenerate go2rtc's config and restart it right here — no more
        // separate "Regenerate go2rtc config" click after every edit. If the
        // restart step fails (e.g. the sudoers rule breaks again), you still
        // find out immediately via the banner on index.php instead of silently
        // running on a stale config.
        $sync = sync_go2rtc_config();
        $_SESSION['go2rtc_sync_result'] = $sync;

        header('Location: index.php');
        exit;
    }
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title><?= $id ? 'Edit' : 'Add' ?> Camera</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;margin:0;padding:24px}
  form{max-width:480px}
  label{display:block;margin-top:14px;font-size:13px;color:#9aa4b2}
  input[type=text],input[type=password],input[type=number],select{
    width:100%;padding:8px;margin-top:4px;border-radius:4px;border:1px solid #333;
    background:#1a1d24;color:#fff;box-sizing:border-box}
  .hint{color:#888;font-size:12px;margin:4px 0 0}
  .row{display:flex;gap:16px}
  .row>div{flex:1}
  .checks{display:flex;gap:20px;margin-top:16px;align-items:center}
  .checks label{margin:0;display:flex;align-items:center;gap:6px;color:#e6e6e6}
  button{margin-top:20px;padding:10px 18px;background:#3b82f6;border:none;border-radius:4px;
    color:#fff;font-weight:600;cursor:pointer}
  .err{color:#f87171}
  a{color:#93c5fd}
</style>
</head>
<body>
<p><a href="index.php">&larr; Back to cameras</a></p>
<h2><?= $id ? 'Edit camera' : 'Add camera' ?></h2>
<?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
<form method="post">
  <div class="row">
    <div>
      <label>Name (id, used internally — no spaces)</label>
      <input type="text" name="name" value="<?= h($cam['name']) ?>" required pattern="[A-Za-z0-9_\-]+">
    </div>
    <div>
      <label>Display label</label>
      <input type="text" name="label" value="<?= h($cam['label']) ?>">
    </div>
  </div>
  <div class="row">
    <div>
      <label>Camera IP</label>
      <input type="text" name="ip" value="<?= h($cam['ip']) ?>" required>
    </div>
    <div>
      <label>RTSP port</label>
      <input type="number" name="port" value="<?= h((string)$cam['port']) ?>" required>
    </div>
  </div>
  <label>RTSP path (e.g. /Streaming/Channels/101 or /cam/realmonitor?channel=1&subtype=0)</label>
  <input type="text" name="rtsp_path" value="<?= h($cam['rtsp_path']) ?>">

  <label>Sub-stream RTSP path (optional — lower-res stream for browser/app compatibility &amp; slow networks,
  e.g. /Streaming/Channels/102 or /cam/realmonitor?channel=1&subtype=1)</label>
  <input type="text" name="sub_rtsp_path" value="<?= h($cam['sub_rtsp_path'] ?? '') ?>"
         placeholder="Leave blank if this camera has no sub-stream">
  <p class="hint">Most DVRs' main stream is H.265 (HEVC), which browsers can't play over WebRTC — but the
  sub-stream is almost always H.264, which works everywhere. Set this and the sync script will offer both
  to go2rtc, so it auto-picks whichever the viewer's browser/network can handle — and both the web viewer
  and the app will use it specifically for grid/thumbnail tiles (much cheaper to decode several at once),
  reserving the main stream for when you open a single camera fullscreen.</p>
  <div class="row">
    <div>
      <label>Username</label>
      <input type="text" name="username" value="<?= h($cam['username']) ?>">
    </div>
    <div>
      <label>Password <?= $id ? '(leave blank to keep current)' : '' ?></label>
      <input type="password" name="password" autocomplete="new-password">
    </div>
  </div>
  <div class="checks">
    <label><input type="checkbox" name="detect_object" <?= $cam['detect_object'] ? 'checked' : '' ?>> Object detection</label>
    <label><input type="checkbox" name="detect_face" <?= $cam['detect_face'] ? 'checked' : '' ?>> Face detection</label>
    <label><input type="checkbox" name="enabled" <?= $cam['enabled'] ? 'checked' : '' ?>> Enabled</label>
  </div>
  <label>Recording</label>
  <select name="recording_mode">
    <option value="off" <?= $cam['recording_mode'] === 'off' ? 'selected' : '' ?>>Off</option>
    <option value="continuous" <?= $cam['recording_mode'] === 'continuous' ? 'selected' : '' ?>>Continuous (24/7)</option>
    <option value="motion" <?= $cam['recording_mode'] === 'motion' ? 'selected' : '' ?>>Motion-triggered clips only</option>
  </select>
  <label>Sort order (position in grid)</label>
  <input type="number" name="sort_order" value="<?= h((string)$cam['sort_order']) ?>">
  <button type="submit"><?= $id ? 'Save changes' : 'Add camera' ?></button>
</form>
</body>
</html>
