<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

function ordered_cameras() {
    return db()->query('SELECT id, name, label, sort_order FROM cameras ORDER BY sort_order, id')->fetchAll();
}

// Move camera $id up (dir=-1) or down (dir=+1) by swapping sort_order with
// its neighbor in the current ordering. Re-normalizes everyone's sort_order
// to clean sequential integers first, so this works correctly even if the
// values currently have gaps or ties (e.g. everything still at the '0'
// default from before this page existed).
if (isset($_GET['move'], $_GET['dir'])) {
    $moveId = (int)$_GET['move'];
    $dir = $_GET['dir'] === 'down' ? 1 : -1;

    $cams = ordered_cameras();
    foreach ($cams as $i => $c) {
        db()->prepare('UPDATE cameras SET sort_order = ? WHERE id = ?')->execute([$i, $c['id']]);
        $cams[$i]['sort_order'] = $i;
    }
    $idx = null;
    foreach ($cams as $i => $c) {
        if ((int)$c['id'] === $moveId) { $idx = $i; break; }
    }
    $swapIdx = $idx === null ? null : $idx + $dir;
    if ($idx !== null && $swapIdx !== null && $swapIdx >= 0 && $swapIdx < count($cams)) {
        db()->prepare('UPDATE cameras SET sort_order = ? WHERE id = ?')->execute([$swapIdx, $cams[$idx]['id']]);
        db()->prepare('UPDATE cameras SET sort_order = ? WHERE id = ?')->execute([$idx, $cams[$swapIdx]['id']]);
    }
    header('Location: reorder.php');
    exit;
}

$cams = ordered_cameras();
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Reorder cameras — Camera Portal</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;margin:0;padding:24px}
  a{color:#93c5fd}
  ul{list-style:none;margin:0;padding:0;max-width:520px}
  li{display:flex;align-items:center;gap:12px;padding:10px 14px;margin-bottom:6px;
     background:#161922;border:1px solid #262a35;border-radius:6px}
  .pos{color:#666;width:24px;text-align:right}
  .name{flex:1}
  .name small{color:#888;display:block}
  button{background:#1f2330;border:1px solid #333;color:#e6e6e6;padding:6px 10px;
     border-radius:4px;cursor:pointer;font-size:14px}
  button:disabled{opacity:.3;cursor:default}
  .hint{color:#888;font-size:13px;margin-bottom:16px}
</style>
</head>
<body>
<p><a href="index.php">&larr; Back to cameras</a></p>
<h2>Reorder cameras</h2>
<p class="hint">This is the order cameras appear in the grid viewer and the app (paginated
1/4/8/16/32 at a time, in this order).</p>
<ul>
  <?php foreach ($cams as $i => $c): ?>
  <li>
    <span class="pos"><?= $i + 1 ?></span>
    <span class="name"><?= h($c['label'] ?: $c['name']) ?><small><?= h($c['name']) ?></small></span>
    <button <?= $i === 0 ? 'disabled' : '' ?> onclick="location='reorder.php?move=<?= $c['id'] ?>&dir=up'">&uarr;</button>
    <button <?= $i === count($cams) - 1 ? 'disabled' : '' ?> onclick="location='reorder.php?move=<?= $c['id'] ?>&dir=down'">&darr;</button>
  </li>
  <?php endforeach; ?>
  <?php if (!$cams): ?><p class="hint">No cameras added yet.</p><?php endif; ?>
</ul>
</body>
</html>
