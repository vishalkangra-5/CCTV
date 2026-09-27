<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

init_cctv_session();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = $_POST['password'] ?? '';
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
    $stmt->execute([$u]);
    $row = $stmt->fetch();
    if ($row && password_verify($p, $row['password_hash'])) {
        $_SESSION['admin_id'] = $row['id'];
        // Send them back wherever they were trying to go (e.g. straight to
        // /viewer/ if that's what a viewer-role team member hit directly),
        // not always to the admin panel. Only accept a local path — never an
        // absolute URL to another host — so this can't be used as an open redirect.
        $next = $_POST['next'] ?? '';
        $isAdmin = (!isset($row['role']) || $row['role'] === 'admin');
        if ($next !== '' && $next[0] === '/' && (!isset($next[1]) || $next[1] !== '/')) {
            header('Location: ' . $next);
        } else {
            header('Location: ' . ($isAdmin ? 'index.php' : '../viewer/'));
        }
        exit;
    }
    $error = 'Invalid username or password.';
}
$next = $_GET['next'] ?? ($_POST['next'] ?? '');
?>
<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Camera Portal — Login</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f1115;color:#e6e6e6;display:flex;height:100vh;align-items:center;justify-content:center;margin:0}
  form{background:#1a1d24;padding:32px;border-radius:8px;width:300px}
  input{width:100%;padding:10px;margin:8px 0;border-radius:4px;border:1px solid #333;background:#0f1115;color:#fff;box-sizing:border-box}
  button{width:100%;padding:10px;background:#3b82f6;border:none;border-radius:4px;color:#fff;font-weight:600;cursor:pointer}
  .err{color:#f87171;font-size:14px}
  h2{margin-top:0}
</style>
</head>
<body>
<form method="post">
  <h2>Camera Portal</h2>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <input type="hidden" name="next" value="<?= h($next) ?>">
  <input name="username" placeholder="Username" autofocus required>
  <input name="password" type="password" placeholder="Password" required>
  <button type="submit">Sign in</button>
</form>
</body>
</html>
