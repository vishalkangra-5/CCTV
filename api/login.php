<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

init_cctv_session();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

if (is_logged_in()) {
    echo json_encode(['ok' => true]);
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = db()->prepare('SELECT * FROM admin_users WHERE username = ?');
$stmt->execute([$username]);
$row = $stmt->fetch();

if ($row && password_verify($password, $row['password_hash'])) {
    // Same session key login.php uses, so this and the web login are
    // interchangeable — whichever one you authenticate through, both the
    // viewer and the admin panel see you as logged in.
    $_SESSION['admin_id'] = $row['id'];
    echo json_encode(['ok' => true]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid username or password']);
}
