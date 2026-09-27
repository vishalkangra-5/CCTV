<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../config/config.php';

require_admin();

$cams = db()->query(
    "SELECT id, name, label, recording_mode FROM cameras WHERE recording_mode != 'off' ORDER BY sort_order, id"
)->fetchAll();

$camName = $_GET['camera'] ?? ($cams[0]['name'] ?? '');
$date    = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');

$files = [];
if ($camName !== '') {
    // Same charset camera names are restricted to elsewhere (A-Za-z0-9_-) — still
    // validate here too, since this builds a filesystem path from a GET param.
    if (preg_match('/^[A-Za-z0-9_\-]+$/', $camName)) {
        $dir = RECORDING_DIR . '/' . $camName . '/' . $date;
        if (is_dir($dir)) {
            foreach (scandir($dir) as $f) {
                if (substr($f, -4) !== '.mp4') continue;
                $files[] = [
                    'name' => $f,
                    'size' => filesize($dir . '/' . $f),
                    'is_motion' => (strpos($f, '_motion_') !== false),
                ];
            }
            sort($files);
        }
    }
}

function human_size($bytes) {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1024, 1) . ' KB';
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Recordings — Camera Portal</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;margin:0;padding:24px}
  a{color:#60a5fa}
  .bar{display:flex;gap:12px;align-items:center;margin-bottom:20px;flex-wrap:wrap}
  select,input[type=date]{padding:8px;border-radius:4px;border:1px solid #333;background:#1a1d24;color:#fff}
  table{width:100%;border-collapse:collapse}
  th,td{text-align:left;padding:8px;border-bottom:1px solid #222}
  .tag{padding:2px 8px;border-radius:10px;font-size:12px}
  .tag.motion{background:#78350f;color:#fbbf24}
  .tag.continuous{background:#1e3a5f;color:#93c5fd}
  .note{background:#1a1d24;border:1px solid #333;border-radius:6px;padding:12px;margin-bottom:20px;font-size:14px;color:#ccc}
  .empty{color:#888;padding:20px 0}
</style>
</head>
<body>
<p><a href="index.php">← Back to cameras</a></p>
<h2>Recordings</h2>

<div class="note">
  Clips are stored exactly as the camera sends them (stream-copy, no re-encode)
  — that keeps CPU cost near zero, but if a camera's <strong>main</strong> stream is
  H.265 (HEVC), the clip may not play in-browser depending on your browser/device;
  download it and open with VLC if playback here shows blank/black. Cameras with a
  sub-stream configured for compatibility will generally play fine.
</div>

<form class="bar" method="get">
  <select name="camera" onchange="this.form.submit()">
    <?php foreach ($cams as $c): ?>
      <option value="<?= h($c['name']) ?>" <?= $c['name'] === $camName ? 'selected' : '' ?>>
        <?= h($c['label'] ?: $c['name']) ?> (<?= h($c['recording_mode']) ?>)
      </option>
    <?php endforeach; ?>
    <?php if (!$cams): ?><option value="">No cameras have recording enabled</option><?php endif; ?>
  </select>
  <input type="date" name="date" value="<?= h($date) ?>" onchange="this.form.submit()">
  <noscript><button type="submit">Go</button></noscript>
</form>

<?php if (!$files): ?>
  <p class="empty">No recordings for this camera/date.</p>
<?php else: ?>
  <table>
    <tr><th>File</th><th>Type</th><th>Size</th><th></th></tr>
    <?php foreach ($files as $f): ?>
    <tr>
      <td><?= h($f['name']) ?></td>
      <td><span class="tag <?= $f['is_motion'] ? 'motion' : 'continuous' ?>">
            <?= $f['is_motion'] ? 'motion clip' : 'continuous' ?></span></td>
      <td><?= human_size($f['size']) ?></td>
      <td>
        <a href="recording_file.php?camera=<?= urlencode($camName) ?>&date=<?= urlencode($date) ?>&file=<?= urlencode($f['name']) ?>&mode=play" target="_blank">Play</a>
        &middot;
        <a href="recording_file.php?camera=<?= urlencode($camName) ?>&date=<?= urlencode($date) ?>&file=<?= urlencode($f['name']) ?>&mode=download">Download</a>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
<?php endif; ?>
</body>
</html>
