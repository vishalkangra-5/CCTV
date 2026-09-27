<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

require_admin();

$error = '';
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;

// --- Delete ---
if (isset($_GET['delete'])) {
    $delId = (int)$_GET['delete'];
    if ($delId === (int)current_user()['id']) {
        $error = "You can't delete your own account while logged in as it.";
    } else {
        db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$delId]);
        header('Location: users.php');
        exit;
    }
}

// --- Create / update ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id       = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role     = ($_POST['role'] ?? 'viewer') === 'admin' ? 'admin' : 'viewer';
    $cameraIds = array_map('intval', $_POST['camera_ids'] ?? []);

    if ($username === '') {
        $error = 'Username is required.';
    } elseif (!$id && $password === '') {
        $error = 'Password is required for a new user.';
    } else {
        if ($id) {
            if ($password !== '') {
                db()->prepare('UPDATE admin_users SET username=?, password_hash=?, role=? WHERE id=?')
                    ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role, $id]);
            } else {
                db()->prepare('UPDATE admin_users SET username=?, role=? WHERE id=?')
                    ->execute([$username, $role, $id]);
            }
        } else {
            $stmt = db()->prepare('INSERT INTO admin_users (username, password_hash, role) VALUES (?,?,?)');
            $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $role]);
            $id = (int)db()->lastInsertId();
        }

        // Full replace of this user's camera permissions to match the checkboxes submitted.
        db()->prepare('DELETE FROM camera_permissions WHERE user_id = ?')->execute([$id]);
        if ($role === 'viewer' && $cameraIds) {
            $stmt = db()->prepare('INSERT INTO camera_permissions (user_id, camera_id) VALUES (?,?)');
            foreach ($cameraIds as $camId) {
                $stmt->execute([$id, $camId]);
            }
        }
        header('Location: users.php');
        exit;
    }
}

$users = db()->query('SELECT * FROM admin_users ORDER BY username')->fetchAll();
$allCameras = db()->query('SELECT id, name, label FROM cameras ORDER BY sort_order, id')->fetchAll();

$editUser = ['id' => '', 'username' => '', 'role' => 'viewer'];
$editUserCameraIds = [];
if ($editId) {
    foreach ($users as $u) {
        if ((int)$u['id'] === $editId) { $editUser = $u; break; }
    }
    $stmt = db()->prepare('SELECT camera_id FROM camera_permissions WHERE user_id = ?');
    $stmt->execute([$editId]);
    $editUserCameraIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Users — Camera Portal</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;margin:0;padding:24px}
  a{color:#93c5fd}
  table{width:100%;border-collapse:collapse;margin-bottom:28px}
  th,td{text-align:left;padding:8px;border-bottom:1px solid #222}
  .tag{padding:2px 8px;border-radius:10px;font-size:12px}
  .tag.admin{background:#1e3a5f;color:#93c5fd}
  .tag.viewer{background:#374151;color:#d1d5db}
  form.editor{max-width:480px;background:#161922;border:1px solid #262a35;border-radius:8px;padding:20px}
  label{display:block;margin-top:14px;font-size:13px;color:#9aa4b2}
  input[type=text],input[type=password],select{
    width:100%;padding:8px;margin-top:4px;border-radius:4px;border:1px solid #333;
    background:#1a1d24;color:#fff;box-sizing:border-box}
  .cams{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-top:6px;max-height:220px;overflow:auto;
    border:1px solid #262a35;border-radius:4px;padding:10px}
  .cams label{margin:0;display:flex;gap:6px;align-items:center;color:#e6e6e6;font-size:14px}
  button{margin-top:20px;padding:10px 18px;background:#3b82f6;border:none;border-radius:4px;
    color:#fff;font-weight:600;cursor:pointer}
  .err{color:#f87171}
  .hint{color:#888;font-size:12px;margin-top:6px}
  #camsWrap.hidden{display:none}
</style>
</head>
<body>
<p><a href="index.php">&larr; Back to cameras</a></p>
<h2>Users</h2>
<?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>

<table>
  <tr><th>Username</th><th>Role</th><th>Cameras</th><th></th></tr>
  <?php foreach ($users as $u):
      $count = null;
      if ($u['role'] === 'viewer') {
          $stmt = db()->prepare('SELECT COUNT(*) FROM camera_permissions WHERE user_id = ?');
          $stmt->execute([$u['id']]);
          $count = (int)$stmt->fetchColumn();
      }
  ?>
  <tr>
    <td><?= h($u['username']) ?></td>
    <td><span class="tag <?= h($u['role']) ?>"><?= h($u['role']) ?></span></td>
    <td><?= $u['role'] === 'admin' ? 'all' : ($count . ' assigned') ?></td>
    <td>
      <a href="users.php?edit=<?= $u['id'] ?>">Edit</a>
      &middot;
      <a href="users.php?delete=<?= $u['id'] ?>" onclick="return confirm('Delete this user?')">Delete</a>
    </td>
  </tr>
  <?php endforeach; ?>
</table>

<h3><?= $editId ? 'Edit user' : 'Add user' ?></h3>
<form class="editor" method="post">
  <input type="hidden" name="id" value="<?= h((string)$editUser['id']) ?>">
  <label>Username</label>
  <input type="text" name="username" value="<?= h($editUser['username']) ?>" required>
  <label>Password <?= $editId ? '(leave blank to keep current)' : '' ?></label>
  <input type="password" name="password" autocomplete="new-password">
  <label>Role</label>
  <select name="role" id="roleSelect" onchange="document.getElementById('camsWrap').classList.toggle('hidden', this.value==='admin')">
    <option value="viewer" <?= $editUser['role'] === 'viewer' ? 'selected' : '' ?>>Viewer — can only see assigned cameras</option>
    <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin — full access, all cameras</option>
  </select>

  <div id="camsWrap" class="<?= $editUser['role'] === 'admin' ? 'hidden' : '' ?>">
    <label>Cameras this user can view</label>
    <div class="cams">
      <?php foreach ($allCameras as $cam): ?>
        <label>
          <input type="checkbox" name="camera_ids[]" value="<?= $cam['id'] ?>"
                 <?= in_array($cam['id'], $editUserCameraIds, true) ? 'checked' : '' ?>>
          <?= h($cam['label'] ?: $cam['name']) ?>
        </label>
      <?php endforeach; ?>
      <?php if (!$allCameras): ?><span class="hint">No cameras added yet.</span><?php endif; ?>
    </div>
    <p class="hint">Ignored for Admin role (admins always see every camera).</p>
  </div>

  <button type="submit"><?= $editId ? 'Save changes' : 'Add user' ?></button>
  <?php if ($editId): ?> <a href="users.php" style="margin-left:12px">Cancel</a><?php endif; ?>
</form>
</body>
</html>
