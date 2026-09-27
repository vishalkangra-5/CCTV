<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin();

if (isset($_GET['delete'])) {
    db()->prepare('DELETE FROM cameras WHERE id = ?')->execute([(int)$_GET['delete']]);
    header('Location: index.php');
    exit;
}

$cameras = db()->query('SELECT * FROM cameras ORDER BY sort_order, id')->fetchAll();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Camera Portal — Admin</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;margin:0;padding:24px}
  h1{font-size:20px}
  table{width:100%;border-collapse:collapse;margin-top:16px}
  th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #262a33;font-size:14px}
  th{color:#9aa4b2;font-weight:600}
  a.btn,button.btn{display:inline-block;padding:6px 12px;border-radius:4px;background:#3b82f6;color:#fff;text-decoration:none;border:none;cursor:pointer;font-size:13px}
  a.btn.secondary{background:#2a2f3a}
  a.btn.danger{background:#dc2626}
  .top{display:flex;justify-content:space-between;align-items:center}
  .tag{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;margin-right:4px}
  .tag.on{background:#065f46;color:#a7f3d0}
  .tag.off{background:#374151;color:#9ca3af}
</style>
</head>
<body>
<?php if (!empty($_SESSION['go2rtc_sync_result'])):
    $sync = $_SESSION['go2rtc_sync_result'];
    unset($_SESSION['go2rtc_sync_result']); // flash message — shown once
    $ok = $sync['write_ok'] && $sync['restart_ok'];
?>
  <div style="padding:10px 16px;margin-bottom:16px;border-radius:6px;
              background:<?= $ok ? '#14301f' : '#3a1c1c' ?>;
              color:<?= $ok ? '#86efac' : '#fca5a5' ?>;font-size:14px">
    <?php if ($ok): ?>
      go2rtc config updated automatically — <?= (int)$sync['stream_count'] ?> stream(s), service restarted OK.
    <?php else: ?>
      go2rtc config saved, but auto-restart failed
      (write: <?= $sync['write_ok'] ? 'OK' : 'FAILED' ?>, service: <?= $sync['restart_ok'] ? 'OK' : 'FAILED' ?>).
      <?php if ($sync['restart_log']): ?><br>Log: <?= h($sync['restart_log']) ?><?php endif; ?>
      Try "Regenerate go2rtc config" below, or check the sudoers rule.
    <?php endif; ?>
  </div>
<?php endif; ?>
<div class="top">
  <h1>Cameras (<?= count($cameras) ?>)</h1>
  <div>
    <a class="btn secondary" href="../viewer/index.php">Open Viewer</a>
    <a class="btn secondary" href="recordings.php">Recordings</a>
    <a class="btn secondary" href="reorder.php">Reorder cameras</a>
    <a class="btn secondary" href="users.php">Users</a>
    <a class="btn" href="camera_form.php">+ Add camera</a>
  </div>
</div>
<table>
<tr><th>Name</th><th>Label</th><th>IP:Port</th><th>Path</th><th>User</th><th>Detect</th><th>Recording</th><th>Status</th><th></th></tr>
<?php foreach ($cameras as $c): ?>
<tr>
  <td><?= h($c['name']) ?></td>
  <td><?= h($c['label']) ?></td>
  <td><?= h($c['ip']) ?>:<?= h((string)$c['port']) ?></td>
  <td><?= h($c['rtsp_path']) ?></td>
  <td><?= h($c['username']) ?></td>
  <td>
    <?php if ($c['detect_object']): ?><span class="tag on">object</span><?php endif; ?>
    <?php if ($c['detect_face']): ?><span class="tag on">face</span><?php endif; ?>
    <?php if (!$c['detect_object'] && !$c['detect_face']): ?><span class="tag off">none</span><?php endif; ?>
  </td>
  <td>
    <?php if ($c['recording_mode'] === 'continuous'): ?><span class="tag on">continuous</span>
    <?php elseif ($c['recording_mode'] === 'motion'): ?><span class="tag on">motion clips</span>
    <?php else: ?><span class="tag off">off</span><?php endif; ?>
  </td>
  <td><span class="tag <?= $c['enabled'] ? 'on' : 'off' ?>"><?= $c['enabled'] ? 'enabled' : 'disabled' ?></span></td>
  <td>
    <a class="btn secondary" href="camera_form.php?id=<?= (int)$c['id'] ?>">Edit</a>
    <a class="btn danger" href="index.php?delete=<?= (int)$c['id'] ?>" onclick="return confirm('Delete this camera?')">Delete</a>
  </td>
</tr>
<?php endforeach; ?>
</table>
<p style="margin-top:20px"><a class="btn secondary" href="go2rtc_sync.php">Regenerate go2rtc config</a></p>
</body>
</html>
